# CLAUDE.md

Guidance for AI agents working on **this repository** — the `alwayscurious/laravel-vin`
package itself.

> Editing an app that *installs* this package? The consumer-facing guidance lives in
> [`resources/boost/guidelines/core.blade.php`](resources/boost/guidelines/core.blade.php),
> which Laravel Boost auto-loads into downstream projects. Keep the two in sync when the
> public API changes.

## What this is

A Laravel **package** that decodes a 17-character US VIN into vehicle attributes (year,
make, model, series, trim, body class, manufacturer, vehicle type) via the
[NHTSA vPIC API](https://vpic.nhtsa.dot.gov/api/). Decodes are cached because a VIN's
decode is immutable.

This is **not an application**: there is no `artisan`, no routes, no database, no HTTP
kernel. The framework is booted for tests via Orchestra Testbench. Do not add commands
like `php artisan serve` or `php artisan migrate` to your mental model — they don't exist
here.

## Commands

```bash
composer test          # vendor/bin/phpunit — run the suite
composer lint          # vendor/bin/pint    — apply code-style fixes (writes files)
vendor/bin/pint --test # check style only, no writes — this is what CI runs
```

CI ([`.github/workflows/tests.yml`](.github/workflows/tests.yml)) runs `pint --test`
**then** `phpunit`, against PHP **8.3, 8.4 and 8.5**. A style violation fails the build
before tests run, so run `composer lint` before pushing.

## Layout — `src/`, namespace `AlwaysCurious\Vin\`

| File | Role |
| --- | --- |
| `VinManager.php` | `Illuminate\Support\Manager` driver registry. Resolves the driver named by `vin.driver`, exposes `lookup()`/`tryLookup()`/`isValid()`/`using()`/`extend()`. The facade's root. |
| `Facades/Vin.php` | The `Vin` facade (auto-aliased). Primary consumer entry point → `VinManager`. |
| `VinLookupService.php` | The per-driver workflow. Owns validation, the enabled gate and caching; delegates the decode to a `VinDecoder`. Pure constructor-injected (no `config()` of its own); the manager builds it. |
| `Contracts/VinDecoder.php` | The driver seam: `decode(string $vin, ?int $modelYear): VehicleData`. Host apps register their own via `Vin::extend()`. |
| `Decoders/NhtsaVinDecoder.php` | Default `nhtsa` driver. Owns the NHTSA vPIC HTTP call, retry and response mapping. |
| `VehicleData.php` | Immutable `final readonly` value object returned by lookups. `Arrayable` + `JsonSerializable`. |
| `VinLookupException.php` | `RuntimeException` with a named constructor per failure mode. |
| `VinServiceProvider.php` | Merges + publishes `config/vin.php`, registers the `VinManager` singleton (+ `vin` alias) and the default-driver `VinLookupService` binding. |

Config lives in [`config/vin.php`](config/vin.php); every key is env-driven.

## Design decisions — deliberate, don't "simplify" them away

These look like they could be tidied up, but each is intentional. Changing one is a
behavior change, not a cleanup:

- **Decoders are resolved through a `Manager` (`VinManager`), selected by `vin.driver`.** The
  manager is a `singleton` so `Vin::extend()` registrations survive; it caches decoder
  instances per the `Manager` contract. See [ADR-0004](specs/40-adr/0004-manager-driver-system.md)
  — this reverses ADR-0003's earlier "plain container binding, not a Manager" decision.
- **The gate and cache config stay live even though the manager is a singleton.** `VinManager`
  caches *decoders*, but its `lookup()`/`using()` build a **fresh `VinLookupService` per call**
  that re-reads `vin.enabled` and `vin.cache.*`. So a runtime toggle of the enabled gate or a
  cache-version bump takes effect immediately (VIN-004/VIN-007, INV-4). Driver-level config
  (`vin.decoders.*`) is captured at first resolution; `Vin::forgetDrivers()` refreshes it.
  Don't "optimize" this by caching the whole service in the manager — that would silently break
  the runtime-toggle guarantee.
- **Decoding is delegated to a `VinDecoder`; validation, the enabled gate and caching stay in
  `VinLookupService`.** A host registers only a decoder (via `Vin::extend()`) and inherits
  validation + gate + caching unchanged. Keep those three in `VinLookupService.lookup()`, not
  in the decoder — pushing them into the decoder would force every custom provider to
  re-implement them. Every decoder receives an already-normalized, already-validated VIN.
- **`lookup()` reads the cache by hand instead of `Cache::remember()`.** This is on purpose:
  a stale payload that no longer unserializes into a `VehicleData` (e.g. the value object
  gained a property since it was cached) is re-decoded rather than surfacing as a fatal
  type error. Preserve the `Cache::store($store)->get()` → `instanceof VehicleData` →
  `->put()` shape. The store comes from `vin.cache.store` (null = default).
- **Cache key is `vin:v{cache.version}:{driver}:{VIN}:{modelYear|auto}`** (INV-1). The version
  segment lets a host bump `VIN_CACHE_VERSION` to invalidate every cached decode at once
  without flushing the whole store; the **driver** segment keeps two providers' decodes of the
  same VIN from colliding (VIN-016); the model-year hint is in the key because it changes the
  decode.
- **`ErrorCode` is a comma-separated list** from NHTSA (e.g. `"0,12"`); only the **first**
  entry is the primary decode status. `VehicleData::fromFlatResult` splits and keeps `[0]`.
- **VIN pattern is `/^[A-HJ-NPR-Z0-9]{17}$/`** — 17 chars, excluding `I`, `O`, `Q` (which
  never appear in a real VIN). Input is normalized (`strtoupper(trim())`) before matching.
- **`decodedSuccessfully()` (errorCode === 0) is stricter than `isFullyIdentified()`
  (year + make + model present).** NHTSA can return a full year/make/model while still
  flagging a non-blocking warning, so the two can disagree. Keep both; they answer
  different questions.
- **`NhtsaVinDecoder` HTTP uses `->retry(2, 200, throw: false)`** and then checks
  `$response->failed()` explicitly. `throw: false` means a persistent HTTP error becomes a
  `VinLookupException::requestFailed`, not a raw `RequestException`. A dropped connection
  (`ConnectionException`) is caught separately and rethrown as `connectionFailed`.

## The NHTSA call (`Decoders/NhtsaVinDecoder`)

`GET {base_url}/vehicles/decodevinvalues/{VIN}?format=json[&modelyear={year}]`, where
`base_url` is `vin.decoders.nhtsa.base_url`. The `DecodeVinValues` endpoint returns a single
flat result row at `Results.0`; anything else is a `VinLookupException::unexpectedResponse`.
This is the **default** `nhtsa` driver — a host app registers a different `Contracts\VinDecoder`
via `Vin::extend()` and selects it with `vin.driver` to decode elsewhere.

## Testing conventions

- Tests are **PHPUnit** class-based (not Pest), extending
  [`tests/TestCase.php`](tests/TestCase.php) which extends `Orchestra\Testbench\TestCase`
  and registers `VinServiceProvider`.
- **Never hit the real network.** Always `Http::fake([...])` the vPIC host, as in
  [`tests/VinLookupServiceTest.php`](tests/VinLookupServiceTest.php). Assert requests with
  `Http::assertSent`, `Http::assertSentCount`, `Http::assertNothingSent`.
- `phpunit.xml` sets `failOnWarning="true"` — a deprecation or risky-test warning fails the
  suite, so keep tests warning-clean.
- New failure modes get a named constructor on `VinLookupException` **and** a test.
- A test that enforces a normative requirement carries a `@spec VIN-NNN` docblock (see below).

## Specs — the normative source of truth

Enforceable behavior lives in [`specs/`](specs/) as `[VIN-NNN]` requirements
([`specs/10-domains/vin-lookup.md`](specs/10-domains/vin-lookup.md)); design rationale lives in
[`specs/40-adr/`](specs/40-adr/). This file and the README are *informative* — they narrate and
explain, and point at spec IDs rather than restating requirements as rules. The "deliberate
decisions" section above is the narrative companion to ADR-0002/0003.

Tests trace to specs via `@spec VIN-NNN` docblocks, so the chain is greppable both ways:

```bash
grep -rn "@spec VIN-004" tests/   # every test guarding the enabled-gate requirement
```

When you change product behavior: update the **spec first**, bump its `Version:`, then bring
code, tests (with `@spec`) and the informative narrative into line. Non-product changes
(tooling, CI, formatting) are exempt — see
[`specs/00-overview/definition-of-done.md`](specs/00-overview/definition-of-done.md).

## When you change the public API

Keep these in lockstep — the spec is normative; the rest describe the same surface for
different audiences:

1. [`specs/10-domains/vin-lookup.md`](specs/10-domains/vin-lookup.md) (**normative** — update first)
2. `src/` (the code) + `tests/` (with `@spec` annotations)
3. [`README.md`](README.md) (human docs + the env/config table)
4. [`config/vin.php`](config/vin.php) (defaults + env var names)
5. [`resources/boost/guidelines/core.blade.php`](resources/boost/guidelines/core.blade.php)
   (AI guidance shipped to consumer apps)

Dev-only files (`tests/`, `specs/`, `CLAUDE.md`, tooling config) are kept out of the
distributed package via `export-ignore` in [`.gitattributes`](.gitattributes); add new
tooling/config files there too.

Supported constraints: PHP `^8.3`, `illuminate/*` `^11.0|^12.0|^13.0`, Testbench
`^9.0|^10.0|^11.0`. Don't introduce APIs that require a newer floor without bumping
`composer.json`.
