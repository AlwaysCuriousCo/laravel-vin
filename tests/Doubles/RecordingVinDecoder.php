<?php

namespace AlwaysCurious\Vin\Tests\Doubles;

use AlwaysCurious\Vin\Contracts\VinDecoder;
use AlwaysCurious\Vin\VehicleData;

/**
 * A test double standing in for a consumer-registered VIN driver: it records how
 * often it was called and with what, and returns a canned VehicleData whose make and
 * model identify which double produced it (so cross-driver cache isolation is testable).
 */
class RecordingVinDecoder implements VinDecoder
{
    public int $calls = 0;

    public ?string $lastVin = null;

    public ?int $lastModelYear = null;

    public function __construct(
        private readonly string $make = 'ACME',
        private readonly string $model = 'Rocket',
    ) {}

    public function decode(string $vin, ?int $modelYear = null): VehicleData
    {
        $this->calls++;
        $this->lastVin = $vin;
        $this->lastModelYear = $modelYear;

        return VehicleData::fake(vin: $vin, make: $this->make, model: $this->model);
    }
}
