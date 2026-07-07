# Laravel VIN

[![Latest Version on Packagist](https://img.shields.io/packagist/v/alwayscurious/laravel-vin.svg?style=flat-square)](https://packagist.org/packages/alwayscurious/laravel-vin)
[![Tests](https://github.com/alwayscurious/laravel-vin/actions/workflows/tests.yml/badge.svg)](https://github.com/alwayscurious/laravel-vin/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

Decode a US vehicle VIN into year, make, model, series, trim, body class and
more via the [NHTSA vPIC API](https://vpic.nhtsa.dot.gov/api/). Decodes are
cached (a VIN's decode is immutable), with a version knob to invalidate every
cached decode at once and a master switch to disable live lookups entirely.

NHTSA is the default provider, but the decoder is a **driver** — register your own
with `Vin::extend()` and select it with `VIN_DRIVER`, exactly like Laravel's cache,
mail and filesystem drivers.

## Requirements

- PHP `^8.3`
- Laravel 11, 12 or 13 (`illuminate/*` `^11.0|^12.0|^13.0`)

## Installation

```bash
composer require alwayscurious/laravel-vin
```

The service provider is auto-discovered — no manual registration needed.

Publishing the config file is optional; the package ships with sane defaults
and reads everything from environment variables:

```bash
php artisan vendor:publish --tag=vin-config
```

### Configuration

Every setting is driven by an environment variable, so you rarely need to touch
the published config file:

| Env var             | Config key                     | Default                            | Purpose                                                          |
| ------------------- | ------------------------------ | ---------------------------------- | --------------------------------------------------------------- |
| `VIN_DRIVER`        | `vin.driver`                   | `nhtsa`                            | Which decoder driver lookups use.                               |
| `VIN_BASE_URL`      | `vin.decoders.nhtsa.base_url`  | `https://vpic.nhtsa.dot.gov/api`   | NHTSA vPIC API base URL.                                         |
| `VIN_TIMEOUT`       | `vin.decoders.nhtsa.timeout`   | `10`                               | HTTP timeout (seconds) per decode request.                      |
| `VIN_CACHE_STORE`   | `vin.cache.store`              | *(app default)*                    | Cache store for decodes (blank = the app's default store).      |
| `VIN_CACHE_TTL`     | `vin.cache.ttl`                | `86400`                            | How long (seconds) a decoded VIN stays cached.                  |
| `VIN_CACHE_VERSION` | `vin.cache.version`            | `1`                                | Bump to invalidate every cached decode at once.                 |
| `VIN_ENABLED`       | `vin.enabled`                  | `true`                             | Master switch for live decoding.                                |

## Usage

Use the `Vin` facade (auto-registered — no alias config needed):

```php
use AlwaysCurious\Vin\Facades\Vin;

// Throws VinLookupException on an invalid VIN, an API failure, or when
// live decoding is disabled by configuration.
$vehicle = Vin::lookup('7YAMYFS50TY009706');

// Optional model-year hint to improve decoding accuracy:
$vehicle = Vin::lookup('7YAMYFS50TY009706', 2026);
```

Prefer dependency injection? Type-hint or resolve `VinLookupService` — it is the
default driver's lookup service and exposes the same `lookup()` / `tryLookup()` /
`isValid()` methods:

```php
use AlwaysCurious\Vin\VinLookupService;

public function __construct(private readonly VinLookupService $vin) {}

// ...
$vehicle = $this->vin->lookup('7YAMYFS50TY009706');
```

### `tryLookup()`

Returns `null` instead of throwing on any failure:

```php
$vehicle = Vin::tryLookup('7YAMYFS50TY009706');

if ($vehicle !== null) {
    // ...
}
```

### `isValid()`

Structurally validate a VIN (17 characters, excluding I, O and Q) without
hitting the network:

```php
Vin::isValid('7yamyfs50ty009706'); // true — input is normalized first
Vin::isValid('NOT-A-VIN');         // false
```

### The `VehicleData` value object

`lookup()` / `tryLookup()` return an immutable `VehicleData`:

```php
$vehicle->vin;           // '7YAMYFS50TY009706'
$vehicle->year;          // 2026 (int|null)
$vehicle->make;          // 'HYUNDAI'
$vehicle->model;         // 'Ioniq 9'
$vehicle->series;        // string|null
$vehicle->trim;          // 'Calligraphy'
$vehicle->bodyClass;     // 'Sport Utility Vehicle (SUV)/Multi-Purpose Vehicle (MPV)'
$vehicle->manufacturer;  // 'HYUNDAI MOTOR GROUP METAPLANT AMERICA'
$vehicle->vehicleType;   // 'MULTIPURPOSE PASSENGER VEHICLE (MPV)'
$vehicle->errorCode;     // 0 (int|null) — primary NHTSA decode status
$vehicle->errorText;     // string|null

$vehicle->decodedSuccessfully(); // true when NHTSA reports a clean decode (error code 0)
$vehicle->isFullyIdentified();   // true when year + make + model are all present

$vehicle->toArray();  // snake_cased array
json_encode($vehicle); // JsonSerializable — same shape as toArray()
```

`decodedSuccessfully()` is stricter than `isFullyIdentified()`: NHTSA can return
a full year/make/model while still flagging a non-blocking warning (e.g. a model
year mismatch), in which case `isFullyIdentified()` is `true` but
`decodedSuccessfully()` is `false`.

### Invalidating cached decodes

A VIN's decode never changes, so results are cached for `VIN_CACHE_TTL` seconds.
If NHTSA corrects its data — or you change what the package stores — bump
`VIN_CACHE_VERSION`. The version is part of the cache key, so every previously
cached decode is bypassed at once without flushing your whole cache store.

### Disabling live decoding

Set `VIN_ENABLED=false` to turn off all network calls. `lookup()` then throws a
`VinLookupException` and `tryLookup()` returns `null` — neither hits the API.

## Using a different VIN provider

NHTSA is just the default **driver**. The package uses Laravel's Manager driver
system, so you register your own provider and select it by name — the same
ergonomics as `Cache::extend()` / `Mail::extend()`.

A driver is any class implementing `Contracts\VinDecoder`. It only performs the
lookup and maps the response; the VIN it receives is already normalized (uppercased,
trimmed) and structurally validated, and validation, the enabled gate and caching
are applied around it by the package — so a driver never reimplements them.

```php
namespace App\Vin;

use AlwaysCurious\Vin\Contracts\VinDecoder;
use AlwaysCurious\Vin\VehicleData;
use AlwaysCurious\Vin\VinLookupException;
use Illuminate\Support\Facades\Http;

class AcmeVinDecoder implements VinDecoder
{
    public function __construct(private readonly string $apiKey) {}

    public function decode(string $vin, ?int $modelYear = null): VehicleData
    {
        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->get("https://vin.acme.test/decode/{$vin}");

        if ($response->failed()) {
            throw VinLookupException::requestFailed($vin, $response->status());
        }

        // Map the provider's shape onto VehicleData however it returns data.
        return new VehicleData(
            vin: $vin,
            year: $response->json('year'),
            make: $response->json('make'),
            model: $response->json('model'),
            series: $response->json('series'),
            trim: $response->json('trim'),
            bodyClass: $response->json('body_class'),
            errorCode: 0,
            errorText: null,
        );
    }
}
```

Register it as a named driver in a service provider's `register()` (or `boot()`).
The closure receives the container, so you can pull in config or other services:

```php
use AlwaysCurious\Vin\Facades\Vin;
use App\Vin\AcmeVinDecoder;

Vin::extend('acme', fn ($app) => new AcmeVinDecoder($app['config']['services.acme.key']));
```

Then make it the default for the whole app:

```dotenv
VIN_DRIVER=acme
```

…or use it for a single call while NHTSA stays the default:

```php
$vehicle = Vin::using('acme')->lookup('7YAMYFS50TY009706');
```

Because the package wraps every driver, your provider **automatically inherits**
structural validation, the `VIN_ENABLED` master switch, and caching. Cache entries
are namespaced per driver, so two providers never serve each other's cached decode.
Prefer to decode from a fixed dataset, a queue, or an in-memory table? Same seam —
your `decode()` doesn't have to make an HTTP call at all.

> **Driver config is captured when the driver is first resolved.** If you change a
> driver's own settings (e.g. `vin.decoders.*`) at runtime, call
> `Vin::forgetDrivers()` to rebuild. The enabled gate and cache version are re-read
> on every lookup, so those take effect immediately.

## Testing

Because the package uses Laravel's HTTP client, you can fake the NHTSA API in
your own tests:

```php
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
```

Run the package's own suite with:

```bash
composer test   # vendor/bin/phpunit
composer lint   # vendor/bin/pint
```

## License

The MIT License (MIT). See [LICENSE](LICENSE).
