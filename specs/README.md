# /specs — Normative Specifications

This directory contains the **normative** specifications for `alwayscurious/laravel-vin`.
Normative means enforceable: every MUST / MUST NOT / SHOULD statement here is a testable
acceptance criterion, and each is traced to a test.

## Informative vs. Normative

|                 | Informative                          | Normative (`/specs`)                  |
| --------------- | ------------------------------------ | ------------------------------------- |
| **Purpose**     | Explain, guide, narrate              | Constrain, define acceptance criteria |
| **Language**    | Prose, examples                      | MUST / MUST NOT / SHOULD / SHOULD NOT |
| **Lives in**    | `README.md`, `CLAUDE.md`             | `specs/`                              |
| **Enforced by** | Convention                           | Tests (`@spec` traceability)          |

Rule of thumb: *if a statement can be violated without anyone noticing until production,
it belongs in `/specs`.*

> **This package has no separate `/docs` tree by design.** For a library this small the
> informative layer is already covered: `README.md` is the public/consumer narrative and
> [`../CLAUDE.md`](../CLAUDE.md) is the contributor narrative. A parallel `docs/` tree would
> just duplicate them. `/specs` is the *net-new* normative layer. See
> [ADR-0001](40-adr/0001-spec-driven-development.md) for why the scaffold is deliberately
> trimmed (no `20-apis/`, `30-nfr/`, `docs/`, or CI spec-guard).

## Directory Layout

```
specs/
├── README.md                         ← you are here
├── _templates/
│   └── feature-spec.md               ← copy when adding a new domain spec
├── 00-overview/
│   └── definition-of-done.md         ← the PR checklist every merge must satisfy
├── 10-domains/
│   └── vin-lookup.md                 ← VIN decode requirements [VIN-NNN]
└── 40-adr/                            ← architecture decision records
    ├── 0001-spec-driven-development.md
    ├── 0002-nhtsa-vpic-provider.md
    ├── 0003-caching-and-config-model.md   (decisions 3-4 superseded by 0004)
    └── 0004-manager-driver-system.md
```

## Test ↔ Spec Traceability

Tests that enforce a normative requirement carry a `@spec` docblock annotation naming the
requirement ID(s) they cover. This makes the chain **code → test → spec** navigable in both
directions — grep `@spec VIN-004` to find every test guarding that requirement.

```php
/**
 * @spec VIN-002
 */
public function test_it_rejects_a_structurally_invalid_vin(): void { /* ... */ }
```

`@spec` is a plain doc annotation; PHPUnit ignores it, so it is safe under this suite's
`failOnWarning="true"`. Run `composer test` to confirm the guarded behavior still holds.

## Working with specs

**Reading:** requirements use the `[VIN-NNN]` identifier format. IDs are sequential within
their prefix and are **never reused or renumbered** once shipped.

**Adding a new domain spec:**
1. Copy [`_templates/feature-spec.md`](_templates/feature-spec.md).
2. Pick a new `PREFIX`, assign sequential IDs (`PREFIX-001`, …).
3. Write MUST / MUST NOT statements — avoid ambiguous "should" where a test can enforce it.
4. Add or annotate the test(s) with `@spec PREFIX-NNN`.
5. Bump the spec's `Version:` and note the change in your PR.

**Changing behavior:** update the spec **first** (normative source of truth), then the code,
tests, and the informative narrative (`README.md` / `CLAUDE.md`) to match.

**Non-product-only changes** (tooling, CI, formatting, docs) don't need a spec change — see
the `[spec-exempt]` note in [00-overview/definition-of-done.md](00-overview/definition-of-done.md).
