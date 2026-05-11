# Codex Fix Prompt — Three Platform Bugs
Date: 2026-04-08  
Authority: AGENTS.md, DDB_PLATFORM_CONSTITUTION.md, DDB_OMDB_WOO_BOUNDARIES.md

---

## Context

This prompt fixes three distinct broken flows. Read all context before making any change.
Each fix is independent and scoped. Do not rewrite surrounding logic.
Do not touch: pricing arithmetic, VAT logic, WooCommerce truth, OMDB semantics.

---

## Fix 1 — Combi deal must survive add-to-cart into the WooCommerce cart and checkout

### Problem
When the user selects a combi deal on the product planner form and clicks
"Leg in winkelwagen", the combi selection arrives in `$_POST['sbdp_combi']`
and `$_POST['sbdp_combi_label']`. The `Module::captureCartItemData()` hook
(in `modules/product-page-refresh/Module.php`, registered on
`woocommerce_add_cart_item_data`) reads these POST fields correctly and
stores `combi`, `combi_label`, and `combi_multi` in the cart item data array.

**The suspected break points:**

1. The product planner form only renders a hidden `<select name="sbdp_combi">`.
   When the user clicks a `.sbdp-combi-option` button, the JS sets
   `combiSelect.value = combiVal` and calls `syncPrimaryCombiLabel()`.
   However, the `<select>` is `display:none` and `aria-hidden="true"`.
   Verify that browsers and WooCommerce form submission do NOT skip
   hidden/aria-hidden selects on POST. If they do, the combi ID never
   reaches `$_POST['sbdp_combi']` and the cart item data hook gets `0`.

2. The `captureCartItemData()` reads:
   ```php
   $combi = isset($_POST['sbdp_summary_combi'])
       ? absint(...)
       : (isset($_POST['sbdp_combi']) ? absint(...) : 0);
   ```
   The summary-card combi select (`sbdp_summary_combi`) takes priority over
   the product form combi select (`sbdp_combi`). If a summary card is present
   on the product page and renders an empty value, it silently zeroes the
   combi ID. Verify this is not happening.

3. When `combi_id = 0` after all the above, `combi_multi` is built as an
   empty array `[]`. The cart item, checkout line item, and order meta then
   contain no combi data, so the combi deal is invisible in:
   - Cart: `.sbdp-summary-card` combi display  
   - Checkout: order line item meta  
   - Order confirmation email  

### Required fixes

**File:** `includes/bootstrap/sbdp-single-product-planner.php`

In the combi selection HTML block (the hidden select), ensure the
`<select name="sbdp_combi">` is NOT given `aria-hidden="true"` when it
carries meaningful form data. `aria-hidden` on a form control can cause
assistive technology to skip it and some framework sanitizers to strip it.
Change:
```html
<select name="sbdp_combi" id="sbdp_combi" style="display:none;" aria-hidden="true">
```
To:
```html
<select name="sbdp_combi" id="sbdp_combi" class="sbdp-combi-select" style="display:none;">
```

**File:** `includes/bootstrap/sbdp-single-product-planner.php`

After the JS combi button click handler sets `combiSelectEl.value = combiVal`,
also dispatch a `change` event on the select to ensure any framework listeners
pick up the new value before form submit:
```javascript
const combiSelectEl = form.querySelector('select[name="sbdp_combi"]');
if (combiSelectEl) {
    combiSelectEl.value = combiVal;
    combiSelectEl.dispatchEvent(new Event('change', { bubbles: true }));
}
```

**File:** `modules/product-page-refresh/Module.php`

In `captureCartItemData()` (around line 300–310), add a diagnostic-safe
fallback: if `sbdp_summary_combi` is present but empty AND `sbdp_combi` is
non-zero, ignore the empty summary value and use `sbdp_combi` instead:
```php
$summary_combi = isset($_POST['sbdp_summary_combi'])
    ? absint(wp_unslash($_POST['sbdp_summary_combi']))
    : 0;
$form_combi = isset($_POST['sbdp_combi'])
    ? absint(wp_unslash($_POST['sbdp_combi']))
    : 0;
// Only use summary_combi if it actually has a value; fall through to form_combi otherwise
$combi = ($summary_combi > 0) ? $summary_combi : $form_combi;
```

Apply the same fallback logic in `hydrateProjectedCartItem()` (around line 359–366).

### Verification steps
1. Set `_sbdp_combi_deals` meta on a product to a valid combi product ID.
2. Open the product page, select the combi deal button, fill in date/time/participants.
3. Click "Leg in winkelwagen".
4. Open WooCommerce cart: the cart item name or meta should show the combi label.
5. Proceed to checkout: the order line item should show `Combi-deal: [product name]`.
6. Complete the order: the order confirmation email should include the combi label.
7. In the WP admin order screen: `sbdp_combi` meta key should be non-zero.

---

## Fix 2 — Spots overview: card click must navigate directly to spot detail page

### Problem
In `plugins/ddb-spots-0.1.0/templates/archive-listing-premium.php`, the
spot card template (`templates/oled-card.php`) renders each card as:
```html
<article
  class="ui-listing-card ddb-spot-card"
  data-ddb-spot-card
  data-spot-link="https://example.com/spots/spot-name/"
  ...
  tabindex="0"
  role="button">
```
The `data-ddb-spot-card` attribute triggers a JS listener that intercepts
clicks and populates the right-column `[data-ddb-selected-panel]` instead
of navigating. This creates two clicks to reach the detail page and violates
the platform CTA hierarchy (primary CTA = Bekijk plek = direct navigation).

Per `DDB_PAGE_FAMILIES.md` and `DDB_CTA_MAP.md`:
- Spots Overview primary CTA is "Bekijk plek" — must be a direct link, one click
- The page must NOT behave like a detail page
- A right-column panel that shows detail content is a detail-page behavior inside an overview page

### Required fix

**File:** `plugins/ddb-spots-0.1.0/templates/oled-card.php`

Wrap the entire card article in an `<a>` tag pointing to the spot detail URL,
OR make the primary `.--primary` button inside the card the direct navigation
CTA. The card must navigate to spot detail on primary click without a JS
intermediary panel step.

Change the card article element: remove `role="button"` and `tabindex="0"` 
from the article. Instead, ensure the "Bekijk plek" anchor inside
`.ui-listing-card__actions` uses `data-spot-link` as its `href` and is the
primary focusable element.

The `data-ddb-spot-card` attribute may remain for map highlight behaviour
(hover/focus sync with the map pin), but the click action must NOT be
intercepted to open the panel. The panel may remain as a hover/focus preview
only, activated by `mouseenter`/`focusin` rather than `click`.

**File:** Any JS file that attaches a `click` listener to `[data-ddb-spot-card]`

Find the JS listener that intercepts card clicks and populates
`[data-ddb-selected-panel]`. Change the trigger from `click` to
`mouseenter` + `focusin` so the panel shows on hover/focus (map context)
but click falls through to the `<a href>` inside the card.

```javascript
// Before (wrong):
container.addEventListener('click', function(e) {
    const card = e.target.closest('[data-ddb-spot-card]');
    if (card) {
        e.preventDefault();
        populateSelectedPanel(card);
    }
});

// After (correct):
container.addEventListener('mouseenter', function(e) {
    const card = e.target.closest('[data-ddb-spot-card]');
    if (card) populateSelectedPanel(card);
}, true);
container.addEventListener('focusin', function(e) {
    const card = e.target.closest('[data-ddb-spot-card]');
    if (card) populateSelectedPanel(card);
});
// No click interception — let the <a href> inside the card handle navigation
```

### Verification steps
1. Open `/spots/` or the spots archive page.
2. Click a spot card anywhere except the action buttons.
3. Browser must navigate to the spot detail page in one click.
4. Hovering over a card may update the right-column panel and map highlight — this is acceptable.
5. The "Bekijk plek" button must navigate directly, not open the panel.
6. Accessibility: tab to a card, press Enter → navigates to detail page.

---

## Fix 3 — Plan je Dag: combi deal must reach the planner page after "Plan je dag" click

### Problem
When the user clicks "Plan je dag" on the product planner form, the JS:
1. Calls `preparePlannerPayload('planned')` → builds `planItem` with
   `planItem.options.combiItems` populated
2. Writes `plannerPrefill.options.combiItems` into `sbdp_prefill` URL param
3. Also writes to `sessionStorage[PREFILL_QUEUE_KEY]` via `enqueuePlannerPrefill()`
4. Redirects to `/plan-je-dag/?sbdp_prefill=...`

The combi items ARE present in the prefill object passed via URL and sessionStorage.
However, the planner React app on `/plan-je-dag/` must READ and APPLY
`prefill.options.combiItems` when it boots.

**The likely break point:** The planner boot on `/plan-je-dag/` reads
`window.SBDP_DAY_PLANNER` which is injected by `planner-runtime.php`.
This PHP file must parse the `sbdp_prefill` GET param and pass
`options.combiItems` into the localised boot config. If the PHP-side
`sbdp_prefill` parser drops or ignores `options`, the combi items never
reach the React app's initial state.

### Required fix

**File:** `includes/bootstrap/plan-je-dag/planner-runtime.php` (or wherever
`window.SBDP_DAY_PLANNER` boot config is assembled)

Find where `sbdp_prefill` GET param is parsed. Ensure that the
`options` key — specifically `options.combiItems` — is passed through
into the boot config without being stripped.

```php
// Find the prefill parsing block. It likely looks like:
$prefill = json_decode(
    sanitize_text_field(wp_unslash($_GET['sbdp_prefill'] ?? '')),
    true
) ?: [];

// Ensure options and combiItems survive the sanitize:
$prefill_options = isset($prefill['options']) && is_array($prefill['options'])
    ? $prefill['options']
    : [];
$combi_items = isset($prefill_options['combiItems']) && is_array($prefill_options['combiItems'])
    ? $prefill_options['combiItems']
    : [];

// Pass through into the boot config array that gets localised to window.SBDP_DAY_PLANNER:
$boot_config['prefill']['options']['combiItems'] = $combi_items;
```

Also verify that `sanitize_text_field()` on the raw JSON string does NOT
corrupt the JSON structure (it should not, but `wp_kses()` on a JSON string
would). If any sanitizer is stripping brackets or quotes from the JSON before
`json_decode`, replace it with:
```php
$raw = isset($_GET['sbdp_prefill']) ? wp_unslash($_GET['sbdp_prefill']) : '';
$prefill = json_decode($raw, true);
if (!is_array($prefill)) {
    $prefill = [];
}
// Then validate individual fields, not the raw string
```

**File:** `assets/js/day-planner/store/PlannerProvider.jsx` or
`assets/js/day-planner/index.jsx`

In the boot initialization, after reading `window.SBDP_DAY_PLANNER.prefill`,
ensure `prefill.options.combiItems` is used to seed the initial plan state.
If the PlannerProvider receives `bootConfig.prefill` but only processes
top-level fields (date, time, participants, product_id), add handling for
`options.combiItems`:

```javascript
const prefill = bootConfig?.prefill || {};
const prefillCombiItems = prefill?.options?.combiItems || [];
// Dispatch to state: seed initial combi selection in the plan
if (prefillCombiItems.length > 0) {
    dispatch({ type: 'PREFILL_COMBI_ITEMS', payload: prefillCombiItems });
}
```

Then in the React planner, the prefilled combi items must be visible in the
plan timeline immediately on page load, not requiring re-selection.

### Verification steps
1. On a product page with a combi deal configured via `_sbdp_combi_deals`:
   - Select a date, time, participants
   - Select the combi deal option
2. Click "Plan je dag".
3. On `/plan-je-dag/`, the plan timeline must show:
   - The main product as the anchor booking
   - The combi deal as a segment (before or after, matching `timing` field)
4. The combi segment must show its name, price, and duration.
5. The planner summary/total must include the combi price.
6. Proceeding to "Boek mijn dag" must carry the combi through to the cart.

---

## Do NOT touch
- WooCommerce pricing, VAT, or total calculation logic
- `apply_combi_adjustment()` — pricing is correct, only the data flow is broken
- OMDB semantics or product meta structure
- The planner domain REST API (`/sbdp-planner/v1/evaluate`) — it returns correct data
- Any file under `vendor/`, `tests/`, or `node_modules/`
- The planner plan-je-dag React UI beyond the boot/prefill initialization

## Commit scope
Three separate commits, one per fix:
1. `fix: combi deal survives add-to-cart POST and appears in cart/checkout/order`
2. `fix: spots card navigates directly to detail page on click`
3. `fix: combi deal prefill reaches Plan je Dag planner on redirect`
