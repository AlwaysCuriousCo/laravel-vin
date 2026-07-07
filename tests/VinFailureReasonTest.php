<?php

namespace AlwaysCurious\Vin\Tests;

use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\VinFailureReason;
use AlwaysCurious\Vin\VinLookupException;
use Illuminate\Support\Facades\Http;

class VinFailureReasonTest extends TestCase
{
    private const VIN = '7YAMYFS50TY009706';

    /**
     * @spec VIN-021
     */
    public function test_an_invalid_vin_carries_the_invalid_reason(): void
    {
        Http::fake();

        try {
            Vin::lookup('NOT-A-VIN');
            $this->fail('Expected a VinLookupException.');
        } catch (VinLookupException $e) {
            $this->assertSame(VinFailureReason::InvalidVin, $e->reason);
            $this->assertFalse($e->reason->isTransient());
        }
    }

    /**
     * @spec VIN-021
     */
    public function test_the_disabled_gate_carries_the_disabled_reason(): void
    {
        Http::fake();
        config(['vin.enabled' => false]);

        try {
            Vin::lookup(self::VIN);
            $this->fail('Expected a VinLookupException.');
        } catch (VinLookupException $e) {
            $this->assertSame(VinFailureReason::Disabled, $e->reason);
        }
    }

    /**
     * @spec VIN-021
     */
    public function test_an_http_failure_carries_the_transient_request_failed_reason(): void
    {
        config(['vin.decoders.nhtsa.retry.times' => 1]);
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response('', 500)]);

        try {
            Vin::lookup(self::VIN);
            $this->fail('Expected a VinLookupException.');
        } catch (VinLookupException $e) {
            $this->assertSame(VinFailureReason::RequestFailed, $e->reason);
            $this->assertTrue($e->reason->isTransient());
        }
    }

    /**
     * @spec VIN-021
     */
    public function test_the_historical_constructor_signature_still_works(): void
    {
        $e = new VinLookupException('boom', 7);

        $this->assertSame('boom', $e->getMessage());
        $this->assertSame(7, $e->getCode());
        // Reason defaults when constructed positionally, so old call sites keep compiling.
        $this->assertSame(VinFailureReason::UnexpectedResponse, $e->reason);
    }
}
