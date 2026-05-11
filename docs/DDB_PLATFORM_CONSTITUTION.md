# DagjeDenBosch Platform Constitution

## Purpose
This document freezes the platform truth before implementation.

DagjeDenBosch must behave like **one premium product ecosystem**, not a collection of disconnected templates, plugins, page styles, or isolated module interfaces.

This constitution defines:
- platform purpose
- journey phases
- CSOT boundaries
- domain boundaries
- shell rules
- canonical page roles
- canonical CTA logic
- canonical component families
- design direction
- execution-layer rules
- implementation law
- completion standards

Companion truth specs for booking execution:
- `docs/DDB_PARTICIPANTS_TRUTH.md`
- `docs/DDB_AVAILABILITY_TRUTH.md`
- `docs/DDB_PROVIDER_INTEGRATION_TRUTH.md`

---

## 1. Core platform truth

DagjeDenBosch is a platform for:
1. discovering places and activities
2. saving interesting options
3. planning a logical day
4. booking or requesting
5. managing and upgrading bookings
6. experiencing tours
7. returning later for a new experience

The platform must feel:
- premium
- calm
- mobile-first
- OLED-strong in dark mode
- truly light in light mode
- coherent across all page families
- commercially clear
- planning-aware
- operationally reliable
- consistent from first discovery to post-booking experience

The platform must never feel like:
- separate plugins stitched together
- a WordPress site with inconsistent modules
- multiple competing visual systems
- a mix of editorial pages, admin panels, and booking widgets without one product logic

### Premium visual baseline
The premium baseline is:
- `#000000` page canvas
- near-black surfaces
- restrained gold accenting
- Quattrocento for display typography
- Quattrocento Sans for UI/body
- one premium button family
- one premium card/surface family

No public page may introduce a separate visual baseline.

---

## 2. The 3 truths that must never be mixed

### 2.1 Design CSOT
The visual source of truth is:

- one active design-system runtime for token output and theme mapping (currently `ddb-core-ui/core-ui.php`)
- the DagjeDenBosch Unified Design System for shared components, states, patterns, responsive behavior, and dark/light logic
- compatibility bridges may exist in MU/bootstrap layers, but they may not emit competing public stylesheet truth when `ddb-core-ui` is active

This layer owns:
- colors
- surfaces
- typography
- spacing
- radii
- shadows
- buttons
- cards
- forms
- tabs
- summary bars
- shared shells
- interaction states
- responsive rules
- motion rules
- CTA hierarchy styling

### 2.2 Domain CSOT
The domain source of truth is:

- OMDB

OMDB defines:
- products
- segments
- vendors
- metadata
- arrangement/combi structure
- pricing definitions
- availability definitions
- planning relationships
- domain meaning
- product-to-place / place-to-activity relationships where applicable

OMDB does **not** define final commercial truth.

### 2.3 Execution / Commerce truth
The commercial execution truth is:

- WooCommerce + booking layer

WooCommerce defines:
- final prices
- VAT
- totals
- cart
- checkout
- order truth
- real-time availability
- bookable truth
- final participant-based totals
- final booking states

UI may display and orchestrate.  
UI may **not** redefine business truth.

---

## 3. Hard system boundaries

### 3.1 What belongs in `ddb-core-design-system.php`
- token output
- dark/light theme mapping
- surface mapping
- typography mapping
- spacing/radius/shadow token mapping
- shared visual variables
- responsive token mapping
- theme-level data attributes and variable output

### 3.2 What belongs in the Unified Design System
- button family
- card family
- form/filter family
- tab family
- summary/CTA family
- map/detail family
- planner/composer UI primitives
- section shells
- review blocks
- practical info blocks
- status chips and badges
- interaction states
- dark/light rules
- responsive component behavior
- page-family layout patterns

### 3.3 What belongs in Elementor
- template structure
- section ordering
- content placement
- display conditions
- template assembly

Elementor may assemble.  
Elementor may **not** become a second design system.

### 3.4 What belongs in OMDB
- domain structure
- product/segment/vendor relationships
- arrangement/combi relationships
- metadata
- pricing definitions
- availability definitions
- planning/domain meaning

### 3.5 What belongs in WooCommerce / booking
- final price execution
- VAT
- totals
- cart/order/checkout truth
- real-time availability
- bookable truth

### 3.6 What must never be duplicated in page-level UI logic
- pricing arithmetic
- tax calculation
- availability truth
- booking-state truth
- domain reinterpretation of OMDB fields
- planner business logic
- cart truth
- checkout truth

---

## 4. Canonical shell truth

All public pages must respect the same shell:

1. Header at top
2. Main in the middle
3. Footer at bottom

No page may break this order.

This applies to:
- homepage
- archive/overview pages
- spot detail
- product detail
- planner-related pages
- planning cart
- checkout
- account-related pages
- portal pages
- tour-related pages where applicable

Header, main, and footer must remain structurally stable across:
- theme templates
- Elementor templates
- WooCommerce templates
- plugin-driven routes/pages

No page may simulate its own shell if it is supposed to live inside the platform shell.

---

## 5. Journey model

The platform supports 7 phases:

1. Ontdek
2. Bewaar
3. Plan
4. Boek
5. Beheer & upgrade
6. Beleef
7. Kom terug

Each page must belong primarily to **one** phase.  
No page may try to do multiple primary phases at once.

A page may support a secondary transition, but must still have:
- one primary purpose
- one dominant CTA logic
- one clear next step

---

## 6. Canonical page roles

### 6.1 Homepage
**Primary phase:** Ontdek + start Plan  
**Purpose:** route users into the correct flow  
**Must do:** explain how to begin  
**Must not do:** act like a detail page, planner, and landing page all at once

### 6.2 Activities Overview
**Primary phase:** Ontdek -> Plan  
**Purpose:** browse and compare activities quickly  
**Must do:** support filtering, scanning, and light selection  
**Must not do:** become a landing page, detail page, or planner tool

### 6.3 Spots Overview
**Primary phase:** Ontdek  
**Purpose:** discover places and select one for inspection  
**Must do:** support browse -> select -> add to day  
**Must not do:** become a stacked multi-tool with full detail, planning, and alternatives all visible at once

### 6.4 Spot Detail
**Primary phase:** Plan bridge  
**Purpose:** help decide whether a place fits the day  
**Must do:** explain, contextualize, and allow add-to-day  
**Must not do:** behave like a generic SEO dump or plugin detail page

### 6.5 Product Detail
**Primary phase:** Plan -> Boek  
**Purpose:** convert interest into add-to-day or booking  
**Must do:** provide practical info, booking context, and combinations  
**Must not do:** remain legacy Woo with disconnected sections

### 6.6 Planner / Plan je dag
**Primary phase:** Plan -> Boek  
**Purpose:** turn selected items into a logical, bookable day  
**Must do:** orchestrate, optimize, and summarize  
**Must not do:** become a generic browse layer or duplicate booking truth

### 6.7 Planning Cart
**Primary phase:** Boek  
**Purpose:** confirm what has been selected before checkout  
**Must do:** show a calm, trustworthy summary of the chosen day, participants, timing, and pricing  
**Must not do:** become a discovery page, a dense admin table, or a second planner

### 6.8 Checkout / Afrekenen
**Primary phase:** Boek  
**Purpose:** complete the booking or request flow with maximum clarity and minimum friction  
**Must do:** present pricing, participant details, and payment/request steps clearly and calmly  
**Must not do:** reintroduce discovery noise, upsell clutter, or competing navigation logic

### 6.9 Account
**Primary phase:** Beheer & upgrade  
**Purpose:** manage plans, bookings, saved items, and relevant next steps  
**Must do:** provide overview, control, and context-aware upgrades or continuation  
**Must not do:** feel like a dead admin area or disconnected backend

### 6.10 Portal
**Primary phase:** Beheer & operational collaboration  
**Purpose:** support vendors, partners, or internal workflows in a controlled but still branded environment  
**Must do:** stay operationally efficient while visually aligned with the platform  
**Must not do:** invent a separate visual language or feel like a different product

### 6.11 Tour
**Primary phase:** Beleef  
**Purpose:** guide the user through the experience in a calm, navigable, story-first environment  
**Must do:** support current location, next stop, progression, and content consumption  
**Must not do:** behave like a browse page, booking page, or admin panel

### 6.12 Return / retention touchpoints
**Primary phase:** Kom terug  
**Purpose:** bring users back into the platform for a next outing, revisit, or repeat booking  
**Must do:** surface saved items, new ideas, and relevant next experiences  
**Must not do:** behave like a generic newsletter wall or random promotion layer

---

## 7. Canonical CTA law

Every page must have:
- one primary CTA
- one secondary CTA
- optional tertiary/subtle actions only where needed

Primary and secondary actions may not compete equally.

### 7.1 Homepage
- Primary CTA: Start met plannen
- Secondary CTA: Ontdek activiteiten / Ontdek plekken

### 7.2 Activities Overview
- Primary CTA: Bekijk activiteit
- Secondary CTA: Voeg toe aan dag

### 7.3 Spots Overview
- Primary CTA: Bekijk plek
- Secondary CTA: Bewaar
- Contextual CTA: Voeg toe aan dag

### 7.4 Spot Detail
- Primary CTA: Voeg toe aan mijn dag
- Secondary CTA: Route / Bekijk op kaart
- Tertiary CTA: Bewaar

### 7.5 Product Detail
- Primary CTA: Boek nu **or** Voeg toe aan mijn dag, depending on product type
- Secondary CTA: Bewaar / Bekijk combinaties

### 7.6 Planner
- Primary CTA: Boek mijn dag
- Secondary CTA: Vraag offerte aan

### 7.7 Planning Cart
- Primary CTA: Verder naar afrekenen / Aanvraag afronden
- Secondary CTA: Pas planning aan

### 7.8 Checkout
- Primary CTA: Bevestig en betaal / Verstuur aanvraag
- Secondary CTA: Terug naar overzicht

### 7.9 Account
- Primary CTA: Bekijk je planning / Voeg nog iets toe
- Secondary CTA: Beheer boeking

### 7.10 Portal
- Primary CTA: context-dependent operational action
- Secondary CTA: supporting workflow action

### 7.11 Tour
- Primary CTA: Start route / Volgende stop
- Secondary CTA: Bekijk kaart / Bekijk route

### 7.12 Return / retention
- Primary CTA: Plan je volgende dag
- Secondary CTA: Bekijk nieuwe plekken / Herhaal favorieten

### CTA visual law
- Orientation CTAs must feel lighter than action CTAs.
- “Bewaar” may never feel as strong as “Boek”.
- “Bekijk plek” may be stronger than “Voeg toe” on overview pages.
- “Voeg toe aan mijn dag” must be stronger than “Bewaar” on detail pages.
- Execution pages must not be visually dominated by browse-style actions.

---

## 8. Canonical component winners

### 8.1 Buttons
One family only:
- primary
- secondary
- ghost
- inline
- icon button

### 8.2 Cards
One family with controlled variants:
- overview cards
- detail support cards
- combo/suggestion cards
- mini-cards

### 8.3 Forms / filters
One family only:
- search
- select
- chips
- reset
- submit
- steppers

### 8.4 Tabs
One family only:
- same active state
- same spacing
- same mobile behavior

### 8.5 Summary / CTA blocks
One family only:
- bottom CTA zone
- summary bars
- detail closing CTA
- planner summary family
- cart summary family
- checkout reassurance blocks

### 8.6 Map / detail panels
One family only:
- map shell
- selected item panel
- helper block
- practical info side panel

### 8.7 Planner / booking flow blocks
One family only:
- program segments
- timeline blocks
- optimization helper blocks
- add-to-day confirmation blocks
- cart summary modules
- checkout step modules

### 8.8 Account / portal modules
One family only:
- account overview cards
- booking status cards
- saved-items modules
- management panels
- operational tables/panels
- portal action bars

### 8.9 Tour / beleven modules
One family only:
- stop header
- route progress
- current/next stop panel
- content/media block
- map/route shell
- continue-experience CTA family

---

## 9. Design direction

The platform must use:
- dark premium surfaces
- restrained gold accents
- clean spacing rhythm
- strong hierarchy
- calm overview pages
- elegant detail pages
- planning-aware but not noisy UI
- trustworthy execution layers
- immersive but controlled tour layers
- consistent mobile behavior

Avoid:
- random bright blocks
- dense utility UI
- multiple equal-weight panels
- plugin-like admin styling
- page-local visual hacks
- excessive gold/yellow accents
- checkout/cart areas that feel like generic Woo pages
- portal/account/tour layers that feel like separate products

---

## 10. Page-family design rules

### 10.1 Overview Family
Includes:
- Activities Overview
- Spots Overview
- similar browse/list pages

Must use:
- compact intro
- clean filter bar
- scanable cards
- low-noise hierarchy
- one dominant browsing surface
- minimal helper panel only if truly useful

Must not use:
- giant landing-page heroes
- detail-page density
- stacked multi-tool behavior
- multiple equal-weight side modules

### 10.2 Detail Family
Includes:
- Spot Detail
- Product Detail

Must use:
- clear hero
- context strip
- practical info
- reviews
- combinations
- closing CTA

Must not use:
- generic SEO dump behavior
- disconnected legacy modules
- broken raw field rendering
- generic Woo-style clutter

### 10.3 Execution Family
Includes:
- Planner
- Planning Cart
- Checkout

Must use:
- trust-first hierarchy
- clear summaries
- strong CTA discipline
- minimal noise
- strong continuity

Must not use:
- discovery clutter
- duplicated pricing logic
- admin-like tables unless strictly needed

### 10.4 Management Family
Includes:
- Account
- Portal

Must use:
- structured operational UI
- shared component families
- clear status/action hierarchy

Must not use:
- a separate backend product language
- disconnected design choices

### 10.5 Experience Family
Includes:
- Tour
- route/player/stop experiences

Must use:
- progression clarity
- route clarity
- strong current/next logic
- calm immersive presentation

Must not use:
- browse-page logic
- booking-page clutter
- admin-like utility surfaces

---

## 11. Execution layers that must remain coherent

The platform is not complete at discovery and planning level alone.

The following execution layers must feel like the same product family:

### 11.1 Planning Cart
Must feel like:
- the calm confirmation layer between planning and checkout
- a trustworthy summary, not a cluttered cart table

### 11.2 Checkout / Afrekenen
Must feel like:
- focused
- trustworthy
- low-friction
- commercially clear
- not visually disconnected from planner or detail pages

### 11.3 Account / Portal
Must feel like:
- structured and operational
- still clearly part of DagjeDenBosch
- efficient without becoming generic admin software

### 11.4 Tour / Beleef
Must feel like:
- a guided experience layer
- immersive but usable
- clearly distinct in purpose, but still visually related to the platform

---

## 12. Additional hard rules for execution and experience layers

### 12.1 Planning Cart
- must summarize, not distract
- must not behave like a generic Woo cart table if a calmer structured summary is possible
- must preserve final Woo pricing truth

### 12.2 Checkout / Afrekenen
- must be visually aligned with the platform
- must remain low-friction and trust-first
- must not reintroduce discover-layer clutter
- must not redefine pricing, tax, or booking truth

### 12.3 Account / Portal
- must remain operationally efficient
- must not invent a separate backend visual language
- must use shared shell, spacing, buttons, cards, and status logic where appropriate

### 12.4 Tour / Beleef
- must prioritize progression, route, and content consumption
- must not overload the experience with browse/admin/booking interactions
- must visually relate to the brand while clearly serving the “beleef” phase

---

## 13. Hard implementation law

Before page polish:
1. freeze the canon
2. stabilize shell
3. normalize primitives
4. align page families
5. run regression and safety checks

Never do page polish before primitive normalization.

Never treat page-level fixes as proof that the design system is working.

---

## 14. Today’s priority page families

The first visible premium baseline must cover:

1. Homepage
2. Activities Overview
3. Spots Overview
4. Spot Detail
5. Product Detail

The second visible alignment layer must cover:

6. Planner / Plan je dag
7. Planning Cart
8. Checkout / Afrekenen
9. Account / Portal
10. Tour / Beleef

Planner domain logic, pricing truth, and checkout execution logic must remain protected during visual/system alignment.

---

## 15. Completion standard

A platform refinement is only considered done if:
- page family role is clear
- CTA hierarchy matches the journey phase
- shared components come from the design system
- shell remains stable
- dark/light remain coherent
- mobile behavior is improved
- OMDB semantics are preserved
- Woo pricing/booking truth is preserved
- no page behaves like its own design island
- cart and checkout feel like part of the same ecosystem
- account and portal remain operational but visually aligned
- tour pages are distinct in purpose but still system-consistent

---

## 16. Final law

DagjeDenBosch must behave like one product.

If any page, module, plugin, template, or flow behaves like:
- a separate design system
- a separate product
- a separate visual language
- a separate booking truth
- a separate domain truth

then the platform is not yet normalized.
