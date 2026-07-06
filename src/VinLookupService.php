<?php

namespace AlwaysCurious\Vin;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Encapsulates the NHTSA vPIC API for decoding a VIN into vehicle attributes.
 *
 * @see https://vpic.nhtsa.dot.gov/api/
 */
class VinLookupService
{
    /**
     * VIN structure: 17 characters, excluding the letters I, O and Q.
     */
    private const VIN_PATTERN = '/^[A-HJ-NPR-Z0-9]{17}$/';

    private readonly string $baseUrl;

    private readonly int $timeout;

    private readonly int $cacheTtl;

    private readonly int $cacheVersion;

    private readonly bool $enabled;

    public function __construct(?string $baseUrl = null, ?int $timeout = null, ?int $cacheTtl = null, ?bool $enabled = null, ?int $cacheVersion = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('vin.base_url'), '/');
        $this->timeout = $timeout ?? (int) config('vin.timeout', 10);
        $this->cacheTtl = $cacheTtl ?? (int) config('vin.cache_ttl', 86400);
        $this->cacheVersion = $cacheVersion ?? (int) config('vin.cache_version', 1);
        $this->enabled = $enabled ?? (bool) config('vin.enabled', true);
    }

    /**
     * Decode a VIN into Year, Make, Model, Series, Trim and Body Class.
     *
     * Results are cached because a VIN's decode is immutable.
     *
     * @param  int|null  $modelYear  Optional model year hint to improve decoding accuracy.
     *
     * @throws VinLookupException when the VIN is invalid, the API fails, or live
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

        // The version segment lets the host app purge decoded VINs (a version
        // bump) without a store-wide cache flush.
        $cacheKey = sprintf('vin:v%d:%s:%s', $this->cacheVersion, $vin, $modelYear ?? 'auto');

        // Read through the cache by hand rather than Cache::remember so a stale
        // payload that no longer unserializes into a VehicleData (e.g. the value
        // object gained a property since it was cached) is re-decoded instead of
        // surfacing as a fatal type error.
        $cached = Cache::get($cacheKey);

        if ($cached instanceof VehicleData) {
            return $cached;
        }

        $vehicle = $this->decode($vin, $modelYear);

        Cache::put($cacheKey, $vehicle, $this->cacheTtl);

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

    /**
     * @throws VinLookupException
     */
    private function decode(string $vin, ?int $modelYear): VehicleData
    {
        $query = ['format' => 'json'];

        if ($modelYear !== null) {
            $query['modelyear'] = $modelYear;
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->retry(2, 200, throw: false)
                ->acceptJson()
                ->get("/vehicles/decodevinvalues/{$vin}", $query);
        } catch (ConnectionException $e) {
            throw VinLookupException::connectionFailed($vin, $e);
        }

        if ($response->failed()) {
            throw VinLookupException::requestFailed($vin, $response->status());
        }

        // The "DecodeVinValues" endpoint returns a single flat result row.
        $result = $response->json('Results.0');

        if (! is_array($result)) {
            throw VinLookupException::unexpectedResponse($vin);
        }

        return VehicleData::fromFlatResult($vin, $result);
    }
}
