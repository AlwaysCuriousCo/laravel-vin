# [Domain / Feature Name] Spec

**ID prefix:** `PREFIX`
**Status:** Draft | Review | Accepted | Deprecated
**Version:** 0.1.0
**Last updated:** YYYY-MM-DD
**Owners:** @handle

---

## 1. Purpose

One paragraph. What behavior does this spec constrain, and why does it matter?

## 2. Scope

**In scope:**
- Bullet what this spec covers

**Out of scope:**
- Bullet what this spec intentionally does not cover (link to the spec/ADR that does)

## 3. Definitions

| Term | Definition |
| ---- | ---------- |
| term | definition |

## 4. Requirements

### 4.1 [Sub-area]

**[PREFIX-001]** … MUST …

> **Rationale:** Why this constraint exists.
> **Test:** `tests/SomeTest.php::test_method`

---

**[PREFIX-002]** … MUST NOT …

> **Rationale:** …
> **Test:** `tests/SomeTest.php::test_method`

---

**[PREFIX-003]** … SHOULD …

> **Rationale:** …
> **Test:** N/A (advisory)

## 5. Invariants

State that must always hold, regardless of operation:

- **INV-1:** …
- **INV-2:** …

## 6. Acceptance Criteria

Checklist a reviewer uses before approving a PR that touches this spec area:

- [ ] Every MUST / MUST NOT requirement has a corresponding passing test carrying its `@spec` ID
- [ ] Invariants are exercised by at least one test
- [ ] No new behavior contradicts an existing requirement in this spec
- [ ] Spec `Version:` bumped if any requirement changed

## 7. Related

- ADR: [link to ADR]
- Spec: [link to related spec]
- Narrative: [link to README section / CLAUDE.md]
