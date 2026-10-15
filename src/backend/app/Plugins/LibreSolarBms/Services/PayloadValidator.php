<?php

declare(strict_types=1);

namespace App\Plugins\LibreSolarBms\Services;

use App\Plugins\LibreSolarBms\Exceptions\LibreSolarBmsException;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

class PayloadValidator {
    private const array KINDS = ['telemetry', 'summary', 'event', 'attributes', 'status', 'event-receipt'];
    private const array FAULTS = [
        'cell_undervoltage', 'cell_overvoltage', 'short_circuit',
        'discharge_overcurrent', 'charge_overcurrent', 'open_wire',
        'discharge_undertemperature', 'discharge_overtemperature',
        'charge_undertemperature', 'charge_overtemperature',
        'internal_overtemperature', 'cell_failure', 'discharge_switch_off',
        'charge_switch_off', 'mosfet_overtemperature',
    ];
    private const array STATES = ['OFF', 'CHG', 'DIS', 'NORMAL', 'SHUTDOWN'];

    private Validator $validator;
    /** @var list<string> */
    private array $fields;

    public function __construct() {
        $this->validator = new Validator();
        $this->validator->setMaxErrors(100);
        $resources = dirname(__DIR__).'/resources';
        foreach (glob($resources.'/schema/*.schema.json') ?: [] as $path) {
            $this->validator->resolver()->registerRaw(file_get_contents($path));
        }
        $definitions = json_decode(file_get_contents($resources.'/fields.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->fields = array_keys($definitions['fields']);
    }

    /** @return array<string, mixed> */
    public function validate(string $kind, string $json): array {
        if (!in_array($kind, self::KINDS, true)) {
            throw new LibreSolarBmsException('invalid_payload', 422);
        }
        if (strlen($json) > 32768) {
            throw new LibreSolarBmsException('payload_too_large', 413);
        }
        $object = json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new LibreSolarBmsException('invalid_payload', 422, null, [['path' => '', 'code' => 'invalid_json']]);
        }
        $messageId = is_object($object) && isset($object->message_id) && is_string($object->message_id)
            && strlen($object->message_id) <= 160 ? $object->message_id : null;
        if (!$this->finite($object)) {
            throw new LibreSolarBmsException('invalid_payload', 422, $messageId, [['path' => '', 'code' => 'type']]);
        }
        $result = $this->validator->validate($object, 'urn:libresolar:bms:v1:'.$kind);
        if (!$result->isValid()) {
            throw new LibreSolarBmsException('invalid_payload', 422, $messageId, $this->schemaErrors($result->error()));
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $errors = $this->semanticErrors($kind, $payload);
        if ($errors !== []) {
            throw new LibreSolarBmsException('invalid_payload', 422, $messageId, $errors);
        }

        return $payload;
    }

    /** @return list<array{path: string, code: string}> */
    private function schemaErrors(?ValidationError $error): array {
        if (!$error instanceof ValidationError) {
            return [['path' => '', 'code' => 'schema']];
        }
        if ($error->subErrors() !== []) {
            $errors = [];
            foreach ($error->subErrors() as $child) {
                array_push($errors, ...$this->schemaErrors($child));
            }

            return $errors;
        }
        $path = array_map(static fn (int|string $part): string => str_replace(['~', '/'], ['~0', '~1'], (string) $part), $error->data()->fullPath());

        return [['path' => $path === [] ? '' : '/'.implode('/', $path), 'code' => $error->keyword()]];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{path: string, code: string}>
     */
    private function semanticErrors(string $kind, array $payload): array {
        $errors = [];
        $reject = static function (string $path, string $code) use (&$errors): void {
            $errors[] = ['path' => $path, 'code' => $code];
        };
        if (in_array($kind, ['telemetry', 'summary', 'event', 'attributes'], true)) {
            $suffix = $kind === 'event' ? 'events' : $kind;
            if ($payload['message_id'] !== $payload['node_id'].':'.$payload['session_id'].':'.$suffix.':'.$this->counter($payload['sequence'])) {
                $reject('/message_id', 'identity_mismatch');
            }
            // The schema fixes timestamps to UTC with identical millisecond precision.
            if ($payload['published_at'] < $payload['observed_at']) {
                $reject('/published_at', 'before_observation');
            }
            if (($payload['source'] === 'simulator' && $payload['clock_source'] !== 'simulator_host')
                || ($payload['source'] === 'hardware' && $payload['clock_source'] === 'simulator_host')) {
                $reject('/clock_source', 'source_mismatch');
            }
        }
        if ($kind === 'summary' || $kind === 'event') {
            $prefix = $payload['node_id'].':'.$payload['session_id'].':telemetry:';
            $reference = substr($payload['telemetry_message_id'], strlen($prefix));
            if (!str_starts_with($payload['telemetry_message_id'], $prefix) || !ctype_digit($reference)
                || (float) $reference > 9007199254740991 || (float) $reference >= $payload['sequence']) {
                $reject('/telemetry_message_id', 'invalid_sample_reference');
            }
        }
        if ($kind === 'summary' && $payload['last_source_observed_at'] !== $payload['observed_at']) {
            $reject('/last_source_observed_at', 'observation_mismatch');
        }
        if ($kind === 'summary' || $kind === 'telemetry') {
            $this->measurementErrors($payload, $reject);
        }
        if ($kind === 'telemetry') {
            $this->telemetryErrors($payload, $reject);
        }
        if ($kind === 'event') {
            $this->eventErrors($payload, $reject);
        }
        if ($kind === 'attributes') {
            if ($payload['cell_count'] != count($payload['cell_channels'])) {
                $reject('/cell_count', 'channel_count_mismatch');
            }
            if ($payload['temperature_sensor_count'] != count($payload['temperature_sensor_channels'])) {
                $reject('/temperature_sensor_count', 'channel_count_mismatch');
            }
            if (in_array('soh', $payload['supported_fields'], true) || in_array('cycle_count', $payload['supported_fields'], true)) {
                $reject('/supported_fields', 'unsupported_firmware_field');
            }
            if (($payload['source'] === 'simulator') !== ($payload['simulation_time_factor'] !== null)) {
                $reject('/simulation_time_factor', 'source_mismatch');
            }
        }
        if ($kind === 'status') {
            if ($payload['message_id'] !== $payload['node_id'].':'.$payload['adapter_id'].':status:'.$this->counter($payload['status_revision'])) {
                $reject('/message_id', 'identity_mismatch');
            }
            if ($payload['source_status'] === 'fresh' && $payload['last_source_observed_at'] === null) {
                $reject('/last_source_observed_at', 'missing_fresh_observation');
            }
            if ($payload['last_source_observed_at'] !== null
                && $payload['last_source_observed_at'] > $payload['status_checked_at']) {
                $reject('/status_checked_at', 'before_observation');
            }
        }

        if ($kind === 'event-receipt') {
            $parts = explode(':', $payload['event_id']);
            $sequence = end($parts);
            if (!str_starts_with($payload['event_id'], $payload['node_id'].':') || !str_contains($payload['event_id'], ':events:')
                || !ctype_digit($sequence) || (float) $sequence > 9007199254740991) {
                $reject('/event_id', 'identity_mismatch');
            }
            if ($payload['message_id'] !== $payload['event_id'].':receipt:'.$payload['consumer_id']) {
                $reject('/message_id', 'identity_mismatch');
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed>           $payload
     * @param callable(string, string): void $reject
     */
    private function measurementErrors(array $payload, callable $reject): void {
        $quality = $payload['quality'];
        foreach ($this->fields as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $reading = $payload[$field];
            if ($reading === null && !isset($quality[$field])) {
                $reject('/'.$field, 'missing_quality');
            }
            if ($reading !== null && !is_array($reading) && isset($quality[$field])) {
                $reject('/quality/'.$field, 'quality_on_present_value');
            }
            if (is_array($reading) && $field !== 'faults') {
                foreach ($reading as $index => $item) {
                    if ($item === null && !isset($quality[$field.'['.$index.']'])) {
                        $reject('/'.$field.'/'.$index, 'missing_quality');
                    }
                }
            }
        }
        foreach ($quality as $path => $reason) {
            preg_match('/^([a-z_]+)(?:\[(\d+)\])?$/', $path, $matches);
            $field = $matches[1] ?? null;
            $index = $matches[2] ?? null;
            if ($field === null || !in_array($field, $this->fields, true) || !array_key_exists($field, $payload)) {
                $reject('/quality/'.$path, 'unknown_quality_path');
                continue;
            }
            if ($index !== null) {
                if (!is_array($payload[$field]) || (int) $index >= count($payload[$field]) || $payload[$field][(int) $index] !== null) {
                    $reject('/quality/'.$path, 'quality_on_present_value');
                }
            } elseif (is_array($payload[$field]) && ($reason !== 'incomplete' || !in_array(null, $payload[$field], true))) {
                $reject('/quality/'.$path, 'invalid_array_quality');
            }
        }
        if (($quality['soh'] ?? null) !== 'unsupported' || ($quality['cycle_count'] ?? null) !== 'unsupported') {
            $reject('/quality', 'unsupported_firmware_field');
        }
        if ($payload['faults'] !== $this->decoded($payload['error_flags'])) {
            $reject('/faults', 'mask_mismatch');
        }
        $state = $payload['bms_state_code'] === null ? null : (self::STATES[(int) $payload['bms_state_code']] ?? 'unknown');
        if ($payload['bms_state'] !== $state) {
            $reject('/bms_state', 'state_mismatch');
        }
    }

    /**
     * @param array<string, mixed>           $payload
     * @param callable(string, string): void $reject
     */
    private function telemetryErrors(array $payload, callable $reject): void {
        $this->derivedErrors('cell_voltage_delta', $payload['cell_voltages'], $payload, $reject, true);
        $this->derivedErrors('cell_temperature_max', $payload['cell_temperatures'], $payload, $reject);
        $components = [];
        foreach (['ic_temperature', 'mosfet_temperature', 'shunt_temperature'] as $field) {
            if (($payload['quality'][$field] ?? null) !== 'unsupported') {
                $components[] = $payload[$field];
            }
        }
        $this->derivedErrors('component_temperature_max', $components, $payload, $reject);
    }

    /**
     * @param list<int|float|null>           $entries
     * @param array<string, mixed>           $payload
     * @param callable(string, string): void $reject
     */
    private function derivedErrors(string $field, array $entries, array $payload, callable $reject, bool $delta = false): void {
        if ($entries === [] || in_array(null, $entries, true)) {
            if ($payload[$field] !== null) {
                $reject('/'.$field, 'incomplete_derived_value');
            }

            return;
        }
        $expected = max($entries) - ($delta ? min($entries) : 0);
        if ($payload[$field] === null || abs($payload[$field] - $expected) > 1e-9) {
            $reject('/'.$field, 'derived_value_mismatch');
        }
    }

    /**
     * @param array<string, mixed>           $payload
     * @param callable(string, string): void $reject
     */
    private function eventErrors(array $payload, callable $reject): void {
        if ($payload['event_id'] !== $payload['message_id']) {
            $reject('/event_id', 'identity_mismatch');
        }
        $current = $this->decoded($payload['current_error_flags']);
        $previous = $this->decoded($payload['previous_error_flags']);
        if ($payload['faults'] !== $current) {
            $reject('/faults', 'mask_mismatch');
        }
        if ($payload['kind'] === 'fault_changed') {
            if ($current === null || $previous === null || $payload['current_error_flags'] == $payload['previous_error_flags']) {
                $reject('/kind', 'invalid_transition');

                return;
            }
            if ($payload['added_faults'] !== array_values(array_diff($current, $previous))) {
                $reject('/added_faults', 'transition_mismatch');
            }
            if ($payload['cleared_faults'] !== array_values(array_diff($previous, $current))) {
                $reject('/cleared_faults', 'transition_mismatch');
            }

            return;
        }
        if ($payload['kind'] === 'fault_observed') {
            if ($payload['previous_error_flags'] !== null || $current === null || $current === []
                || $payload['added_faults'] !== $current || $payload['cleared_faults'] !== []) {
                $reject('/kind', 'invalid_first_observation');
            }

            return;
        }
        if ($payload['previous_error_flags'] !== null || $payload['added_faults'] !== [] || $payload['cleared_faults'] !== []) {
            $reject('/kind', 'invented_gap_transition');
        }
    }

    /** @return list<string>|null */
    private function decoded(int|float|null $mask): ?array {
        if ($mask === null) {
            return null;
        }
        $faults = [];
        for ($bit = 0; $bit < 32; ++$bit) {
            if (((int) $mask & (1 << $bit)) !== 0) {
                $faults[] = self::FAULTS[$bit] ?? 'unknown_bit_'.$bit;
            }
        }
        sort($faults, SORT_STRING);

        return $faults;
    }

    private function finite(mixed $value): bool {
        if (is_float($value)) {
            return is_finite($value);
        }
        if (is_array($value) || is_object($value)) {
            foreach ($value as $child) {
                if (!$this->finite($child)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function counter(int|float $value): string {
        return sprintf('%.0f', $value);
    }
}
