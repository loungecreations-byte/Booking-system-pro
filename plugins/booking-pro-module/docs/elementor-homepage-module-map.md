# DagjeDenBosch Homepage Module Map (Elementor)

This is the editor-safe source of truth for homepage section classes.
Use Elementor section-level CSS class field: Advanced > CSS Classes.

## Global rules

- Keep Elementor as structure owner.
- Keep all visual truth in `homepage.css` and `ddb-flow-system.css`.
- Apply one module class per section and optional inner classes per widget/container.
- Do not paste one large static HTML blob.

## Section mapping (top to bottom)

1. Hero
- Section class: `ddb-hp-hero ddb-df-hero ddb-section`
- Heading widget class: `ddb-df-hero__title`
- Intro text widget class: `ddb-df-hero__lede`
- Buttons container class: `ddb-df-hero__actions`
- Buttons: `ddb-df-btn ddb-df-btn--accent` (primary), `ddb-df-btn ddb-df-btn--secondary` (secondary)

2. Start Slim Panel
- Section class: `ddb-hp-surface ddb-section`
- Inner container class: `ddb-shell ddb-df-start-slim`
- Panel title class: `ddb-df-start-slim__title`
- Items wrapper class: `ddb-df-start-slim__grid`
- Each item container class: `ddb-df-start-slim__item`
- Item kicker/title/desc classes:
  - `ddb-df-start-slim__item-kicker`
  - `ddb-df-start-slim__item-label`
  - `ddb-df-start-slim__item-desc`

3. Entry Points
- Section class: `ddb-hp-surface ddb-section`
- Section shell class: `ddb-shell`
- Cards wrapper class: `ddb-df-entry-points__grid`
- Each card container class: `ddb-df-entry-card`
- Card text classes:
  - `ddb-df-entry-card__kicker`
  - `ddb-df-entry-card__title`
  - `ddb-df-entry-card__desc`
  - `ddb-df-entry-card__cta`

4. How It Works
- Section class: `ddb-hp-raised ddb-section`
- Section shell class: `ddb-shell`
- Steps wrapper class: `ddb-df-how-it-works__steps`
- Each step class: `ddb-df-step-card`
- Step text classes:
  - `ddb-df-step-card__title`
  - `ddb-df-step-card__desc`

5. Why It Works
- Section class: `ddb-hp-feature ddb-section`
- Section shell class: `ddb-shell`
- Use cards with `ddb-card` and standard heading/text widgets.

6. Dayflow Timeline
- Section class: `ddb-hp-surface ddb-section`
- Section shell class: `ddb-shell`
- Timeline wrapper class: `ddb-df-timeline`
- Each event class: `ddb-df-timeline-event`
- Event text classes:
  - `ddb-df-timeline-event__time`
  - `ddb-df-timeline-event__label`
  - `ddb-df-timeline-event__desc`

7. Trust / Product Quality
- Section class: `ddb-hp-raised ddb-section`
- Section shell class: `ddb-shell`
- Grid class: `ddb-df-trust__grid`
- Card class: `ddb-df-trust-card`
- Card text classes:
  - `ddb-df-trust-card__number`
  - `ddb-df-trust-card__title`
  - `ddb-df-trust-card__desc`

8. Final CTA
- Section class: `ddb-hp-cta ddb-df-final-cta ddb-section`
- Title class: `ddb-df-final-cta__title`
- Text class: `ddb-df-final-cta__text`
- Actions wrapper class: `ddb-df-final-cta__actions`
- Buttons: `ddb-df-btn ddb-df-btn--accent` and `ddb-df-btn ddb-df-btn--secondary`

## Discovery archive reuse (spots/activities)

- Top wrapper: `ddb-shell`
- Filter row: `ddb-df-filter-bar`
- Filter label: `ddb-df-filter-bar__label`
- Filter group: `ddb-df-filter-bar__group`
- Filter chip: `ddb-df-filter-pill` (+ `ddb-df-filter-pill--active`)
- Result header: `ddb-df-result-header`
- Result title/count: `ddb-df-result-header__title`, `ddb-df-result-header__count`
- Main layout: `ddb-discovery-layout`
- Left list: `ddb-discovery-layout__list` with `ddb-discovery-cards`
- Card: `ui-listing-card ddb-df-spot-card`
- Right panel: `ddb-discovery-layout__map` with `ddb-df-map-panel`

## Notes

- Homepage base section background reset is automatic for sections without `ddb-hp-*` and `ddb-df-*`.
- Archive discovery background reset is automatic for spots/activities post type archives.
- This map is intentionally class-first so editors can compose sections without changing PHP.
