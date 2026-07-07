# VIN Lookup Spec

**ID prefix:** `VIN`
**Status:** Accepted
**Version:** 2.0.0
**Last updated:** 2026-07-06
**Owners:** @alwayscurious

---

## 1. Purpose

Constrains how the package turns a raw VIN string into a `VehicleData`: input handling, the
enabled gate, caching, the pluggable decoder **driver system**, and the behavior of the
default NHTSA decoder. These are the guarantees a consuming app relies on and the reasons the
code is shaped the way it is.

## 2. Scope

**In scope:**
- Normalization and structural validation of VIN input
- The `vin.enabled` master gate
- Read-through caching, the cache store selection, and cache invalidation via `vin.cache.version`
- The `VinManager` driver system: driver selection (`vin.driver`), custom driver registration
  (`extend`), per-call driver override (`using`), and the `Vin` facade
- The `Contracts\VinDecoder` seam and the default `nhtsa` driver
- The default `Decoders\NhtsaVinDecoder` request/response contract and error mapping

**Out of scope:**
- The precise field mapping / nullability of `VehicleData` and the
  `decodedSuccessfully()` vs `isFullyIdentified()` distinction (documented in the README;
  candidate for a future `vehicle-data.md` spec if it grows testable rules)
- NHTSA-specific decisions and the rationale for the decoder seam — see
  [ADR-0002](../40-adr/0002-nhtsa-vpic-provider.md)
- The driver-system, caching and config-model *design rationale* — see
  [ADR-0004](../40-adr/0004-manager-driver-system.md) (which supersedes parts of
  [ADR-0003](../40-adr/0003-caching-and-config-model.md))
- Check-digit (9th position) verification — **explicitly not performed**; NHTSA validates it
  server-side and reports it in `errorText`

## 3. Definitions

| Term               | Definition |
| ------------------ | ---------- |
| Normalize          | `strtoupper(trim($vin))` |
| Structurally valid | Matches `^[A-HJ-NPR-Z0-9]{17}$` — 17 chars, excluding `I`, `O`, `Q` |
| Decoder            | An implementation of `AlwaysCurious\Vin\Contracts\VinDecoder` |
| Driver             | A named decoder registered on `VinManager` (built-in `nhtsa`, or a host-registered name) |
| Manager            | `AlwaysCurious\Vin\VinManager`, the `Illuminate\Support\Manager` that resolves drivers |
| Enabled gate       | The `vin.enabled` config flag checked before any work |

## 4. Requirements

### 4.1 Normalization & validation

**[VIN-001]** `lookup()`, `tryLookup()` and `isValid()` MUST normalize the input VIN
(uppercase + trim) before validating or decoding, and the VIN handed to a decoder MUST be
the normalized form.

> **Rationale:** Users paste VINs with stray case/whitespace; decoders and the cache key must
> see one canonical form.
> **Test:** `tests/VinLookupServiceTest.php::test_it_normalizes_and_validates_the_vin`,
> `tests/DriverManagerTest.php::test_a_custom_driver_receives_the_normalized_vin_and_model_year_hint`

---

**[VIN-002]** A VIN is valid only if it matches `^[A-HJ-NPR-Z0-9]{17}$`. `lookup()` MUST throw
`VinLookupException` for an invalid VIN and MUST NOT invoke the decoder.

> **Rationale:** Never spend a network call (or a custom provider call) on input that cannot be
> a VIN; fail fast and cheap.
> **Test:** `tests/VinLookupServiceTest.php::test_it_rejects_a_structurally_invalid_vin`,
> `tests/DriverManagerTest.php::test_validation_runs_before_a_custom_driver_is_called`

---

**[VIN-003]** `isValid()` MUST report structural validity only, performing no decoder or
network call.

> **Rationale:** Lets apps validate form input (e.g. return 422) without any I/O.
> **Test:** `tests/VinLookupServiceTest.php::test_is_valid_checks_structure_without_hitting_the_network`

### 4.2 Enabled gate

**[VIN-004]** When `vin.enabled` is `false`, `lookup()` MUST throw
`VinLookupException::lookupDisabled()`, `tryLookup()` MUST return `null`, and neither MUST
invoke the decoder or perform any network call. The gate MUST be read per lookup, so toggling
`vin.enabled` at runtime takes effect on the next call without a manager reset.

> **Rationale:** A single master switch must guarantee *no* outbound traffic — useful for
> incident response, cost control, or offline environments — and must respond to a live toggle.
> **Test:** `tests/VinLookupServiceTest.php::test_lookup_throws_when_decoding_is_disabled`,
> `tests/VinLookupServiceTest.php::test_try_lookup_returns_null_without_calling_the_api_when_disabled`,
> `tests/DriverManagerTest.php::test_the_enabled_gate_short_circuits_a_custom_driver`

### 4.3 Failure handling

**[VIN-005]** `tryLookup()` MUST return `null` for any failure that `lookup()` would throw
(invalid VIN, disabled gate, decoder/transport failure, unusable response).

> **Rationale:** Gives display paths a total, non-throwing variant with a single null contract.
> **Test:** `tests/VinLookupServiceTest.php::test_try_lookup_returns_null_on_failure`

### 4.4 Caching

**[VIN-006]** A successful decode MUST be cached and reused within the same
`vin.cache.version`; a cache hit MUST NOT invoke the decoder again.

> **Rationale:** A VIN's decode is immutable, so a repeat decode is wasted work regardless of
> which provider is bound. Caching wraps the decoder, not the reverse.
> **Test:** `tests/VinLookupServiceTest.php::test_it_caches_a_decoded_vin_and_reuses_it_within_a_version`,
> `tests/DriverManagerTest.php::test_caching_wraps_a_custom_driver`

---

**[VIN-007]** Bumping `vin.cache.version` MUST bypass every previously cached decode, without a
store-wide cache flush, and MUST take effect on the next lookup at runtime.

> **Rationale:** When NHTSA corrects data, or the stored shape changes, hosts need one-knob
> invalidation that doesn't evict unrelated cache entries.
> **Test:** `tests/VinLookupServiceTest.php::test_bumping_the_cache_version_bypasses_the_previously_cached_vin`

---

**[VIN-017]** Caching MUST use the cache store named by `vin.cache.store`; when it is `null`
the application's default store MUST be used.

> **Rationale:** Hosts may want VIN decodes in a dedicated/persistent store separate from their
> default cache.
> **Test:** `tests/DriverManagerTest.php::test_caching_uses_the_configured_cache_store`

### 4.5 Driver system & decoder seam

**[VIN-008]** The active decoder MUST be resolved through the `VinManager` driver system. The
default driver is selected by `vin.driver` and defaults to `nhtsa`, which resolves to
`Decoders\NhtsaVinDecoder`. `VinManager` MUST wrap the resolved decoder in a `VinLookupService`
so validation, the enabled gate and caching apply to it.

> **Rationale:** Drivers are swappable and selectable by name without touching
> `VinLookupService`; the box works out of the box with NHTSA.
> **Test:** `tests/DriverManagerTest.php::test_the_default_driver_is_the_nhtsa_decoder`

---

**[VIN-009]** A custom driver MUST be able to replace the default (with no NHTSA HTTP call) and
MUST receive the already-normalized, already-validated VIN plus the model-year hint. Validation
(VIN-002), the enabled gate (VIN-004) and caching (VIN-006) MUST wrap every driver equally — a
decoder MUST NOT be able to bypass them.

> **Rationale:** A custom provider implements only the lookup + mapping and inherits validation,
> gating and caching for free — it must not be able to bypass them.
> **Test:** `tests/DriverManagerTest.php::test_a_custom_driver_replaces_nhtsa_without_any_http_call`,
> `tests/DriverManagerTest.php::test_a_custom_driver_receives_the_normalized_vin_and_model_year_hint`,
> `tests/DriverManagerTest.php::test_validation_runs_before_a_custom_driver_is_called`,
> `tests/DriverManagerTest.php::test_caching_wraps_a_custom_driver`

---

**[VIN-013]** `vin.driver` MUST select the default driver used by `lookup()`, `tryLookup()`,
`isValid()` and `app(VinLookupService::class)`. `VinManager::using($name)` MUST perform a
lookup through the named driver **without** changing the default driver.

> **Rationale:** One env var (`VIN_DRIVER`) picks the provider app-wide; `using()` allows a
> per-call override for mixed-provider apps.
> **Test:** `tests/DriverManagerTest.php::test_using_selects_a_named_driver_without_changing_the_default`

---

**[VIN-014]** A decoder registered via `VinManager::extend($name, fn ($app) => $decoder)` MUST
be selectable by `$name` (through `vin.driver` or `using($name)`) and MUST be wrapped with the
same validation, gate and caching as the default driver.

> **Rationale:** The idiomatic Laravel extension point; a host registers its provider once in a
> service provider and selects it by name.
> **Test:** `tests/DriverManagerTest.php::test_an_extended_driver_is_selectable_by_name`,
> `tests/DriverManagerTest.php::test_caching_wraps_a_custom_driver`

---

**[VIN-015]** The `Vin` facade MUST resolve to `VinManager` and expose `lookup`, `tryLookup`,
`isValid`, `using`, `extend`, `getDefaultDriver` and `forgetDrivers`.

> **Rationale:** A first-class facade is the primary consumer entry point and the driver-system
> control surface.
> **Test:** `tests/DriverManagerTest.php::test_the_facade_resolves_to_the_manager`

---

**[VIN-016]** The cache key MUST include the active driver name so decodes produced by different
drivers for the same VIN never collide.

> **Rationale:** Two providers can disagree on a VIN's decode; their cached results must be kept
> apart, and switching `vin.driver` must not serve another provider's cached value.
> **Test:** `tests/DriverManagerTest.php::test_cache_is_namespaced_per_driver`

### 4.6 Default NHTSA decoder

**[VIN-010]** `NhtsaVinDecoder` MUST request
`GET {base_url}/vehicles/decodevinvalues/{VIN}?format=json`, forwarding the model-year hint as
a `modelyear` query parameter when provided, and map the flat `Results.0` row into a
`VehicleData`. `base_url` comes from `vin.decoders.nhtsa.base_url`.

> **Rationale:** The `DecodeVinValues` endpoint returns one flat row; the model-year hint
> improves NHTSA's accuracy.
> **Test:** `tests/VinLookupServiceTest.php::test_it_decodes_a_vin_into_vehicle_data`,
> `tests/VinLookupServiceTest.php::test_it_passes_the_model_year_hint_to_the_api`

---

**[VIN-011]** A failed NHTSA HTTP response MUST surface as `VinLookupException::requestFailed()`
via `lookup()` (and therefore `null` via `tryLookup()` per VIN-005).

> **Rationale:** Transport/HTTP failures are mapped to the package's own exception type, never
> leaked as a raw `RequestException`.
> **Test:** `tests/VinLookupServiceTest.php::test_it_throws_when_the_api_returns_an_error_status`

---

**[VIN-012]** The NHTSA `ErrorCode` field (a comma-separated list, e.g. `"0,12"`) MUST be
reduced to its first segment as the primary decode status on `VehicleData::$errorCode`.

> **Rationale:** Only the first code is the primary status; the rest are secondary flags. `0`
> means a clean decode.
> **Test:** `tests/VinLookupServiceTest.php::test_it_decodes_a_vin_into_vehicle_data`

## 5. Invariants

- **INV-1:** The cache key is
  `vin:v{cache.version}:{driver}:{normalized VIN}:{modelYear|'auto'}` — version, driver, VIN
  and model-year hint all participate, so different versions, drivers or hints never collide.
  (Guarded by VIN-006 / VIN-007 / VIN-016.)
- **INV-2:** Any VIN reaching `VinDecoder::decode()` has already been normalized (per VIN-001)
  and structurally validated (per VIN-002).
- **INV-3:** Validation, the enabled gate and caching are applied by the `VinLookupService` that
  `VinManager` builds around every driver, never by a decoder — so they hold identically for the
  default and every custom driver. (VIN-009.)
- **INV-4:** `VinManager` caches decoder instances (per the `Manager` contract), but builds a
  fresh `VinLookupService` per lookup that re-reads the gate and cache config — so runtime
  changes to `vin.enabled` and `vin.cache.version` take effect without `forgetDrivers()`, while
  driver-level config (`vin.decoders.*`) is captured at first resolution and refreshed via
  `Vin::forgetDrivers()`. (VIN-004 / VIN-007.)

## 6. Acceptance Criteria

- [ ] Every MUST / MUST NOT above has a passing test carrying its `@spec VIN-NNN` docblock
- [ ] INV-1 through INV-4 are each exercised by at least one test
- [ ] No new behavior contradicts an existing requirement here
- [ ] Spec `Version:` bumped if any requirement changed

## 7. Related

- ADR: [ADR-0002 — NHTSA vPIC provider & the decoder seam](../40-adr/0002-nhtsa-vpic-provider.md)
- ADR: [ADR-0003 — Caching & config model](../40-adr/0003-caching-and-config-model.md) (superseded in part)
- ADR: [ADR-0004 — Manager driver system](../40-adr/0004-manager-driver-system.md)
- Narrative (contributor): [`../../CLAUDE.md`](../../CLAUDE.md)
- Narrative (consumer): `../../README.md`, `../../resources/boost/guidelines/core.blade.php`
