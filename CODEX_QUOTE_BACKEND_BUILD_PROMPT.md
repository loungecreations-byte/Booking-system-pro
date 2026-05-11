# CODEX: Quote Backend v2.0 — Complete Build Prompt
## ServiceNow-Inspired Enterprise Quote System for DagjeDenBosch

**Document Version**: 1.0  
**Status**: PARTIALLY EXECUTABLE UNDER CURRENT GUARDRAILS  
**Target Scope**: Safe Phase 1-2 operator-workspace improvements only  
**Date**: May 10, 2026

---

## 🎯 MISSION STATEMENT

Transform DagjeDenBosch quote backend from scattered 6-step workflow into **enterprise-grade unified workspace** inspired by ServiceNow CPQ. Operators should go from "open quote" → "email sent" in **3 clicks and under 2 minutes**, with zero invalid states possible.

**Success Metric**: Quote prep time < 2 min. Operator confusion = 0. Configuration changes without developer = YES.

---

## 📦 WHAT IS ALREADY BUILT (Phase 1 — Reference Only)

### ✅ Already Implemented
- Dashboard tab render (110 lines in Controller.php)
- Quick-prepare handler (80 lines, orchestrates review flow)
- Tab navigation updated (default to 'dashboard')
- Assumptions quick-confirm buttons (inline forms)
- Quote admin fatal on `openAssumptions` fixed
- Business-rule validation layer added for operator guidance
- Dashboard blocker priority engine added (`DashboardBlockerService`)
- Dashboard now shows one operator focus state at a time: blocked, assumptions, ready, or locked
- Mobile-safe focus-card CSS added
- Frozen quote immutability remains enforced in quick-prepare / review / assumption flows

### ✅ Already Documented
- AGENTS.md guardrails (CSOT, send-guards, truth preservation)
- CODEX_QUOTE_WORKFLOW_IMPROVEMENTS.md (phase 1 technical design)
- SERVICENOW_INSPIRED_QUOTE_BACKEND.md (roadmap + principles)

### DO NOT MODIFY WITHOUT APPROVAL
- `QuoteSendReadinessValidator` (validate flow)
- `assertProposalSendAllowed()` (send-guard)
- Participants truth (request.group_size immutable)
- Availability truth (assumptions ≠ booking changes)
- CSOT principle (all truth from runtime)

### DEFERRED / NOT SAFE TO EXECUTE BLINDLY
- UI-configurable pricing engine replacement
- Auto-calculated commercial truth outside Woo/runtime authority
- Customer portal / partner portal
- Multi-channel quote engine expansion
- Rule systems that mutate quote pricing semantics without explicit architecture approval

---

## 🏗️ ARCHITECTURE CONSTRAINTS (Non-Negotiable)

### A. TRUTH PRESERVATION (CSOT)

**Rule**: Single source of truth for quote readiness = `wp_bsp_quote_assumptions` + `wp_bsp_quotes.send_status`.

```
✅ ALLOWED:
- Service reads assumptions → calculates readiness
- Dashboard renders assumptions as UI
- Handler resolves assumptions → updates DB

❌ FORBIDDEN:
- UI-side calculations of readiness
- Planner inventing send-status (only DB source)
- Caching send-readiness state > 1 request
- "Soft delete" assumptions without DB update
```

### B. SEND-GUARD INTEGRITY

**Rule**: Quote cannot be sent without passing `QuoteSendReadinessValidator::assertReadyToSend()`.

```php
// This call MUST be in any send path:
QuoteSendReadinessValidator::assertReadyToSend($quoteId);

// Checks:
✅ Customer email exists
✅ Quote lines exist
✅ No open assumptions with blocks_send=1
✅ Version confidence adequate
✅ Commercial totals reasonable
✅ WooCommerce readiness
```

**Implementation Contract**:
- Every `sendProposal()` call must validate first
- No UI-side "enable/disable send button" without backend validation
- Assume blockers always exist (operator never sees "ready" without backend confirmation)

### C. PARTICIPANTS IMMUTABILITY

**Rule**: `wp_bsp_quote_requests.group_size` cannot change during quote operation.

```php
// FORBIDDEN:
$quote->request->group_size = 20; // WRONG!
$quote->setGroupSize(20);         // WRONG!

// ALLOWED:
// group_size is read from request at quote start
// Used for validation (not for pricing recalc)
// If needed to change: must create NEW quote
```

### D. AVAILABILITY TRUTH

**Rule**: Resolving `uncertain_availability` assumption ≠ booking availability confirmed.

```php
// FORBIDDEN:
$assumption->resolve(); // WRONG - this does NOT book the date
$booking->confirm();    // WRONG - must be separate flow

// ALLOWED:
$assumption->resolve(); // Marks assumption resolved
// Separate: operator manually checks calendar in booking system
// Then: operator creates WooCommerce order/reservation
```

### E. OMDB/WOO BOUNDARY

**Rule**: Quote operations (build/assumptions) ≠ WooCommerce operations (cart/checkout).

```php
// FORBIDDEN:
Quote::sendAndCheckout(); // WRONG

// ALLOWED:
Quote::sendProposal();    // Email to customer
// Separate: Customer clicks link → WooCommerce checkout
// Separate: Booking engine handles availability
```

### F. REQUEST ITEM ISOLATION

**Rule**: Items from `wp_bsp_quote_requests` never enter direct checkout without quote approval.

```php
// FORBIDDEN:
$item = QuoteRequest::getItem(1);
$cart->addItem($item);      // WRONG - bypasses quote flow

// ALLOWED:
$quote = Quote::create($request);
$quote->approve();
// Then: operator manually creates WooCommerce order
// Items chosen from catalog, NOT from quote request
```

---

## 📋 PHASE-BY-PHASE IMPLEMENTATION GUIDE

### PHASE 1: Unified Dashboard (ALREADY DONE — REFERENCE)

**Files Modified**:
1. `modules/quotes/Admin/Controller.php`
   - Added `renderQuoteDetail()` dashboard section (~110 lines)
   - Modified `resolveWorkspaceTab()` to default to 'dashboard'
   - Added `handleQuickPrepareToSend()` (80 lines)

2. `modules/quotes/Module.php`
   - Added hook: `add_action('admin_post_sbdp_quote_quick_prepare_to_send', ...)`

**Status**: ✅ PRODUCTION READY

---

### PHASE 2: Smart Logic & Validation (SAFE)

#### Objective
Reduce operator errors from 15% to <1% via guided validation and contextual help.

#### Implementation Breakdown

##### 2.1 Business Rule Validator (1.5 hours)

**File to Create**: `modules/quotes/Service/QuoteBusinessRuleValidator.php`

```php
<?php
namespace BSP\Quotes\Service;

class QuoteBusinessRuleValidator {
    private $quoteId;
    private $quote;
    private $db;

    public function __construct($quoteId) {
        $this->quoteId = $quoteId;
        $this->quote = QuoteRepository::find($quoteId);
        $this->db = $GLOBALS['wpdb'];
    }

    /**
     * Comprehensive validation of quote completeness
     * @return array ['valid' => bool, 'violations' => [...]]
     */
    public function validateComplete() {
        $violations = [];

        // Check 1: Required program set
        if (!$this->hasRequiredProgram()) {
            $violations[] = [
                'code' => 'no_program',
                'severity' => 'error',
                'message' => 'Programma niet ingesteld',
                'fix' => 'Ga naar Build tab, voeg programma toe',
                'fix_url' => '?workspace_tab=build'
            ];
        }

        // Check 2: Valid customer contact
        if (!$this->hasValidCustomer()) {
            $violations[] = [
                'code' => 'no_customer',
                'severity' => 'error',
                'message' => 'Klantcontact onvolledig (naam + email)',
                'fix' => 'Ga naar Intake tab, vul klantinfo in',
                'fix_url' => '?workspace_tab=intake'
            ];
        }

        // Check 3: Reasonable price
        if (!$this->hasReasonablePrice()) {
            $violations[] = [
                'code' => 'price_invalid',
                'severity' => 'error',
                'message' => 'Prijs onredelijk (min €500, max €50.000)',
                'fix' => 'Controleer aantal personen en programmakosten',
                'fix_url' => '?workspace_tab=build'
            ];
        }

        // Check 4: Available date
        if (!$this->hasValidDate()) {
            $violations[] = [
                'code' => 'date_invalid',
                'severity' => 'error',
                'message' => 'Datum in verleden of buiten bereik',
                'fix' => 'Kies toekomstige datum (max 1 jaar)',
                'fix_url' => '?workspace_tab=intake'
            ];
        }

        // Check 5: Group size within bounds
        if (!$this->hasValidGroupSize()) {
            $violations[] = [
                'code' => 'group_size_invalid',
                'severity' => 'warning',
                'message' => 'Groepsgrootte ongebruikelijk (< 5 of > 500)',
                'fix' => 'Bevestig groepsgrootte. Create nieuwe quote if wijziging nodig',
                'fix_url' => null
            ];
        }

        return [
            'valid' => empty($violations),
            'violations' => $violations,
            'error_count' => count(array_filter($violations, fn($v) => $v['severity'] === 'error')),
            'warning_count' => count(array_filter($violations, fn($v) => $v['severity'] === 'warning'))
        ];
    }

    private function hasRequiredProgram() {
        return !empty($this->quote->program_id) && $this->quote->program_id > 0;
    }

    private function hasValidCustomer() {
        $request = QuoteRepository::getRequest($this->quoteId);
        return !empty($request['requester_email']) && 
               !empty($request['requester_name']) &&
               filter_var($request['requester_email'], FILTER_VALIDATE_EMAIL);
    }

    private function hasReasonablePrice() {
        $price = $this->quote->total_price;
        return $price >= 500 && $price <= 50000;
    }

    private function hasValidDate() {
        $date = strtotime($this->quote->preferred_date);
        $now = time();
        $oneYearAhead = strtotime('+1 year');
        return $date > $now && $date < $oneYearAhead;
    }

    private function hasValidGroupSize() {
        $size = $this->quote->group_size;
        return $size >= 5 && $size <= 500;
    }
}
```

**Usage in Dashboard**:
```php
$validator = new QuoteBusinessRuleValidator($quoteId);
$validation = $validator->validateComplete();

if (!$validation['valid']) {
    // Show violations with actionable fixes
    foreach ($validation['violations'] as $v) {
        echo sprintf(
            '<div class="violation violation-%s">
                <strong>%s</strong><br>
                %s<br>
                %s
             </div>',
            $v['severity'],
            $v['message'],
            $v['fix'],
            $v['fix_url'] ? '<a href="' . $v['fix_url'] . '">Go to fix</a>' : ''
        );
    }
}
```

##### 2.2 Discovery Flow Service (NEEDS DECISION / DEFERRED)

This section is concept-only for now.

Do not implement direct product suggestion or pricing calculation from this prompt as commercial truth.
Discovery may guide operators, but pricing/totals must still come from runtime/Woo-backed quote lines.

**File to Create**: `modules/quotes/Service/QuoteDiscoveryService.php`

```php
<?php
namespace BSP\Quotes\Service;

class QuoteDiscoveryService {
    
    /**
     * Define questionnaire for quote intake
     * @return array questions
     */
    public static function defineIntakeQuestions() {
        return [
            [
                'id' => 'group_size',
                'type' => 'number',
                'question' => 'Wat is de groepsgrootte?',
                'hint' => 'Aantal deelnemers',
                'required' => true,
                'min' => 5,
                'max' => 500,
                'validation' => 'positive_integer',
                'blocks' => ['pricing', 'availability']  // Can't proceed without this
            ],
            [
                'id' => 'preferred_date',
                'type' => 'date',
                'question' => 'Voorkeursdatum?',
                'hint' => 'Bijv. 15 juni 2026',
                'required' => true,
                'validation' => 'future_date',
                'blocks' => ['availability']
            ],
            [
                'id' => 'program_type',
                'type' => 'select',
                'question' => 'Welk programmatype?',
                'options' => [
                    'daytrip' => 'Daguitje (dagtocht)',
                    'team_building' => 'Teambuilding event',
                    'school' => 'Schooluitje',
                    'corporate' => 'Corporate event',
                    'custom' => 'Maatwerk'
                ],
                'required' => true,
                'validation' => 'enum',
                'affects' => ['product_suggestions']
            ],
            [
                'id' => 'add_ons',
                'type' => 'checkbox',
                'question' => 'Wat wil je erbij?',
                'options' => [
                    'lunch' => 'Lunch (€8/p)',
                    'parking' => 'Parkeerplaats (€2/p)',
                    'guide' => 'Gids/begeleiding (€50)',
                    'transport' => 'Vervoer (op maat)'
                ],
                'required' => false,
                'affects' => ['pricing']
            ],
            [
                'id' => 'budget_range',
                'type' => 'select',
                'question' => 'Budget range?',
                'options' => [
                    '500-1000' => '€500 - €1.000',
                    '1000-2000' => '€1.000 - €2.000',
                    '2000-5000' => '€2.000 - €5.000',
                    '5000+' => '€5.000+'
                ],
                'required' => false,
                'validation' => 'enum',
                'helps' => 'operator_guidance'
            ],
            [
                'id' => 'special_requests',
                'type' => 'textarea',
                'question' => 'Bijzonderheden?',
                'placeholder' => 'Bijv. allergies, accessibility needs, etc.',
                'required' => false,
                'affects' => ['assumptions']
            ]
        ];
    }

    /**
     * Auto-suggest products based on answers
     * @param array $answers
     * @return array suggestions
     */
    public static function suggestProducts($answers) {
        $suggestions = [];
        $groupSize = intval($answers['group_size'] ?? 0);
        $programType = $answers['program_type'] ?? null;

        // Rule: All groups can do daguitje
        $suggestions[] = [
            'product_id' => 1,
            'name' => 'Dagtocht DagjeDenBosch',
            'confidence' => 'high',
            'reason' => 'Alle groepsgroottes geschikt',
            'base_price' => 22.50,
            'calculated_price' => 22.50 * $groupSize
        ];

        // Rule: Group > 30 → suggest discount
        if ($groupSize >= 30) {
            $suggestions[] = [
                'product_id' => 2,
                'name' => 'Group Discount (-10%)',
                'confidence' => 'high',
                'reason' => 'Groep > 30 mensen',
                'discount_percentage' => 10,
                'applies_to' => 'total'
            ];
        }

        // Rule: Group < 10 → surcharge
        if ($groupSize < 10) {
            $suggestions[] = [
                'product_id' => 3,
                'name' => 'Small Group Surcharge',
                'confidence' => 'high',
                'reason' => 'Kleine groep (< 10)',
                'extra_cost' => 50
            ];
        }

        // Rule: Date within 2 weeks → flag uncertain_availability
        $daysUntil = (strtotime($answers['preferred_date']) - time()) / 86400;
        if ($daysUntil < 14) {
            $suggestions[] = [
                'type' => 'assumption',
                'assumption_type' => 'uncertain_availability',
                'confidence' => 'medium',
                'reason' => sprintf('Datum binnen %d dagen', $daysUntil),
                'auto_resolve' => false,
                'manual_review' => 'Beschikbaarheid checken met planning'
            ];
        }

        return $suggestions;
    }

    /**
     * Auto-calculate price based on rules
     * @param int $groupSize
     * @param array $selections (program, add-ons, etc)
     * @return array pricing breakdown
     */
    public static function autoCalculatePrice($groupSize, $selections = []) {
        $basePrice = 22.50; // Per person
        $subtotal = $basePrice * $groupSize;
        $addons = 0;
        $discounts = 0;
        $surcharges = 0;

        // Add-ons
        if (in_array('lunch', $selections['add_ons'] ?? [])) {
            $addons += 8 * $groupSize;
        }
        if (in_array('parking', $selections['add_ons'] ?? [])) {
            $addons += 2 * $groupSize;
        }
        if (in_array('guide', $selections['add_ons'] ?? [])) {
            $addons += 50;
        }

        // Discounts
        if ($groupSize >= 50) {
            $discounts += $subtotal * 0.15; // -15%
        } elseif ($groupSize >= 30) {
            $discounts += $subtotal * 0.10; // -10%
        } elseif ($groupSize >= 15) {
            $discounts += $subtotal * 0.05; // -5%
        }

        // Surcharges
        if ($groupSize < 10) {
            $surcharges += 50;
        }

        $total = $subtotal + $addons - $discounts + $surcharges;

        return [
            'base_price_per_person' => $basePrice,
            'group_size' => $groupSize,
            'subtotal' => round($subtotal, 2),
            'add_ons' => [
                'lunch' => in_array('lunch', $selections['add_ons'] ?? []) ? round(8 * $groupSize, 2) : 0,
                'parking' => in_array('parking', $selections['add_ons'] ?? []) ? round(2 * $groupSize, 2) : 0,
                'guide' => in_array('guide', $selections['add_ons'] ?? []) ? 50 : 0,
            ],
            'add_ons_total' => round($addons, 2),
            'discounts' => [
                'group_discount' => round($discounts, 2),
                'discount_percentage' => $groupSize >= 50 ? 15 : ($groupSize >= 30 ? 10 : ($groupSize >= 15 ? 5 : 0))
            ],
            'surcharges' => [
                'small_group' => $groupSize < 10 ? $surcharges : 0
            ],
            'surcharges_total' => round($surcharges, 2),
            'total' => round($total, 2),
            'price_valid' => $total >= 500 && $total <= 50000
        ];
    }
}
```

**Usage in Discovery Form**:
```php
// When operator starts new quote:
$questions = QuoteDiscoveryService::defineIntakeQuestions();
$this->renderDiscoveryForm($questions);

// After operator submits:
$suggestions = QuoteDiscoveryService::suggestProducts($_POST);
$pricing = QuoteDiscoveryService::autoCalculatePrice(
    $_POST['group_size'],
    ['add_ons' => $_POST['add_ons'] ?? []]
);
```

##### 2.3 Enhanced Error Messages (1 hour)

**Modify**: `modules/quotes/Admin/Controller.php` → renderQuoteDetail()

Add section before dashboard render:

```php
// Show validation violations
$businessValidator = new QuoteBusinessRuleValidator($quoteId);
$validation = $businessValidator->validateComplete();

if (!$validation['valid']) {
    $this->renderValidationViolations($validation, $quoteId);
}
```

**Create method in Controller**:
```php
private function renderValidationViolations($validation, $quoteId) {
    ?>
    <div class="sbdp-violations-container">
        <h3>⚠️ Problemen opgelost nodig:</h3>
        
        <?php foreach ($validation['violations'] as $violation): ?>
            <div class="violation violation-<?php echo esc_attr($violation['severity']); ?>">
                <div class="violation-message">
                    <?php echo esc_html($violation['message']); ?>
                </div>
                <div class="violation-fix">
                    <strong>Oplossing:</strong> <?php echo esc_html($violation['fix']); ?>
                    <?php if ($violation['fix_url']): ?>
                        <a href="<?php echo esc_attr($violation['fix_url']); ?>" class="button button-primary button-small">
                            Naar fix
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <style>
        .sbdp-violations-container { background: #fff8e5; padding: 15px; border-left: 4px solid #ffb81c; margin: 15px 0; }
        .violation { margin: 10px 0; padding: 10px; background: #fff; border: 1px solid #ddd; }
        .violation-error { border-left: 4px solid #dc3545; }
        .violation-warning { border-left: 4px solid #ffc107; }
        .violation-fix { margin-top: 8px; font-size: 0.9em; }
    </style>
    <?php
}
```

##### 2.4 Progress Indicators (SAFE IF READ-ONLY)

**Add to Dashboard render**:
```php
// Calculate completion %
$readinessCheck = QuoteSendReadinessValidator::inspect($quoteId);
$totalChecks = 7; // customer email, lines, blockers, version, commercial, woo, business rules
$passedChecks = count(array_filter($readinessCheck['checks'], fn($c) => $c['passed']));
$completionPercent = round(($passedChecks / $totalChecks) * 100);

?>
<div class="progress-indicator">
    <div class="progress-label">
        Voorbereiding: <?php echo $completionPercent; ?>% klaar
    </div>
    <div class="progress-bar">
        <div class="progress-fill" style="width: <?php echo $completionPercent; ?>%"></div>
    </div>
    <div class="progress-details">
        <?php foreach ($readinessCheck['checks'] as $check): ?>
            <div class="check-item <?php echo $check['passed'] ? 'passed' : 'failed'; ?>">
                <?php echo $check['passed'] ? '✅' : '❌'; ?>
                <?php echo esc_html($check['label']); ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
```

---

### PHASE 3: Configuration Engine (DEFERRED)

Do not implement this phase under current guardrails without a separate architecture decision.
Reason:
- rule-managed pricing can easily become a second commercial source of truth
- operator-managed bundle/discount logic risks bypassing Woo/runtime execution authority
- assumption auto-rules are safer than pricing auto-rules, but still need explicit scope control

#### Objective
Enable operators/managers to modify pricing rules, bundles, and auto-assumptions without code.

#### Implementation Breakdown

##### 3.1 Configuration Storage (1 hour)

**Create Migration**: `modules/quotes/Migrations/2026_05_10_create_config_rules.php`

```php
<?php
namespace BSP\Quotes\Migrations;

class CreateConfigRulesTable {
    public static function up() {
        global $wpdb;
        $table = $wpdb->prefix . 'bsp_quote_config_rules';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rule_type VARCHAR(50) NOT NULL, -- 'pricing', 'product', 'assumption', 'discount'
            rule_name VARCHAR(100) NOT NULL,
            rule_key VARCHAR(100) UNIQUE NOT NULL,
            rule_config LONGTEXT NOT NULL, -- JSON
            is_active TINYINT(1) DEFAULT 1,
            created_by BIGINT UNSIGNED,
            updated_by BIGINT UNSIGNED,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_rule_type (rule_type),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        $wpdb->query($sql);
    }
    
    public static function down() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}bsp_quote_config_rules");
    }
}
```

**Run Migration**:
```php
// In Module.php activation:
\add_action('plugin_activation', function() {
    \BSP\Quotes\Migrations\CreateConfigRulesTable::up();
});
```

##### 3.2 Configuration Service (2 hours)

**File to Create**: `modules/quotes/Service/QuoteConfigurationService.php`

```php
<?php
namespace BSP\Quotes\Service;

class QuoteConfigurationService {
    private $db;
    private $table;
    
    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'bsp_quote_config_rules';
    }
    
    /**
     * Get all active rules for a type
     */
    public function getRulesByType($type) {
        $results = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->table} WHERE rule_type = %s AND is_active = 1 ORDER BY created_at DESC",
                $type
            ),
            ARRAY_A
        );
        
        return array_map(function($row) {
            $row['rule_config'] = json_decode($row['rule_config'], true);
            return $row;
        }, $results);
    }
    
    /**
     * Create new rule
     */
    public function createRule($type, $name, $key, $config, $userId) {
        $this->db->insert($this->table, [
            'rule_type' => $type,
            'rule_name' => $name,
            'rule_key' => $key,
            'rule_config' => json_encode($config),
            'is_active' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        
        return $this->db->insert_id;
    }
    
    /**
     * Update rule
     */
    public function updateRule($ruleId, $config, $userId) {
        $this->db->update($this->table, [
            'rule_config' => json_encode($config),
            'updated_by' => $userId,
        ], ['id' => $ruleId]);
    }
    
    /**
     * Toggle rule active/inactive
     */
    public function toggleRule($ruleId, $active) {
        $this->db->update($this->table, ['is_active' => $active ? 1 : 0], ['id' => $ruleId]);
    }
    
    /**
     * Apply pricing rules to calculate total
     */
    public function applyPricingRules($basePrice, $groupSize, $addOns = []) {
        $rules = $this->getRulesByType('pricing');
        $price = $basePrice * $groupSize;
        
        foreach ($rules as $rule) {
            $config = $rule['rule_config'];
            
            // Discount by group size
            if ($config['type'] === 'discount_by_group') {
                $thresholds = $config['thresholds']; // [[50, 0.15], [30, 0.10], ...]
                foreach ($thresholds as [$threshold, $discount]) {
                    if ($groupSize >= $threshold) {
                        $price *= (1 - $discount);
                        break;
                    }
                }
            }
            
            // Seasonal multiplier
            if ($config['type'] === 'seasonal_multiplier') {
                $date = new \DateTime();
                $month = $date->format('m');
                if (isset($config['months'][$month])) {
                    $price *= $config['months'][$month];
                }
            }
            
            // Surcharge rules
            if ($config['type'] === 'surcharge') {
                if ($config['condition'] === 'group_size_below' && $groupSize < $config['threshold']) {
                    $price += $config['amount'];
                }
            }
        }
        
        return round($price, 2);
    }
    
    /**
     * Check if assumption should auto-resolve or be flagged
     */
    public function shouldFlagAssumption($type, $context) {
        $rules = $this->getRulesByType('assumption');
        
        foreach ($rules as $rule) {
            if ($rule['rule_key'] === $type) {
                $config = $rule['rule_config'];
                
                // Check condition
                if ($this->matchesCondition($context, $config['condition'])) {
                    return [
                        'should_flag' => true,
                        'severity' => $config['severity'] ?? 'warning',
                        'blocks_send' => $config['blocks_send'] ?? false,
                        'auto_resolve' => $config['auto_resolve'] ?? false
                    ];
                }
            }
        }
        
        return ['should_flag' => false];
    }
    
    private function matchesCondition($context, $condition) {
        // Examples:
        // ['group_size_below', 'value' => 10]
        // ['date_within_days', 'value' => 14]
        
        if ($condition['type'] === 'group_size_below') {
            return $context['group_size'] < $condition['value'];
        }
        
        if ($condition['type'] === 'date_within_days') {
            $days = (strtotime($context['date']) - time()) / 86400;
            return $days < $condition['value'];
        }
        
        return false;
    }
}
```

##### 3.3 Configuration Admin Pages (3 hours)

**File to Create**: `modules/quotes/Admin/Pages/ConfigurationPage.php`

```php
<?php
namespace BSP\Quotes\Admin\Pages;

class ConfigurationPage {
    private $service;
    
    public function __construct() {
        $this->service = new \BSP\Quotes\Service\QuoteConfigurationService();
    }
    
    public static function register() {
        \add_submenu_page(
            'sbdp_quotes',
            'Quote Configuration',
            '⚙️ Configuration',
            'manage_woocommerce',
            'sbdp_quote_config',
            [__CLASS__, 'render']
        );
    }
    
    public static function render() {
        $page = new self();
        
        // Handle form submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('sbdp_config_form');
            
            if (isset($_POST['action']) && $_POST['action'] === 'save_rule') {
                $page->handleSaveRule();
            }
        }
        
        $page->renderPage();
    }
    
    private function handleSaveRule() {
        $ruleType = sanitize_text_field($_POST['rule_type'] ?? '');
        $ruleName = sanitize_text_field($_POST['rule_name'] ?? '');
        $ruleKey = sanitize_text_field($_POST['rule_key'] ?? '');
        $ruleConfig = json_decode(stripslashes($_POST['rule_config_json'] ?? '{}'), true);
        
        if (!$ruleType || !$ruleName) {
            wp_die('Missing required fields');
        }
        
        $this->service->createRule(
            $ruleType,
            $ruleName,
            $ruleKey,
            $ruleConfig,
            get_current_user_id()
        );
        
        wp_redirect(add_query_arg('status', 'saved'));
        exit;
    }
    
    private function renderPage() {
        ?>
        <div class="wrap">
            <h1>📋 Quote Configuration</h1>
            
            <?php if (isset($_GET['status']) && $_GET['status'] === 'saved'): ?>
                <div class="notice notice-success"><p>✅ Configuration saved</p></div>
            <?php endif; ?>
            
            <!-- Tabs -->
            <nav class="nav-tab-wrapper">
                <a href="?page=sbdp_quote_config&tab=pricing" class="nav-tab <?php echo ($_GET['tab'] ?? 'pricing') === 'pricing' ? 'nav-tab-active' : ''; ?>">
                    💰 Pricing Rules
                </a>
                <a href="?page=sbdp_quote_config&tab=products" class="nav-tab <?php echo ($_GET['tab'] ?? 'pricing') === 'products' ? 'nav-tab-active' : ''; ?>">
                    📦 Products
                </a>
                <a href="?page=sbdp_quote_config&tab=assumptions" class="nav-tab <?php echo ($_GET['tab'] ?? 'pricing') === 'assumptions' ? 'nav-tab-active' : ''; ?>">
                    ⚠️ Auto-Assumptions
                </a>
            </nav>
            
            <div class="tab-content">
                <?php
                $tab = $_GET['tab'] ?? 'pricing';
                
                if ($tab === 'pricing') {
                    $this->renderPricingRules();
                } elseif ($tab === 'products') {
                    $this->renderProducts();
                } elseif ($tab === 'assumptions') {
                    $this->renderAssumptions();
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    private function renderPricingRules() {
        $rules = $this->service->getRulesByType('pricing');
        
        ?>
        <div class="card">
            <h2>Pricing Rules</h2>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Rule Name</th>
                        <th>Type</th>
                        <th>Config</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rules as $rule): ?>
                        <tr>
                            <td><?php echo esc_html($rule['rule_name']); ?></td>
                            <td><?php echo esc_html($rule['rule_config']['type'] ?? 'N/A'); ?></td>
                            <td><small><?php echo esc_html(json_encode($rule['rule_config'])); ?></small></td>
                            <td><?php echo $rule['is_active'] ? '✅ Active' : '❌ Inactive'; ?></td>
                            <td>
                                <a href="#" class="button button-small">Edit</a>
                                <a href="#" class="button button-small">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <hr>
            
            <h3>New Pricing Rule</h3>
            <form method="POST" class="config-form">
                <?php wp_nonce_field('sbdp_config_form'); ?>
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="rule_type" value="pricing">
                
                <div class="form-group">
                    <label>Rule Name (display only)</label>
                    <input type="text" name="rule_name" placeholder="e.g. Group Discount 10+" required>
                </div>
                
                <div class="form-group">
                    <label>Rule Key (internal reference)</label>
                    <input type="text" name="rule_key" placeholder="e.g. discount_10plus" required>
                </div>
                
                <div class="form-group">
                    <label>Rule Type</label>
                    <select name="rule_config_type" id="rule-type">
                        <option value="">Select type...</option>
                        <option value="discount_by_group">Discount by Group Size</option>
                        <option value="seasonal_multiplier">Seasonal Multiplier</option>
                        <option value="surcharge">Surcharge</option>
                    </select>
                </div>
                
                <!-- Dynamic fields based on type -->
                <div id="rule-config-fields"></div>
                
                <button type="submit" class="button button-primary">Save Rule</button>
            </form>
        </div>
        
        <script>
        document.getElementById('rule-type').addEventListener('change', function(e) {
            const type = e.target.value;
            const fieldsContainer = document.getElementById('rule-config-fields');
            
            if (type === 'discount_by_group') {
                fieldsContainer.innerHTML = `
                    <div class="form-group">
                        <label>Discount Tiers (JSON)</label>
                        <textarea name="rule_config_json" placeholder='[[50, 0.15], [30, 0.10], [15, 0.05]]'></textarea>
                    </div>
                `;
            } else if (type === 'seasonal_multiplier') {
                fieldsContainer.innerHTML = `
                    <div class="form-group">
                        <label>Monthly Multipliers (JSON)</label>
                        <textarea name="rule_config_json" placeholder='{"06": 1.15, "07": 1.20, "08": 1.15}'></textarea>
                    </div>
                `;
            }
        });
        </script>
        <?php
    }
    
    private function renderProducts() {
        echo '<p>Product catalog UI coming in Phase 3.2</p>';
    }
    
    private function renderAssumptions() {
        echo '<p>Assumption auto-rules UI coming in Phase 3.3</p>';
    }
}
```

**Register in Module.php**:
```php
\add_action('admin_menu', [ConfigurationPage::class, 'register']);
```

---

### PHASE 4: Multi-Channel (4-5 HOURS)

#### Objective
Make quote engine accessible via API, mobile UI, and customer portal.

##### 4.1 REST API Enhancements (2 hours)

Ensure `/wp-json/bsp/v1/quotes/` endpoints support:
- `GET /quotes/{id}` — Full quote data
- `POST /quotes/{id}/assumptions/{aid}/resolve` — Resolve assumption
- `POST /quotes/{id}/send` — Send proposal
- `GET /quotes?status=draft|review|sent` — Filtered list
- `GET /quotes?channel=mobile|api|portal` — Channel-aware rendering

##### 4.2 Mobile Dashboard (1.5 hours)

Create responsive CSS for existing dashboard:
```css
/* Mobile-first responsive */
@media (max-width: 768px) {
    .sbdp-dashboard { padding: 10px; }
    .dashboard-section { margin: 10px 0; }
    .assumptions-list { display: flex; flex-direction: column; }
    .checklist { font-size: 0.9em; }
    .action-button { width: 100%; padding: 15px; font-size: 1.1em; }
}
```

##### 4.3 Customer Portal (1.5 hours)

Create new template:
- Customers see: Quote status, proposal preview, next actions
- Customers can: View PDF, confirm receipt, ask questions
- No editing capability (read-only)

---

### PHASE 5: Performance (2-3 HOURS)

#### Objective
Ensure Quote Dashboard loads < 500ms even with 1000s of quotes.

##### 5.1 Query Optimization (1 hour)

Add indexes:
```sql
ALTER TABLE wp_bsp_quotes ADD INDEX idx_status_created (status, created_at);
ALTER TABLE wp_bsp_quote_assumptions ADD INDEX idx_quote_blocks (quote_id, blocks_send);
ALTER TABLE wp_bsp_quote_messages ADD INDEX idx_quote_created (quote_id, created_at DESC);
```

##### 5.2 Caching Strategy (1 hour)

```php
class QuoteDashboardCache {
    public static function getQuoteSnapshot($quoteId, $forceRefresh = false) {
        $cacheKey = "quote_snapshot_{$quoteId}";
        
        if (!$forceRefresh) {
            $cached = wp_cache_get($cacheKey);
            if ($cached) return $cached;
        }
        
        // Fresh fetch
        $snapshot = [
            'status' => /* ... */,
            'assumptions' => /* ... */,
            'readiness' => /* ... */,
            'timestamp' => time()
        ];
        
        // Cache for 5 minutes or until next change
        wp_cache_set($cacheKey, $snapshot, '', 300);
        
        return $snapshot;
    }
}

// Invalidate cache when quote changes:
\add_action('bsp/quotes/assumption_resolved', function($quoteId) {
    wp_cache_delete("quote_snapshot_{$quoteId}");
});
```

---

## ✅ TESTING CHECKLIST

### Phase 1 Validation (Already Done)
- [x] Dashboard renders without errors
- [x] Quick-prepare handler works
- [x] Assumptions quick-confirm saves
- [x] Send-guard still enforced

### Phase 2 Validation (During/After Implementation)
- [x] Business rule validator catches core release-safe violations
- [x] Error messages are actionable (include fix links)
- [ ] Discovery flow captures all required answers
- [ ] Auto-calculated pricing matches expected output
- [x] Progress indicator shows correct %

Discovery flow and auto-calculated pricing remain deferred because they can introduce a second pricing or booking truth if implemented without a separate architecture decision.

### Phase 3 Validation
- [ ] Configuration rules save/load correctly
- [ ] Pricing rules apply correctly to calculations
- [ ] Can toggle rules active/inactive
- [ ] Assumption auto-rules work as configured
- [ ] No operator edits break validation guards

### Phase 4 Validation
- [ ] API returns correct data per channel
- [x] Mobile dashboard focus card is responsive
- [ ] Customer portal shows read-only view
- [ ] No sensitive data leaks

### Phase 5 Validation
- [ ] Dashboard loads < 500ms with 1000 quotes
- [ ] Query times improve 50%+ with indexes
- [ ] Cache invalidation works correctly

### INTEGRATION TESTS (All Phases)
```php
// Test: Quote creation → assumption flagging → quick-prepare → send
$quote = Quote::create($request);
assert($quote->status === 'draft');

// Assumptions auto-flagged?
$assumptions = $quote->listAssumptions();
assert(count($assumptions) > 0);

// Quick-prepare resolves + approves + ready to send
$quote->quickPrepareToSend();
assert($quote->send_status === 'ready_to_send');
assert($quote->review_status === 'approved');

// Can send?
$sendResult = $quote->sendProposal();
assert($sendResult['success'] === true);
assert($sendResult['email_sent'] === true);
```

---

## 🚀 DEPLOYMENT CHECKLIST

### Pre-Deployment (Dev Environment)
- [ ] All 4 Phases implemented and tested
- [ ] No CSOT violations introduced
- [ ] No send-guard bypasses
- [ ] All guardrails intact
- [ ] Documentation updated
- [ ] Database migrations reversible

### Production Deployment (Staged)

**Week 1: Phase 1 + 2** (Dashboard + Smart Logic)
```bash
# 1. Backup database
mysqldump wp_dagjedenbosch > backup-2026-05-10.sql

# 2. Deploy Phase 1 code (already done)
# 3. Deploy Phase 2 code
# 4. Run migrations (if any)
# 5. Test on quotes 16-20 in production
# 6. Roll out to all operators
```

**Week 2: Phase 3** (Configuration Engine)
```bash
# 1. Deploy config admin pages
# 2. Create default configuration rules
# 3. Train managers on rule editing
# 4. Monitor operator feedback
```

**Week 3: Phase 4** (Multi-Channel)
```bash
# 1. Deploy API updates
# 2. Deploy mobile CSS
# 3. Deploy customer portal
# 4. Test integrations
```

**Week 4: Phase 5** (Performance)
```bash
# 1. Add database indexes
# 2. Deploy caching
# 3. Monitor performance metrics
# 4. Optimize as needed
```

### Post-Deployment Monitoring
- [ ] Error logs clean (no exceptions)
- [ ] Quote creation time < 1 sec
- [ ] Dashboard load time < 500ms
- [ ] Zero failed sends (no send-guard bypasses)
- [ ] Operator satisfaction feedback
- [ ] Configuration changes tracked in audit log

### Rollback Plan (If Issues Found)
```bash
# 1. Revert code to last known good version
# 2. Restore database from backup
# 3. Clear all caches
# 4. Notify operators
# 5. Post-mortem analysis
```

---

## 🛡️ GUARDRAILS VERIFICATION

Before deploying each phase, verify:

### CSOT Preserved
```php
// ✅ MUST verify:
$quote->send_status = 'ready_to_send';  // Only from DB, never from UI
$assumptions = listQuoteAssumptions();   // Only source of truth for blockers
// NOT: $readiness = calculateInUI();    // FORBIDDEN
```

### Send-Guards Active
```php
// ✅ Every send path must call:
QuoteSendReadinessValidator::assertReadyToSend($quoteId);

// NOT: if ($ui_says_ready) sendEmail();  // FORBIDDEN
```

### Participants Immutable
```php
// ✅ Read only:
$groupSize = $quote->request->group_size;

// NOT: $quote->request->group_size = 20;  // FORBIDDEN
```

### Availability Truth
```php
// ✅ Separate concerns:
$assumption->resolve();    // Assumption resolved
$booking->confirm();       // Booking confirmed (different system)

// NOT: resolve() books availability  // FORBIDDEN
```

---

## 📞 SUCCESS CRITERIA

| Metric | Target | How to Measure |
|--------|--------|---|
| Quote prep time | < 2 min | Timer from open → email sent |
| Operator clicks | 3 | Count user interactions |
| Invalid quotes submitted | 0% | No failed validations |
| Business rule changes without dev | ∞ | Count config changes |
| Dashboard load time | < 500ms | Network DevTools |
| CSOT violations | 0 | Code review + tests |
| Send-guard bypasses | 0 | Test suite |

---

## 🎓 KNOWLEDGE BASE

### Key Files Modified
- `modules/quotes/Admin/Controller.php` (dashboard + handlers)
- `modules/quotes/Module.php` (hooks)
- [NEW] `modules/quotes/Service/QuoteBusinessRuleValidator.php`
- [NEW] `modules/quotes/Service/DashboardBlockerService.php`
- [DEFERRED] `modules/quotes/Service/QuoteDiscoveryService.php`
- [DEFERRED] `modules/quotes/Service/QuoteConfigurationService.php`
- [DEFERRED] `modules/quotes/Admin/Pages/ConfigurationPage.php`

### Key Dependencies (Do Not Modify)
- `QuoteSendReadinessValidator` — Send guard
- `QuoteReviewService` — Review flow
- `QuoteCommunicationService` — Email sending
- `QuoteRepository` — Data access
- `QuoteEventLogger` — Audit trail

### Useful Queries for Testing
```sql
-- All quotes in system
SELECT id, quote_reference, status, send_status, created_at FROM wp_bsp_quotes ORDER BY created_at DESC;

-- Quotes with open send-blocking assumptions
SELECT DISTINCT q.id, q.quote_reference, COUNT(a.id) as open_blockers
FROM wp_bsp_quotes q
LEFT JOIN wp_bsp_quote_assumptions a ON q.id = a.quote_id AND a.status = 'open' AND a.blocks_send = 1
GROUP BY q.id
HAVING open_blockers > 0;

-- Configuration rules (Phase 3+)
SELECT * FROM wp_bsp_quote_config_rules WHERE is_active = 1;
```

---

## 📋 FINAL CHECKLIST BEFORE CODEX EXECUTION

- [x] Phase 1 already implemented (reference)
- [x] Safe Phase 2 operator validation implemented
- [x] Phase 2-5 fully designed (this document)
- [x] All guardrails documented
- [x] Testing strategy defined
- [x] Deployment plan created
- [x] File structure clear
- [x] Dependencies documented
- [x] Success metrics defined

**Status**: ✅ **SAFE PHASE 1-2 IMPLEMENTED; PHASE 3-5 NEED DECISION BEFORE CODE**

---

**Document Owner**: Codex Agent  
**Last Updated**: May 10, 2026  
**Version**: 1.0 Production Ready
