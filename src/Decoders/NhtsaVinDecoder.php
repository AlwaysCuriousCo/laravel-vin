<?php

namespace AlwaysCurious\Vin\Decoders;

use AlwaysCurious\Vin\Contracts\BatchVinDecoder;
use AlwaysCurious\Vin\Vehicle\AttributeLevel;
use AlwaysCurious\Vin\VehicleData;
use AlwaysCurious\Vin\VinLookupException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The default VIN decoder ("nhtsa" driver): NHTSA's vPIC "DecodeVinValues" endpoint.
 *
 * GET {base_url}/vehicles/decodevinvalues/{VIN}?format=json[&modelyear={year}]
 * returns a single flat result row at Results.0; anything else is treated as an
 * unexpected response. It also implements {@see BatchVinDecoder} via the sibling
 * POST {base_url}/vehicles/DecodeVinValuesBatch/ endpoint for one-round-trip bulk decodes.
 *
 * Configuration is passed in by VinManager (from vin.decoders.nhtsa.*); the decoder
 * itself reads no global config, so it is trivially constructable in isolation.
 *
 * @see https://vpic.nhtsa.dot.gov/api/
 */
class NhtsaVinDecoder implements BatchVinDecoder
{
    public const DEFAULT_BASE_URL = 'https://vpic.nhtsa.dot.gov/api';

    private readonly string $baseUrl;

    public function __construct(
        ?string $baseUrl = null,
        private readonly int $timeout = 10,
        private readonly AttributeLevel $attributes = AttributeLevel::Full,
        private readonly int $retryTimes = 2,
        private readonly int $retrySleep = 200,
    ) {
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
    }

    public function decode(string $vin, ?int $modelYear = null): VehicleData
    {
        $query = ['format' => 'json'];

        if ($modelYear !== null) {
            $query['modelyear'] = $modelYear;
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->retry($this->retryTimes, $this->retrySleep, throw: false)
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

        return VehicleData::fromFlatResult($vin, $result, $this->attributes);
    }

    /**
     * Decode a batch of VINs in one call via DecodeVinValuesBatch. The `data` payload is a
     * semicolon-separated list of VINs (each optionally suffixed `,{modelYear}`); the response
     * `Results` array is returned in submission order, so rows are paired to VINs by index.
     *
     * @param  array<int, string>  $vins
     * @return array<string, VehicleData>
     */
    public function decodeMany(array $vins, ?int $modelYear = null): array
    {
        $vins = array_values($vins);

        if ($vins === []) {
            return [];
        }

        $data = implode(';', array_map(
            fn (string $vin) => $modelYear !== null ? "{$vin},{$modelYear}" : $vin,
            $vins,
        ));

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->retry($this->retryTimes, $this->retrySleep, throw: false)
                ->acceptJson()
                ->asForm()
                ->post('/vehicles/DecodeVinValuesBatch/', ['format' => 'json', 'data' => $data]);
        } catch (ConnectionException $e) {
            throw VinLookupException::connectionFailed($vins[0], $e);
        }

        if ($response->failed()) {
            throw VinLookupException::requestFailed($vins[0], $response->status());
        }

        $results = $response->json('Results');

        if (! is_array($results) || count($results) !== count($vins)) {
            throw VinLookupException::unexpectedResponse($vins[0]);
        }

        $decoded = [];

        foreach ($vins as $i => $vin) {
            $row = $results[$i] ?? null;

            if (! is_array($row)) {
                throw VinLookupException::unexpectedResponse($vin);
            }

            $decoded[$vin] = VehicleData::fromFlatResult($vin, $row, $this->attributes);
        }

        return $decoded;
    }
}
