# Vehicle Data Spec

**ID prefix:** `VD`
**Status:** Accepted
**Version:** 1.3.0
**Last updated:** 2026-07-07
**Owners:** @alwayscurious

---

## 1. Purpose

Constrains how a decoded provider response is projected onto the `VehicleData` value object:
the typed identity fields, the typed **attribute groups** (engine, safety, body, plant), the
raw **attribute passthrough**, and the serialized shape. This is the contract a consuming app
reads after a lookup, and the reason `VehicleData` can expose the full NHTSA field set without
surfacing 100+ loosely-typed columns as the primary interface.

The [VIN Lookup spec](vin-lookup.md) previously scoped this mapping out ("candidate for a
future `vehicle-data.md` spec if it grows testable rules"); this spec is that home.

## 2. Scope

**In scope:**
- The full attribute passthrough (`$attributes`) built by `VehicleData::fromFlatResult()` and
  the `attribute()` accessor over it
- The typed attribute groups `engine`, `safety`, `body`, `plant` and their population
- Numeric coercion rules for typed group fields (int / float / null)
- The **attribute level** (`identity` / `typed` / `full`) that governs how much of a decode is
  hydrated, and the `vin.decoders.nhtsa.attributes` config that selects it
- The `toArray()` / `jsonSerialize()` shape
- The identity-field projection helpers (`only()`, `toColumns()`) and the `fake()` factory
- The `decodedSuccessfully()` vs `isFullyIdentified()` distinction

**Out of scope:**
- Normalization, validation, the enabled gate, caching and the driver system — see
  [VIN Lookup spec](vin-lookup.md) (`VIN-NNN`)
- The exact set of NHTSA field names (a data mapping, not a testable rule); the design
  rationale for exposing both typed groups and a raw bag — see
  [ADR-0005](../40-adr/0005-extended-vehicle-attributes.md)
- What a *custom* (non-NHTSA) driver puts in `$attributes` — a driver that constructs
  `VehicleData` directly is only bound by the constructor's defaults, not by `fromFlatResult`

## 3. Definitions

| Term            | Definition |
| --------------- | ---------- |
| Row               | The flat associative array from NHTSA's `DecodeVinValues` `Results.0` |
| Non-empty field   | A row value for which Laravel's `filled()` is `true` (not `null`, not `''`) |
| Core identity     | `vin`, `year`, `make`, `model`, `trim`, `bodyClass`, `vehicleType`, `manufacturer`, `errorCode`, `errorText` — always hydrated, at every level |
| Extended identity | `series` — hydrated only from the `Typed` level up |
| Attribute group   | One of the typed value objects `Vehicle\Engine`, `Vehicle\Safety`, `Vehicle\Body`, `Vehicle\Plant` |
| Passthrough       | `$attributes` — every non-empty row field kept verbatim, keyed by NHTSA field name |
| Attribute level   | `Vehicle\AttributeLevel` — `Identity` (core identity), `Typed` (adds extended identity + groups), `Full` (adds passthrough); the `$level` argument to `fromFlatResult()` |

## 4. Requirements

### 4.1 Attribute passthrough

**[VD-001]** At the `Full` attribute level (the default; see VD-007), `VehicleData::fromFlatResult()`
MUST preserve **every non-empty field** of the row in `$attributes`, keyed by its original NHTSA
field name, with each value trimmed to a string. Empty/blank fields MUST be omitted.

> **Rationale:** Total parity with a raw passthrough — anything NHTSA returns stays reachable,
> even fields the package does not lift into a typed property.
> **Test:** `tests/VehicleDataTest.php::test_it_keeps_every_non_empty_field_in_the_attribute_bag`

---

**[VD-002]** `attribute(string $key, ?string $default = null)` MUST return the stored string for
a present key and `$default` (null unless supplied) for an absent or blank field. Lookup is by
the **exact** NHTSA field name.

> **Rationale:** A single, safe accessor for the long tail that never warns on a missing key.
> **Test:** `tests/VehicleDataTest.php::test_attribute_reads_the_bag_with_a_default`

### 4.2 Typed attribute groups

**[VD-003]** At the `Typed` and `Full` levels, `fromFlatResult()` MUST populate the `engine`,
`safety`, `body` and `plant` groups from the row. On a `VehicleData` produced by
`fromFlatResult()` these groups MUST always be present (never `null`) **at every level**; an
individual group field MUST be `null` when its source row field is blank (or, at the `Identity`
level, for every field).

> **Rationale:** `$vehicle->engine->horsepower` must be safe to read without a null check on the
> group itself — even in `Identity` mode — while an absent underlying value is honestly `null`.
> **Test:** `tests/VehicleDataTest.php::test_it_populates_the_typed_groups_from_the_row`,
> `tests/VehicleDataTest.php::test_groups_are_present_but_empty_when_the_row_lacks_them`,
> `tests/VehicleDataTest.php::test_identity_level_skips_the_groups_and_the_raw_bag`

---

**[VD-004]** Numeric group fields — `engine->cylinders`, `engine->horsepower`,
`engine->transmissionSpeeds`, `body->doors`, `body->seats`, `body->seatRows` (integers) and
`engine->displacementL`, `engine->displacementCc` (floats) — MUST be `null` when the source
field is blank or non-numeric, and MUST NOT be coerced to `0` / `0.0`.

> **Rationale:** A missing door count is unknown, not zero doors; silent `0` coercion would be a
> data bug for any consumer that treats the value as truth.
> **Test:** `tests/VehicleDataTest.php::test_numeric_group_fields_are_null_when_blank_or_non_numeric`

### 4.3 Serialization

**[VD-005]** `toArray()` and `jsonSerialize()` MUST return the identity fields plus the four
typed groups nested under snake_cased keys (`engine`, `safety`, `body`, `plant`). The raw
`$attributes` passthrough MUST NOT be embedded in that output; it is reachable only via the
`$attributes` property and `attribute()`.

> **Rationale:** The serialized shape stays curated and stable regardless of how many columns a
> given VIN happens to populate; the raw bag is an explicit opt-in, not a default payload.
> **Test:** `tests/VehicleDataTest.php::test_to_array_nests_the_groups_and_omits_the_raw_bag`

### 4.4 Attribute level

**[VD-007]** `fromFlatResult()` MUST accept an `AttributeLevel` that bounds how much beyond core
identity it hydrates:
- `Identity` — core identity only; `series`, the groups (present-but-empty) and `$attributes`
  (`[]`) are all unhydrated.
- `Typed` — core identity plus `series` and the populated groups; `$attributes` empty (`[]`).
- `Full` — everything at `Typed`, plus the full passthrough (VD-001).

The built-in NHTSA decoder MUST select the level from `vin.decoders.nhtsa.attributes`. The
shipped config default is `identity` — the clean ~80% set at the smallest footprint. A missing or
unrecognized config value MUST fall back to `Full` (safe: more data, never a silent strip on an
upgrade whose published config predates this key); the `fromFlatResult()` parameter likewise
defaults to `Full` (the mapping utility maps everything unless asked otherwise). A lighter level
MUST skip the work it excludes (no passthrough loop below `Full`; no group or `series` mapping at
`Identity`) so it costs neither the cycles nor the cache footprint of what it omits.

> **Rationale:** Most consumers only need year/make/model/trim/body class/vehicle type/manufacturer;
> that is the default, and it should cost nothing for the engine/safety/body/plant mapping or a
> ~140-field passthrough — in CPU or cache size. `series` is comparatively niche and often empty,
> so it rides with the richer `Typed` level rather than the lean default.
> **Test:** `tests/VehicleDataTest.php::test_identity_level_skips_the_groups_and_the_raw_bag`,
> `tests/VehicleDataTest.php::test_typed_level_includes_groups_but_not_the_raw_bag`,
> `tests/VehicleDataTest.php::test_series_is_extended_identity_hydrated_from_the_typed_level`,
> `tests/VehicleDataTest.php::test_fromflatresult_defaults_to_the_full_level`,
> `tests/VehicleDataTest.php::test_attribute_level_resolves_from_config_with_a_full_fallback`,
> `tests/VinLookupServiceTest.php::test_the_default_level_is_identity`,
> `tests/VinLookupServiceTest.php::test_the_identity_level_omits_series_groups_and_the_raw_bag`,
> `tests/VinLookupServiceTest.php::test_the_typed_attribute_level_adds_series_and_groups_but_not_the_raw_bag`,
> `tests/VinLookupServiceTest.php::test_the_full_level_exposes_series_groups_and_the_raw_passthrough`

> **Note — caching:** the level changes the stored shape of a cached decode. Changing it does not
> retro-actively re-decode already-cached VINs; pair the change with a `vin.cache.version` bump
> (VIN-007) to invalidate them.

### 4.5 Decode status helpers

**[VD-006]** `decodedSuccessfully()` MUST return `true` only when `errorCode === 0`.
`isFullyIdentified()` MUST return `true` only when `year`, `make` and `model` are all present.
The two MUST be independent: a full identity with a non-zero (non-blocking) `errorCode` yields
`isFullyIdentified() === true` and `decodedSuccessfully() === false`.

> **Rationale:** NHTSA can return a complete year/make/model while still flagging a non-blocking
> warning (e.g. a model-year mismatch); the two questions have different answers.
> **Test:** `tests/VehicleDataTest.php::test_decode_status_helpers_are_independent`

### 4.6 Projection helpers

**[VD-008]** `only(array $keys)` MUST project the named identity fields keyed by their property name,
and `toColumns(array $map)` MUST project the identity fields named by `map`'s keys, re-keyed to
`map`'s column values. Projectable keys are the property names of the flat **identity** fields
(`vin`, `year`, `make`, `model`, `series`, `trim`, `bodyClass`, `manufacturer`, `vehicleType`,
`errorCode`, `errorText`); the nested typed groups are NOT projectable. An unknown key MUST throw
`InvalidArgumentException` (never silently drop).

> **Rationale:** The common consumption pattern is filling a model's columns from a decode; a
> first-class projection standardizes it and a throw-on-typo contract prevents a silently missing
> column. The merge policy (which columns win) stays in the app — this only produces the array.
> **Test:** `tests/VehicleDataProjectionTest.php::test_only_projects_named_properties_keyed_by_property_name`,
> `tests/VehicleDataProjectionTest.php::test_to_columns_maps_properties_onto_column_names`,
> `tests/VehicleDataProjectionTest.php::test_only_throws_on_an_unknown_field`,
> `tests/VehicleDataProjectionTest.php::test_to_columns_throws_on_an_unknown_field`

**[VD-009]** `VehicleData::fake(...)` MUST build a `VehicleData` with test-friendly defaults, letting
a caller override only the fields they name (all constructor fields accepted as named arguments).

> **Rationale:** Tests and the `Vin::fake()` decoder need a `VehicleData` without hand-assembling the
> full constructor or knowing any provider's wire format.
> **Test:** `tests/VinFakeTest.php::test_vehicle_data_fake_overrides_only_the_named_fields`

## 5. Invariants

- **INV-1:** `$attributes` is exactly the set of non-empty row fields, keyed by NHTSA field name
  with trimmed string values. (VD-001.)
- **INV-2:** Every typed group field is a typed projection of a subset of `$attributes`; the
  identity fields likewise denormalize from the same row. A field present in a typed property is
  therefore *also* present (as its raw string) under its NHTSA key in `$attributes`.
- **INV-3:** A `VehicleData` from `fromFlatResult()` never has a `null` group; only individual
  group *fields* are nullable. (VD-003.) This holds at every attribute level, including
  `Identity` (where the groups are present but empty).
- **INV-4:** The attribute level bounds hydration monotonically: `Identity` ⊆ `Typed` ⊆ `Full`.
  A lighter level's output is exactly a heavier level's output with the excluded parts empty
  (`series`, the group fields, and `$attributes`); it never adds or alters a **core** identity
  field or a populated group field. (VD-007.)

## 6. Acceptance Criteria

- [ ] Every MUST / MUST NOT above has a passing test carrying its `@spec VD-NNN` docblock
- [ ] INV-1 through INV-3 are each exercised by at least one test
- [ ] No new behavior contradicts a requirement in [`vin-lookup.md`](vin-lookup.md)
- [ ] Spec `Version:` bumped if any requirement changed

## 7. Related

- ADR: [ADR-0005 — Extended vehicle attributes: typed groups + raw passthrough](../40-adr/0005-extended-vehicle-attributes.md)
- Spec: [VIN Lookup spec](vin-lookup.md) (normalization, gate, caching, driver system)
- Narrative (contributor): [`../../CLAUDE.md`](../../CLAUDE.md)
- Narrative (consumer): `../../README.md`, `../../resources/boost/guidelines/core.blade.php`
