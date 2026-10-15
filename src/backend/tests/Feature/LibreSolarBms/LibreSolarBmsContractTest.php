<?php

namespace Tests\Feature\LibreSolarBms;

use App\Plugins\LibreSolarBms\Exceptions\LibreSolarBmsException;
use App\Plugins\LibreSolarBms\Services\PayloadValidator;
use PHPUnit\Framework\TestCase;

class LibreSolarBmsContractTest extends TestCase {
    public function testEveryPublishedValidFixturePassesThePhpValidator(): void {
        $manifest = $this->manifest();
        $this->assertCount(16, $manifest['valid']);
        $validator = new PayloadValidator();
        foreach ($manifest['valid'] as $name) {
            $json = $this->fixture('valid/'.$name);
            $kind = substr($name, strrpos($name, '.') + 1);
            $this->assertSame(json_decode($json, true, flags: JSON_THROW_ON_ERROR), $validator->validate($kind, $json), $name);
        }
    }

    public function testEveryPublishedInvalidFixtureIsRejectedByThePhpValidator(): void {
        $manifest = $this->manifest();
        $this->assertCount(17, $manifest['invalid']);
        $validator = new PayloadValidator();
        foreach ($manifest['invalid'] as $name) {
            $kind = substr($name, strrpos($name, '.') + 1);
            $this->assertRejected($validator, $kind, $this->fixture('invalid/'.$name), $name);
        }
    }

    public function testMalformedDatesUuidsAndNonFiniteNumbersAreRejected(): void {
        $validator = new PayloadValidator();
        $summary = json_decode($this->fixture('valid/normal.summary'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['2026-02-30T14:00:00.000Z', '2026-10-08T25:00:00.000Z', '2026-10-08T14:00:00Z', '2026-10-08T14:00:00.000+00:00'] as $timestamp) {
            $invalid = [...$summary, 'observed_at' => $timestamp, 'published_at' => $timestamp, 'last_source_observed_at' => $timestamp];
            $this->assertRejected($validator, 'summary', json_encode($invalid, JSON_THROW_ON_ERROR), $timestamp);
        }
        $invalid = [...$summary, 'session_id' => str_repeat('-', 36)];
        $this->assertRejected($validator, 'summary', json_encode($invalid, JSON_THROW_ON_ERROR), 'Malformed UUID');
        $json = $this->fixture('valid/normal.summary');
        $this->assertRejected($validator, 'summary', str_replace('74.2', '1e999', $json), 'Infinite numeric value');
        $this->assertRejected($validator, 'summary', str_replace('74.2', 'NaN', $json), 'Non-JSON numeric value');
    }

    public function testObjectsArraysAndNullRetainTheirDistinctContractMeanings(): void {
        $validator = new PayloadValidator();
        $json = $this->fixture('valid/normal.summary');
        $this->assertRejected($validator, 'summary', '[]', 'Root array');
        $this->assertRejected($validator, 'summary', 'null', 'Root null');
        $this->assertRejected($validator, 'summary', '{', 'Malformed JSON');
        $this->assertRejected($validator, 'summary', str_replace('"faults": []', '"faults": {}', $json), 'Fault object');
        $summary = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $summary['quality'] = [];
        $this->assertRejected($validator, 'summary', json_encode($summary, JSON_THROW_ON_ERROR), 'Quality array');
        $summary = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $summary['faults'] = null;
        $summary['error_flags'] = null;
        $summary['quality']['faults'] = 'not_reported';
        $summary['quality']['error_flags'] = 'not_reported';
        $result = $validator->validate('summary', json_encode($summary, JSON_THROW_ON_ERROR));
        $this->assertNull($result['faults']);
        $this->assertNull($result['error_flags']);
        $this->assertSame(74.2, $result['soc']);
    }

    private function assertRejected(PayloadValidator $validator, string $kind, string $json, string $case): void {
        try {
            $validator->validate($kind, $json);
        } catch (LibreSolarBmsException $exception) {
            $this->assertSame('invalid_payload', $exception->getMessage(), $case);

            return;
        }
        $this->fail('Invalid payload accepted: '.$case);
    }

    /** @return array{valid: list<string>, invalid: list<string>} */
    private function manifest(): array {
        return json_decode($this->fixture('manifest'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fixture(string $name): string {
        return file_get_contents(__DIR__.'/../../fixtures/LibreSolarBms/v1/'.$name.'.json');
    }
}
