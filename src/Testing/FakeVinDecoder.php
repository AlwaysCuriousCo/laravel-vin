<?php

namespace AlwaysCurious\Vin\Testing;

use AlwaysCurious\Vin\Contracts\VinDecoder;
use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\VehicleData;
use Throwable;

/**
 * A {@see VinDecoder} that returns preset {@see VehicleData} (or throws a preset {@see Throwable})
 * by VIN, with no network call. Installed by {@see Vin::fake()}; an
 * unmapped VIN yields a generated {@see VehicleData::fake()} so a test never needs to know the
 * driver's wire format. Because it plugs in at the decoder seam, a faked lookup still flows through
 * the real validation, enabled gate and caching (VIN-024).
 */
class FakeVinDecoder implements VinDecoder
{
    /** @var array<string, VehicleData|Throwable> */
    private array $map = [];

    /**
     * @param  array<string, VehicleData|Throwable>  $map  Keyed by VIN (normalized on the way in).
     */
    public function __construct(array $map = [])
    {
        foreach ($map as $vin => $value) {
            $this->map[strtoupper(trim((string) $vin))] = $value;
        }
    }

    public function decode(string $vin, ?int $modelYear = null): VehicleData
    {
        $preset = $this->map[$vin] ?? null;

        if ($preset instanceof Throwable) {
            throw $preset;
        }

        return $preset ?? VehicleData::fake(vin: $vin);
    }
}
