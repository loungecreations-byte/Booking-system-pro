# Codex Prompt: Apply Governance Update

Use this prompt as a reference when applying future DagjeDenBosch governance updates.

## Scope

Update documentation and agent instructions only. Do not change runtime code unless the task explicitly asks for implementation after governance is updated.

## Required Checks

1. Locate existing truth docs in `docs/`.
2. Locate agent instructions such as `AGENTS.md` and governance agent docs.
3. Preserve existing rules and append/merge new rules without deleting existing protections.
4. Explicitly classify impacted truths:
   - participants truth
   - availability truth
   - provider integration truth
   - Woo price/order/payment/tax truth
   - request/direct routing
   - cancellation truth
5. Validate with `git status --short` and `git diff -- docs`.

## Provider Integration Rules

- Schedule is never availability truth.
- Provider availability must use canonical participants.
- External `available:true` without hold routes to `REQUEST`, not `DIRECT`.
- Provider prices are never WooCommerce price truth.
- Frontend/planner may not determine provider truth directly.
- Provider integrations go through server-side adapters/services.
- Direct booking requires response schema, idempotency, server-side price validation, capacity lock/hold, booking confirmation, cancellation/change path, status callbacks or manual confirmation, and commercial permission.

## Eliio Product 115

DDB product `115` (`E-Chopper tour`) remains request-only:

- `directBookable=false`
- `supplierConfirmationRequired=true`
- route `REQUEST` / quote

Eliio `GET /availability/widget` may be used only as a server-side participant-sensitive availability pre-check. Eliio `POST /booking-widget` is forbidden for direct checkout.
