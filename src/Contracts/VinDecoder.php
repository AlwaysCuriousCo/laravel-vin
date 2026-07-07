<?php

namespace AlwaysCurious\Vin\Contracts;

use AlwaysCurious\Vin\Decoders\NhtsaVinDecoder;
use AlwaysCurious\Vin\VehicleData;
use AlwaysCurious\Vin\VinLookupException;
use AlwaysCurious\Vin\VinLookupService;

/**
 * A VIN decoder "driver" — the seam for plugging in a different VIN lookup provider.
 *
 * The package ships {@see NhtsaVinDecoder} as the default `nhtsa` driver. A host app
 * registers its own provider as a named driver on the manager and selects it via
 * `vin.driver` (or per call with `Vin::using($name)`):
 *
 *     Vin::extend('acme', fn ($app) => new AcmeVinDecoder($app['config']['services.acme.key']));
 *
 * Implementations only perform the provider lookup and map the response into a
 * VehicleData. The VIN they receive has already been normalized (uppercased,
 * trimmed) and validated, and {@see VinLookupService} owns the
 * enabled gate and caching — so a custom decoder inherits all three without
 * reimplementing them.
 */
interface VinDecoder
{
    /**
     * Decode a normalized, structurally-valid 17-character VIN into a VehicleData.
     *
     * @param  int|null  $modelYear  Optional model year hint to improve accuracy.
     *
     * @throws VinLookupException on a provider/transport failure or unusable response.
     */
    public function decode(string $vin, ?int $modelYear = null): VehicleData;
}
