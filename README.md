# Laravel VIN

[![Latest Version on Packagist](https://img.shields.io/packagist/v/alwayscurious/laravel-vin.svg?style=flat-square)](https://packagist.org/packages/alwayscurious/laravel-vin)
[![Tests](https://github.com/alwayscurious/laravel-vin/actions/workflows/tests.yml/badge.svg)](https://github.com/alwayscurious/laravel-vin/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

Decode a US vehicle VIN into year, make, model, series, trim, body class and
more via the [NHTSA vPIC API](https://vpic.nhtsa.dot.gov/api/). Decodes are
cached (a VIN's decode is immutable), with a version knob to invalidate every
cached decode at once and a master switch to disable live lookups entirely.

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

| Env var             | Config key         | Default                            | Purpose                                                              |
| ------------------- | ------------------ | ---------------------------------- | ------------------------------------------------------------------- |
| `VIN_BASE_URL`      | `vin.base_url`     | `https://vpic.nhtsa.dot.gov/api`   | NHTSA vPIC API base URL.                                             |
| `VIN_TIMEOUT`       | `vin.timeout`      | `10`                               | HTTP timeout (seconds) per decode request.                          |
| `VIN_CACHE_TTL`     | `vin.cache_ttl`    | `86400`                            | How long (seconds) a decoded VIN stays cached.                      |
| `VIN_CACHE_VERSION` | `vin.cache_version`| `1`                                | Bump to invalidate every cached decode at once.                     |
| `VIN_ENABLED`       | `vin.enabled`      | `true`                             | Master switch for live decoding.                                    |

## Usage

Resolve `VinLookupService` from the container:

```php
use AlwaysCurious\Vin\VinLookupService;

$service = app(VinLookupService::class);

// Throws VinLookupException on an invalid VIN, an API failure, or when
// live decoding is disabled by configuration.
$vehicle = $service->lookup('7YAMYFS50TY009706');

// Optional model-year hint to improve decoding accuracy:
$vehicle = $service->lookup('7YAMYFS50TY009706', 2026);
```

### `tryLookup()`

Returns `null` instead of throwing on any failure:

```php
$vehicle = $service->tryLookup('7YAMYFS50TY009706');

if ($vehicle !== null) {
    // ...
}
```

### `isValid()`

Structurally validate a VIN (17 characters, excluding I, O and Q) without
hitting the network:

```php
$service->isValid('7yamyfs50ty009706'); // true — input is normalized first
$service->isValid('NOT-A-VIN');         // false
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

## Testing

Because the package uses Laravel's HTTP client, you can fake the NHTSA API in
your own tests:

```php
use AlwaysCurious\Vin\VinLookupService;
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

$vehicle = app(VinLookupService::class)->lookup('7YAMYFS50TY009706');
```

Run the package's own suite with:

```bash
composer test   # vendor/bin/phpunit
composer lint   # vendor/bin/pint
```

## License

The MIT License (MIT). See [LICENSE](LICENSE).
