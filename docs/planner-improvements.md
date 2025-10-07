# Planner Module Improvement Plan

## Security Enhancements
- Harden `/sbdp/v1/compose_booking` by requiring a signed nonce header (custom `X-SBDP-Nonce`) issued via planner page; fall back to allow filters for kiosks.
- Audit other public endpoints (`/sbdp/v1/services`, `/sbdp/v1/availability/plan`) and add rate-limit hooks (`apply_filters( 'sbdp/rest/rate_limit', … )`).
- Document how to rotate demo data seeders and how to disable planner seeding on production.

## UX & Admin Experience
- Expand `Bookings` dashboard to surface key metrics (today's bookings, resource load) pulled from REST.
- Embed availability calendar widget inside `render_availability()` rather than blank shell; reuse existing JS or add concise instructions.
- Provide direct links/buttons to planner page, availability editor and pricing rules.

## Documentation & Testing
- Add admin handbook covering planner workflows (creating bookable products, adjusting availability, publishing planner page).
- Extend regression checklist with planner-specific UI/REST tests (drag & drop, compose_booking pay/request, pricing edge cases).
- Add Playwright/Cypress scripts to automate planner front-end smoke tests post-deploy.

## Status
- Pending implementation; prioritise security hardening and admin UX updates before channel sync work.