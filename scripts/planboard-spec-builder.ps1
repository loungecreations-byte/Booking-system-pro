# ==========================================================
# Planboard Spec Builder (Booking Pro)
# ==========================================================
# Gebruik:
#   pwsh -File scripts/planboard-spec-builder.ps1 -Name "Planboard v1"
# Opties:
#   -Name           Weergavenaam in spec headers
#   -BaseUrl        Basis-URL voor REST (default: https://site.local/wp-json)
#   -ChannelSync    Schakel channel sync flows in (default: $true)
#   -Realtime       Websocket/Pusher kanaal genereren (default: $true)
param(
  [string]$Name = "Planboard",
  [string]$BaseUrl = "https://site.local/wp-json",
  [bool]$ChannelSync = $true,
  [bool]$Realtime = $true
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSCommandPath
$specDir = Join-Path $root "..\spec\planboard"
New-Item -ItemType Directory -Force -Path $specDir | Out-Null

function Write-File($path, $content) {
  $dir = Split-Path -Parent $path
  New-Item -ItemType Directory -Force -Path $dir | Out-Null
  $content | Out-File -FilePath $path -Encoding UTF8
  Write-Host " $path"
}

# ---------- planboard.yaml ----------
$planboard = @"
meta:
  name: "$Name"
  version: "1.0.0"
  owner: "Booking Pro"
  updated_at: "$(Get-Date -Format o)"
runtime:
  base_url: "$BaseUrl"
  auth:
    type: "wp-nonce"
    header: "X-WP-Nonce"
  realtime:
    enabled: $Realtime
    transport: "websocket"
    channel: "sbdp.planboard.\${tenantId}"
domains:
  - bookings
  - availability
  - planner
  - pricing
  - channels
  - notifications
non_functional:
  sla:
    rto_minutes: 30
    rpo_minutes: 15
    latency_p50_ms: 200
    latency_p95_ms: 600
  rate_limits:
    read_rpm: 600
    write_rpm: 120
data_sources:
  - name: primary_db
    type: "mysql"
    owner: "booking-core"
  - name: cache
    type: "redis"
flows:
  live_board:
    goal: "Realtime zicht op capaciteit en status per timeslot"
    steps:
      - subscribe: "realtime.channel"
      - fetch: "GET /sbdp/v1/planner/board?days=7&outlet={id}"
      - hydrate: "bookings + availability + priceHints"
      - update_on: ["BookingCreated","BookingUpdated","CapacityAdjusted","PriceUpdated"]
  manage_bookings:
    goal: "Snel zoeken, wijzigen, annuleren, notities en refunds"
    steps:
      - search: "GET /sbdp/v1/bookings?query=..."
      - edit:   "PATCH /sbdp/v1/bookings/{id}"
      - cancel: "POST /sbdp/v1/bookings/{id}/cancel"
      - refund: "POST /sbdp/v1/bookings/{id}/refund"
  drag_drop:
    goal: "Herplannen met drag & drop tussen tijdsloten/ressources"
    steps:
      - start:  "POST /sbdp/v1/drag/sessions"
      - apply:  "POST /sbdp/v1/drag/apply {bookingId, toSlotId}"
      - commit: "POST /sbdp/v1/drag/commit"
      - rollback: "POST /sbdp/v1/drag/rollback"
    constraints:
      - "respect participant limits"
      - "respect resource exclusivity"
      - "no cross-outlet unless role permits"
  availability_ops:
    goal: "Capaciteit en blokkades beheren"
    steps:
      - add_block:    "POST /sbdp/v1/availability/blocks"
      - remove_block: "DELETE /sbdp/v1/availability/blocks/{id}"
      - set_quota:    "POST /sbdp/v1/availability/quotas"
  permissions:
    roles: ["owner","manager","agent","supplier","viewer"]
    rules:
      - "agent: manage own-outlet bookings, no pricing edits"
      - "manager: all bookings in tenant, pricing limited"
      - "owner: full access"
integrations:
  channels:
    enabled: $ChannelSync
    providers: ["GetYourGuide","Viator","Tripadvisor","Briq"]
    sync:
      schedule: "*/15 * * * *"
      hooks: ["onInventoryChange","onPriceChange","onBlackout"]
ui:
  drag_and_drop:
    lib: "native-html5"
    drop_targets: ["timeslot","resource"]
    ghost_preview: true
    snap_minutes: 15
  board:
    timescales: ["day","week"]
    density: "auto"
    indicators: ["overbook","low_capacity","hardware_alert"]
"@

# ---------- openapi.yaml ----------
$openapi = @"
openapi: 3.0.3
info:
  title: "$Name API"
  version: "1.0.0"
servers:
  - url: "$BaseUrl"
paths:
  /sbdp/v1/planner/board:
    get:
      summary: Get planboard
      parameters:
        - in: query
          name: days
          schema: { type: integer, default: 7 }
        - in: query
          name: outlet
          schema: { type: string }
      responses:
        "200": { description: OK }
  /sbdp/v1/bookings:
    get:
      summary: Search bookings
      parameters:
        - in: query
          name: query
          schema: { type: string }
      responses:
        "200": { description: OK }
  /sbdp/v1/bookings/{id}:
    patch:
      summary: Edit booking
      parameters:
        - in: path
          name: id
          required: true
          schema: { type: string }
      requestBody:
        required: true
        content:
          application/json:
            schema:
              `$ref: "#/components/schemas/BookingPatch"
      responses:
        "200": { description: Updated }
  /sbdp/v1/bookings/{id}/cancel:
    post:
      summary: Cancel booking
      responses: { "200": { description: Cancelled } }
  /sbdp/v1/bookings/{id}/refund:
    post:
      summary: Refund booking
      responses: { "200": { description: Refunded } }
  /sbdp/v1/drag/sessions:
    post:
      summary: Start DnD session
      responses: { "201": { description: Created } }
  /sbdp/v1/drag/apply:
    post:
      summary: Apply tentative move
      requestBody:
        required: true
        content:
          application/json:
            schema:
              `$ref: "#/components/schemas/DragApply"
      responses:
        "200": { description: Tentative OK }
  /sbdp/v1/drag/commit:
    post:
      summary: Commit DnD changes
      responses: { "200": { description: Committed } }
  /sbdp/v1/drag/rollback:
    post:
      summary: Rollback DnD changes
      responses: { "200": { description: Rolled back } }
  /sbdp/v1/availability/blocks:
    post:
      summary: Add block
      responses: { "201": { description: Created } }
components:
  schemas:
    BookingPatch:
      type: object
      properties:
        status: { type: string, enum: [confirmed, pending, cancelled] }
        notes:  { type: string }
        meta:   { type: object, additionalProperties: true }
    DragApply:
      type: object
      required: [ bookingId, toSlotId ]
      properties:
        bookingId: { type: string }
        toSlotId:  { type: string }
        keepPrice: { type: boolean, default: true }
"@

# ---------- events.yaml ----------
$events = @"
version: 1
bus: sbdp-planboard
events:
  - name: BookingCreated
    source: bookings
    payload:
      bookingId: string
      outletId: string
      timeslot: string
      participants: integer
  - name: BookingUpdated
    source: bookings
    payload:
      bookingId: string
      changes: object
  - name: CapacityAdjusted
    source: availability
    payload:
      outletId: string
      slotId: string
      delta: integer
  - name: PriceUpdated
    source: pricing
    payload:
      productId: string
      ruleId: string
      newPrice: number
retries:
  max_attempts: 8
  backoff: "exponential"
security:
  signature_header: "X-SBDP-Signature"
"@

# ---------- permissions.csv ----------
$permissions = @"
role,bookings.view,bookings.edit,bookings.cancel,pricing.edit,availability.edit,channels.sync,admin
owner,1,1,1,1,1,1,1
manager,1,1,1,1,1,1,0
agent,1,1,1,0,0,0,0
supplier,1,0,0,0,1,0,0
viewer,1,0,0,0,0,0,0
"@

# ---------- acceptance.md ----------
$acceptance = @"
# Acceptatiecriteria  $Name

## Live Board
- **AC1**: Initiele load ≤ 2s voor 7 dagen en ≤ 200 resources.
- **AC2**: Realtime update binnen 1s na event (BookingCreated/Updated/CapacityAdjusted/PriceUpdated).
- **AC3**: Overbook indicator zichtbaar met tooltip & oorzaak.

## Manage Bookings
- **AC4**: Zoekresultaat binnen 500ms voor 10k records (met index).
- **AC5**: Edit PATCH valideert rolrechten en business rules.
- **AC6**: Refunds loggen audit trail met actor, bedrag en tijdstempel.

## Drag & Drop
- **AC7**: DnD respecteert quota/blackouts; bij conflict duidelijke foutmelding.
- **AC8**: Commit maakt een atomair change-set; rollback herstelt volledig.
- **AC9**: Slepen tussen outlets alleen voor rollen manager/owner.

## Availability Ops
- **AC10**: Block toevoegen/verwijderen zichtbaar op board binnen 1s.
- **AC11**: Quota wijzigingen triggeren channel-sync (indien aan).

## Beveiliging
- **AC12**: Elke schrijf-call vereist WP nonce + capability check.
- **AC13**: Webhooks zijn gesigneerd + replay protection (timestamp + nonce).
"@

# ---------- test-plan.md ----------
$testplan = @"
# Testplan  $Name

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
"@

# ---------- erd.mmd ----------
$erd = @"
erDiagram
  BOOKING ||--o{ BOOKING_NOTE : has
  BOOKING ||--|| TIMESLOT : scheduled_in
  BOOKING }o--|| CUSTOMER : belongs_to
  TIMESLOT ||--|| RESOURCE : allocates
  RESOURCE }o--o{ OUTLET : grouped_by
  PRICE_RULE }o--o{ PRODUCT : applies_to
  CHANNEL ||--o{ CHANNEL_SYNC : logs
  AVAILABILITY_BLOCK }o--|| RESOURCE : locks
"@

# Write files
Write-Host "Generating planboard spec artifacts..."
Write-File (Join-Path $specDir 'planboard.yaml') $planboard
Write-File (Join-Path $specDir 'openapi.yaml') $openapi
Write-File (Join-Path $specDir 'events.yaml') $events
Write-File (Join-Path $specDir 'permissions.csv') $permissions
Write-File (Join-Path $specDir 'acceptance.md') $acceptance
Write-File (Join-Path $specDir 'test-plan.md') $testplan
Write-File (Join-Path $specDir 'erd.mmd') $erd

Write-Host "`nDone. Specs available under $specDir"
