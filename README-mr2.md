# Laravel VIN

![Laravel VIN — powerful VIN lookup for Laravel](art/Laravel%20Vin%20Header.png)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/alwayscurious/laravel-vin.svg?style=flat-square)](https://packagist.org/packages/alwayscurious/laravel-vin)
[![Tests](https://github.com/alwayscurious/laravel-vin/actions/workflows/tests.yml/badge.svg)](https://github.com/alwayscurious/laravel-vin/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

Look up a VIN in Laravel and get the vehicle's year, make, model, trim, body class, and more. The default provider is NHTSA's free [vPIC database](https://vpic.nhtsa.dot.gov/api/), so there's no account to create, API key to manage, or per-lookup fee.

```bash
composer require alwayscurious/laravel-vin
```

```php
use AlwaysCurious\Vin\Facades\Vin;

$vehicle = Vin::lookup('JT2SW21M0M0012345');

$vehicle->year;      // 1991
$vehicle->make;      // 'TOYOTA'
$vehicle->model;     // 'MR2'
$vehicle->bodyClass; // 'Coupe'
```

> **Coverage:** NHTSA's data covers vehicles made for the US market. For other markets, you can [use a different VIN provider](#using-a-different-vin-provider).

## Why Laravel VIN

### Easy to get started

Install the package and call `Vin::lookup()`. Results are cached, so repeated lookups don't need another network request. If you need engine, safety, body, or plant details, set `VIN_ATTRIBUTES`.

### Validation, errors, and control

- Validate VINs before making a request. The form rule can check the ISO 3779 check digit, and `Vin::inspect()` explains why a VIN failed validation.
- Handle failures with a single `catch`. Each `VinLookupException` has a typed reason, so your app can respond differently to invalid input and a provider outage.
- Decode a batch in one request, retry temporary failures, turn off live decoding, or invalidate cached results through configuration.
- Listen for decode events in your own logs or metrics, including failures caught by `tryLookup()`.

### Fits into a Laravel app

- NHTSA is the default provider. Register another one with `Vin::extend()` and keep the same validation, caching, and enabled setting.
- Use `Vin::fake()` to return known vehicles in tests and assert which VINs were looked up.
- Map decoded fields to your Eloquent columns with `toColumns()`.
- The public API follows [Semantic Versioning](#versioning).

## Requirements

- PHP `^8.3`
- Laravel 11, 12 or 13 (`illuminate/*` `^11.0|^12.0|^13.0`)

## Installation

```bash
composer require alwayscurious/laravel-vin
```

Laravel discovers the service provider automatically.

You can use the defaults and configure the package through environment variables. If you want a config file, publish it with:

```bash
php artisan vendor:publish --tag=vin-config
```

### Configuration

Each setting has an environment variable:

| Env var             | Config key                     | Default                            | Purpose                                                          |
| ------------------- | ------------------------------ | ---------------------------------- | --------------------------------------------------------------- |
| `VIN_DRIVER`        | `vin.driver`                   | `nhtsa`                            | Decoder to use for lookups.                                      |
| `VIN_BASE_URL`      | `vin.decoders.nhtsa.base_url`  | `https://vpic.nhtsa.dot.gov/api`   | NHTSA vPIC API base URL.                                         |
| `VIN_TIMEOUT`       | `vin.decoders.nhtsa.timeout`   | `10`                               | HTTP timeout (seconds) per decode request.                      |
| `VIN_ATTRIBUTES`    | `vin.decoders.nhtsa.attributes`| `identity`                         | Which fields to keep: `identity`, `typed`, or `full` (see below).|
| `VIN_RETRY_TIMES`   | `vin.decoders.nhtsa.retry.times`| `2`                               | Max NHTSA request attempts (`1` disables retrying).             |
| `VIN_RETRY_SLEEP`   | `vin.decoders.nhtsa.retry.sleep`| `200`                             | Milliseconds between NHTSA retry attempts.                      |
| `VIN_CACHE_STORE`   | `vin.cache.store`              | *(app default)*                    | Cache store for decodes (blank = the app's default store).      |
| `VIN_CACHE_TTL`     | `vin.cache.ttl`                | `86400`                            | How long (seconds) a decoded VIN stays cached.                  |
| `VIN_CACHE_VERSION` | `vin.cache.version`            | `1`                                | Bump to invalidate every cached decode at once.                 |
| `VIN_ENABLED`       | `vin.enabled`                  | `true`                             | Enable or disable live decoding.                                |

## Usage

Use the `Vin` facade, which Laravel registers automatically:

```php
use AlwaysCurious\Vin\Facades\Vin;

// Throws VinLookupException on an invalid VIN, an API failure, or when
// live decoding is disabled by configuration.
$vehicle = Vin::lookup('JT2SW21M0M0012345');

// Optional model-year hint to improve decoding accuracy:
$vehicle = Vin::lookup('JT2SW21M0M0012345', 1991);
```

If you prefer dependency injection, type-hint or resolve `VinLookupService`. It uses the default driver and exposes `lookup()`, `tryLookup()`, and `isValid()`:

```php
use AlwaysCurious\Vin\VinLookupService;

public function __construct(private readonly VinLookupService $vin) {}

// ...
$vehicle = $this->vin->lookup('JT2SW21M0M0012345');
```

### `tryLookup()`

Returns `null` instead of throwing on any failure:

```php
$vehicle = Vin::tryLookup('JT2SW21M0M0012345');

if ($vehicle !== null) {
    // ...
}
```

### `isValid()`

Check a VIN's structure (17 characters, excluding I, O, and Q) without a network request:

```php
Vin::isValid('jt2sw21m0m0012345'); // true — input is normalized first
Vin::isValid('NOT-A-VIN');         // false
```

### Validate form input with the `Vin` rule

Use the `Rules\Vin` rule to validate a form field. By default, it checks the VIN's structure, just like `isValid()`. Add `withCheckDigit()` to check the ISO 3779 ninth-character check digit and catch some typing errors before calling the provider:

```php
use AlwaysCurious\Vin\Rules\Vin as VinRule;

$request->validate([
    'vin' => ['required', new VinRule],                 // structural
    // 'vin' => ['required', (new VinRule)->withCheckDigit()], // + check digit
]);
```

`isValid()` checks structure only because some decodable VINs don't follow the check-digit rule. For the stricter check without a network request, use `Vin::hasValidCheckDigit('…')`.

### `inspect()` — find out why a VIN failed validation

`Vin::inspect()` runs the structural and check-digit checks without a network request. It returns a `VinValidation` with an overall result and a specific reason for each failure:

```php
$result = Vin::inspect('JT2SW21M0M0012345');

$result->valid;             // true  — structurally valid AND correct check digit
$result->structurallyValid; // true  — always equals Vin::isValid()
$result->checkDigitValid;   // true
$result->errors;            // []

// A structurally valid VIN with a mistyped check digit:
$bad = Vin::inspect('JT2SW21M1M0012345');
$bad->fails();              // true
$bad->messages();           // ['The VIN check digit (9th character) does not match; the VIN may be mistyped.']

// Each failure mode is distinguishable:
Vin::inspect('JT2SW21MOM0012345')->errors; // [VinValidationError::IllegalCharacters]  (letter O for zero)
Vin::inspect('JT2SW21M0M001234')->errors;  // [VinValidationError::WrongLength]         (not 17 chars)
```

`$result->valid` requires both checks to pass. `$result->structurallyValid` matches the less strict `isValid()` check used during decoding. `toArray()` and JSON encoding produce a flat result with `vin`, `valid`, `structurally_valid`, `check_digit_valid`, and `errors`.

### `lookupMany()` — decode a batch

`lookupMany()` sends uncached VINs to NHTSA's `DecodeVinValuesBatch` endpoint in one request. It returns `VehicleData` values keyed by normalized VIN, in input order:

```php
$vehicles = Vin::lookupMany(['JT2SW21M0M0012345', '1HGCM82633A004352']);

$vehicles['JT2SW21M0M0012345']->make; // 'TOYOTA'
```

The enabled setting and cache apply to the batch. An invalid VIN throws before any request, so check uncertain input with `isValid()` first. If a custom driver doesn't support batching, `lookupMany()` calls `lookup()` for each VIN instead.

### Handle lookup failures

`VinLookupException` includes a `VinFailureReason` in `->reason`. You can use it to choose a response in one `catch`:

```php
use AlwaysCurious\Vin\VinFailureReason;
use AlwaysCurious\Vin\VinLookupException;

try {
    $vehicle = Vin::lookup($request->input('vin'));
} catch (VinLookupException $e) {
    return match ($e->reason) {
        VinFailureReason::InvalidVin        => back()->withErrors(['vin' => 'That isn’t a valid VIN.']),
        VinFailureReason::Disabled          => response('VIN decoding is temporarily disabled.', 503),
        default                             => $e->reason->isTransient()
            ? response('The VIN service is unavailable, try again shortly.', 503)
            : throw $e,
    };
}
```

### Decode events

The package dispatches `Events\VinDecoded` for successful lookups, with a `fromCache` flag. It dispatches `Events\VinDecodeFailed` for failures, with the `VinFailureReason` and exception. Failure events still fire when `tryLookup()` catches the error. You can listen for them in your own logging or metrics:

```php
use AlwaysCurious\Vin\Events\VinDecoded;
use Illuminate\Support\Facades\Event;

Event::listen(function (VinDecoded $event) {
    Telemetry::record('vin.decoded', [
        'vin' => $event->vehicle->vin,
        'driver' => $event->driver,
        'cached' => $event->fromCache,
    ]);
});
```

### The `VehicleData` value object

`lookup()` and `tryLookup()` return an immutable `VehicleData`. With the default `VIN_ATTRIBUTES=identity` setting, it contains these fields:

```php
$vehicle->vin;           // 'JT2SW21M0M0012345'
$vehicle->year;          // 1991 (int|null)
$vehicle->make;          // 'TOYOTA'
$vehicle->model;         // 'MR2'
$vehicle->trim;          // null (string|null) — not every VIN carries a trim
$vehicle->bodyClass;     // 'Coupe'
$vehicle->vehicleType;   // 'PASSENGER CAR'
$vehicle->manufacturer;  // 'TOYOTA MOTOR CORPORATION'
$vehicle->errorCode;     // 0 (int|null) — primary NHTSA decode status
$vehicle->errorText;     // string|null

$vehicle->series;        // string|null — hydrated from the 'typed' level up (see below)

$vehicle->decodedSuccessfully(); // true when NHTSA reports a clean decode (error code 0)
$vehicle->isFullyIdentified();   // true when year + make + model are all present

$vehicle->toArray();  // snake_cased array (identity + nested groups; see below)
json_encode($vehicle); // JsonSerializable — same shape as toArray()
```

For `series`, engine, safety, body, and plant details, or the raw NHTSA fields, change `VIN_ATTRIBUTES` (see [Choose which attributes to keep](#choose-which-attributes-to-keep)). The examples below need `typed` or `full`.

`decodedSuccessfully()` requires an NHTSA error code of `0`. A result can still have a year, make, and model when NHTSA reports a warning, such as a model-year mismatch. In that case, `isFullyIdentified()` is `true` and `decodedSuccessfully()` is `false`.

#### Fill a model with `only()` or `toColumns()`

To save a decode with `Model::fill()`, use `only()` to select fields by their property names or `toColumns()` to map them to your model's column names:

```php
$vehicle->only(['make', 'model', 'year', 'bodyClass']);
// ['make' => 'TOYOTA', 'model' => 'MR2', 'year' => 1991, 'bodyClass' => 'Coupe']

$vehicle->toColumns(['year' => 'model_year', 'make' => 'make', 'bodyClass' => 'body_class']);
// ['model_year' => 1991, 'make' => 'TOYOTA', 'body_class' => 'Coupe']

$car->fill($vehicle->toColumns([...]));
```

Both methods throw if you name an unknown field. They work with the flat identity fields; your app decides how to merge the values into an existing row.

### Extended attributes: engine, safety, body, and plant

NHTSA's `DecodeVinValues` response includes more than the identity fields. Four typed groups hold the commonly used specs. Each group is always present, but an individual field is `null` when NHTSA has no value for it. Missing numbers, such as doors or horsepower, are `null` rather than `0`:

```php
$vehicle->engine->fuelTypePrimary;      // 'Gasoline'
$vehicle->engine->horsepower;           // int|null   e.g. 130
$vehicle->engine->displacementL;        // float|null e.g. 2.2
$vehicle->engine->cylinders;            // int|null   e.g. 4
$vehicle->engine->model;                // '5S'
$vehicle->engine->electrificationLevel; // null — set for hybrids/EVs, e.g. 'BEV (Battery Electric Vehicle)'

$vehicle->body->doors;                  // int|null   e.g. 2
$vehicle->body->seats;                  // int|null
$vehicle->body->gvwr;                   // 'Class 1: 6,000 lb or less (2,722 kg or less)'

$vehicle->safety->airbagFront;          // 'Driver Seat Only'
$vehicle->safety->seatbelts;            // 'Manual'
$vehicle->safety->rearVisibilitySystem; // null — NHTSA's backup-camera field

$vehicle->plant->country;               // 'JAPAN'
```

Each group implements `Arrayable` and `JsonSerializable`. `VehicleData::toArray()` and JSON encoding include them under `engine`, `safety`, `body`, and `plant`.

### Raw NHTSA attributes

With `VIN_ATTRIBUTES=full`, you can also access fields that aren't in the typed groups, such as `OtherEngineInfo`, `NCSABodyType`, and `Note`. The non-empty response fields keep their original NHTSA names:

```php
$vehicle->attribute('OtherEngineInfo');         // 'Electronic Fuel Injection'
$vehicle->attribute('NoSuchField');             // null
$vehicle->attribute('NoSuchField', 'unknown');  // 'unknown' — optional default

$vehicle->attributes; // ['Make' => 'TOYOTA', 'EngineHP' => '130', ...] full non-empty row
```

Raw values are trimmed strings. Use the typed groups if you need `int` or `float` values. The raw fields are available through `->attributes` and `attribute()`; `toArray()` and `json_encode()` do not include them.

### Choose which attributes to keep

The default keeps cached results small. Typed groups and raw attributes take more work to build and more space to cache. Set `VIN_ATTRIBUTES` (`vin.decoders.nhtsa.attributes`) to the level you need:

| `VIN_ATTRIBUTES`     | Core identity | `series` | Typed groups | Raw `attributes` | Use when… |
| -------------------- | :-----------: | :------: | :----------: | :--------------: | --------- |
| `identity` (default) | ✓             |          |              |                  | You only need the core vehicle fields. Uses the least cache space. |
| `typed`              | ✓             | ✓        | ✓            |                  | You also need `series` or typed engine, safety, body, and plant fields. |
| `full`               | ✓             | ✓        | ✓            | ✓                | You also need NHTSA fields outside the typed groups. |

Core identity includes year, make, model, trim, body class, vehicle type, and manufacturer. VIN and decode status are always present. The groups also remain present at every level; lower levels leave their fields `null` and `->attributes` empty. You can safely read `$vehicle->engine->horsepower` at any level.

> If you change the level, bump `VIN_CACHE_VERSION` so cached VINs are decoded again with the new setting.

### Invalidating cached decodes

Results are cached for `VIN_CACHE_TTL` seconds. If NHTSA updates its data or you change what the package stores, bump `VIN_CACHE_VERSION`. The version is part of each cache key, so new lookups skip the old entries without flushing the rest of your cache.

### Disabling live decoding

Set `VIN_ENABLED=false` to turn off live decoding. `lookup()` then throws a `VinLookupException`, and `tryLookup()` returns `null`. Neither calls the API.

## Using a different VIN provider

NHTSA is the default driver. You can register another provider and select it by name, much like you would with `Cache::extend()` or `Mail::extend()`.

A driver implements `Contracts\VinDecoder` to look up a VIN and map the response to `VehicleData`. The package passes it a trimmed, uppercase, structurally valid VIN. The package also handles validation, the enabled setting, and caching for the driver.

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

Register the driver in a service provider's `register()` or `boot()` method. The closure receives the container, so it can read config or resolve other services:

```php
use AlwaysCurious\Vin\Facades\Vin;
use App\Vin\AcmeVinDecoder;

Vin::extend('acme', fn ($app) => new AcmeVinDecoder($app['config']['services.acme.key']));
```

To use it by default, set:

```dotenv
VIN_DRIVER=acme
```

You can also use it for one call while keeping NHTSA as the default:

```php
$vehicle = Vin::using('acme')->lookup('JT2SW21M0M0012345');
```

Every driver uses the package's structural validation, `VIN_ENABLED` setting, and cache. Cache keys include the driver name, so providers don't share decoded results. A driver's `decode()` method can also read from a fixed dataset, queue, or in-memory table instead of an HTTP API.

> **Driver config is read when the driver is first resolved.** If you change its settings, such as `vin.decoders.*`, at runtime, call `Vin::forgetDrivers()` to rebuild it. The enabled setting and cache version are read on each lookup, so changes to those take effect immediately.

## Testing

### Use `Vin::fake()`

Use `Vin::fake()` to test without calling NHTSA. Map a VIN to a `VehicleData` value, which you can build with `VehicleData::fake()`. Unmapped VINs return a generated fake. Validation, the enabled setting, and caching still apply, and the fake records lookups for assertions:

```php
use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\VehicleData;

$fake = Vin::fake([
    '1FTFW1E50NKF12345' => VehicleData::fake(make: 'Ford', model: 'F-150'),
]);

$vehicle = Vin::lookup('1FTFW1E50NKF12345'); // 'Ford' — no HTTP call

$fake->assertLookedUp('1FTFW1E50NKF12345');
$fake->assertLookedUpCount(1);
```

To test a failure, map a VIN to a `Throwable`, for example `Vin::fake(['…' => VinLookupException::requestFailed('…', 503)])`.

### Faking the HTTP layer directly

If you want to test the HTTP response handling, use Laravel's HTTP client to fake NHTSA:

```php
use AlwaysCurious\Vin\Facades\Vin;
use Illuminate\Support\Facades\Http;

Http::fake([
    'vpic.nhtsa.dot.gov/*' => Http::response([
        'Results' => [[
            'Make' => 'TOYOTA',
            'Model' => 'MR2',
            'ModelYear' => '1991',
            'ErrorCode' => '0',
        ]],
    ]),
]);

$vehicle = Vin::lookup('JT2SW21M0M0012345');
```

Run the package's tests and formatter with:

```bash
composer test   # vendor/bin/phpunit
composer lint   # vendor/bin/pint
```

## Versioning

This package follows [Semantic Versioning](https://semver.org). Since version 1.0.0, that covers the public API: the `Vin` facade, `Contracts\VinDecoder`, `VehicleData`, `VinLookupException`, and the `vin.*` config keys and environment variables. See the [CHANGELOG](CHANGELOG.md) for release details.

## License

Licensed under the MIT License. See [LICENSE](LICENSE).
