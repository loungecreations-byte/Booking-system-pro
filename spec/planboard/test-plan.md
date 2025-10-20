# Testplan  Planboard v1

## E2E Scenario's
1. **Boeking aanmaken via API** ⇒ verschijnt op live board ⇒ DnD naar nieuw slot ⇒ commit ⇒ pricing blijft behouden (keepPrice=true).
2. **Capaciteit blokkeren** ⇒ slot kleurt 'blocked' ⇒ DnD naar blocked slot faalt met 409.
3. **Annuleren + Refund** ⇒ audit trail bevat reden + actor ⇒ channel sync markeert als cancelled.

## Edge Cases
- DnD cross-outlet zonder permissie ⇒ 403
- Overbook threshold ⇒ badge + waarschuwing
- Websocket disconnect ⇒ fallback naar poll (15s)

## Performance
- Board 7 dagen / 200 resources / 3k bookings ⇒ ≤ 2s TTFB (met cache priming)
- P95 REST latencies binnen SLA
