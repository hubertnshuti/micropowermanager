<?php

namespace App\Plugins\LibreSolarBms\Services;

use App\Plugins\LibreSolarBms\Exceptions\LibreSolarBmsException;
use App\Plugins\LibreSolarBms\Models\Battery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BatteryService {
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function ingest(string $kind, array $payload, string $json): array {
        return DB::connection('tenant')->transaction(function () use ($kind, $payload, $json): array {
            $battery = Battery::where('node_id', $payload['node_id'])->lockForUpdate()->first();
            if (!$battery instanceof Battery) {
                throw new LibreSolarBmsException('battery_not_provisioned', 404, $payload['message_id']);
            }
            if ($battery->source !== $payload['source']) {
                throw new LibreSolarBmsException('invalid_payload', 422, $payload['message_id']);
            }
            $fingerprint = hash('sha256', json_encode($this->normalize(json_decode($json)), JSON_THROW_ON_ERROR));
            $receipts = DB::connection('tenant')->table('libre_solar_bms_receipts')->where('battery_id', $battery->id);
            $receipt = (clone $receipts)->where('message_id', $payload['message_id'])->first();
            if ($receipt !== null) {
                if ($receipt->fingerprint !== $fingerprint) {
                    throw new LibreSolarBmsException('id_conflict', 409, $payload['message_id']);
                }

                return ['result' => $receipt->result === 'accepted' ? 'duplicate' : $receipt->result,
                    'message_id' => $payload['message_id'], ...($receipt->reason === null ? [] : ['reason' => $receipt->reason])];
            }
            $state = DB::connection('tenant')->table('libre_solar_bms_battery_states')->where('battery_id', $battery->id)->first();
            $outcome = $this->apply($battery, $state, $kind, $payload, $json);
            $receipts->insert([
                'battery_id' => $battery->id, 'message_id' => $payload['message_id'],
                'fingerprint' => $fingerprint, 'result' => $outcome['result'],
                'reason' => $outcome['reason'] ?? null, 'received_at' => now(),
            ]);

            return [...$outcome, 'message_id' => $payload['message_id']];
        }, 3);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function apply(Battery $battery, ?object $state, string $kind, array $payload, string $json): array {
        $current = $state?->summary === null ? null : json_decode($state->summary, true);
        $reason = $this->snapshotOrder($battery, $payload, $current, true);
        if ($reason !== null) {
            return ['result' => 'ignored_stale', 'reason' => $reason];
        }
        DB::connection('tenant')->table('libre_solar_bms_battery_states')->updateOrInsert(
            ['battery_id' => $battery->id],
            ['summary' => $json, 'summary_received_at' => now()]
        );

        return ['result' => 'accepted'];
    }

    /** @param array<string, mixed> $payload
     * @param array<string, mixed>|null $current
     */
    private function snapshotOrder(Battery $battery, array $payload, ?array $current, bool $canPromote): ?string {
        $session = $payload['session_id'];
        if (in_array($session, $battery->retired_sessions ?? [], true)) {
            return 'superseded_session';
        }
        $observed = Carbon::parse($payload['observed_at']);
        if ($observed->greaterThan(now()->addSeconds(5))) {
            throw new LibreSolarBmsException('session_conflict', 409, $payload['message_id']);
        }
        if ($battery->active_session_id !== null && $battery->active_session_id !== $session) {
            $summary = DB::connection('tenant')->table('libre_solar_bms_battery_states')->where('battery_id', $battery->id)->value('summary');
            $previous = $summary === null ? null : json_decode($summary, true);
            if (!$canPromote || $previous === null || $payload['observed_at'] <= $previous['observed_at'] || $observed->lessThan(now()->subSeconds(150))) {
                throw new LibreSolarBmsException('session_conflict', 409, $payload['message_id']);
            }
            $battery->retired_sessions = [...($battery->retired_sessions ?? []), $battery->active_session_id];
            $battery->active_session_id = $session;
            $battery->save();
        }
        if ($battery->active_session_id === null && $canPromote) {
            $battery->active_session_id = $session;
            $battery->save();
        }
        if ($current !== null && $current['session_id'] === $session) {
            if ($payload['sequence'] < $current['sequence']) {
                return 'superseded_sequence';
            }
            if ($payload['sequence'] === $current['sequence'] || $payload['observed_at'] < $current['observed_at']) {
                throw new LibreSolarBmsException('id_conflict', 409, $payload['message_id']);
            }
        }

        return null;
    }

    private function normalize(mixed $value): mixed {
        if (is_object($value)) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);

            return (object) array_map($this->normalize(...), $properties);
        }
        if (is_array($value)) {
            return array_map($this->normalize(...), $value);
        }

        return $value;
    }

    public function find(int $id): Battery {
        return Battery::find($id) ?? throw new LibreSolarBmsException('not_found', 404);
    }

    /** @return array<string, mixed> */
    public function detail(Battery $battery): array {
        $state = DB::connection('tenant')->table('libre_solar_bms_battery_states')->where('battery_id', $battery->id)->first();
        $summary = $state?->summary === null ? null : json_decode($state->summary);

        return ['id' => $battery->id, 'node_id' => $battery->node_id, 'source' => $battery->source,
            'label' => $battery->label, 'summary' => $summary, 'attributes' => null, 'status' => null,
            'summary_received_at' => $state?->summary_received_at,
            'status_received_at' => null, 'integration_status' => 'stale',
            'integration_stale_after_seconds' => 150, 'metric_observed_at' => $summary?->observed_at];
    }
}
