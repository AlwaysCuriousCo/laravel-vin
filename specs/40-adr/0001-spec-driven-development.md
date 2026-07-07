# ADR-0001: Adopt (right-sized) Spec-Driven Development

**Status:** Accepted
**Date:** 2026-07-06
**Deciders:** Maintainers

---

## Context

The package's behavior — validation rules, the enabled gate, cache invalidation semantics,
the decoder seam — is the kind of thing that can be silently broken by a well-meaning
refactor without any test author noticing the *intent* was a requirement, not an accident.
The README and `CLAUDE.md` narrate behavior but define nothing enforceable.

We evaluated a general "spec-driven `/docs` + `/specs`" scaffold designed for applications.
It assumes an app: OpenAPI contracts, data-model docs, operational runbooks, availability
NFRs, and a CI spec-guard gating a stream of feature PRs. This project is a ~250-line
library with no HTTP surface, no database, and no deployed runtime — most of that scaffold
would be created empty and rot.

## Decision

Adopt a **normative `/specs` directory**, but right-size it to a library:

- **Keep:** `specs/README.md`, `_templates/feature-spec.md`, `00-overview/definition-of-done.md`,
  one domain spec (`10-domains/vin-lookup.md`) using `[VIN-NNN]` IDs with RFC-2119 language,
  and ADRs under `40-adr/`.
- **Trace tests to specs** via a `@spec VIN-NNN` docblock on each enforcing test.
- **Drop (for now):** `20-apis/openapi.yaml` (no HTTP API — the contract is the PHP method
  surface + the upstream vPIC response shape), `30-nfr/` (a library has no SLO), a separate
  `/docs` tree (README + CLAUDE.md already serve the informative role), and the **CI
  spec-guard** + PR template (team-process controls with poor cost/benefit for a stable
  single-maintainer library).

The informative/normative split still holds: `README.md` (consumer) and `CLAUDE.md`
(contributor) are informative and link to spec IDs rather than restating requirements;
`/specs` is normative.

## Alternatives Considered

**Full application scaffold as-written:** maximizes cross-repo consistency but commits many
permanently-empty, app-shaped directories to a small library. Rejected as disproportionate;
revisit if a companion app appears.

**Decisions/ADRs only, no requirement spec:** lighter, but loses the `code → test → spec`
traceability that is this package's best-fit benefit (the suite already reads like a spec).
Rejected as leaving value on the table.

**Keep everything in `CLAUDE.md`/`README.md`:** simple but unenforceable; narrative drifts
from behavior. Rejected.

## Consequences

**Positive:** requirements are captured next to the code and tests that implement them;
`@spec` makes intent greppable; new contributors get one source of truth for constraints.

**Negative:** a small standing cost to keep `vin-lookup.md` and its version in step with the
code; contributors must learn the `[spec-exempt]` convention.

## Review

Revisit if: the package gains an HTTP surface or database (add `20-apis/` / model docs), a
second maintainer or a steady PR flow appears (add the CI spec-guard), or `vin-lookup.md`
repeatedly lags the code it describes (tighten the DoD).
