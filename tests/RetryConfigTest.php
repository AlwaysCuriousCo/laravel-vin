<?php

namespace AlwaysCurious\Vin\Tests;

use AlwaysCurious\Vin\Facades\Vin;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class RetryConfigTest extends TestCase
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
     * @spec VIN-018
     */
    public function test_the_client_retries_transient_connection_failures_per_config(): void
    {
        config(['vin.decoders.nhtsa.retry.times' => 3, 'vin.decoders.nhtsa.retry.sleep' => 0]);

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                throw new ConnectionException('connection dropped');
            }

            return Http::response($this->okResponse());
        });

        $vehicle = Vin::lookup(self::VIN);

        $this->assertSame('HYUNDAI', $vehicle->make);
        $this->assertSame(3, $attempts);
    }

    /**
     * @spec VIN-018
     */
    public function test_a_retry_times_of_one_disables_retrying(): void
    {
        config(['vin.decoders.nhtsa.retry.times' => 1, 'vin.decoders.nhtsa.retry.sleep' => 0]);

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('connection dropped');
        });

        $this->assertNull(Vin::tryLookup(self::VIN));
        $this->assertSame(1, $attempts);
    }
}
