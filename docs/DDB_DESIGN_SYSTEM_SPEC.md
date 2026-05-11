# DagjeDenBosch Design System Spec

## Purpose
This document defines the visual runtime truth for the premium DagjeDenBosch platform.

Rule:
This is not inspiration material.
This is implementation law.

If page-level CSS, Elementor styling, or plugin-level UI conflicts with this document, this document wins.

---

## 1. Core visual truth

DagjeDenBosch must look like:
- one premium product
- calm
- high-contrast
- editorial but controlled
- Apple-clean in hierarchy, not generic SaaS
- luxurious through restraint, not decoration

DagjeDenBosch must not look like:
- stitched-together plugin UI
- WooCommerce with a dark skin
- multiple page-local art directions
- generic gradients and white cards on a dark page

---

## 2. Canonical dark base

### Background truth
- base page background: `#000000`
- primary surface: near-black, not gray-blue
- raised surface: subtle charcoal only
- no white cards on dark pages
- no detached pale side panels

### Surface law
- surface hierarchy must be shallow
- most pages should use only:
  - page canvas
  - primary surface
  - raised surface
- avoid box-in-box-in-box stacking

---

## 3. Accent law

Accent is restrained gold, not loud yellow.

Allowed:
- eyebrow labels
- active chips
- route/status emphasis
- subtle selected states
- premium edge accents

Not allowed:
- gold everywhere
- multiple gold buttons competing on one page
- gold as background truth

Primary CTA may use a high-contrast premium fill.
Accent still remains gold.

---

## 4. Typography law

### Canonical font stack
- display/headlines: `Quattrocento`
- body/UI: `Quattrocento Sans`

### Rules
- headlines must feel elegant and premium
- body text must remain highly readable
- overview pages stay compact
- detail pages may breathe more
- execution pages must prioritize clarity over flourish

### Forbidden
- Inter, Manrope, or system sans as local truth
- inconsistent heading font families per module
- tiny muted gray text as a substitute for hierarchy

---

## 5. Spacing and radius law

### Canonical rhythm
- component radius: `11px` equivalent
- section/card radius: `22px` equivalent
- generous outer spacing
- compact internal grouping where comparison matters

### Rules by family
- overview pages: compact and scan-first
- detail pages: airy and composed
- execution pages: tighter but calm
- tour pages: immersive, not noisy

---

## 6. Button law

There is one button family.

### Primary
- strongest action only
- visually dominant
- never duplicated 3 times in the same zone

### Secondary
- supportive
- quiet but clear

### Ghost
- contextual
- not equal in weight to primary

### Forbidden
- browser-default checked/button states
- one-off white-outline buttons on special pages
- overview cards with equal-weight CTA pairs

---

## 7. Card law

Overview cards, detail support cards, summary cards, and experience cards must feel related.

Shared requirements:
- same surface logic
- same border logic
- same radius logic
- same image treatment
- same spacing discipline

### Overview card law
- image with fixed ratio
- title
- one short meta line
- optional short badges
- one primary action
- one secondary action

No overview card may become a mini detail page.

---

## 8. Map law

Maps are supportive context unless the page family explicitly centers route behavior.

### Rules
- maps must live inside the same surface family as the rest of the page
- broken tile states must have designed fallback states
- map side panels may not become a second app
- white map shells are forbidden on dark pages

### Fallback rule
If tiles fail:
- keep route or selection useful
- show designed fallback state
- keep CTA to external route where relevant

---

## 9. Page-family layout canon

### Overview family
Must share:
- compact intro
- contained filter bar
- scanable grid
- one supportive rail
- clean CTA hierarchy

Activities Overview and Spots Overview must feel like siblings.

### Detail family
Must share:
- hero/media block
- context strip
- summary/decision area
- practical info
- closing CTA

Spot Detail and Product Detail must feel like the same product family.

### Execution family
Must share:
- command/summary structure
- trust-first hierarchy
- low clutter
- authoritative totals

### Experience family
Tour may be more immersive, but must still be visibly DagjeDenBosch.

---

## 10. Truth enforcement

The active runtime owner is:
- `ddb-core-ui/core-ui.php`

The design spec must be enforced through:
- runtime tokens
- shared component classes
- page-family patterns

It must not depend on:
- Elementor custom CSS as long-term truth
- emergency page overrides
- plugin-island styling

---

## 11. Completion standard

A page is not done until:
- it respects the black premium base
- it uses canonical typography
- it uses the shared button family
- it uses the shared card/surface logic
- it matches its page family layout canon
- it does not invent a local design language
- it keeps OMDB and Woo boundaries intact
