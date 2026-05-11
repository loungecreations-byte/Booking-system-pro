# DagjeDenBosch OMDB / Woo Boundaries

## Purpose
This document protects domain truth and execution truth while design, UX, template, and component refactors are performed.

Rule:
UI may present and orchestrate.
UI may not redefine domain or commercial truth.

Companion booking truth specs:
- `docs/DDB_PARTICIPANTS_TRUTH.md`
- `docs/DDB_AVAILABILITY_TRUTH.md`
- `docs/DDB_PROVIDER_INTEGRATION_TRUTH.md`

---

## 1. The two protected backend truths

### 1.1 OMDB = domain truth
OMDB is the source of meaning and structure.

OMDB defines:
- products
- segments
- vendors
- metadata
- arrangement/combi structure
- pricing definitions
- availability definitions
- planning relationships
- domain semantics

OMDB is not the final execution layer.

### 1.2 WooCommerce + booking = commercial execution truth
WooCommerce / booking defines:
- final price
- VAT
- totals
- cart truth
- checkout truth
- order truth
- real-time availability
- bookable truth

Woo is the final execution layer.

---

## 2. What UI may do

UI may:
- display OMDB-backed fields
- display Woo-backed prices and booking states
- orchestrate user flow between discover, plan, and book
- summarize selected items
- visually group arrangements and combinations
- show contextual recommendations
- help users move from one phase to the next

UI may not:
- invent or reinterpret domain semantics
- compute commercial truth independently
- replace real-time truth with guessed logic

---

## 3. What must stay in OMDB

OMDB-only truths include:
- product meaning
- vendor meaning
- segment meaning
- arrangement/combi relationship meaning
- domain-level planning relationships
- pricing definitions as domain metadata
- availability definitions as planning/domain metadata
- metadata semantics

### Important rule
If an OMDB-backed field is ugly or broken in the UI:
- fix the rendering layer first
- do not casually change the field meaning

---

## 4. What must stay in Woo / booking

Woo-only truths include:
- final price amounts
- VAT-inclusive/exclusive handling
- total calculations
- cart totals
- checkout totals
- final bookability
- final order state
- real-time availability state

### Important rule
If a UI needs a price:
- prefer Woo final truth
- do not recompute the amount in templates/cards/JS
- do not treat OMDB price definitions as final customer-facing totals unless explicitly resolved through the proper execution layer

---

## 5. Common mistakes that are forbidden

### 5.1 Pricing duplication
Forbidden:
- multiplying values in templates/cards just to “show total”
- using OMDB pricing definitions as final price truth
- inventing fallback customer prices in JS
- manually recreating VAT or discount arithmetic in UI code

### 5.2 Availability duplication
Forbidden:
- showing “available” based only on static metadata
- treating OMDB definitions as real-time truth
- using front-end logic to claim bookability without Woo/booking confirmation

### 5.3 Domain leakage into UI
Forbidden:
- page templates or JS deciding what a domain field means
- silently renaming domain concepts in the rendering layer
- hardcoding reinterpretations of OMDB fields into cards or detail pages

---

## 6. Safe adaptation rule

If legacy output or ugly structure exists:
- preserve domain meaning
- preserve execution truth
- use adapters or presenters where needed
- transform output safely without breaking contracts

### Safe adapter examples
- formatting raw opening-hours data for display
- mapping OMDB metadata to a presentational label
- turning Woo totals into a calm summary card

### Unsafe examples
- changing OMDB meaning because the UI is awkward
- replacing Woo total logic with UI-computed totals
- “faking” availability in overview cards

---

## 7. Page-specific boundary rules

### 7.1 Overview pages
May:
- show discovery-friendly summaries
- support add-to-day orchestration
- show contextual labels

May not:
- display invented final totals
- display invented real-time availability truth
- compute planner logic locally

### 7.2 Detail pages
May:
- explain the place/product
- show practical data
- show add-to-day or booking CTA
- show context and combinations

May not:
- invent commercial truth
- treat domain definitions as final totals
- make final booking promises without execution truth

### 7.3 Planner
May:
- orchestrate selections
- summarize
- optimize sequence
- hand off to booking

May not:
- replace Woo as final price truth
- replace Woo/booking as availability truth
- change OMDB meaning

### 7.4 Planning Cart / Checkout
Must:
- trust Woo final truth
- remain low-friction and accurate

May not:
- reinterpret domain meaning
- recalculate totals in ad-hoc UI code

### 7.5 Account / Portal
May:
- show saved data
- show booking state
- show operational information

May not:
- invent new business state meanings
- override canonical booking truth

### 7.6 Tour
May:
- use domain-linked experience data
- guide the user through route/progression

May not:
- invent booking or availability truth
- act as an alternative planning or checkout engine

---

## 8. High-risk zones

Treat these areas as high-risk until fully read and understood:
- `mu-plugins/`
- planner domain files
- pricing-related PHP
- pricing-related JS
- Woo single product templates
- add-to-day handoff logic
- cart/checkout templates
- anything referencing:
  - price
  - total
  - tax
  - VAT
  - availability
  - capacity
  - booking state

---

## 9. Required review triggers

Stop and escalate for human review if a task requires:
- changing OMDB field meaning
- changing OMDB structure/semantics
- changing Woo pricing execution
- changing VAT logic
- changing order/cart truth
- changing real-time availability logic
- changing add-to-day contract structure
- changing planner domain behavior
- changing booking finalization logic

---

## 10. Safe implementation law

Before implementing a UI or template change, ask:
1. Is this visual only?
2. Is this presentational only?
3. Does this change domain meaning?
4. Does this change price truth?
5. Does this change availability truth?
6. Does this change planner execution behavior?

If the answer to 3, 4, 5, or 6 is yes:
- stop
- isolate the risk
- review before continuing

---

## 11. Final law

OMDB defines what things mean.  
Woo defines what is finally true for commerce.  
The UI may guide, summarize, and present — but it may never casually become the place where domain truth or commercial truth is redefined.