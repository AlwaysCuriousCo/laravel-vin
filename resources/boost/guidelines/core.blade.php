## Laravel VIN (alwayscurious/laravel-vin)

Decodes a 17-character US vehicle VIN into year, make, model, series, trim, body class,
manufacturer and vehicle type via the NHTSA vPIC API. Decodes are cached (a VIN's decode
is immutable) and the whole feature can be disabled via config.

### Calling the package

Use the `AlwaysCurious\Vin\Facades\Vin` facade (auto-aliased as `Vin`). For dependency
injection, type-hint `AlwaysCurious\Vin\VinLookupService` — it is the default driver's
lookup service and has the same `lookup` / `tryLookup` / `isValid` methods. Do not `new`
either; resolve through the container so config and the selected driver apply.

@verbatim
<code-snippet name="Decode a VIN" lang="php">
use AlwaysCurious\Vin\Facades\Vin;

// Throws AlwaysCurious\Vin\VinLookupException on an invalid VIN, an API failure,
// or when live decoding is disabled (config vin.enabled = false).
$vehicle = Vin::lookup('7YAMYFS50TY009706');

// Optional model-year hint improves decode accuracy:
$vehicle = Vin::lookup('7YAMYFS50TY009706', 2026);
</code-snippet>
@endverbatim

### Choosing the right method

- `Vin::lookup(string $vin, ?int $modelYear = null): VehicleData` — throws
  `VinLookupException` on any failure. Use when a failure should surface as an error.
- `Vin::tryLookup(string $vin, ?int $modelYear = null): ?VehicleData` — returns `null`
  instead of throwing. Use in display paths where a missing decode is acceptable.
- `Vin::isValid(string $vin): bool` — structural check only (17 chars, excludes I/O/Q).
  Input is normalized first; **no network call**. Use to validate form input before a lookup.
- `Vin::using(string $driver)` — a lookup service on a specific driver for one call, without
  changing the default (`Vin::using('acme')->lookup($vin)`).

@verbatim
<code-snippet name="Non-throwing lookup and validation" lang="php">
use AlwaysCurious\Vin\Facades\Vin;

if (! Vin::isValid($request->input('vin'))) {
    // 422 — not even structurally a VIN, no API call made
}

$vehicle = Vin::tryLookup($request->input('vin'));

if ($vehicle === null) {
    // invalid VIN, API failure, or decoding disabled — degrade gracefully
}
</code-snippet>
@endverbatim

### The VehicleData value object

`lookup()` / `tryLookup()` return an immutable `final readonly AlwaysCurious\Vin\VehicleData`.
It implements `Arrayable` and `JsonSerializable`, so `->toArray()` and `json_encode($vehicle)`
both produce a snake_cased array.

@verbatim
<code-snippet name="Reading a decoded vehicle" lang="php">
$vehicle->vin;          // '7YAMYFS50TY009706'
$vehicle->year;         // int|null
$vehicle->make;         // string|null  e.g. 'HYUNDAI'
$vehicle->model;        // string|null  e.g. 'Ioniq 9'
$vehicle->series;       // string|null
$vehicle->trim;         // string|null
$vehicle->bodyClass;    // string|null
$vehicle->manufacturer; // string|null
$vehicle->vehicleType;  // string|null
$vehicle->errorCode;    // int|null — primary NHTSA decode status (0 = clean)
$vehicle->errorText;    // string|null

// decodedSuccessfully() is stricter than isFullyIdentified(): NHTSA can return a full
// year/make/model while still flagging a non-blocking warning (e.g. model-year mismatch).
$vehicle->decodedSuccessfully(); // true only when errorCode === 0
$vehicle->isFullyIdentified();   // true when year + make + model are all present
</code-snippet>
@endverbatim

### Configuration

Everything is env-driven; publish the config only to change structure:
`php artisan vendor:publish --tag=vin-config`.

- `VIN_DRIVER` (`vin.driver`, default `nhtsa`) — which decoder driver lookups use.
- `VIN_ENABLED` (`vin.enabled`, default `true`) — master switch. When `false`, `lookup()`
  throws and `tryLookup()` returns `null`, and **neither hits the network**.
- `VIN_CACHE_STORE` (`vin.cache.store`, default the app's default store) — cache store for
  decodes.
- `VIN_CACHE_TTL` (`vin.cache.ttl`, default `86400`) — seconds a decode stays cached.
- `VIN_CACHE_VERSION` (`vin.cache.version`, default `1`) — bump to invalidate every cached
  decode at once (it is part of the cache key) without flushing the whole cache store.
- `VIN_TIMEOUT` (`vin.decoders.nhtsa.timeout`, default `10`) — NHTSA HTTP timeout, seconds.
- `VIN_BASE_URL` (`vin.decoders.nhtsa.base_url`) — NHTSA vPIC base URL; override to point at a mock.

### Using a different VIN provider (driver system)

NHTSA is the default driver. The package uses Laravel's Manager driver system, so register
your own provider with `Vin::extend()` and select it with `VIN_DRIVER` (or per call with
`Vin::using($name)`). A driver implements `AlwaysCurious\Vin\Contracts\VinDecoder` and only
does the lookup + mapping — **do not** subclass or bypass the lookup service, or you lose
validation, the gate and caching. The `$vin` passed to `decode()` is already normalized and
validated.

@verbatim
<code-snippet name="Register and select a custom VIN driver" lang="php">
use AlwaysCurious\Vin\Contracts\VinDecoder;
use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\VehicleData;

class AcmeVinDecoder implements VinDecoder
{
    public function decode(string $vin, ?int $modelYear = null): VehicleData
    {
        // Call your provider, then map its response onto VehicleData.
        return new VehicleData(vin: $vin, year: 2026, make: 'ACME', model: 'Rocket',
            series: null, trim: null, bodyClass: null, errorCode: 0, errorText: null);
    }
}

// In a service provider's register(): the closure receives the container.
Vin::extend('acme', fn ($app) => new AcmeVinDecoder);

// Then set VIN_DRIVER=acme to make it the default, or per call:
$vehicle = Vin::using('acme')->lookup('7YAMYFS50TY009706');
</code-snippet>
@endverbatim

A custom driver inherits validation, the enabled gate and caching for free, and its cache
entries are namespaced per driver. Register drivers on the manager via `Vin::extend()`; never
bind `VinDecoder` directly into the container — that is not how the driver is resolved.

### Testing against this package

The package uses Laravel's HTTP client, so fake the NHTSA host in your own tests — never
let a test hit the live API.

@verbatim
<code-snippet name="Fake the NHTSA API in a test" lang="php">
use AlwaysCurious\Vin\Facades\Vin;
use Illuminate\Support\Facades\Http;

Http::fake([
    'vpic.nhtsa.dot.gov/*' => Http::response([
        'Results' => [[
            'Make' => 'HYUNDAI',
            'Model' => 'Ioniq 9',
            'ModelYear' => '2026',
            'ErrorCode' => '0',
        ]],
    ]),
]);

$vehicle = Vin::lookup('7YAMYFS50TY009706');
</code-snippet>
@endverbatim
