# Definition of Done

**Status:** Accepted
**Version:** 1.0.0
**Last updated:** 2026-07-06

---

Every PR merged to `main` MUST satisfy the applicable items below. Reviewers verify
compliance before approving. This package is a library (no application, database, or
deployed runtime), so items that would only apply to an app are intentionally absent.

## Code Quality

- [ ] `vendor/bin/pint --test` passes (run `composer lint` to auto-fix first)
- [ ] `composer test` (PHPUnit) passes with **no failures and no warnings** — the suite runs
      with `failOnWarning="true"`
- [ ] Public API changes keep the supported floor: PHP `^8.3`, `illuminate/*`
      `^11.0|^12.0|^13.0`, Testbench `^9.0|^10.0|^11.0` (bump `composer.json` if you must raise it)

## Testing

- [ ] Every new feature or bug fix has at least one new test
- [ ] Tests hit no live network — the NHTSA host is always `Http::fake()`d
- [ ] Any test enforcing a normative requirement carries a `@spec VIN-NNN` docblock

## Spec Compliance

- [ ] If the PR changes product behavior (anything in `src/` or `config/`), the relevant
      requirement(s) in [`../10-domains/vin-lookup.md`](../10-domains/vin-lookup.md) are updated,
      **or** the PR title contains `[spec-exempt]` with a one-line justification in the body
- [ ] No new behavior contradicts an existing MUST / MUST NOT in `/specs`
- [ ] If a spec changed: its `Version:` was bumped and the change is noted in the PR description
- [ ] A material design decision is captured as an ADR under [`../40-adr/`](../40-adr/)

> **`[spec-exempt]` escape hatch** — PRs touching only non-product code (CI, `.gitattributes`,
> formatting config, README/CLAUDE narrative, the `specs/`/`resources/boost` docs themselves)
> may opt out of the spec-update requirement by putting `[spec-exempt]` in the PR title with a
> written reason.

## Documentation

- [ ] Public API / config changes are reflected in `README.md`, `config/vin.php`, and the
      consumer guideline `resources/boost/guidelines/core.blade.php`
- [ ] New env vars / config keys are documented in the README config table

## Housekeeping

- [ ] No credentials, secrets, or API keys committed (vPIC needs none — none should appear)
- [ ] New dev-only files are added to `.gitattributes` `export-ignore` so they stay out of the
      distributed package

---

> **Note — CI spec-guard is intentionally not wired up.** A path-based CI check that fails PRs
> touching `src/` without touching `specs/` presumes a team and a steady stream of feature PRs;
> for a stable single-purpose library it is friction with little payoff. The `@spec` annotations
> plus this checklist give most of the traceability value. If the package grows a companion app
> or multiple maintainers, add the guard then (see [ADR-0001](../40-adr/0001-spec-driven-development.md)).
