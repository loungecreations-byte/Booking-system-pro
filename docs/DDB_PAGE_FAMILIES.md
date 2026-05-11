# DagjeDenBosch Page Families

## Purpose
This document defines the canonical page families of the DagjeDenBosch platform.

Rule:
Pages are not designed one by one.
Pages are designed and normalized by family.

This prevents:
- page-specific design drift
- duplicated UI logic
- CTA inconsistency
- shell inconsistency
- plugin-like visual islands

---

## 1. Overview Family

### Includes
- Activities Overview
- Spots Overview
- similar browse/list pages

### Primary journey phase
Ontdek

### Secondary transition
Plan

### Purpose
Help users:
- browse
- filter
- compare
- inspect
- optionally save or add to day

### Must do
- use a compact intro
- use a clean, contained filter bar
- use scanable cards
- maintain clear visual hierarchy
- support quick comparison
- make the next step obvious

### Must not do
- behave like a landing page
- behave like a detail page
- behave like a planner
- behave like a stacked multi-tool
- overload cards with detail-level information
- overload the right side with secondary interfaces

### CTA logic
#### Activities Overview
- primary: Bekijk activiteit
- secondary: Voeg toe aan dag
- tertiary: Bewaar

#### Spots Overview
- primary: Bekijk plek
- secondary: Bewaar
- contextual: Voeg toe aan dag

### Canonical structure
1. compact intro
2. filter bar
3. result grid/list
4. optional light contextual helper
5. clean ending / continuation / pagination
6. footer

Activities Overview and Spots Overview must share:
- the same shell rhythm
- the same intro/filter/result/rail proportion
- the same card image ratio
- the same CTA weighting
- the same map-helper behavior

### Component family requirements
- overview cards
- filter/form family
- subtle chips
- light helper panel only if truly useful
- clear overview CTA hierarchy

---

## 2. Detail Family

### Includes
- Spot Detail
- Product Detail

### Primary journey phase
Plan bridge

### Secondary transition
Boek

### Purpose
Help users:
- understand the selected place or product
- decide whether it fits the day
- review practical details
- see combinations
- act with the correct CTA

### Must do
- explain quickly
- contextualize clearly
- support add-to-day or booking
- show practical information cleanly
- show useful related combinations
- close with a strong next step

### Must not do
- behave like an SEO content dump
- behave like a legacy Woo page
- behave like a plugin detail page
- stack unrelated modules without hierarchy
- bury the main CTA
- show broken/raw field rendering

### CTA logic
#### Spot Detail
- primary: Voeg toe aan mijn dag
- secondary: Route / Bekijk op kaart
- tertiary: Bewaar

#### Product Detail
- primary: Boek nu or Voeg toe aan mijn dag
- secondary: Bewaar / Bekijk combinaties

### Canonical structure
1. hero
2. context strip
3. overview / summary
4. practical info
5. reviews
6. combinations / next steps
7. closing CTA
8. clean footer transition

### Component family requirements
- hero family
- context strip family
- practical info blocks
- review blocks
- combo cards
- closing CTA block

---

## 3. Execution Family

### Includes
- Planner / Plan je dag
- Planning Cart
- Checkout / Afrekenen

### Primary journey phase
Boek

### Purpose
Turn selected items into:
- a logical day
- a trustworthy summary
- a confirmed booking or request

### Must do
- summarize clearly
- keep trust high
- reduce friction
- preserve pricing truth
- preserve booking truth
- keep the user focused

### Must not do
- reintroduce discovery clutter
- duplicate pricing logic
- duplicate availability logic
- overload the screen with low-priority UI
- feel like generic Woo tables/admin

### CTA logic
#### Planner
- primary: Boek mijn dag
- secondary: Vraag offerte aan

#### Planning Cart
- primary: Verder naar afrekenen / Aanvraag afronden
- secondary: Pas planning aan

#### Checkout
- primary: Bevestig en betaal / Verstuur aanvraag
- secondary: Terug naar overzicht

### Canonical structure
#### Planner
1. command bar
2. selected day structure / timeline
3. additions / optimization
4. summary / decision bar

#### Planning Cart
1. summary of selected day
2. participants / booking context
3. totals / trust layer
4. next action CTA

#### Checkout
1. booking confirmation context
2. participant/billing details
3. trust/reassurance
4. payment/request confirmation CTA

### Component family requirements
- planner blocks
- summary family
- cart summary family
- checkout step family
- reassurance blocks
- trust-first CTA family

---

## 4. Management Family

### Includes
- Account
- Portal
- booking management pages
- partner/vendor operational views where relevant

### Primary journey phase
Beheer & upgrade

### Purpose
Help users:
- manage bookings
- review plans
- save or reopen flows
- add relevant upgrades
- complete operational tasks

### Must do
- feel structured
- feel controlled
- remain visually aligned with the platform
- surface relevant next actions
- support upgrade and continuation without clutter

### Must not do
- feel like a dead admin area
- invent a separate backend design language
- overload users with low-priority management tools
- become visually disconnected from the platform

### CTA logic
#### Account
- primary: Bekijk je planning / Voeg nog iets toe
- secondary: Beheer boeking

#### Portal
- primary: context-specific operational action
- secondary: supporting workflow action

### Canonical structure
1. overview/status layer
2. relevant action modules
3. saved/booking/order blocks
4. optional upgrade/continuation blocks
5. operational details only where needed

### Component family requirements
- account overview cards
- status cards
- management panels
- operational action bars
- saved item modules
- structured tables/panels where required

---

## 5. Experience Family

### Includes
- Tour
- route/player pages
- stop detail pages
- chapter/mission/experience flows

### Primary journey phase
Beleef

### Purpose
Help users:
- consume the experience
- navigate between stops
- understand where they are
- continue to the next step
- stay immersed without getting lost

### Must do
- prioritize route clarity
- prioritize progression clarity
- support current vs next stop
- support content/media consumption
- remain calm and immersive

### Must not do
- behave like discovery pages
- behave like booking pages
- behave like admin panels
- overload the experience with operational clutter

### CTA logic
- primary: Start route / Volgende stop
- secondary: Bekijk kaart / Bekijk route

### Canonical structure
1. current stop / chapter header
2. route/progress context
3. content/media block
4. next action / next stop
5. optional map shell

### Component family requirements
- stop header
- route progress block
- content/media block
- next-step CTA family
- map/route shell

---

## 6. Return Family

### Includes
- return touchpoints
- retention prompts
- saved/favorite revisit surfaces
- revisit/home/account follow-up modules

### Primary journey phase
Kom terug

### Purpose
Bring users back for:
- a next outing
- a repeat visit
- new places
- a continuation of saved intent

### Must do
- reconnect with prior interest
- surface relevant new ideas
- make repeat planning easy
- support saved/favorite re-entry

### Must not do
- act like generic newsletter clutter
- show random promotional content without context
- restart the user journey from zero when known context exists

### CTA logic
- primary: Plan je volgende dag
- secondary: Bekijk nieuwe plekken / Herhaal favorieten

### Canonical structure
1. reminder of prior interest
2. relevant next suggestions
3. saved/reopen actions
4. next planning CTA

### Component family requirements
- saved-item cards
- revisit CTA blocks
- recommendation strips
- retention summary modules

---

## 7. Family law

A page must first belong to the correct family before it is visually polished.

If a page feels wrong, first ask:
1. Is it in the correct family?
2. Is it using the correct family structure?
3. Is it using the correct family CTA logic?
4. Is it using the correct shared component family?

If the answer is no, the fix is structural, not cosmetic.
