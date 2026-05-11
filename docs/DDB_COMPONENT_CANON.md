# DagjeDenBosch Component Canon

## Purpose
This document defines the canonical component winners for the platform.

Rule:
If a component can be reused across multiple pages, there must be one canonical family for it.

No page, plugin, or module may invent its own competing component family without explicit approval.

---

## 1. Global component law

### 1.1 One family rule
The platform uses:
- one button family
- one card family
- one form/filter family
- one tab family
- one summary/CTA family
- one map/detail family
- one shell family

### 1.2 Variants are allowed, reinvention is not
Controlled variants are allowed.
Competing component systems are not.

### 1.3 Design system ownership
Canonical visual rules belong in:
- `ddb-core-design-system.php` for tokens/theme mapping
- shared Unified Design System component layers for implementation

Component truth may not live primarily in:
- Elementor custom CSS
- page-local CSS
- plugin-local design islands
- template hacks

---

## 2. Button canon

### Canonical family
- primary
- secondary
- ghost
- inline
- icon button

### Allowed differences
- size
- icon presence
- context-specific width
- dark/light theme translation

### Forbidden
- page-specific button styling as new truth
- multiple competing primary button looks
- gold buttons everywhere
- different border-radius logic per module

### Rules
- primary button = strongest action only
- secondary button = supporting action
- ghost = quiet context action
- inline = text or subtle action inside dense UI
- icon button = utility only

---

## 3. Card canon

### Canonical family
One card family with controlled variants:
- overview card
- detail support card
- combo/suggestion card
- mini-card
- status/summary card
- management card

### Shared rules
All cards must share:
- same visual family
- same spacing rhythm
- same border logic
- same radius logic
- same hierarchy logic
- same dark/light translation principles

### Overview card law
Default overview cards must contain only:
- image
- title
- one concise meta line
- 1–2 badges max
- primary CTA
- secondary CTA

They must not become mini detail pages.

They must also share:
- the same image ratio
- the same CTA row structure
- the same surface depth
- the same badge logic
- the same height behavior across overview families

### Forbidden
- long descriptions in overview cards
- too many badges
- too many CTA buttons
- one-off plugin card systems
- separate spot-card vs activity-card design languages

---

## 4. Form / filter canon

### Canonical family
- search field
- select/dropdown
- chips
- reset action
- submit action
- steppers
- toggles/checks where needed

### Rules
- filters must feel related across overview pages
- chips show state, not dominate the page
- reset must stay visually quieter than apply/show results
- search/select controls must share one visual family
- stepper behavior must be consistent

### Forbidden
- thick loud chips as core page identity
- plugin-specific form styling
- one-off search bars
- multiple filter hierarchies with different UX logic

---

## 5. Tab canon

### Canonical family
One tab family only.

### Shared rules
- same active state logic
- same spacing rhythm
- same typography hierarchy
- same mobile collapse/wrap behavior

### Forbidden
- numbered tabs as design default
- plugin-like tab styling
- page-specific tab inventions without explicit need

---

## 6. Summary / CTA canon

### Canonical family
- bottom CTA zone
- decision bar
- planner summary
- cart summary
- detail closing CTA
- reassurance blocks

### Rules
- summary areas must close decisions, not create clutter
- total price or key summary value must feel authoritative in execution contexts
- CTA hierarchy must remain clear
- detail pages and execution pages must feel related, not identical

### Forbidden
- weak or generic closing blocks
- summary zones with multiple equal-weight CTAs
- random CTA block design per page

---

## 7. Map / detail canon

### Canonical family
- map shell
- selected item panel
- helper block
- practical info side panel
- route/mini-map preview blocks

### Rules
- map is supportive, not dominant, unless the page family explicitly demands it
- selected panel must be compact and decision-oriented
- helper block must remain small and useful
- alternatives must not duplicate the main browsing surface

### Forbidden
- second full interface in the sidebar
- admin-like rows under maps
- repeated detail information in overview contexts
- map panels visually unrelated to the rest of the system

---

## 8. Planner / composer canon

### Canonical family
- planner blocks
- program segments
- timeline items
- optimization blocks
- add-to-day confirmation modules
- summary bars
- booking handoff blocks

### Rules
- planner is the execution environment
- planner UI must feel stronger and more structured than overview pages
- planner may be denser than overview, but still within the same design family
- planner summary must relate to cart/checkout summary family

### Forbidden
- planner acting like another discovery page
- overview pages inheriting planner density
- local planner-only design language that breaks family continuity

---

## 9. Hero canon

### Canonical family
Used by:
- homepage
- spot detail
- product detail
- tour where appropriate

### Shared rules
- strong image treatment
- readable overlay
- concise hierarchy
- one clear primary CTA
- one clear secondary CTA
- no oversized fluff or empty marketing space

### Forbidden
- giant landing-page hero on overview pages
- too much meta layered into hero copy
- decorative hero without routing value

---

## 10. Review / content canon

### Canonical family
- review summary
- review card
- description/overview blocks
- practical info blocks
- contextual explanation blocks

### Rules
- readable typography
- strong summary first
- practical data formatted humanly
- content must support decision making
- no raw field output
- no content dump behavior

### Forbidden
- broken field rendering
- generic SEO wall-of-text presentation
- multiple unrelated content-panel styles

---

## 11. Account / portal canon

### Canonical family
- overview/status cards
- management panels
- booking status modules
- saved-item modules
- operational action bars

### Rules
- more structured, but still branded
- efficient without becoming generic admin
- same shell, button, card, and spacing family

### Forbidden
- separate admin product language
- default plugin dashboard styling
- unmanaged portal drift

---

## 12. Tour / experience canon

### Canonical family
- stop header
- route progression
- content/media block
- next-step CTA
- route/map shell

### Rules
- experience-first
- progression clarity
- current/next distinction
- calm and immersive
- still recognizably part of DagjeDenBosch

### Forbidden
- booking/discovery clutter in the experience layer
- generic article-like pages
- admin-like layouts

---

## 13. Canon enforcement law

If a component already has a canonical family:
- reuse it
- extend it carefully if needed
- do not invent a parallel version

If a new need appears:
1. check if the existing family can support it
2. add a controlled variant if needed
3. update the canon
4. only then use it in pages/templates

Never treat a local page fix as a new canonical component by accident.
