# ADR-0007: Consumer DX Surface — Fake, Validation Rule, Check Digit, Failure Reason, Events

**Status:** Accepted
**Date:** 2026-07-07
**Deciders:** Maintainers

---

## Context

Integration feedback from a consuming app surfaced five points of friction that are individually
small but share a theme — the package decodes well but is harder than it should be to *test against,
validate for, and observe*. This ADR records the design choices for that DX bundle; the batch work is
[ADR-0006](0006-batch-decoding.md), and a documented 1.0 is cut alongside these.

## Decision

1. **A shipped test fake — `Vin::fake([...])` → `Testing\VinFake` + `Testing\FakeVinDecoder`.** It
   swaps the manager (the `Bus::fake()` / `Mail::fake()` pattern) but installs a *fake decoder at the
   existing `VinDecoder` seam*, so a faked lookup still flows through the real validation, gate and
   caching — a consumer tests their integration, not a bypass. Preset data is keyed by VIN
   (`VehicleData` or a `Throwable` for failures); unmapped VINs get a generated `VehicleData::fake()`.
   Lookups are recorded for assertion. The fake lives in **`src/`** (not `tests/`, which is
   `export-ignore`d) so consumers can import it; per the framework's own precedent it may reference
   `PHPUnit\Framework\Assert` from a shipped class. ([VIN-024](../10-domains/vin-lookup.md), VD-009.)
2. **A `Rules\Vin` validation rule — structural by default, check digit opt-in.** Structural checking
   mirrors `isValid()` (VIN-002). `withCheckDigit()` and `Vin::hasValidCheckDigit()` add ISO 3779
   9th-position verification via `Support\VinCheckDigit`. This is deliberately kept **out of**
   `isValid()` (VIN-003 unchanged): check-digit compliance is a North-American rule some
   structurally-valid VINs do not honor, so folding it into structural validity would reject VINs the
   decoder can still decode. This **reverses** the earlier "check digit explicitly not performed"
   scope note as an *additive opt-in*, not a change to `isValid()`. (VIN-019, VIN-020.)
3. **A typed `VinFailureReason` on `VinLookupException`.** Every named constructor sets `->reason`
   (`InvalidVin` / `Disabled` / `ConnectionFailed` / `RequestFailed` / `UnexpectedResponse`), so a
   caller branches on the cause from one `catch` instead of `tryLookup()`-then-`isValid()` two-path
   code. The historical positional constructor still works (reason defaults). Chosen over a
   result-object return from `tryLookup()` — a typed exception is additive and non-breaking, where a
   result object is a second return contract. (VIN-021.)
4. **Decode events — `Events\VinDecoded` / `Events\VinDecodeFailed`, dispatched by
   `VinLookupService`** (the shared choke point, never the decoder, so custom drivers get them free).
   `VinDecoded` carries a `fromCache` flag and fires on cache hits too, so telemetry is honest and
   filterable; `VinDecodeFailed` fires even on failures `tryLookup()` swallows. (VIN-022.)
5. **`VehicleData` projection helpers (`only()`, `toColumns()`) and a `fake()` factory.** Projection
   produces a `Model::fill()`-ready array and throws on an unknown field; the **merge policy stays in
   the app**. (VD-008, VD-009.)
6. **Configurable NHTSA retry** (`vin.decoders.nhtsa.retry.times|sleep`), replacing the hard-coded
   `retry(2, 200)`. (VIN-018.)

## Alternatives Considered

**Fake by swapping the whole manager (bypassing the lookup service):** simpler assertions, but a
faked lookup would skip validation/gate/caching, so a consumer's test would not catch passing an
invalid VIN or a disabled gate. Rejected in favor of faking at the decoder seam.

**Fold the check digit into `isValid()`:** one obvious method, but a breaking change that rejects
today-valid VINs. Rejected; kept as an opt-in.

**Ship the "fill only blank columns, caller wins" merge on `VehicleData`:** matches the consumer's
hand-rolled code, but bakes one write policy into an immutable value object. Rejected — ship the
projection, leave the merge to the app.

**A `VinLookupResult` object from `tryLookup()`:** richer failure ergonomics, but a heavier second
return contract that overlaps the typed exception. Deferred.

## Consequences

**Positive:** consumers test without knowing the NHTSA wire format; forms validate (optionally with a
real check digit) before spending a call; one `catch` renders the right message; hosts get decode
telemetry for free; filling a model from a decode is one call. All additions are additive — no
existing call site breaks — which is what lets 1.0 ship.

**Negative:** more public surface to keep in lockstep (spec, README, boost, config); a shipped class
references PHPUnit (only loaded under test); the check-digit reversal means the scope note in
`vin-lookup.md` had to be superseded rather than left as written.

## Review

Revisit the deferred `VinLookupResult` if consumers still want a non-throwing result with a reason,
and revisit shipping a merge helper if a single dominant merge policy emerges across consumers.
