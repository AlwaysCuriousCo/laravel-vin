<?php

namespace AlwaysCurious\Vin;

use AlwaysCurious\Vin\Contracts\VinDecoder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Validates, gates and caches VIN decodes, delegating the actual decode to a
 * {@see VinDecoder}. This is the workflow that {@see VinManager} builds around every
 * driver — the default NHTSA driver and every host-registered one alike — so
 * validation, the enabled gate and caching wrap all of them equally.
 *
 * It is a pure constructor-injected object: all configuration is passed in by the
 * manager (the single config boundary), so it reads no global config of its own.
 */
class VinLookupService
{
    /**
     * VIN structure: 17 characters, excluding the letters I, O and Q.
     */
    private const VIN_PATTERN = '/^[A-HJ-NPR-Z0-9]{17}$/';

    public function __construct(
        private readonly VinDecoder $decoder,
        private readonly string $driver = 'nhtsa',
        private readonly bool $enabled = true,
        private readonly ?string $cacheStore = null,
        private readonly int $cacheTtl = 86400,
        private readonly int $cacheVersion = 1,
    ) {}

    /**
     * Decode a VIN into Year, Make, Model, Series, Trim and Body Class.
     *
     * Results are cached because a VIN's decode is immutable.
     *
     * @param  int|null  $modelYear  Optional model year hint to improve decoding accuracy.
     *
     * @throws VinLookupException when the VIN is invalid, the decoder fails, or live
     *                            lookups are disabled by configuration.
     */
    public function lookup(string $vin, ?int $modelYear = null): VehicleData
    {
        if (! $this->enabled) {
            throw VinLookupException::lookupDisabled();
        }

        $vin = $this->normalize($vin);

        if (! $this->isValid($vin)) {
            throw VinLookupException::invalidVin($vin);
        }

        // Version + driver + VIN + model-year hint all participate so different
        // versions, drivers or hints never collide, and a version bump bypasses
        // every prior decode without a store-wide flush.
        $cacheKey = sprintf('vin:v%d:%s:%s:%s', $this->cacheVersion, $this->driver, $vin, $modelYear ?? 'auto');

        $cache = Cache::store($this->cacheStore);

        // Read through the cache by hand rather than Cache::remember so a stale
        // payload that no longer unserializes into a VehicleData (e.g. the value
        // object gained a property since it was cached) is re-decoded instead of
        // surfacing as a fatal type error.
        $cached = $cache->get($cacheKey);

        if ($cached instanceof VehicleData) {
            return $cached;
        }

        $vehicle = $this->decoder->decode($vin, $modelYear);

        $cache->put($cacheKey, $vehicle, $this->cacheTtl);

        return $vehicle;
    }

    /**
     * Decode a VIN, returning null instead of throwing on any failure.
     */
    public function tryLookup(string $vin, ?int $modelYear = null): ?VehicleData
    {
        try {
            return $this->lookup($vin, $modelYear);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the given string is structurally a valid VIN.
     */
    public function isValid(string $vin): bool
    {
        return (bool) preg_match(self::VIN_PATTERN, $this->normalize($vin));
    }

    private function normalize(string $vin): string
    {
        return strtoupper(trim($vin));
    }
}
