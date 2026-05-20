# DDB RACI

> **Authority document** — Governance truth. Do not edit without an approved governance task.

## Purpose

Ownership, accountability, and blocking rights for the DagjeDenBosch platform.

## Legend

- **R** — Responsible (does the work)
- **A** — Accountable (sign-off required)
- **C** — Consulted (input required before action)
- **I** — Informed (notified after action)

## RACI Matrix

| Area | Platform Owner | Dev Lead | Agent/Dev | External |
|------|---------------|----------|-----------|---------|
| Authority doc changes | A | R | C | I |
| Shell structure changes | A | R | R | I |
| Booking truth routing | A | A | R | I |
| Product 115 routing | A | A | C | I |
| OMDB / Woo boundary changes | A | A | R | I |
| Provider integrations | A | A | R | C |
| Design token changes | I | A | R | I |
| Release go/no-go | A | C | I | I |
| Emergency changes | A | R | R | I |

## Blocking Rights

- **Platform Owner** may block any change at any time.
- **Dev Lead** may block changes that violate booking truth, OMDB/Woo boundaries, or shell integrity.
- Any reviewer may raise a `NEEDS_DECISION` flag to block a merge.

## Escalation

Unresolved `NEEDS_DECISION` flags escalate to the Platform Owner within 24 hours.
