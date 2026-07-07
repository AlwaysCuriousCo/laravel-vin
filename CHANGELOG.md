# Changelog

All notable changes to `alwayscurious/laravel-vin` are documented here. This project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html) and the format of
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0] - 2026-07-07

First stable release. The public API — the `Vin` facade, the `VinDecoder` driver seam, `VehicleData`,
`VinLookupException`, and the `vin.*` config keys and their env vars — is now covered by SemVer.

### Added

- **`Vin::fake()`** — a shipped test double (`Testing\VinFake` + `Testing\FakeVinDecoder`) that returns
  preset `VehicleData` by VIN with no network call, still applies validation/gate/caching, and records
  lookups for assertion (`assertLookedUp`, `assertNotLookedUp`, `assertNothingLookedUp`,
  `assertLookedUpCount`). Pair with `VehicleData::fake(...)`. (VIN-024, VD-009, ADR-0007)
- **`Rules\Vin`** — a `ValidationRule` for form input: structural by default, `withCheckDigit()` to
  also verify the ISO 3779 9th-position check digit. (VIN-019, VIN-020)
- **`Vin::hasValidCheckDigit()`** and **`Support\VinCheckDigit`** — opt-in check-digit verification,
  deliberately separate from `isValid()` (which stays structural-only). (VIN-020)
- **`Vin::lookupMany()`** and **`Contracts\BatchVinDecoder`** — decode many VINs in one request
  (NHTSA `DecodeVinValuesBatch`), reusing the per-VIN cache and looping `decode()` for drivers without
  batch support. (VIN-023, ADR-0006)
- **Typed failure reason** — `VinLookupException::$reason` (`VinFailureReason`), so a single `catch`
  can branch on the cause; `->reason->isTransient()` flags retryable failures. (VIN-021)
- **Decode events** — `Events\VinDecoded` (with a `fromCache` flag) and `Events\VinDecodeFailed` (with
  the reason), dispatched by `VinLookupService` for host telemetry. (VIN-022)
- **`VehicleData::only()` / `toColumns()`** — project the identity fields into a `Model::fill()`-ready
  array; throw on an unknown field. (VD-008)
- **Configurable NHTSA retry** — `vin.decoders.nhtsa.retry.times` / `.sleep`
  (`VIN_RETRY_TIMES` / `VIN_RETRY_SLEEP`), replacing the hard-coded `retry(2, 200)`. (VIN-018)

### Notes

- All of the above are **additive** — existing call sites are unaffected. `VinLookupException`'s
  historical positional constructor still works (the new `reason` defaults).
- `Vin::isValid()` behavior is unchanged: it remains structural-only. Check-digit verification is a
  separate opt-in (see ADR-0007, which supersedes the earlier "check digit not performed" scope note).
