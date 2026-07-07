<?php

namespace AlwaysCurious\Vin\Tests\Doubles;

use AlwaysCurious\Vin\Contracts\BatchVinDecoder;
use AlwaysCurious\Vin\VehicleData;

/**
 * A batch-capable test double: it records how many single vs. batch decodes it served and the
 * VINs of the last batch, so tests can prove lookupMany() prefers decodeMany() and only batches
 * cache misses.
 */
class RecordingBatchVinDecoder implements BatchVinDecoder
{
    public int $decodeCalls = 0;

    public int $decodeManyCalls = 0;

    /** @var array<int, string> */
    public array $lastBatch = [];

    public function __construct(private readonly string $make = 'ACME') {}

    public function decode(string $vin, ?int $modelYear = null): VehicleData
    {
        $this->decodeCalls++;

        return VehicleData::fake(vin: $vin, make: $this->make);
    }

    public function decodeMany(array $vins, ?int $modelYear = null): array
    {
        $this->decodeManyCalls++;
        $this->lastBatch = array_values($vins);

        $decoded = [];

        foreach ($vins as $vin) {
            $decoded[$vin] = VehicleData::fake(vin: $vin, make: $this->make);
        }

        return $decoded;
    }
}
