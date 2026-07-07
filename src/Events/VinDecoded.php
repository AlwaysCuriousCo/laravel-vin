<?php

namespace AlwaysCurious\Vin\Events;

use AlwaysCurious\Vin\VehicleData;
use AlwaysCurious\Vin\VinLookupService;

/**
 * Dispatched by {@see VinLookupService} after a successful decode — whether the
 * value was freshly decoded or served from cache (see {@see $fromCache}). Lets a host record decodes
 * to its own telemetry without wrapping every call site (VIN-022).
 */
class VinDecoded
{
    public function __construct(
        public readonly VehicleData $vehicle,
        public readonly string $driver,
        public readonly ?int $modelYear = null,
        public readonly bool $fromCache = false,
    ) {}
}
