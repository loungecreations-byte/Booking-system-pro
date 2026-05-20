# DDB Directienotitie TOGAF Governance

> **Authority document** — Governance truth. Do not edit without an approved governance task.

## Purpose

Formal governance mandate and directie-level launch discipline for the DagjeDenBosch platform, aligned with TOGAF architecture governance principles.

## Mandate

The DagjeDenBosch platform operates under a formal architecture governance framework. No significant architectural change may be made without approval from the Platform Owner (acting as Architecture Board chair).

## TOGAF Alignment

The DDB governance model adopts the following TOGAF concepts:

### Architecture Governance

- All platform truths (OMDB, Woo, Booking) are governed as architecture domains.
- Changes to any truth boundary require Architecture Board approval.
- The governance dashboard (`sbdp_design_backend`) serves as the Architecture Compliance dashboard.

### Compliance

- All changes must comply with the authority documents.
- Non-compliance is recorded as a NEEDS_DECISION flag.
- Compliance violations block release gates.

### Change Management

Changes follow the TOGAF Architecture Change Management process:
1. Change request raised with scope, rationale, and risk assessment
2. Impact assessment against all governed areas
3. Architecture Board review and approval
4. Implementation with governance task reference
5. Post-implementation review

## Directie-Level Launch Discipline

Before any go-live launch:

1. Platform Owner confirms all release gates pass.
2. Authority docs are complete and validated.
3. Regression checklist is signed off.
4. No NEEDS_DECISION flags are open.
5. Formal launch sign-off is recorded.

## Risk Register

High-risk areas requiring directie attention:

| Risk | Mitigation |
|------|-----------|
| Provider booking truth bypass | BookingModeService enforced; product 115 always `supplier_confirmation` |
| Woo price override | OMDB/Woo boundaries enforced; planner never sets Woo price |
| Shell drift | Runtime template scan in governance cockpit |
| Data loss in booking flow | Booking truth runtime validated at write time |
