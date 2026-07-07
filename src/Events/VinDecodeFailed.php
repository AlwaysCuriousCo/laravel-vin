<?php

namespace AlwaysCurious\Vin\Events;

use AlwaysCurious\Vin\VinFailureReason;
use AlwaysCurious\Vin\VinLookupException;
use AlwaysCurious\Vin\VinLookupService;

/**
 * Dispatched by {@see VinLookupService} when a lookup fails for any reason —
 * invalid input, the disabled gate, a transport failure, or an unusable response. Carries the typed
 * {@see VinFailureReason} so listeners can filter (e.g. alert only on transient transport failures).
 * Fires even via `tryLookup()`, which swallows the exception but not the telemetry (VIN-022).
 */
class VinDecodeFailed
{
    public function __construct(
        public readonly string $vin,
        public readonly string $driver,
        public readonly VinFailureReason $reason,
        public readonly VinLookupException $exception,
        public readonly ?int $modelYear = null,
    ) {}
}
