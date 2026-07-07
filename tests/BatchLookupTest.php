<?php

namespace AlwaysCurious\Vin\Tests;

use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\Tests\Doubles\RecordingBatchVinDecoder;
use AlwaysCurious\Vin\Tests\Doubles\RecordingVinDecoder;
use AlwaysCurious\Vin\VinFailureReason;
use AlwaysCurious\Vin\VinLookupException;
use Illuminate\Support\Facades\Http;

class BatchLookupTest extends TestCase
{
    private const VIN_A = '7YAMYFS50TY009706';

    private const VIN_B = '1HGCM82633A004352';

    private function batchResponse(): array
    {
        return ['Count' => 2, 'Results' => [
            ['Make' => 'HYUNDAI', 'Model' => 'Ioniq 9', 'ModelYear' => '2026', 'ErrorCode' => '0'],
            ['Make' => 'HONDA', 'Model' => 'Accord', 'ModelYear' => '2003', 'ErrorCode' => '0'],
        ]];
    }

    /**
     * @spec VIN-023
     */
    public function test_the_nhtsa_driver_decodes_a_batch_in_one_http_request(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->batchResponse())]);

        $results = Vin::lookupMany([self::VIN_A, self::VIN_B]);

        $this->assertSame('HYUNDAI', $results[self::VIN_A]->make);
        $this->assertSame('HONDA', $results[self::VIN_B]->make);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'DecodeVinValuesBatch')
            && str_contains($request->body(), self::VIN_A)
            && str_contains($request->body(), self::VIN_B));
    }

    /**
     * @spec VIN-023
     */
    public function test_lookup_many_uses_the_batch_capability_when_available(): void
    {
        $decoder = new RecordingBatchVinDecoder;
        Vin::extend('batch', fn () => $decoder);
        config(['vin.driver' => 'batch']);

        $results = Vin::lookupMany([self::VIN_A, self::VIN_B]);

        $this->assertSame([self::VIN_A, self::VIN_B], array_keys($results));
        $this->assertSame(1, $decoder->decodeManyCalls);
        $this->assertSame(0, $decoder->decodeCalls);
    }

    /**
     * @spec VIN-023
     * @spec VIN-006
     */
    public function test_lookup_many_reuses_cache_and_only_batches_the_misses(): void
    {
        $decoder = new RecordingBatchVinDecoder;
        Vin::extend('batch', fn () => $decoder);
        config(['vin.driver' => 'batch']);

        Vin::lookup(self::VIN_A);   // primes the cache for A via single decode()

        $results = Vin::lookupMany([self::VIN_A, self::VIN_B]);

        $this->assertArrayHasKey(self::VIN_A, $results);
        $this->assertArrayHasKey(self::VIN_B, $results);
        // Only the cache miss (B) reaches the batch call.
        $this->assertSame([self::VIN_B], $decoder->lastBatch);
    }

    /**
     * @spec VIN-023
     */
    public function test_lookup_many_falls_back_to_looping_decode_without_batch_support(): void
    {
        $decoder = new RecordingVinDecoder('ACME', 'Rocket'); // implements VinDecoder, not BatchVinDecoder
        Vin::extend('single', fn () => $decoder);
        config(['vin.driver' => 'single']);

        $results = Vin::lookupMany([self::VIN_A, self::VIN_B]);

        $this->assertCount(2, $results);
        $this->assertSame(2, $decoder->calls);
    }

    /**
     * @spec VIN-023
     * @spec VIN-002
     */
    public function test_lookup_many_throws_on_a_structurally_invalid_vin_before_any_call(): void
    {
        $decoder = new RecordingBatchVinDecoder;
        Vin::extend('batch', fn () => $decoder);
        config(['vin.driver' => 'batch']);

        $threw = false;
        try {
            Vin::lookupMany([self::VIN_A, 'NOT-A-VIN']);
        } catch (VinLookupException $e) {
            $threw = true;
            $this->assertSame(VinFailureReason::InvalidVin, $e->reason);
        }

        $this->assertTrue($threw);
        $this->assertSame(0, $decoder->decodeManyCalls);
        $this->assertSame(0, $decoder->decodeCalls);
    }

    /**
     * @spec VIN-023
     * @spec VIN-004
     */
    public function test_lookup_many_honors_the_enabled_gate(): void
    {
        config(['vin.enabled' => false, 'vin.driver' => 'batch']);
        $decoder = new RecordingBatchVinDecoder;
        Vin::extend('batch', fn () => $decoder);

        $threw = false;
        try {
            Vin::lookupMany([self::VIN_A]);
        } catch (VinLookupException $e) {
            $threw = true;
            $this->assertSame(VinFailureReason::Disabled, $e->reason);
        }

        $this->assertTrue($threw);
        $this->assertSame(0, $decoder->decodeManyCalls);
    }

    /**
     * @spec VIN-023
     */
    public function test_lookup_many_de_duplicates_while_preserving_order(): void
    {
        $decoder = new RecordingBatchVinDecoder;
        Vin::extend('batch', fn () => $decoder);
        config(['vin.driver' => 'batch']);

        $results = Vin::lookupMany([self::VIN_B, self::VIN_A, self::VIN_B]);

        $this->assertSame([self::VIN_B, self::VIN_A], array_keys($results));
        $this->assertSame([self::VIN_B, self::VIN_A], $decoder->lastBatch);
    }
}
