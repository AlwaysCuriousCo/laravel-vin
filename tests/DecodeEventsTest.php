<?php

namespace AlwaysCurious\Vin\Tests;

use AlwaysCurious\Vin\Events\VinDecoded;
use AlwaysCurious\Vin\Events\VinDecodeFailed;
use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\VinFailureReason;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class DecodeEventsTest extends TestCase
{
    private const VIN = '7YAMYFS50TY009706';

    private function okResponse(): array
    {
        return ['Results' => [[
            'Make' => 'HYUNDAI',
            'Model' => 'Ioniq 9',
            'ModelYear' => '2026',
            'ErrorCode' => '0',
        ]]];
    }

    /**
     * @spec VIN-022
     */
    public function test_a_successful_decode_dispatches_vin_decoded(): void
    {
        Event::fake();
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->okResponse())]);

        Vin::lookup(self::VIN, 2026);

        Event::assertDispatched(VinDecoded::class, fn (VinDecoded $e) => $e->vehicle->make === 'HYUNDAI'
            && $e->driver === 'nhtsa'
            && $e->modelYear === 2026
            && $e->fromCache === false);
    }

    /**
     * @spec VIN-022
     */
    public function test_a_cache_hit_dispatches_vin_decoded_flagged_from_cache(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response($this->okResponse())]);

        Vin::lookup(self::VIN);   // prime cache (events not yet faked)

        Event::fake();
        Vin::lookup(self::VIN);   // served from cache

        Event::assertDispatched(VinDecoded::class, fn (VinDecoded $e) => $e->fromCache === true);
        Http::assertSentCount(1);
    }

    /**
     * @spec VIN-022
     */
    public function test_a_failed_decode_dispatches_vin_decode_failed_with_reason(): void
    {
        config(['vin.decoders.nhtsa.retry.times' => 1]);
        Event::fake();
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response('', 500)]);

        // tryLookup swallows the exception, but the failure telemetry still fires.
        $this->assertNull(Vin::tryLookup(self::VIN));

        Event::assertDispatched(VinDecodeFailed::class, fn (VinDecodeFailed $e) => $e->reason === VinFailureReason::RequestFailed
            && $e->vin === self::VIN
            && $e->driver === 'nhtsa');
    }
}
