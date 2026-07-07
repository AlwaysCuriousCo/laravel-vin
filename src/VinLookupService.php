<?php

namespace AlwaysCurious\Vin;

use AlwaysCurious\Vin\Contracts\BatchVinDecoder;
use AlwaysCurious\Vin\Contracts\VinDecoder;
use AlwaysCurious\Vin\Events\VinDecoded;
use AlwaysCurious\Vin\Events\VinDecodeFailed;
use AlwaysCurious\Vin\Support\VinCheckDigit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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
     * Results are cached because a VIN's decode is immutable. On success a {@see VinDecoded} event
     * is dispatched (with a `fromCache` flag); on any failure a {@see VinDecodeFailed} carrying the
     * typed {@see VinFailureReason} is dispatched before the exception propagates.
     *
     * @param  int|null  $modelYear  Optional model year hint to improve decoding accuracy.
     *
     * @throws VinLookupException when the VIN is invalid, the decoder fails, or live
     *                            lookups are disabled by configuration.
     */
    public function lookup(string $vin, ?int $modelYear = null): VehicleData
    {
        try {
            [$vehicle, $fromCache] = $this->resolve($vin, $modelYear);
        } catch (VinLookupException $e) {
            Event::dispatch(new VinDecodeFailed($this->normalize($vin), $this->driver, $e->reason, $e, $modelYear));

            throw $e;
        }

        Event::dispatch(new VinDecoded($vehicle, $this->driver, $modelYear, $fromCache));

        return $vehicle;
    }

    /**
     * Decode a VIN, returning null instead of throwing on any failure. A {@see VinDecodeFailed}
     * event is still dispatched, so telemetry sees the failure even though the caller does not.
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
     * Decode many VINs at once, reusing the cache per VIN and decoding the misses in a single batch
     * call when the driver implements {@see BatchVinDecoder} (otherwise looping {@see
     * VinDecoder::decode()}). Returns decoded vehicles keyed by normalized VIN, in input order; a
     * VIN dropped by a partial provider failure is simply absent. The enabled gate applies to the
     * whole batch, and each VIN is normalized and structurally validated up front — a single invalid
     * VIN throws before any provider call (pre-filter with {@see isValid()} or {@see Rules\Vin} to
     * avoid it). A {@see VinDecoded} / {@see VinDecodeFailed} event is dispatched per VIN.
     *
     * @param  array<int, string>  $vins
     * @return array<string, VehicleData>
     *
     * @throws VinLookupException when the gate is disabled or any VIN is structurally invalid.
     */
    public function lookupMany(array $vins, ?int $modelYear = null): array
    {
        if (! $this->enabled) {
            throw VinLookupException::lookupDisabled();
        }

        // Normalize + validate every VIN up front (fail fast, before any provider call) and
        // de-duplicate while preserving first-seen order.
        $normalized = [];

        foreach ($vins as $vin) {
            $vin = $this->normalize($vin);

            if (! $this->matchesPattern($vin)) {
                throw VinLookupException::invalidVin($vin);
            }

            $normalized[$vin] = true;
        }

        $normalized = array_keys($normalized);

        $cache = Cache::store($this->cacheStore);

        $results = [];
        $fromCache = [];
        $misses = [];

        foreach ($normalized as $vin) {
            $cached = $cache->get($this->cacheKey($vin, $modelYear));

            if ($cached instanceof VehicleData) {
                $results[$vin] = $cached;
                $fromCache[$vin] = true;
            } else {
                $misses[] = $vin;
            }
        }

        if ($misses !== []) {
            try {
                $decoded = $this->decodeMany($misses, $modelYear);
            } catch (VinLookupException $e) {
                // A batch/transport failure fails every miss; give each a terminal event.
                foreach ($misses as $vin) {
                    Event::dispatch(new VinDecodeFailed($vin, $this->driver, $e->reason, $e, $modelYear));
                }

                throw $e;
            }

            foreach ($decoded as $vin => $vehicle) {
                $cache->put($this->cacheKey($vin, $modelYear), $vehicle, $this->cacheTtl);
                $results[$vin] = $vehicle;
                $fromCache[$vin] = false;
            }
        }

        // Re-key in input order and dispatch a success event per resolved VIN.
        $ordered = [];

        foreach ($normalized as $vin) {
            if (isset($results[$vin])) {
                $ordered[$vin] = $results[$vin];
                Event::dispatch(new VinDecoded($results[$vin], $this->driver, $modelYear, $fromCache[$vin]));
            }
        }

        return $ordered;
    }

    /**
     * Whether the given string is structurally a valid VIN (charset + length only). This does NOT
     * verify the check digit — use {@see hasValidCheckDigit()} for that.
     */
    public function isValid(string $vin): bool
    {
        return $this->matchesPattern($this->normalize($vin));
    }

    /**
     * Whether the VIN is structurally valid AND its ISO 3779 9th-position check digit is correct.
     * Opt-in and stricter than {@see isValid()}; performs no network call (VIN-020).
     */
    public function hasValidCheckDigit(string $vin): bool
    {
        $vin = $this->normalize($vin);

        return $this->matchesPattern($vin) && VinCheckDigit::matches($vin);
    }

    /**
     * Resolve a single VIN through the gate, validation and read-through cache.
     *
     * @return array{0: VehicleData, 1: bool} the decoded vehicle and whether it came from cache
     */
    private function resolve(string $vin, ?int $modelYear): array
    {
        if (! $this->enabled) {
            throw VinLookupException::lookupDisabled();
        }

        $vin = $this->normalize($vin);

        if (! $this->matchesPattern($vin)) {
            throw VinLookupException::invalidVin($vin);
        }

        $cache = Cache::store($this->cacheStore);
        $cacheKey = $this->cacheKey($vin, $modelYear);

        // Read through the cache by hand rather than Cache::remember so a stale payload that no
        // longer unserializes into a VehicleData (e.g. the value object gained a property since it
        // was cached) is re-decoded instead of surfacing as a fatal type error.
        $cached = $cache->get($cacheKey);

        if ($cached instanceof VehicleData) {
            return [$cached, true];
        }

        $vehicle = $this->decoder->decode($vin, $modelYear);

        $cache->put($cacheKey, $vehicle, $this->cacheTtl);

        return [$vehicle, false];
    }

    /**
     * Decode the cache-missed VINs via the driver's batch capability, or by looping decode().
     *
     * @param  array<int, string>  $vins
     * @return array<string, VehicleData>
     */
    private function decodeMany(array $vins, ?int $modelYear): array
    {
        if ($this->decoder instanceof BatchVinDecoder) {
            return $this->decoder->decodeMany($vins, $modelYear);
        }

        $decoded = [];

        foreach ($vins as $vin) {
            $decoded[$vin] = $this->decoder->decode($vin, $modelYear);
        }

        return $decoded;
    }

    /**
     * The cache key for a normalized VIN. Version + driver + VIN + model-year hint all participate
     * so different versions, drivers or hints never collide, and a version bump bypasses every prior
     * decode without a store-wide flush (INV-1).
     */
    private function cacheKey(string $normalizedVin, ?int $modelYear): string
    {
        return sprintf('vin:v%d:%s:%s:%s', $this->cacheVersion, $this->driver, $normalizedVin, $modelYear ?? 'auto');
    }

    private function matchesPattern(string $normalizedVin): bool
    {
        return (bool) preg_match(self::VIN_PATTERN, $normalizedVin);
    }

    private function normalize(string $vin): string
    {
        return strtoupper(trim($vin));
    }
}
