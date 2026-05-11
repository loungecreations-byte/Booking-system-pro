<?php

declare(strict_types=1);

namespace SBDP\Pricing;

use DateTimeImmutable;
use InvalidArgumentException;
use WC_Product;

/**
 * Central pricing calculations shared across planner, REST, and checkout flows.
 */
final class PricingService
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Create a pricing quote for the provided product.
     *
     * @param array<string, mixed> $context
     */
    public function quote(int $productId, int $participants = 1, array $context = array()): array
    {
        $product = $this->requireProduct($productId);
        $participants = max(1, $participants);

        $pricing = $this->getProductPricing($productId, array_merge($context, array(
            'participants' => $participants,
        )));
        $grossBasePrice = (float) ($pricing['base_price'] ?? 0.0);
        $grossPerPerson = (float) ($pricing['per_person'] ?? 0.0);
        $supportsPersons = ! empty($pricing['supports_persons']);

        $perPersonTotalGross = $supportsPersons ? $grossPerPerson * $participants : 0.0;
        $lineSubtotalGross   = round(max(0.0, $grossBasePrice + $perPersonTotalGross), 2);

        $netBasePrice     = $this->convertGrossToNet($product, $grossBasePrice);
        $netPerPerson     = $this->convertGrossToNet($product, $grossPerPerson);
        $perPersonTotalNet = $supportsPersons ? $netPerPerson * $participants : 0.0;
        $lineSubtotalNet   = round(max(0.0, $netBasePrice + $perPersonTotalNet), 2);

        $adjustments = array();
        $fixedFeeGross = (float) ($pricing['fixed_fee'] ?? 0.0);
        $fixedFeeNet   = $this->convertGrossToNet($product, $fixedFeeGross);
        $grossAdjustmentsTotal = 0.0;
        $netAdjustmentsTotal   = 0.0;

        if ($fixedFeeGross > 0.0) {
            $adjustments[] = array(
                'label' => $this->translate('Booking fee'),
                'amount' => round($fixedFeeGross, 2),
                'scope'  => 'booking',
            );
            $grossAdjustmentsTotal += $fixedFeeGross;
            $netAdjustmentsTotal   += $fixedFeeNet;
        }

        $adjustments = $this->normalizeRows(
            $this->applyFilters('sbdp/pricing/quote_adjustments', $adjustments, $product, $participants, $context),
            'adjustment'
        );
        foreach ($adjustments as $row) {
            $grossAdjustmentsTotal += (float) $row['amount'];
            $netAdjustmentsTotal   += $this->convertGrossToNet($product, (float) $row['amount']);
        }

        $discounts = $this->normalizeRows(
            $this->applyFilters('sbdp/pricing/quote_discounts', array(), $product, $participants, $context),
            'discount'
        );

        if (($context['channel'] ?? '') === 'day_planner' && $participants >= 10 && $lineSubtotalGross > 0) {
            $discounts[] = array(
                'label'  => $this->translate('Group discount'),
                'amount' => round($lineSubtotalGross * 0.05, 2),
                'scope'  => 'booking',
            );
        }

        $grossDiscountTotal = $this->sumRows($discounts);

        $grossTaxable = $lineSubtotalGross + $grossAdjustmentsTotal;
        $netTaxable   = $lineSubtotalNet + $netAdjustmentsTotal;

        // Force unit price to be gross-based for the response.
        $unitPrice = $supportsPersons && $grossPerPerson > 0.0
            ? $grossPerPerson
            : ($grossBasePrice > 0.0 ? $grossBasePrice : $lineSubtotalGross);

        // Calculate tax based on the gross taxable amount.
        $taxDelta     = max(0.0, round($grossTaxable - $netTaxable, 2));

        $taxes = array();
        if ($taxDelta > 0.0) {
            $taxes[] = array(
                'label'  => $this->translate('Tax'),
                'amount' => $taxDelta,
                'scope'  => 'booking',
                'type'   => 'tax',
            );
        }

        // Gross input: totals stay gross; tax rows are informational.
        // We ensure total is exactly the gross amount.
        $total = round($grossTaxable - $grossDiscountTotal, 2);

        $channel  = isset($context['channel']) ? (string) $context['channel'] : 'web';
        $currency = $this->resolveCurrency($context['currency'] ?? null);

        return array(
            'product_id'  => $productId,
            'participants' => $participants,
            'channel'     => $channel,
            'currency'    => $currency,
            'line_item'   => array(
                'line_subtotal' => $lineSubtotalGross,
                'pricing'       => array(
                    'base_price'       => round($grossBasePrice, 2),
                    'per_person'       => $supportsPersons ? round($grossPerPerson, 2) : 0.0,
                    'supports_persons' => $supportsPersons,
                ),
            ),
            'adjustments' => $adjustments,
            'discounts'   => $discounts,
            'taxes'       => $taxes,
            'total'       => $total,
            'meta'        => array(
                'applied_rules'   => isset($pricing['applied_rules']) && is_array($pricing['applied_rules']) ? $pricing['applied_rules'] : array(),
                'pricing_summary' => $pricing,
                'unit_price'      => round($unitPrice, 2),
            ),
            'unit_price'  => round($unitPrice, 2),
        );
    }

    /**
     * Produce a booking-style breakdown array used by legacy flows.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function calculateBookingTotal(int $productId, int $participants, array $context = array())
    {
        $quote = $this->quote($productId, $participants, $context);

        return array(
            'product_id'      => $productId,
            'participants'    => $participants,
            'line_item'       => $quote['line_item'],
            'adjustments'     => $quote['adjustments'],
            'discounts'       => $quote['discounts'],
            'taxes'           => $quote['taxes'],
            'subtotal'        => $quote['line_item']['line_subtotal'],
            'fee'             => $this->sumRows($quote['adjustments']),
            'discount_total'  => $this->sumRows($quote['discounts']),
            'tax_total'       => $this->sumRows($quote['taxes']),
            'total'           => $quote['total'],
            'pricing'         => $quote['meta']['pricing_summary'],
            'channel'         => $quote['channel'],
            'currency'        => $quote['currency'],
        );
    }

    /**
     * Return normalized product pricing meta.
     *
     * @return array<string, mixed>
     */
    public function getProductPricing(int $productId, array $context = array()): array
    {
        $product = $this->requireProduct($productId);

        // ALWAYS default to gross for UI and general calculation unless explicitly asked for net.
        if (! isset($context['price_mode'])) {
            $context['price_mode'] = 'gross';
        }

        $peopleTypesEnabled = $this->isTruthy(get_post_meta($productId, '_sbdp_enable_person_types', true));
        $basePricePerPerson = $this->usesBasePricePerPerson($productId);
        $base      = ($peopleTypesEnabled || $basePricePerPerson) ? 0.0 : $this->readBaseAmount($product, $context);
        $perPerson = $this->readPerPersonAmount($product, $context);
        $supports  = $peopleTypesEnabled || $basePricePerPerson || $this->supportsPersons($product, $perPerson);

        if (! $supports) {
            $perPerson = 0.0;
        }

        $taxClass = (string) get_post_meta($productId, '_tax_class', true);
        $pricing = array(
            'product_id'        => $productId,
            'base_price'        => $base,
            'per_person'        => round($perPerson, 2),
            'supports_persons'  => $supports,
            'base_price_per_person' => $basePricePerPerson,
            'fixed_fee'         => $this->readFixedFee($product, $context),
            'tax_class'         => $taxClass,
        );

        return AdvancedPricingRules::resolve(
            get_post_meta($productId, '_sbdp_advanced_price_rules', true),
            $context,
            $pricing
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTaxRows(WC_Product $product, int $participants): array
    {
        if (! function_exists('wc_tax_enabled') || ! wc_tax_enabled()) {
            return array();
        }

        if (! function_exists('wc_get_price_including_tax') || ! function_exists('wc_get_price_excluding_tax')) {
            return array();
        }

        $excl = (float) wc_get_price_excluding_tax($product, array('qty' => $participants));
        $incl = (float) wc_get_price_including_tax($product, array('qty' => $participants));
        $delta = round($incl - $excl, 2);

        if ($delta <= 0.0) {
            return array();
        }

        return array(
            array(
                'label'  => $this->translate('Tax'),
                'amount' => $delta,
                'scope'  => 'booking',
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>>|mixed $rows
     */
    private function normalizeRows($rows, string $type): array
    {
        if (! is_array($rows)) {
            return array();
        }

        $normalized = array();
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $amount = isset($row['amount']) ? (float) $row['amount'] : 0.0;
            if ($amount === 0.0) {
                continue;
            }

            $label = isset($row['label']) ? (string) $row['label'] : '';
            if ($label === '') {
                $label = ucfirst($type);
            }

            $normalized[] = array(
                'label'  => $label,
                'amount' => round($amount, 2),
                'scope'  => isset($row['scope']) ? (string) $row['scope'] : $type,
                'type'   => $type,
            );
        }

        return $normalized;
    }

    /**
     * Sum the 'amount' field across multiple rows.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function sumRows(array $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sum += (float) ($row['amount'] ?? 0.0);
        }

        return round($sum, 2);
    }

    private function resolveCurrency($value): string
    {
        if (is_string($value) && $value !== '') {
            return strtoupper($value);
        }

        if (function_exists('get_option')) {
            $option = (string) get_option('woocommerce_currency', 'EUR');
            if ($option !== '') {
                return strtoupper($option);
            }
        }

        return 'EUR';
    }

    private function requireProduct(int $productId): WC_Product
    {
        if (! function_exists('wc_get_product')) {
            throw new InvalidArgumentException('WooCommerce not available.');
        }

        $product = wc_get_product($productId);
        if (! $product instanceof WC_Product) {
            throw new InvalidArgumentException('Product not found.');
        }

        return $product;
    }

    private function readBaseAmount(WC_Product $product, array $context): float
    {
        $wantsGross = true; // Always gross for B2C platform as per AGENTS.md

        // _sbdp_base_price is stored incl. BTW by admins — return as-is.
        $meta = get_post_meta($product->get_id(), '_sbdp_base_price', true);
        if (is_numeric($meta) && (float) $meta > 0.0) {
            return round((float) $meta, 2);
        }

        $raw = $product->get_price( 'edit' );
        if (is_numeric($raw) && (float) $raw > 0.0) {
            return $this->adjustForTax($product, (float) $raw, $wantsGross);
        }

        $regular = $product->get_regular_price( 'edit' );
        if (is_numeric($regular) && (float) $regular > 0.0) {
            return $this->adjustForTax($product, (float) $regular, $wantsGross);
        }

        return 0.0;
    }

    private function readPerPersonAmount(WC_Product $product, ?array $context): float
    {
        $basePricePerPerson = $this->usesBasePricePerPerson($product->get_id());

        // People types pricing: use first type price when enabled.
        $peopleTypes = get_post_meta($product->get_id(), '_sbdp_people_types', true);
        $peopleTypesEnabled = $this->isTruthy(get_post_meta($product->get_id(), '_sbdp_enable_person_types', true));
        if ($peopleTypesEnabled && is_array($peopleTypes) && $peopleTypes !== array()) {
            $first = reset($peopleTypes);
            if (is_array($first) && isset($first['price']) && is_numeric($first['price'])) {
                // People type prices are entered incl. BTW — return as-is.
                return round((float) $first['price'], 2);
            }
        }

        $meta = get_post_meta($product->get_id(), '_sbdp_price_per_person', true);
        if (is_numeric($meta) && (float) $meta > 0.0) {
            $wantsGross = true; // Always gross for B2C platform as per AGENTS.md

            return $this->adjustForTax($product, (float) $meta, $wantsGross);
        }

        if ($basePricePerPerson) {
            // _sbdp_base_price is stored incl. BTW by admins — return as-is.
            $baseMeta = get_post_meta($product->get_id(), '_sbdp_base_price', true);
            if (is_numeric($baseMeta) && (float) $baseMeta > 0.0) {
                return round((float) $baseMeta, 2);
            }
        }

        if ($basePricePerPerson) {
            $wantsGross = true; // Always gross for B2C platform as per AGENTS.md

            if ($wantsGross) {
                $raw = $product->get_price();
                if (is_numeric($raw) && (float) $raw > 0.0) {
                    return $this->adjustForTax($product, (float) $raw, true);
                }
                $regular = $product->get_regular_price();
                if (is_numeric($regular) && (float) $regular > 0.0) {
                    return $this->adjustForTax($product, (float) $regular, true);
                }
            }

            if (function_exists('wc_get_price_excluding_tax')) {
                $price = wc_get_price_excluding_tax($product, array('qty' => 1));
                if ((float) $price > 0.0) {
                    return round((float) $price, 2);
                }
            }
        }

        return 0.0;
    }

    private function adjustForTax(WC_Product $product, float $price, bool $wantsGross): float
    {
        if ($price <= 0.0) {
            return round($price, 2);
        }

        if ($wantsGross) {
            if (function_exists('wc_get_price_including_tax')) {
                $incl = wc_get_price_including_tax($product, array('price' => $price, 'qty' => 1));
                if ((float) $incl > 0.0) {
                    return round((float) $incl, 2);
                }
            }
        } else {
            if (function_exists('wc_get_price_excluding_tax')) {
                $excl = wc_get_price_excluding_tax($product, array('price' => $price, 'qty' => 1));

                if ((float) $excl > 0.0) {
                    return round((float) $excl, 2);
                }
            }
        }

        return round($price, 2);
    }

    private function convertGrossToNet(WC_Product $product, float $gross): float
    {
        if ($gross <= 0.0) {
            return round($gross, 2);
        }

        if (function_exists('wc_get_price_excluding_tax')) {
            $net = wc_get_price_excluding_tax($product, array('price' => $gross, 'qty' => 1));
            if ((float) $net > 0.0) {
                return round((float) $net, 2);
            }
        }

        return round($gross, 2);
    }

    private function supportsPersons(WC_Product $product, float $perPerson): bool
    {
        if ($this->usesBasePricePerPerson($product->get_id())) {
            return true;
        }

        $flag = get_post_meta($product->get_id(), '_sbdp_enable_people', true);
        if ($this->isTruthy($flag)) {
            return true;
        }

        return $perPerson > 0.0;
    }

    private function readFixedFee(WC_Product $product, array $context = array()): float
      {
          $fee = get_post_meta($product->get_id(), '_sbdp_base_fee', true);
          if (! is_numeric($fee) || (float) $fee <= 0.0) {
              return 0.0;
          }

          $wantsGross = true; // Always gross for B2C platform as per AGENTS.md

          return $this->adjustForTax($product, (float) $fee, $wantsGross);
      }

    private function usesBasePricePerPerson(int $productId): bool
    {
        $flag = get_post_meta($productId, '_sbdp_base_price_per_person', true);
        if ($flag !== '' && $flag !== null) {
            return $this->isTruthy($flag);
        }

        // Backward compatibility for older saves where the checkbox polluted the
        // numeric per-person field.
        $legacy = get_post_meta($productId, '_sbdp_price_per_person', true);
        if (is_string($legacy) && ! is_numeric($legacy)) {
            return $this->isTruthy($legacy);
        }

        return false;
    }

    private function isTruthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, array('1', 'yes', 'true', 'on'), true);
        }

        return (bool) $value;
    }

    /**
     * Wrapper around apply_filters to protect CLI contexts.
     *
     * @param mixed $value
     * @param mixed ...$args
     *
     * @return mixed
     */
    private function applyFilters(string $hook, $value, ...$args)
    {
        if (function_exists('apply_filters')) {
            return apply_filters($hook, $value, ...$args);
        }

        return $value;
    }

    private function translate(string $text): string
    {
        if (function_exists('__')) {
            return __($text, 'sbdp');
        }

        return $text;
    }
}

final class AdvancedPricingRules
{
    /**
     * @param mixed $rules
     * @param array<string, mixed> $context
     * @param array<string, mixed> $pricing
     * @return array<string, mixed>
     */
    public static function resolve($rules, array $context, array $pricing): array
    {
        if (! is_array($rules) || $rules === array()) {
            $pricing['applied_rules'] = array();
            return $pricing;
        }

        $participants = max(1, (int) ($context['participants'] ?? 1));
        $start = self::resolveMoment($context);
        $durationMinutes = self::resolveDurationMinutes($context);
        $matchContext = array(
            'participants' => $participants,
            'moment' => $start,
            'duration_minutes' => $durationMinutes,
        );

        $applied = array();
        foreach ($rules as $index => $rule) {
            if (! is_array($rule) || ! self::matches($rule, $matchContext)) {
                continue;
            }

            $newPrice = self::toFloatOrNull($rule['price'] ?? null);
            if ($newPrice === null || $newPrice < 0.0) {
                continue;
            }

            $target = ! empty($pricing['supports_persons']) ? 'per_person' : 'base_price';
            $pricing[$target] = round($newPrice, 2);

            if ($target === 'per_person' && ! empty($pricing['base_price_per_person'])) {
                $pricing['base_price'] = 0.0;
            }

            $applied[] = array(
                'index' => (int) $index,
                'condition' => (string) ($rule['condition'] ?? ''),
                'value' => (string) ($rule['value'] ?? ''),
                'price' => round($newPrice, 2),
                'target' => $target,
            );
        }

        $pricing['applied_rules'] = $applied;
        return $pricing;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $context
     */
    private static function matches(array $rule, array $context): bool
    {
        $condition = isset($rule['condition']) ? strtolower(trim((string) $rule['condition'])) : '';
        $value = isset($rule['value']) ? trim((string) $rule['value']) : '';

        if ($condition === '' || $value === '') {
            return false;
        }

        switch ($condition) {
            case 'people':
                return self::matchesNumberRule((int) ($context['participants'] ?? 1), $value, true);
            case 'duration':
                $duration = (int) ($context['duration_minutes'] ?? 0);
                return $duration > 0 && self::matchesNumberRule($duration, $value, true);
            case 'date':
                $moment = $context['moment'] ?? null;
                return $moment instanceof DateTimeImmutable && self::matchesDateRule($moment, $value);
            case 'weekday':
                $moment = $context['moment'] ?? null;
                return $moment instanceof DateTimeImmutable && self::matchesWeekdayRule($moment, $value);
            case 'month':
                $moment = $context['moment'] ?? null;
                return $moment instanceof DateTimeImmutable && self::matchesMonthRule($moment, $value);
            default:
                return false;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function resolveMoment(array $context): ?DateTimeImmutable
    {
        $candidates = array(
            $context['start'] ?? null,
            $context['time'] ?? null,
            $context['date'] ?? null,
            $context['preferred_date'] ?? null,
        );

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                return new DateTimeImmutable(trim($candidate));
            } catch (\Throwable $exception) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function resolveDurationMinutes(array $context): int
    {
        $candidates = array(
            $context['duration_minutes'] ?? null,
            $context['duration'] ?? null,
            $context['durationMinutes'] ?? null,
        );

        foreach ($candidates as $candidate) {
            $value = self::toFloatOrNull($candidate);
            if ($value !== null && $value > 0) {
                return (int) round($value);
            }
        }

        return 0;
    }

    private static function matchesDateRule(DateTimeImmutable $moment, string $value): bool
    {
        $date = $moment->format('Y-m-d');
        $range = self::splitRange($value);
        if ($range !== null) {
            [$from, $to] = $range;
            return $date >= $from && $date <= $to;
        }

        return $date === $value;
    }

    private static function matchesWeekdayRule(DateTimeImmutable $moment, string $value): bool
    {
        $weekday = (int) $moment->format('w');
        $tokens = preg_split('/[\s,;|\/]+/', strtolower($value)) ?: array();
        $map = array(
            '0' => 0, '7' => 0, 'sun' => 0, 'sunday' => 0, 'zo' => 0, 'zon' => 0, 'zondag' => 0,
            '1' => 1, 'mon' => 1, 'monday' => 1, 'ma' => 1, 'maa' => 1, 'maandag' => 1,
            '2' => 2, 'tue' => 2, 'tuesday' => 2, 'di' => 2, 'din' => 2, 'dinsdag' => 2,
            '3' => 3, 'wed' => 3, 'wednesday' => 3, 'wo' => 3, 'woe' => 3, 'woensdag' => 3,
            '4' => 4, 'thu' => 4, 'thursday' => 4, 'do' => 4, 'don' => 4, 'donderdag' => 4,
            '5' => 5, 'fri' => 5, 'friday' => 5, 'vr' => 5, 'vri' => 5, 'vrijdag' => 5,
            '6' => 6, 'sat' => 6, 'saturday' => 6, 'za' => 6, 'zat' => 6, 'zaterdag' => 6,
        );

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (array_key_exists($token, $map) && $map[$token] === $weekday) {
                return true;
            }
        }

        return false;
    }

    private static function matchesMonthRule(DateTimeImmutable $moment, string $value): bool
    {
        $month = (int) $moment->format('n');
        $tokens = preg_split('/[\s,;|\/]+/', strtolower($value)) ?: array();
        $map = array(
            '1' => 1, '01' => 1, 'jan' => 1, 'january' => 1, 'januari' => 1,
            '2' => 2, '02' => 2, 'feb' => 2, 'february' => 2, 'februari' => 2,
            '3' => 3, '03' => 3, 'mar' => 3, 'march' => 3, 'mrt' => 3, 'maart' => 3,
            '4' => 4, '04' => 4, 'apr' => 4, 'april' => 4,
            '5' => 5, '05' => 5, 'may' => 5, 'mei' => 5,
            '6' => 6, '06' => 6, 'jun' => 6, 'june' => 6, 'juni' => 6,
            '7' => 7, '07' => 7, 'jul' => 7, 'july' => 7, 'juli' => 7,
            '8' => 8, '08' => 8, 'aug' => 8, 'august' => 8,
            '9' => 9, '09' => 9, 'sep' => 9, 'sept' => 9, 'september' => 9,
            '10' => 10, 'oct' => 10, 'okt' => 10, 'october' => 10, 'oktober' => 10,
            '11' => 11, 'nov' => 11, 'november' => 11,
            '12' => 12, 'dec' => 12, 'december' => 12,
        );

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (array_key_exists($token, $map) && $map[$token] === $month) {
                return true;
            }
        }

        return false;
    }

    private static function matchesNumberRule(int $actual, string $value, bool $singleNumberMeansMinimum = false): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('/^(>=|<=|>|<)\s*([0-9]+(?:[.,][0-9]+)?)$/', $value, $matches) === 1) {
            $threshold = (float) str_replace(',', '.', $matches[2]);
            return match ($matches[1]) {
                '>=' => $actual >= $threshold,
                '<=' => $actual <= $threshold,
                '>' => $actual > $threshold,
                '<' => $actual < $threshold,
            };
        }

        if (preg_match('/^([0-9]+(?:[.,][0-9]+)?)\+$/', $value, $matches) === 1) {
            return $actual >= (float) str_replace(',', '.', $matches[1]);
        }

        if (preg_match('/^([0-9]+(?:[.,][0-9]+)?)\s*-\s*([0-9]+(?:[.,][0-9]+)?)$/', $value, $matches) === 1) {
            $min = (float) str_replace(',', '.', $matches[1]);
            $max = (float) str_replace(',', '.', $matches[2]);
            return $actual >= min($min, $max) && $actual <= max($min, $max);
        }

        $range = self::splitNumericRange($value);
        if ($range !== null) {
            [$min, $max] = $range;
            return $actual >= min($min, $max) && $actual <= max($min, $max);
        }

        $single = self::toFloatOrNull($value);
        if ($single === null) {
            return false;
        }

        return $singleNumberMeansMinimum ? $actual >= $single : $actual === (int) round($single);
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private static function splitRange(string $value): ?array
    {
        if (strpos($value, '>') === false) {
            return null;
        }

        $parts = array_map('trim', explode('>', $value, 2));
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return array($parts[0], $parts[1]);
    }

    /**
     * @return array{0:float,1:float}|null
     */
    private static function splitNumericRange(string $value): ?array
    {
        $range = self::splitRange($value);
        if ($range === null) {
            return null;
        }

        $from = self::toFloatOrNull($range[0]);
        $to = self::toFloatOrNull($range[1]);
        if ($from === null || $to === null) {
            return null;
        }

        return array($from, $to);
    }

    private static function toFloatOrNull($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim(str_replace(',', '.', $value));
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }
}
