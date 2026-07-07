# ADR-0005: Extended Vehicle Attributes — Typed Groups Plus a Raw Passthrough

**Status:** Accepted
**Date:** 2026-07-07
**Deciders:** Maintainers

---

## Context

`VehicleData` originally exposed a curated identity subset of the NHTSA `DecodeVinValues`
response: year, make, model, series, trim, body class, manufacturer, vehicle type, and the
decode status. But `DecodeVinValues` returns ~140 fields per VIN — engine and powertrain
(fuel type, displacement, horsepower, drive, transmission), passive/active safety (airbag
locations, ABS, ESC, TPMS, driver-assist), body/dimensions (doors, seats, GVWR), and the
manufacturing plant. A consumer that needs any of these currently cannot reach them through
this package, even though the data already arrives in the same HTTP response we parse.

A comparable package (`composite/laravel-nhtsa`) hands back NHTSA's raw response wholesale.
That achieves total field coverage but discards typing: every value is a loosely-typed string
and the shape is whatever NHTSA sent. We want the coverage **without** giving up the typed,
immutable value object that is the point of this package.

## Decision

`VehicleData::fromFlatResult()` now produces **both** a typed surface and a raw passthrough:

1. **Typed attribute groups.** Four `final readonly` value objects under
   `AlwaysCurious\Vin\Vehicle\` — `Engine`, `Safety`, `Body`, `Plant` — carry the
   commonly-used specs as typed, nullable properties (ints for counts/horsepower, floats for
   displacement, strings otherwise). They are exposed as always-present properties
   (`$vehicle->engine->horsepower`), so reading a group never needs a null check; only the
   individual fields are nullable. (Requirement [VD-003](../10-domains/vehicle-data.md), VD-004.)
2. **A raw passthrough.** `$vehicle->attributes` keeps **every non-empty** row field verbatim,
   keyed by its NHTSA field name; `attribute('SomeField')` reads it safely. This closes the
   parity gap for the long tail we do not lift into a typed property. (VD-001, VD-002.)
3. **A curated serialization.** `toArray()` / `jsonSerialize()` emit the identity fields plus
   the four nested groups, but **not** the raw bag — so the serialized shape stays stable and
   small regardless of how many columns a given VIN populates. The raw bag is an explicit
   opt-in via the property/accessor. (VD-005.)

This mirrors the pattern already in the value object: the identity fields (`make`, `year`, …)
are themselves a typed denormalization of the same row. The groups extend that pattern; the
raw bag backs it with the complete source.

## Alternatives Considered

**Raw passthrough only** (a bag + `attribute()`, no typed groups). Smallest surface and total
parity, but every extended field stays an untyped string — abandoning the typed-value-object
ethos for exactly the fields we are adding. Rejected: it makes us a passthrough with typed
identity bolted on, not a typed decoder.

**Expanded typed fields only** (add ~25 flat typed properties to `VehicleData`, no raw bag).
Fully typed and lean (nothing stored twice), but it is **not** parity: the fields we choose not
to promote (dimensions, market/NCSA codes, notes, …) become permanently unreachable, and every
future field NHTSA adds needs a code change. Rejected as the sole approach — though it is the
"typed" half of what we shipped.

**Lazy group accessors** (`engine()` methods projecting from `$attributes`, groups not stored).
Avoids storing group fields twice. Rejected for consumer ergonomics and consistency: the
package exposes state as public readonly properties everywhere else, and the identity fields
already denormalize from the row, so eager typed groups match the established shape. The
duplication is a few small objects over a already-small row.

## Consequences

**Positive:** full field coverage with a typed, discoverable surface for the common specs;
`attribute()` covers everything else; the serialized shape is unchanged for existing keys
(groups are additive) and stays curated; custom drivers are unaffected — the new constructor
parameters all default (empty groups, empty bag), so `new VehicleData(...)` keeps working.

**Negative:** a cached `VehicleData` grows to hold the raw row plus the typed groups (the
overlapping fields are stored twice). This is a small, bounded increase per decode and is
consistent with the identity fields already denormalizing from the row; hosts that correct the
stored shape use the existing `vin.cache.version` knob (VIN-007) to invalidate. `toArray()`
gains nested `engine`/`safety`/`body`/`plant` keys — additive, but consumers doing a strict
whole-array comparison of the old shape must account for them.

## Review

Revisit if the raw-bag storage cost becomes material at scale (switch to lazy group projection
from `$attributes`, dropping the stored groups), if NHTSA changes `DecodeVinValues` field names
(remap the groups), or if a group grows enough shared behavior to warrant its own spec section.
