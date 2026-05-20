# DDB Page Families

> **Authority document** — Governance truth. Do not edit without an approved governance task.

## Purpose

Defines the six canonical page families. Each page on the DagjeDenBosch platform belongs to exactly one family.

## 1. Overview family

### Includes
- Spot listing pages
- Search results pages
- Category / tag archive pages

### Primary journey phase
Discovery

### Must do
- Show spot cards with OMDB semantic data
- Support filtering by type, date, participants

### Must not do
- Start a booking flow
- Show commerce (price) as primary action

---

## 2. Detail family

### Includes
- Spot detail pages
- Experience detail pages

### Primary journey phase
Consideration

### Must do
- Show full OMDB content for the spot
- Offer "add to day" or "book now" as primary CTA
- Show booking mode (direct / request / supplier confirmation) correctly

### Must not do
- Skip the planner flow for request-only products
- Show Woo price as the headline metric without OMDB context

---

## 3. Execution family

### Includes
- Cart page
- Checkout page

### Primary journey phase
Commitment

### Must do
- Carry `ddb-commerce-shell` shell markers in WooCommerce overrides
- Let WooCommerce own price, tax, and order truth

### Must not do
- Override WooCommerce price calculation
- Allow request-only items to proceed to checkout directly

---

## 4. Management family

### Includes
- My Account page
- Order history page
- Order detail page

### Primary journey phase
Post-purchase

### Must do
- Carry `ddb-account-shell` markers in WooCommerce overrides
- Show booking status from BookingTruthRuntimeService

### Must not do
- Directly mutate order status without WooCommerce hooks

---

## 5. Experience family

### Includes
- Planner / day builder pages

### Primary journey phase
Planning

### Must do
- Read planner state from canonical planner session
- Route to correct booking mode per product

### Must not do
- Calculate booking totals (Woo truth)
- Set `directBookable: true` for request-only products

---

## 6. Return family

### Includes
- Thank-you / order received page
- Booking confirmation page

### Primary journey phase
Confirmation

### Must do
- Carry `ddb-order-received-layout` marker
- Show order summary from WooCommerce order truth

### Must not do
- Re-attempt payment or booking
- Directly read booking state outside WooCommerce order
