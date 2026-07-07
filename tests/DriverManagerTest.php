<?php

namespace AlwaysCurious\Vin\Tests;

use AlwaysCurious\Vin\Decoders\NhtsaVinDecoder;
use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\Tests\Doubles\RecordingVinDecoder;
use AlwaysCurious\Vin\VehicleData;
use AlwaysCurious\Vin\VinLookupService;
use AlwaysCurious\Vin\VinManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DriverManagerTest extends TestCase
{
    private const VIN = '7YAMYFS50TY009706';

    /**
     * @spec VIN-008
     */
    public function test_the_default_driver_is_the_nhtsa_decoder(): void
    {
        $this->assertSame('nhtsa', Vin::getDefaultDriver());
        $this->assertInstanceOf(NhtsaVinDecoder::class, Vin::driver());
    }

    /**
     * @spec VIN-015
     */
    public function test_the_facade_resolves_to_the_manager(): void
    {
        $this->assertInstanceOf(VinManager::class, Vin::getFacadeRoot());
        $this->assertInstanceOf(VinLookupService::class, Vin::using());
    }

    /**
     * @spec VIN-009
     */
    public function test_a_custom_driver_replaces_nhtsa_without_any_http_call(): void
    {
        Http::fake();
        Vin::extend('acme', fn () => new RecordingVinDecoder('ACME', 'Rocket'));
        config(['vin.driver' => 'acme']);

        $vehicle = Vin::lookup(self::VIN);

        $this->assertSame('ACME', $vehicle->make);
        $this->assertSame('Rocket', $vehicle->model);
        Http::assertNothingSent();
    }

    /**
     * @spec VIN-001
     * @spec VIN-009
     */
    public function test_a_custom_driver_receives_the_normalized_vin_and_model_year_hint(): void
    {
        $decoder = new RecordingVinDecoder;
        Vin::extend('acme', fn () => $decoder);
        config(['vin.driver' => 'acme']);

        Vin::lookup('  7yamyfs50ty009706 ', 2026);

        $this->assertSame(self::VIN, $decoder->lastVin);
        $this->assertSame(2026, $decoder->lastModelYear);
    }

    /**
     * @spec VIN-002
     * @spec VIN-009
     */
    public function test_validation_runs_before_a_custom_driver_is_called(): void
    {
        $decoder = new RecordingVinDecoder;
        Vin::extend('acme', fn () => $decoder);
        config(['vin.driver' => 'acme']);

        $this->assertNull(Vin::tryLookup('NOT-A-VIN'));
        $this->assertSame(0, $decoder->calls);
    }

    /**
     * @spec VIN-004
     * @spec VIN-009
     */
    public function test_the_enabled_gate_short_circuits_a_custom_driver(): void
    {
        config(['vin.enabled' => false, 'vin.driver' => 'acme']);
        $decoder = new RecordingVinDecoder;
        Vin::extend('acme', fn () => $decoder);

        $this->assertNull(Vin::tryLookup(self::VIN));
        $this->assertSame(0, $decoder->calls);
    }

    /**
     * @spec VIN-006
     * @spec VIN-009
     * @spec VIN-014
     */
    public function test_caching_wraps_a_custom_driver(): void
    {
        $decoder = new RecordingVinDecoder;
        Vin::extend('acme', fn () => $decoder);
        config(['vin.driver' => 'acme']);

        Vin::lookup(self::VIN);
        Vin::lookup(self::VIN);

        $this->assertSame(1, $decoder->calls);
    }

    /**
     * @spec VIN-013
     */
    public function test_using_selects_a_named_driver_without_changing_the_default(): void
    {
        Http::fake();
        Vin::extend('acme', fn () => new RecordingVinDecoder('ACME', 'Rocket'));

        $vehicle = Vin::using('acme')->lookup(self::VIN);

        $this->assertSame('ACME', $vehicle->make);
        $this->assertSame('nhtsa', Vin::getDefaultDriver());
        Http::assertNothingSent();
    }

    /**
     * @spec VIN-014
     */
    public function test_an_extended_driver_is_selectable_by_name(): void
    {
        Http::fake();
        Vin::extend('beta', fn () => new RecordingVinDecoder('BETA', 'Two'));
        config(['vin.driver' => 'beta']);

        $vehicle = Vin::lookup(self::VIN);

        $this->assertSame('BETA', $vehicle->make);
        Http::assertNothingSent();
    }

    /**
     * @spec VIN-016
     */
    public function test_cache_is_namespaced_per_driver(): void
    {
        $alpha = new RecordingVinDecoder('ALPHA', 'One');
        $beta = new RecordingVinDecoder('BETA', 'Two');
        Vin::extend('alpha', fn () => $alpha);
        Vin::extend('beta', fn () => $beta);

        $viaAlpha = Vin::using('alpha')->lookup(self::VIN);
        $viaBeta = Vin::using('beta')->lookup(self::VIN);

        $this->assertSame('ALPHA', $viaAlpha->make);
        $this->assertSame('BETA', $viaBeta->make);
        $this->assertSame(1, $alpha->calls);
        $this->assertSame(1, $beta->calls);
    }

    /**
     * @spec VIN-017
     */
    public function test_caching_uses_the_configured_cache_store(): void
    {
        config(['cache.stores.vin_store' => ['driver' => 'array']]);
        config(['vin.cache.store' => 'vin_store']);
        Vin::extend('acme', fn () => new RecordingVinDecoder('ACME', 'Rocket'));
        config(['vin.driver' => 'acme']);

        Vin::lookup(self::VIN);

        $key = 'vin:v1:acme:'.self::VIN.':auto';
        $this->assertInstanceOf(VehicleData::class, Cache::store('vin_store')->get($key));
        $this->assertNull(Cache::store('array')->get($key));
    }
}
