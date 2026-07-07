<?php

namespace AlwaysCurious\Vin\Facades;

use AlwaysCurious\Vin\Testing\VinFake;
use AlwaysCurious\Vin\VehicleData;
use AlwaysCurious\Vin\VinManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \AlwaysCurious\Vin\VehicleData lookup(string $vin, ?int $modelYear = null)
 * @method static \AlwaysCurious\Vin\VehicleData|null tryLookup(string $vin, ?int $modelYear = null)
 * @method static array<string, \AlwaysCurious\Vin\VehicleData> lookupMany(array $vins, ?int $modelYear = null)
 * @method static bool isValid(string $vin)
 * @method static bool hasValidCheckDigit(string $vin)
 * @method static \AlwaysCurious\Vin\VinLookupService using(?string $driver = null)
 * @method static \AlwaysCurious\Vin\Contracts\VinDecoder driver(string|null $driver = null)
 * @method static \AlwaysCurious\Vin\VinManager extend(string $driver, \Closure $callback)
 * @method static string getDefaultDriver()
 * @method static \AlwaysCurious\Vin\VinManager forgetDrivers()
 *
 * @see VinManager
 */
class Vin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return VinManager::class;
    }

    /**
     * Swap the manager for a fake that routes every decode to preset {@see VehicleData}
     * (or a generated fake) with no network call, while still applying validation, the enabled gate
     * and caching, and recording lookups for assertion. The map is keyed by VIN; a value may be a
     * `VehicleData` or a `Throwable` to simulate a failure.
     *
     *     $fake = Vin::fake(['1FTFW1E50NKF12345' => VehicleData::fake(make: 'Ford')]);
     *     // ... exercise code under test ...
     *     $fake->assertLookedUp('1FTFW1E50NKF12345');
     *
     * @param  array<string, VehicleData|\Throwable>  $map
     */
    public static function fake(array $map = []): VinFake
    {
        $fake = new VinFake(static::getFacadeApplication(), $map);

        static::swap($fake);

        return $fake;
    }
}
