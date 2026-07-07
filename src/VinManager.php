<?php

namespace AlwaysCurious\Vin;

use AlwaysCurious\Vin\Contracts\VinDecoder;
use AlwaysCurious\Vin\Decoders\NhtsaVinDecoder;
use Illuminate\Support\Manager;

/**
 * The driver registry for VIN decoders.
 *
 * Resolves the decoder named by `vin.driver` (default `nhtsa`) and wraps it in a
 * {@see VinLookupService} so validation, the enabled gate and caching apply to every
 * driver. Register a custom provider with `extend()`:
 *
 *     Vin::extend('acme', fn ($app) => new AcmeVinDecoder($app['config']['services.acme.key']));
 *
 * The manager caches decoder instances (per the {@see Manager} contract) but builds a
 * fresh VinLookupService on every lookup, so the enabled gate and cache config are
 * re-read at runtime. Driver-level config (`vin.decoders.*`) is captured when a driver
 * is first resolved; call `forgetDrivers()` to pick up a runtime change to it.
 *
 * @method \AlwaysCurious\Vin\Contracts\VinDecoder driver(string|null $driver = null)
 */
class VinManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('vin.driver', 'nhtsa');
    }

    /**
     * Decode a VIN with the default driver.
     *
     * @throws VinLookupException
     */
    public function lookup(string $vin, ?int $modelYear = null): VehicleData
    {
        return $this->using()->lookup($vin, $modelYear);
    }

    /**
     * Decode a VIN with the default driver, returning null on any failure.
     */
    public function tryLookup(string $vin, ?int $modelYear = null): ?VehicleData
    {
        return $this->using()->tryLookup($vin, $modelYear);
    }

    /**
     * Whether the given string is structurally a valid VIN (no decoder/network call).
     */
    public function isValid(string $vin): bool
    {
        return $this->using()->isValid($vin);
    }

    /**
     * Get a lookup service bound to a specific driver (the default driver when null),
     * without changing the default. A fresh service is returned each call so the gate
     * and cache config are read live.
     */
    public function using(?string $driver = null): VinLookupService
    {
        $driver = $driver ?: $this->getDefaultDriver();

        return new VinLookupService(
            decoder: $this->driver($driver),
            driver: $driver,
            enabled: (bool) $this->config->get('vin.enabled', true),
            cacheStore: $this->config->get('vin.cache.store'),
            cacheTtl: (int) $this->config->get('vin.cache.ttl', 86400),
            cacheVersion: (int) $this->config->get('vin.cache.version', 1),
        );
    }

    /**
     * Create the built-in "nhtsa" driver from vin.decoders.nhtsa.*.
     */
    protected function createNhtsaDriver(): VinDecoder
    {
        return new NhtsaVinDecoder(
            $this->config->get('vin.decoders.nhtsa.base_url'),
            (int) $this->config->get('vin.decoders.nhtsa.timeout', 10),
        );
    }
}
