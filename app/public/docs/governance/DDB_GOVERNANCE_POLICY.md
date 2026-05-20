# DDB Governance Policy

> **Authority document** — Governance truth. Do not edit without an approved governance task.

## Purpose

Governs how changes are made to the DagjeDenBosch platform. Defines change discipline, release process, and exception handling.

## Change Discipline

### Governed changes (require a governance task)

- Any change to authority documents (`docs/` or `docs/governance/`)
- Any change to booking truth routing logic
- Any change that introduces a new `directBookable: true` for a product
- Any change to shell structure or canonical token definitions
- Any change to OMDB or Woo boundaries
- Any new provider integration

### Standard changes (require Level 1–2 review)

- Feature additions within an approved scope
- Bug fixes that do not touch governed areas
- Test additions or improvements
- CSS improvements within the design system token system

### Emergency changes

Emergency changes may bypass Level 3–4 review but must be documented in a governance task within 24 hours.

## Release Discipline

1. No release proceeds without completing the applicable review loop levels.
2. No release proceeds with unresolved NEEDS_DECISION flags.
3. Authority docs must be present and passing before a go-live release.

## Exception Handling

Exceptions to governance rules require:
1. A written exception request describing the risk and rationale
2. Sign-off from the platform owner
3. A remediation task scheduled within 30 days

## Audit Trail

All governed changes must reference the governance task in the commit message.
