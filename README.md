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
| `VIN_ATTRIBUTES`    | `vin.decoders.nhtsa.attributes`| `identity`                         | How much to hydrate: `identity`, `typed`, or `full` (see below).|
| `VIN_RETRY_TIMES`   | `vin.decoders.nhtsa.retry.times`| `2`                               | Max NHTSA request attempts (`1` disables retrying).             |
| `VIN_RETRY_SLEEP`   | `vin.decoders.nhtsa.retry.sleep`| `200`                             | Milliseconds between NHTSA retry attempts.                      |
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

### Validating form input — the `Vin` rule and check digit

Validate a VIN field with the `Rules\Vin` rule object. By default it checks structure only (the same
as `isValid()`); call `withCheckDigit()` to also verify the ISO 3779 9th-position check digit and
catch a transposed/mistyped VIN *before* spending a decode call:

```php
use AlwaysCurious\Vin\Rules\Vin as VinRule;

$request->validate([
    'vin' => ['required', new VinRule],                 // structural
    // 'vin' => ['required', (new VinRule)->withCheckDigit()], // + check digit
]);
```

`isValid()` stays structural-only on purpose — some real, decodable VINs don't honor the check digit.
When you want the stricter gate without the network, use `Vin::hasValidCheckDigit('…')`.

### `lookupMany()` — decode a batch in one request

Importing a fleet? `lookupMany()` decodes many VINs in a single provider round-trip (NHTSA's
`DecodeVinValuesBatch`), reusing the per-VIN cache and only decoding the misses. It returns
`VehicleData` keyed by normalized VIN, in input order:

```php
$vehicles = Vin::lookupMany(['7YAMYFS50TY009706', '1HGCM82633A004352']);

$vehicles['7YAMYFS50TY009706']->make; // 'HYUNDAI'
```

The enabled gate and caching apply to the whole batch; a structurally invalid VIN throws before any
request (pre-filter with `isValid()` if your input may be dirty). A custom driver that doesn't
implement batching still works — `lookupMany()` transparently falls back to looping `lookup()`.

### Knowing *why* a lookup failed

`VinLookupException` carries a typed `->reason` (`VinFailureReason`), so a single `catch` can render
the right message instead of pairing `isValid()` with `tryLookup()`:

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

The package dispatches `Events\VinDecoded` on every successful lookup (with a `fromCache` flag) and
`Events\VinDecodeFailed` on every failure (with the `VinFailureReason` and the exception) — the latter
fires even when `tryLookup()` swallows the error. Wire them to your own telemetry:

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

`lookup()` / `tryLookup()` return an immutable `VehicleData`. **By default** (`VIN_ATTRIBUTES=identity`)
it carries the clean core set that covers ~80% of use cases:

```php
$vehicle->vin;           // '7YAMYFS50TY009706'
$vehicle->year;          // 2026 (int|null)
$vehicle->make;          // 'HYUNDAI'
$vehicle->model;         // 'Ioniq 9'
$vehicle->trim;          // 'Calligraphy'
$vehicle->bodyClass;     // 'Sport Utility Vehicle (SUV)/Multi-Purpose Vehicle (MPV)'
$vehicle->vehicleType;   // 'MULTIPURPOSE PASSENGER VEHICLE (MPV)'
$vehicle->manufacturer;  // 'HYUNDAI MOTOR GROUP METAPLANT AMERICA'
$vehicle->errorCode;     // 0 (int|null) — primary NHTSA decode status
$vehicle->errorText;     // string|null

$vehicle->series;        // string|null — hydrated from the 'typed' level up (see below)

$vehicle->decodedSuccessfully(); // true when NHTSA reports a clean decode (error code 0)
$vehicle->isFullyIdentified();   // true when year + make + model are all present

$vehicle->toArray();  // snake_cased array (identity + nested groups; see below)
json_encode($vehicle); // JsonSerializable — same shape as toArray()
```

Want engine/safety/body/plant specs, `series`, or the raw NHTSA fields too? Raise the level with
`VIN_ATTRIBUTES` (see [Trim the response](#only-need-yearmakemodel-trim-the-response)) — everything
below this line needs `typed` or `full`.

`decodedSuccessfully()` is stricter than `isFullyIdentified()`: NHTSA can return
a full year/make/model while still flagging a non-blocking warning (e.g. a model
year mismatch), in which case `isFullyIdentified()` is `true` but
`decodedSuccessfully()` is `false`.

#### Filling a model — `only()` / `toColumns()`

To persist a decode, project the identity fields into a `Model::fill()`-ready array. `only()` keeps
the property names; `toColumns()` re-keys them onto your column names:

```php
$vehicle->only(['make', 'model', 'year', 'trim']);
// ['make' => 'HYUNDAI', 'model' => 'Ioniq 9', 'year' => 2026, 'trim' => 'Calligraphy']

$vehicle->toColumns(['year' => 'model_year', 'make' => 'make', 'bodyClass' => 'body_class']);
// ['model_year' => 2026, 'make' => 'HYUNDAI', 'body_class' => 'Sport Utility Vehicle (SUV)/...']

$car->fill($vehicle->toColumns([...]));
```

Both throw on an unknown field (so a typo surfaces), and both project only the flat identity fields —
your app keeps ownership of *which* columns win when merging into an existing row.

### Extended attributes — engine, safety, body, plant

`DecodeVinValues` returns far more than identity. Beyond the fields above, four typed,
always-present groups carry the commonly-used specs. Each field is `null` when NHTSA has no
value for it — a missing numeric field (doors, horsepower, …) is `null`, never `0`:

```php
$vehicle->engine->fuelTypePrimary;      // 'Electric'
$vehicle->engine->horsepower;           // int|null   e.g. 422
$vehicle->engine->displacementL;        // float|null e.g. 5.0
$vehicle->engine->driveType;            // 'AWD'
$vehicle->engine->transmissionStyle;    // 'Automatic'
$vehicle->engine->electrificationLevel; // 'BEV (Battery Electric Vehicle)'

$vehicle->body->doors;                  // int|null   e.g. 4
$vehicle->body->seats;                  // int|null
$vehicle->body->gvwr;                   // 'Class 2E: 6,001 - 7,000 lb ...'

$vehicle->safety->airbagCurtain;        // 'All Rows'
$vehicle->safety->rearVisibilitySystem; // 'Standard' — NHTSA's backup-camera field
$vehicle->safety->electronicStabilityControl; // 'Standard'

$vehicle->plant->city;                  // 'ELLABELL'
$vehicle->plant->country;               // 'UNITED STATES (USA)'
```

Each group is itself `Arrayable` + `JsonSerializable`. `toArray()` / `json_encode()` on the
`VehicleData` nest them under `engine`, `safety`, `body` and `plant`.

### Raw attribute passthrough

For anything NHTSA returns that the typed groups don't surface (e.g. `DestinationMarket`,
`NCSABodyType`, `Note`), the complete non-empty response row is kept verbatim, keyed by the
original NHTSA field name:

```php
$vehicle->attribute('DestinationMarket');       // 'North America'
$vehicle->attribute('NoSuchField');             // null
$vehicle->attribute('NoSuchField', 'unknown');  // 'unknown' — optional default

$vehicle->attributes; // ['Make' => 'HYUNDAI', 'EngineHP' => '422', ...] full non-empty row
```

Raw values are strings exactly as NHTSA sent them (trimmed); use the typed groups above when
you want real `int` / `float` types. The raw bag is **not** embedded in `toArray()` /
`json_encode()` — it's reachable only via `->attributes` and `attribute()`, so your serialized
payloads stay curated and stable.

### Only need year/make/model? Trim the response

The default is deliberately lean. Building the typed groups — and especially keeping the full raw
passthrough — costs a little CPU per decode and, more importantly, makes each **cached** row
larger. Step up with `VIN_ATTRIBUTES` (`vin.decoders.nhtsa.attributes`) only when you need more:

| `VIN_ATTRIBUTES`     | Core identity | `series` | Typed groups | Raw `attributes` | Use when… |
| -------------------- | :-----------: | :------: | :----------: | :--------------: | --------- |
| `identity` (default) | ✓             |          |              |                  | You read year/make/model/trim/body class/vehicle type/manufacturer. Smallest cache, least work. |
| `typed`              | ✓             | ✓        | ✓            |                  | You also want `series` and engine/safety/body/plant typed, but never the long tail. |
| `full`               | ✓             | ✓        | ✓            | ✓                | You want everything, including fields not lifted into a typed group. |

Core identity = year, make, model, trim, body class, vehicle type, manufacturer (plus VIN and
decode status, which are always present). At any level the groups are still present (never null) —
lighter levels just leave their fields `null` and keep `->attributes` empty, so
`$vehicle->engine->horsepower` is always safe to read.

> Because the level changes what's stored, bump `VIN_CACHE_VERSION` when you change it so already
> cached VINs are re-decoded at the new level (see below).

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

### `Vin::fake()` — the easy way

Swap the decoder for a fake so your tests never touch the network or the NHTSA wire format. Map a VIN
to a `VehicleData` (build one with `VehicleData::fake()`); unmapped VINs return a generated fake. The
fake still runs the real validation, enabled gate and caching, and records lookups for assertion:

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

Map a VIN to a `Throwable` to exercise a failure path (`Vin::fake(['…' => VinLookupException::requestFailed('…', 503)])`).

### Faking the HTTP layer directly

Prefer to assert on the wire? The package uses Laravel's HTTP client, so you can fake NHTSA instead:

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

## Versioning

This package follows [Semantic Versioning](https://semver.org). As of **1.0.0** the public API — the
`Vin` facade, the `Contracts\VinDecoder` driver seam, `VehicleData`, `VinLookupException`, and the
`vin.*` config keys / env vars — is stable and covered by SemVer. See the [CHANGELOG](CHANGELOG.md)
for what each release adds.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
