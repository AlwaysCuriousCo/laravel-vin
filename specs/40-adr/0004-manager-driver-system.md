# ADR-0004: Adopt a Manager-Based Driver System for Decoders

**Status:** Accepted
**Date:** 2026-07-06
**Deciders:** Maintainers
**Supersedes:** [ADR-0003](0003-caching-and-config-model.md) decisions 3 and 4

---

## Context

[ADR-0003](0003-caching-and-config-model.md) put the decoder behind a plain container binding
of `Contracts\VinDecoder` and deliberately *rejected* an `Illuminate\Support\Manager`, because a
Manager caches driver instances and that conflicted with ADR-0003's "bind, not singleton,
re-read config on every resolve" guarantee.

Since then the requirement has sharpened: the package should be a **first-class Laravel product**
where consumers select a VIN provider by name (`VIN_DRIVER`), register their own provider with the
idiomatic `extend()` hook, and call a `Vin` facade — the same ergonomics as Cache, Mail, and
Filesystem. A single anonymous container binding does not offer named driver selection, a facade,
or a discoverable extension point. The earlier "one seam, so a Manager is overkill" reasoning no
longer matches the product goal.

The open question ADR-0003 raised — *"reconsider a Manager, weighed against the config-reread
guarantee"* — is exactly what this ADR resolves.

## Decision

1. **Introduce `VinManager extends Illuminate\Support\Manager`** as the driver registry.
   `getDefaultDriver()` returns `config('vin.driver', 'nhtsa')`; `createNhtsaDriver()` builds the
   default `NhtsaVinDecoder` from `vin.decoders.nhtsa.*`. Registered as a **singleton** so
   `extend()` registrations survive. ([VIN-008](../10-domains/vin-lookup.md), VIN-013, VIN-014.)
2. **Custom drivers via `extend($name, fn ($app) => $decoder)`.** The closure returns a raw
   `VinDecoder`; the manager wraps it. Registration uses the injected `$app` container argument,
   which is passed identically on Laravel 11/12/13 (we do not rely on newer versions' closure
   `$this` rebinding). (VIN-014.)
3. **Ship a `Vin` facade** (`Facades\Vin`, auto-aliased `Vin`) resolving to `VinManager`, exposing
   `lookup`/`tryLookup`/`isValid`/`using`/`extend`/`getDefaultDriver`/`forgetDrivers`. (VIN-015.)
4. **Preserve the runtime-reread guarantee where it matters, by splitting what is cached.** The
   Manager caches **decoder** instances (standard `Manager` behavior). But the workflow methods
   build a **fresh `VinLookupService` per call** that re-reads the enabled gate and cache config,
   so runtime toggles of `vin.enabled` / `vin.cache.version` still take effect immediately — the
   property ADR-0003 protected. Driver-level config (`vin.decoders.*`) is captured at first
   resolution; `Vin::forgetDrivers()` refreshes it. (VIN-004, VIN-007, INV-4.)
5. **Namespace the cache key by driver:**
   `vin:v{cache.version}:{driver}:{normalized VIN}:{modelYear|'auto'}`. Two providers can decode
   the same VIN differently; their cached results must not collide, and switching `vin.driver`
   must not serve another provider's value. (VIN-016, INV-1.)
6. **Restructure config into Laravel's per-driver shape:** `vin.driver`, `vin.decoders.{name}.*`,
   `vin.cache.{store,ttl,version}`, `vin.enabled` — mirroring `cache.stores` / `mail.mailers`.
   Env var names are unchanged (`VIN_BASE_URL`, `VIN_TIMEOUT`, `VIN_CACHE_TTL`,
   `VIN_CACHE_VERSION`, `VIN_ENABLED`) plus `VIN_DRIVER` and `VIN_CACHE_STORE`. (VIN-017.)

`VinLookupService` keeps its role from ADR-0002/0003 — normalization, validation, the gate and
caching — but becomes a pure constructor-injected object (no `config()` reads of its own); the
manager is now the single config boundary.

## Alternatives Considered

**Keep the plain container binding (ADR-0003 status quo):** simplest, but no named driver
selection, no facade, no discoverable `extend()` — not the "first-class product" the requirement
now calls for. Superseded.

**Textbook Manager that caches the whole wrapped service** (like `CacheManager` caching a
`Repository`): fewer moving parts, but a cached service captures `vin.enabled` / `vin.cache.version`
at creation, so a runtime toggle would not take effect until `forgetDrivers()` — silently
regressing VIN-004's incident-response guarantee. Rejected in favor of caching only decoders and
rebuilding the (cheap) service per call.

**A config class-string (`vin.driver_class`) instead of a Manager:** swaps the provider but gives
no named registry, no `extend()`, no facade. Rejected.

**Lazily re-read `base_url`/`timeout` inside the decoder to keep those live too:** would fully
restore ADR-0003's per-resolve reread, but couples the decoder to global `config()` and deviates
from how Laravel drivers capture config at creation. Rejected as not worth the coupling;
`forgetDrivers()` covers the rare runtime change of a driver's own config.

## Consequences

**Positive:** named provider selection via `VIN_DRIVER`; idiomatic `Vin::extend()`; a first-class
`Vin` facade; per-driver cache isolation; runtime toggles of the gate and cache version still work;
config matches Laravel conventions. Custom providers still inherit validation/gate/caching for free
(INV-3 unchanged).

**Negative:** more moving parts than one binding (a manager + facade); driver-level config
(`vin.decoders.*`) is captured at first resolution and needs `Vin::forgetDrivers()` to refresh
mid-process; the config restructure is a breaking change to `config/vin.php`'s shape (env var
names are preserved, and the package is pre-1.0).

## Review

Revisit if per-call service construction ever shows up as a hotspot (cache the service per driver
and expose an explicit refresh instead), or if a driver ever needs first-class per-request config
that `forgetDrivers()` handles awkwardly.
