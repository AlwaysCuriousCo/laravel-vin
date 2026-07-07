# ADR-0002: NHTSA vPIC as the Default Provider, Behind a Decoder Seam

**Status:** Accepted
**Date:** 2026-07-06
**Deciders:** Maintainers

---

## Context

The package needs to turn a VIN into vehicle attributes. Decoding requires a data source,
and there are several: NHTSA's vPIC API, commercial VIN APIs, and offline WMI/pattern
tables. We also want consumers who have a preferred provider not to be forced onto ours.

vPIC offers two relevant endpoints: `DecodeVin` (returns many `{Variable, Value}` rows that
must be pivoted) and `DecodeVinValues` (returns a single flat row keyed by field name). It
also offers a `DecodeVINValuesBatch` endpoint for multiple VINs per call.

## Decision

1. **Default to NHTSA vPIC.** It is free, requires no API key, is maintained by a US
   government agency, and covers US-market vehicles well — the package's target audience.
2. **Use the `DecodeVinValues` endpoint**, not `DecodeVin`. The flat single-row shape maps
   directly onto `VehicleData::fromFlatResult()` with no pivoting.
3. **Put the provider behind a `Contracts\VinDecoder` seam.** `VinLookupService` owns
   normalization, validation, the enabled gate and caching, then delegates only the decode to
   the bound `VinDecoder`. `Decoders\NhtsaVinDecoder` is the default binding; a host app binds
   its own implementation to decode elsewhere and inherits the wrapper behavior for free.
   (Requirements: [VIN-008](../10-domains/vin-lookup.md), VIN-009, VIN-010.)
4. **Single VIN per call.** The batch endpoint is intentionally not used.

## Alternatives Considered

**`DecodeVin` (key/value rows):** requires pivoting hundreds of rows per VIN with no benefit
over the flat endpoint. Rejected.

**Hardcode NHTSA with no seam:** simplest, but locks every consumer onto vPIC and makes the
provider untestable in isolation. Rejected — the seam costs one interface and one binding.

**A commercial provider as default:** better data on some non-US vehicles, but requires keys,
billing, and per-consumer signup. Wrong default for a free, drop-in package. Available to
consumers via a custom `VinDecoder`.

**Offline WMI/pattern decode:** no network dependency, but far less data (no trim/body class)
and a maintenance burden to keep tables current. Rejected as the default.

**Batch endpoint:** would complicate the single-VIN `lookup()` contract and the cache-key
model. Out of scope; a batch method can be added later without breaking single lookups.

## Consequences

**Positive:** works with zero configuration and no API key; consumers can swap providers with
a one-line container binding; the flat endpoint keeps mapping trivial.

**Negative:** non-US VINs may decode poorly (a scope limit, not a bug); the package depends on
NHTSA availability for the default path (mitigated by caching and the `vin.enabled` gate); no
built-in batch decode.

## Review

Revisit if NHTSA changes the `DecodeVinValues` contract, if demand for batch decoding is
strong enough to justify a dedicated method, or if a materially better free US provider
appears.
