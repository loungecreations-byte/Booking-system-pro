# DagjeDenBosch CTA Map

## Purpose
This document defines the canonical CTA hierarchy for the DagjeDenBosch platform.

Rule:
Each page family has one primary CTA.
Secondary actions may support the journey.
No page may turn every action into a primary action.

---

## 1. CTA hierarchy law

- Primary CTA: the main next step for the page family
- Secondary CTA: the supportive next step
- Tertiary CTA: quiet utility or reassurance

Primary CTA must always be visually strongest.
Secondary CTA must never compete equally.
Tertiary CTA must stay quiet.

---

## 2. Overview family CTA map

### Activities Overview
- Primary: Bekijk activiteit
- Secondary: Voeg toe aan dag
- Tertiary: Bewaar

### Spots Overview
- Primary: Bekijk plek
- Secondary: Bewaar
- Contextual: Voeg toe aan dag

### Overview CTA rule
- Overview pages favor inspect and compare first
- Add-to-day is contextual, not dominant
- Booking is not the primary discovery CTA

---

## 3. Detail family CTA map

### Spot Detail
- Primary: Voeg toe aan mijn dag
- Secondary: Route / Bekijk op kaart
- Tertiary: Bewaar

### Product Detail
- Primary: Boek nu
- Secondary: Voeg toe aan mijn dag
- Tertiary: Bewaar / Bekijk combinaties

### Detail CTA rule
- Detail pages bridge discovery to planning or booking
- Practical context must support the primary CTA
- No SEO wall or clutter may weaken the CTA

---

## 4. Execution family CTA map

### Planner
- Primary: Boek mijn dag
- Secondary: Vraag offerte aan

### Planning Cart
- Primary: Verder naar afrekenen
- Secondary: Pas planning aan

### Checkout
- Primary: Bevestig en betaal
- Secondary: Terug naar overzicht

### Execution CTA rule
- Execution pages favor trust and completion
- Discovery CTAs must not reappear as equal-weight actions
- WooCommerce final truth remains authoritative

---

## 5. Management and experience CTA map

### Account
- Primary: Bekijk je planning
- Secondary: Voeg nog iets toe

### Portal
- Primary: Beheer profiel / beheer aanbod
- Secondary: Bekijk aanvragen

### Tour
- Primary: Start route
- Secondary: Volgende stop

### Management rule
- Management and experience pages keep their own journey logic
- They must still live inside the shared platform shell

---

## 6. CTA map law

- One page family, one CTA role
- Overview pages inspect first
- Detail pages decide next
- Execution pages complete the flow
- Management pages organize and upgrade
- Experience pages guide and continue

---

## 7. Provider Availability CTA Rules

Provider availability can influence customer guidance, but it must not override runtime route intent.

- If provider status is request-only, the primary CTA must route to request/quote, not direct checkout.
- If external availability is `available:true` without a proven hold and booking path, CTA copy may say the slot appears available, but the action remains request/quote.
- If external availability is `unavailable`, CTA copy should guide the customer to another time or an alternative request.
- If external availability is `unknown` or `error`, CTA copy should explain that manual confirmation is required and keep the request/quote path available.

For DDB product `115` (`E-Chopper tour`) with Eliio/Eropuitje:

- Do not show a primary direct checkout CTA based on Eliio availability.
- Use request/offerte CTA hierarchy until a separate approved task changes product capability.
- `directBookable:true` is forbidden for this product under the current governance state.
