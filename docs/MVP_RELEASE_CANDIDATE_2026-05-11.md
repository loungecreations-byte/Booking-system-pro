# MVP Release Candidate - 2026-05-11

## Status

MVP release candidate is GO.

## Validated booking flows

- Normal Woo checkout.
- Direct Bierproeverij booking via `compose_booking`.
- Planner to cart/checkout.
- Quote handoff toward Woo.

## Release-critical boundaries

- Public payment/order mutation routes remain blocked.
- Public booking intents do not trust client-supplied prices or statuses.
- Quotes use `approved_version_id` as the commercial handoff source.
- Sent and accepted quotes are immutable.
- Invalid quotes are blocked server-side.

## Known non-blocking follow-up

- Historical planner memory fatal is not reproducible and remains post-MVP investigation.
- OpenAI draft functions are optional because of quota/429 behavior.
- Further DTO hardening is post-MVP.
- Quote-order backfill is post-MVP.

## Snapshot note

This document belongs to the release baseline snapshot for the local project repository.
