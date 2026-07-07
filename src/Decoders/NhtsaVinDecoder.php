<?php

namespace AlwaysCurious\Vin\Decoders;

use AlwaysCurious\Vin\Contracts\VinDecoder;
use AlwaysCurious\Vin\VehicleData;
use AlwaysCurious\Vin\VinLookupException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The default VIN decoder ("nhtsa" driver): NHTSA's vPIC "DecodeVinValues" endpoint.
 *
 * GET {base_url}/vehicles/decodevinvalues/{VIN}?format=json[&modelyear={year}]
 * returns a single flat result row at Results.0; anything else is treated as an
 * unexpected response.
 *
 * Configuration is passed in by VinManager (from vin.decoders.nhtsa.*); the decoder
 * itself reads no global config, so it is trivially constructable in isolation.
 *
 * @see https://vpic.nhtsa.dot.gov/api/
 */
class NhtsaVinDecoder implements VinDecoder
{
    public const DEFAULT_BASE_URL = 'https://vpic.nhtsa.dot.gov/api';

    private readonly string $baseUrl;

    public function __construct(?string $baseUrl = null, private readonly int $timeout = 10)
    {
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
