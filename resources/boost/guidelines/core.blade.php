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
- `Vin::lookupMany(array $vins, ?int $modelYear = null): array<string, VehicleData>` — decode a
  batch in one request (keyed by normalized VIN, input order). Use for fleet/bulk imports.
- `Vin::hasValidCheckDigit(string $vin): bool` — stricter than `isValid()`; also verifies the ISO
  3779 9th-position check digit. **No network call**. `isValid()` stays structural-only on purpose.
- `Vin::inspect(string $vin): VinValidation` — the offline validator that reports *why* a VIN is
  invalid, not just a bool: `->valid` (structure + check digit), `->structurallyValid`,
  `->checkDigitValid`, and `->errors` (typed `VinValidationError`: `WrongLength` / `IllegalCharacters`
  / `InvalidCheckDigit`, with `->messages()` for display). **No network call**. Use when form feedback
  must distinguish wrong length / illegal character / bad check digit.
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

### Validating input, typed failures, batch and events

- **Validation rule.** Use `AlwaysCurious\Vin\Rules\Vin` for form input: `['vin' => ['required', new
  \AlwaysCurious\Vin\Rules\Vin]]` (structural), or `(new Vin)->withCheckDigit()` to also verify the
  check digit. Do **not** hand-roll a VIN regex.
- **Typed failure reason.** When you need to know *why* a lookup failed, catch `VinLookupException`
  and branch on `$e->reason` (a `VinFailureReason`: `InvalidVin` / `Disabled` / `ConnectionFailed` /
  `RequestFailed` / `UnexpectedResponse`; `$e->reason->isTransient()` flags retryable ones) instead
  of pairing `isValid()` with `tryLookup()`.
- **Batch.** For many VINs, prefer `Vin::lookupMany([...])` (one request) over a loop of `lookup()`.
  It throws on a structurally invalid VIN before any request — pre-filter with `isValid()` if input
  may be dirty.
- **Events.** Listen for `AlwaysCurious\Vin\Events\VinDecoded` (has a `fromCache` flag) and
  `VinDecodeFailed` (has `->reason`) to feed your own telemetry — no need to wrap call sites. Do not
  dispatch these yourself; the package does.

@verbatim
<code-snippet name="Rule, batch and typed failure reason" lang="php">
use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\Rules\Vin as VinRule;
use AlwaysCurious\Vin\VinFailureReason;
use AlwaysCurious\Vin\VinLookupException;

$request->validate(['vin' => ['required', (new VinRule)->withCheckDigit()]]);

$vehicles = Vin::lookupMany(['7YAMYFS50TY009706', '1HGCM82633A004352']); // keyed by VIN

try {
    $vehicle = Vin::lookup($request->input('vin'));
} catch (VinLookupException $e) {
    $transient = $e->reason->isTransient();     // ConnectionFailed / RequestFailed
    // render the right message from $e->reason
}
</code-snippet>
@endverbatim

### The VehicleData value object

`lookup()` / `tryLookup()` return an immutable `final readonly AlwaysCurious\Vin\VehicleData`.
It implements `Arrayable` and `JsonSerializable`, so `->toArray()` and `json_encode($vehicle)`
both produce a snake_cased array. By default (`VIN_ATTRIBUTES=identity`) it carries the core
identifying fields below; `series`, the typed groups and the raw passthrough need `typed`/`full`.

@verbatim
<code-snippet name="Reading a decoded vehicle" lang="php">
$vehicle->vin;          // '7YAMYFS50TY009706'
$vehicle->year;         // int|null
$vehicle->make;         // string|null  e.g. 'HYUNDAI'
$vehicle->model;        // string|null  e.g. 'Ioniq 9'
$vehicle->trim;         // string|null
$vehicle->bodyClass;    // string|null
$vehicle->vehicleType;  // string|null
$vehicle->manufacturer; // string|null
$vehicle->errorCode;    // int|null — primary NHTSA decode status (0 = clean)
$vehicle->errorText;    // string|null
$vehicle->series;       // string|null — only at the 'typed'/'full' levels

// decodedSuccessfully() is stricter than isFullyIdentified(): NHTSA can return a full
// year/make/model while still flagging a non-blocking warning (e.g. model-year mismatch).
$vehicle->decodedSuccessfully(); // true only when errorCode === 0
$vehicle->isFullyIdentified();   // true when year + make + model are all present
</code-snippet>
@endverbatim

To persist a decode, project the identity fields with `only(['make','model',...])` (keyed by property
name) or `toColumns(['year' => 'model_year', ...])` (re-keyed to your columns) — both return a
`Model::fill()`-ready array and throw on an unknown field. They project only the flat identity fields;
keep your own merge policy (which columns win) in the app. Use `VehicleData::fake(make: 'Ford', ...)`
to build test doubles.

### Extended attributes and the raw passthrough

Beyond identity, `VehicleData` exposes four always-present typed groups — `engine`, `safety`,
`body`, `plant` — and a raw passthrough of every non-empty NHTSA field. Prefer a typed group
field when one exists (it is correctly typed and nullable); fall back to `attribute()` for the
long tail. Every group field is `null` when NHTSA has no value — a numeric field is never
coerced to `0`. `toArray()` / `json_encode()` nest the groups but do **not** include the raw
bag; reach it only via `->attributes` / `attribute()`.

The default level is `identity` (core identity only — smallest cached rows, least work). Step up
with `VIN_ATTRIBUTES` when the app needs more: `typed` adds `series` and the groups; `full` also
adds the raw passthrough. The groups are non-null at every level, so `$vehicle->engine->horsepower`
is always safe to read (it is just `null` when not hydrated). Changing the level changes the cached
shape — bump `VIN_CACHE_VERSION` alongside it.

@verbatim
<code-snippet name="Reading extended attributes" lang="php">
$vehicle->engine->horsepower;        // int|null   e.g. 422
$vehicle->engine->displacementL;     // float|null e.g. 5.0
$vehicle->engine->fuelTypePrimary;   // string|null e.g. 'Electric'
$vehicle->body->doors;               // int|null
$vehicle->safety->rearVisibilitySystem; // string|null e.g. 'Standard' (NHTSA's backup-camera field)
$vehicle->plant->country;            // string|null e.g. 'UNITED STATES (USA)'

// Long tail not lifted into a typed property — raw NHTSA field name, string value:
$vehicle->attribute('DestinationMarket');      // string|null
$vehicle->attribute('NCSABodyType', 'unknown'); // optional default when blank/absent
$vehicle->attributes;                          // full non-empty row, keyed by NHTSA field name
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
- `VIN_ATTRIBUTES` (`vin.decoders.nhtsa.attributes`, default `identity`) — how much of each decode
  to hydrate: `identity` (core identity, the lean default), `typed` (adds `series` + groups), or
  `full` (adds the raw passthrough). Lighter levels skip work and shrink the cached row; bump
  `VIN_CACHE_VERSION` when you change it.
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

**Prefer `Vin::fake()`** — it swaps the decoder for preset data with no network call, so you never
reverse-engineer the NHTSA URL or `Results.0` shape. It still runs the real validation, gate and
caching, and records lookups for assertion. Only fall back to `Http::fake()` when you specifically
need to assert on the wire. Never let a test hit the live API.

@verbatim
<code-snippet name="Fake VIN decoding in a test" lang="php">
use AlwaysCurious\Vin\Facades\Vin;
use AlwaysCurious\Vin\VehicleData;

$fake = Vin::fake([
    '1FTFW1E50NKF12345' => VehicleData::fake(make: 'Ford', model: 'F-150'),
]);

$vehicle = Vin::lookup('1FTFW1E50NKF12345'); // 'Ford' — no HTTP call
// unmapped VINs return a generated VehicleData::fake(); map a Throwable to test a failure path

$fake->assertLookedUp('1FTFW1E50NKF12345');
$fake->assertLookedUpCount(1);
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Or fake the HTTP layer directly" lang="php">
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
