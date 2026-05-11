# Board MVP Audit — 2026-04-11

## Executive Summary

DagjeDenBosch has a strong target architecture and a clear premium platform constitution.

The central board finding is:

- strategy and page-family governance are stronger than runtime normalization
- the platform is viable as a controlled Monday MVP
- it is not yet credible as a fully normalized premium one-product platform

As of 2026-04-11, the highest-impact CSOT defect was reduced:

- public frontend pages now resolve to one visual owner: `ddb-core-ui/core-ui.php`
- the MU design bridge remains for orchestration/compatibility only
- the MU bridge no longer emits competing public stylesheet truth on standard public routes when `ddb-core-ui` is active

## TOGAF Assessment

### Business Architecture

Status: strong target, medium execution maturity

Strengths:
- clear journey phases
- clear page-family roles
- clear CTA hierarchy doctrine

Weaknesses:
- some live pages still carry mixed-phase behavior
- execution surfaces still inherit discovery clutter in places

### Data Architecture

Status: conceptually correct, operationally fragile

Strengths:
- OMDB domain truth is explicitly protected
- WooCommerce is explicitly protected as execution truth

Weaknesses:
- planner/product/cart handoff remains high-risk
- orchestration layers still mediate too many truth transitions

### Application Architecture

Status: feature-rich, too coupled

Strengths:
- modular domain footprint
- explicit planner, product-overview, partner-program, and portal slices

Weaknesses:
- `booking-pro-module` remains a broad orchestration monolith
- Elementor + WooCommerce + custom planner + MU adapters still create high regression pressure
- compatibility bridges still exist where canonical runtime ownership should eventually be singular

### Technology Architecture

Status: practical for MVP, not yet elegant

Strengths:
- known stack
- local deployability
- stable enough for controlled release

Weaknesses:
- active theme shell still depends on third-party theme substrate
- CSS/runtime layering remains denser than constitutionally desired

## CSOT Assessment

### Design CSOT

Target:
- `ddb-core-ui/core-ui.php`

Current:
- `ddb-core-ui` is the public design owner
- `ddb-core-design-system.php` remains as compatibility/orchestration support

Board note:
- this is materially better than prior dual-owner runtime behavior
- the remaining bridge should be treated as transitional technical debt

### Domain CSOT

Target:
- OMDB

Current:
- largely respected
- still vulnerable where presentation/orchestration adapters are overly complex

### Execution / Commerce CSOT

Target:
- WooCommerce + booking layer

Current:
- broadly respected
- still high-risk across planner -> cart -> checkout continuity

## MVP Risk Register

### Red Risks

1. Planner continuity regressions
- drag/drop, clear-plan, prefill locks, and booking handoff have recently required runtime stabilization

2. Product-detail commercial continuity
- product planner and product-detail rendering recently required recovery work

3. Runtime coupling
- multiple orchestration layers mean innocuous fixes can still cause cross-flow regressions

### Amber Risks

1. Overview-family visual drift
- Activities and Spots are closer, but not fully normalized

2. Tour fallback quality
- immersive experience is not yet premium-grade under degraded map/tile conditions

3. Portal/account alignment
- operationally useful, not yet fully canonical in polish or hierarchy

### Green Signals

1. Constitution and component canon are strong
2. One public design owner is now re-established
3. Core commercial boundaries are understood by the codebase and docs

## Monday Recommendation

### Go Condition

Go as a controlled MVP if:
- scope is positioned as an MVP
- no new architectural features are introduced before launch
- only blocker fixes and regression-safe polish are allowed
- daily regression checks cover product -> planner, product -> cart, planner -> checkout

### No-Go Condition

No-go if Monday is positioned as:
- final premium platform launch
- fully normalized cross-family product system
- broad release of every secondary surface with equal confidence

## Mandatory Focus Between Now And Monday

1. Freeze design/runtime ownership
- no return to dual public stylesheet truth

2. Freeze planner business behavior
- only regression-safe bugfixes

3. Protect product-detail continuity
- product planner and handoff routes must remain stable

4. Verify execution journey twice daily
- product detail
- planner
- cart
- checkout

5. Defer ambition on secondary polish
- portal
- extended tour refinement
- non-critical visual experimentation

## 30 / 60 / 90 Day Normalization

### 30 Days
- eliminate emergency compatibility paths no longer needed
- simplify planner handoff seams
- normalize Activities/Spots overview family further

### 60 Days
- reduce MU orchestration footprint
- narrow `booking-pro-module` runtime responsibilities
- harden execution-family contract testing

### 90 Days
- complete single-runtime design ownership
- reduce third-party theme dependency in public shell behavior
- finish family-level premium normalization across all core surfaces
