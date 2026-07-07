# ADR-0006: Batch VIN Decoding via an Optional Decoder Capability

**Status:** Accepted
**Date:** 2026-07-07
**Deciders:** Maintainers

---

## Context

Decoding a fleet one VIN at a time is N HTTP round-trips. NHTSA exposes a `DecodeVinValuesBatch`
endpoint that decodes many VINs in one POST, and a bulk importer wants that — but the package's seam
(`Contracts\VinDecoder::decode`) is single-VIN, the cache is per-VIN, and `lookup()` owns validation,
the gate and caching (INV-3). A batch path has to preserve all of that without forcing every custom
driver to grow a batch method, and without a single bad VIN failing a 1,000-VIN import in a confusing
way.

## Decision

1. **Add an *optional* `Contracts\BatchVinDecoder extends VinDecoder`** with
   `decodeMany(array $vins, ?int $modelYear): array` returning `VehicleData` keyed by VIN. A driver
   opts in; one that does not keeps working. ([VIN-023](../10-domains/vin-lookup.md).)
2. **`VinLookupService::lookupMany()` owns the workflow, the decoder owns only the round-trip** —
   mirroring the single-VIN split. `lookupMany()` applies the enabled gate to the whole batch,
   normalizes + validates + de-duplicates up front, serves cache hits per VIN, and decodes only the
   misses; it calls `decodeMany()` when the decoder implements `BatchVinDecoder`, else loops
   `decode()`. So batch decodes inherit the gate, validation and per-VIN caching unchanged, and the
   cache key (INV-1) is reused verbatim — a VIN decoded singly and one decoded in a batch share a
   cache entry.
3. **Fail fast on an invalid VIN.** A structurally invalid VIN throws `VinLookupException` (reason
   `InvalidVin`) *before* any provider call, exactly like `lookup()`. A bulk caller that wants to
   tolerate dirty input pre-filters with `isValid()` / `Rules\Vin`. Chosen over silently dropping
   invalid VINs (which reads as "decoded everything" when it did not) and over a per-VIN error map
   (more surface than the fail-fast contract warrants at 1.0).
4. **Order + keying.** Results are keyed by normalized VIN and returned in first-seen input order;
   duplicates collapse. The NHTSA `decodeMany` pairs response `Results` to input VINs **by index**
   (the batch endpoint returns rows in submission order); a count mismatch is an
   `unexpectedResponse`.
5. **The NHTSA driver implements `BatchVinDecoder`** via
   `POST {base_url}/vehicles/DecodeVinValuesBatch/` with a `data` payload of `VIN[,modelYear]`
   entries joined by `;`.

## Alternatives Considered

**Put `decodeMany` on the base `VinDecoder` interface:** simplest dispatch, but a breaking change
that forces every existing custom driver to implement batching. Rejected in favor of an optional
sub-interface with a loop fallback.

**Return a per-VIN result/error map instead of throwing on invalid input:** richer, but a heavier
contract than the single-VIN path and easy to ignore the error half of. Deferred; the typed
`VinFailureReason` (ADR-0007) already covers *why* a whole call failed, and `isValid()` pre-filtering
covers dirty bulk input.

**Dispatch one aggregate event for a batch:** loses per-VIN telemetry parity with `lookup()`. Chosen
instead: one `VinDecoded` / `VinDecodeFailed` per VIN, so a listener sees the same events regardless
of whether a VIN was decoded singly or in a batch.

## Consequences

**Positive:** a fleet import is one round-trip; custom drivers gain batching by opting in and keep
working without it; batch and single decodes share validation, the gate, the cache and the event
stream.

**Negative:** index-based row pairing assumes NHTSA preserves submission order (documented, guarded by
a count check); a batch that mixes valid and invalid VINs fails fast rather than returning partial
results (caller pre-filters); a large batch emits one event per VIN.

## Review

Revisit if consumers need lenient bulk semantics (a per-VIN error map / a "skip invalid" mode) or
per-VIN model-year hints in one batch — both are additive on top of this contract.
