<?php

namespace AlwaysCurious\Vin\Contracts;

use AlwaysCurious\Vin\VehicleData;
use AlwaysCurious\Vin\VinLookupException;
use AlwaysCurious\Vin\VinLookupService;

/**
 * An optional capability a {@see VinDecoder} may add to decode many VINs in one provider round-trip
 * (e.g. NHTSA's `DecodeVinValuesBatch`). {@see VinLookupService::lookupMany()}
 * uses it when the active decoder implements it, and otherwise falls back to looping {@see
 * VinDecoder::decode()} — so a custom driver gains batching by opting in, and keeps working without
 * it. Every VIN passed here has already been normalized and structurally validated, exactly like
 * `decode()` (INV-2), and validation, the enabled gate and caching are still applied per VIN by the
 * lookup service (VIN-023).
 */
interface BatchVinDecoder extends VinDecoder
{
    /**
     * Decode a batch of normalized, structurally-valid VINs in a single call.
     *
     * @param  array<int, string>  $vins  Normalized VINs; the caller has de-duplicated and validated them.
     * @param  int|null  $modelYear  Optional model-year hint applied to every VIN in the batch.
     * @return array<string, VehicleData> Decoded vehicles keyed by VIN; every input VIN MUST be present.
     *
     * @throws VinLookupException on a provider/transport failure or unusable response.
     */
    public function decodeMany(array $vins, ?int $modelYear = null): array;
}
