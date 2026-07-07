<?php

namespace AlwaysCurious\Vin\Facades;

use AlwaysCurious\Vin\VinManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \AlwaysCurious\Vin\VehicleData lookup(string $vin, ?int $modelYear = null)
 * @method static \AlwaysCurious\Vin\VehicleData|null tryLookup(string $vin, ?int $modelYear = null)
 * @method static bool isValid(string $vin)
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
}
