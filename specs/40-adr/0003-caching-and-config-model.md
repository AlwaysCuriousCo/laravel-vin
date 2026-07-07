# ADR-0003: Caching, Container Bindings, and the Config-Reread Model

**Status:** Accepted; decisions 3 & 4 superseded by [ADR-0004](0004-manager-driver-system.md)
**Date:** 2026-07-06
**Deciders:** Maintainers

---

> **Superseded in part (2026-07-06):** Decision 3 ("bind, not singleton") and decision 4 ("the
> decoder seam is a plain container binding, *not* a `Manager`") were reversed by
> [ADR-0004](0004-manager-driver-system.md), which adopts a `VinManager` driver system and a
> `Vin` facade. The *config-reread guarantee* those decisions protected is preserved differently
> (the manager caches decoders but rebuilds a fresh `VinLookupService` per call — see ADR-0004
> decision 4 / INV-4). Decisions 1, 2 and 5 below (hand-rolled read-through cache, versioned
> cache key, and validation/gate/caching living in `VinLookupService`) still stand — though the
> key of decision 2 gained a `{driver}` segment and the config keys were renamed
> (`vin.cache_version` → `vin.cache.version`) by ADR-0004. The current key format is INV-1.

## Context

A VIN's decode is immutable, so repeated lookups should not repeat work. At the same time,
host apps may change `vin.*` settings at runtime (e.g. from database-backed settings, or
toggling the enabled gate during an incident), and expect the next lookup to honor the new
values. NHTSA occasionally corrects data, so consumers need a way to invalidate cached
decodes without nuking their whole cache store. These forces interact and several "obvious"
simplifications would quietly break one of them.

## Decision

1. **Read-through cache by hand, not `Cache::remember()`.** `lookup()` does
   `Cache::get()` → check `instanceof VehicleData` → decode → `Cache::put()`. A stale payload
   that no longer unserializes into a current `VehicleData` (e.g. the value object gained a
   property) is re-decoded instead of surfacing as a fatal type error. ([VIN-006](../10-domains/vin-lookup.md).)
2. **Versioned cache key** `vin:v{cache_version}:{normalized VIN}:{modelYear|'auto'}`. Bumping
   `vin.cache_version` bypasses every prior decode at once, without a store-wide flush. (VIN-007,
   INV-1.)
3. **Bind, not singleton**, for both `VinLookupService` and `VinDecoder`. Each resolve
   constructs a fresh instance that re-reads `config('vin.*')` (and the currently bound
   decoder), so runtime config changes take effect without a stale instance lingering.
4. **The decoder seam is a plain container binding, not an `Illuminate\Support\Manager`.** A
   Manager caches driver instances by design, which would defeat decision (3)'s
   config-reread-per-resolve guarantee.
5. **Validation, the enabled gate and caching live in `VinLookupService`**, wrapping the
   decoder — never inside a decoder. A custom provider therefore cannot accidentally bypass
   them. (VIN-009, INV-3.)

## Alternatives Considered

**`Cache::remember()`:** terser, but a stale/incompatible cached value would deserialize into
a broken object and throw at the call site instead of being transparently re-decoded. Rejected.

**Singleton bindings:** faster resolves, but a long-lived instance captures config at first
resolve and ignores later runtime changes — breaking the DB-backed-settings use case.
Rejected.

**`Manager`-based driver system for providers:** idiomatic for multi-driver Laravel packages,
but its instance caching conflicts with the config-reread guarantee, and the package has a
single seam (one `decode()` method), so a Manager is overkill. Rejected in favor of a plain
interface binding.

**Cache invalidation by tags/flush:** many stores don't support tags, and a flush evicts
unrelated entries. The version-in-key approach works on every store and is surgical. Rejected.

## Consequences

**Positive:** runtime config changes are honored; one-knob, store-agnostic cache
invalidation; stale/incompatible cached payloads self-heal; custom decoders inherit
validation/gate/caching for free.

**Negative:** a fresh service + decoder are constructed on every resolve (negligible cost for
this workload); the manual cache dance is a few lines more than `Cache::remember()` and must
be preserved deliberately (documented in `CLAUDE.md` and pinned by VIN-006).

## Review

Revisit if per-resolve construction ever shows up as a hotspot, or if a future multi-provider
requirement genuinely needs driver selection by name (reconsider a Manager, weighed against
the config-reread guarantee).
