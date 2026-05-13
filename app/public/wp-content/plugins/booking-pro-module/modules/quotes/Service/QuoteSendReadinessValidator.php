<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteSendReadinessValidator
{
    public function __construct(private QuoteRepositoryInterface $repository)
    {
    }

    public function assertReadyToSend(int $quoteId): void
    {
        $inspection = $this->inspect($quoteId);
        if ($inspection['ready']) {
            return;
        }

        $firstBlocker = $inspection['blockers'][0]['message'] ?? 'Quote is niet verzendklaar.';
        throw new InvalidArgumentException((string) $firstBlocker);
    }

    /**
     * @return array{
     *     ready: bool,
     *     blockers: array<int, array{code:string,message:string}>
     * }
     */
    public function inspect(int $quoteId): array
    {
        $blockers = array();

        try {
            $context = $this->buildContext($quoteId);
        } catch (InvalidArgumentException $exception) {
            return array(
                'ready' => false,
                'blockers' => array(
                    array(
                        'code' => 'context_invalid',
                        'message' => $exception->getMessage(),
                    ),
                ),
            );
        }

        $blockers = array_merge(
            $blockers,
            $this->inspectCustomerEmail($context['request']),
            $this->inspectQuoteLines($context['lines']),
            $this->inspectOpenSendBlockers($quoteId),
            $this->inspectVersionConfidence($context['version']),
            $this->inspectCommercialTotals($context['lines'], $context['version']),
            $this->inspectWooCommercialReadiness($context['lines'])
        );

        return array(
            'ready' => $blockers === array(),
            'blockers' => $blockers,
        );
    }

    /**
     * @return array{
     *     quote: array<string, mixed>,
     *     request: array<string, mixed>,
     *     version: array<string, mixed>,
     *     lines: array<int, array<string, mixed>>
     * }
     */
    private function buildContext(int $quoteId): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        $requestId = (int) ($quote['quote_request_id'] ?? 0);
        if ($requestId <= 0) {
            throw new InvalidArgumentException('Quote kan niet verzendklaar worden gezet zonder gekoppelde klantaanvraag.');
        }

        $versionId = (int) ($quote['current_version_id'] ?? 0);
        if ($versionId <= 0) {
            throw new InvalidArgumentException('Quote kan niet verzendklaar worden gezet zonder actieve versie.');
        }

        $request = $this->repository->findQuoteRequest($requestId);
        if ($request === null) {
            throw new InvalidArgumentException('Gekoppelde klantaanvraag voor quote ontbreekt.');
        }

        $version = $this->repository->findQuoteVersion($versionId);
        if ($version === null) {
            throw new InvalidArgumentException('Actieve quote-versie niet gevonden.');
        }

        return array(
            'quote' => $quote,
            'request' => $request,
            'version' => $version,
            'lines' => $this->repository->listQuoteLines($versionId),
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function inspectCustomerEmail(array $request): array
    {
        $email = trim((string) ($request['requester_email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return array(array(
                'code' => 'customer_email_invalid',
                'message' => 'Quote kan niet verzendklaar worden gezet zonder geldig klant e-mailadres.',
            ));
        }

        return array();
    }

    private function inspectOpenSendBlockers(int $quoteId): array
    {
        $blockers = array();
        foreach ($this->repository->listQuoteAssumptions($quoteId) as $assumption) {
            if ((string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }

            if (! empty($assumption['blocks_send'])) {
                $message = trim((string) ($assumption['message'] ?? ''));
                $blockers[] = array(
                    'code' => 'send_assumption_open',
                    'message' => $message !== ''
                        ? $message
                        : 'Quote kan niet verzendklaar worden gezet zolang blokkerende controles open staan.',
                );
            }
        }

        return $blockers;
    }

    /**
     * @param array<string, mixed> $version
     */
    private function inspectVersionConfidence(array $version): array
    {
        $blockers = array();
        foreach (array(
            'pricing_confidence' => 'bevestigde prijsstatus',
            'availability_confidence' => 'bevestigde beschikbaarheidsstatus',
        ) as $field => $label) {
            $value = trim((string) ($version[$field] ?? ''));
            if ($value === '' || $value === 'unknown') {
                $blockers[] = array(
                    'code' => $field . '_missing',
                    'message' => sprintf('Quote kan niet verzendklaar worden gezet zonder %s.', $label),
                );
            }
        }

        return $blockers;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $version
     */
    private function inspectCommercialTotals(array $lines, array $version): array
    {
        $blockers = array();
        $currency = null;
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $lineNumber = (int) ($line['line_number'] ?? 0);
            try {
                $lineTotal = $this->normalizeAmount($line['line_total_snapshot'] ?? null);
                $unitAmount = $this->normalizeAmount($line['unit_amount_snapshot'] ?? null);
            } catch (InvalidArgumentException $exception) {
                $blockers[] = array(
                    'code' => 'line_amount_invalid',
                    'message' => $exception->getMessage(),
                );
                continue;
            }
            $lineCurrency = strtoupper(trim((string) ($line['currency'] ?? '')));

            if ($lineTotal < 0.0 || $unitAmount < 0.0) {
                $blockers[] = array(
                    'code' => 'line_amount_negative',
                    'message' => sprintf('Quote-regel %d bevat een ongeldig negatief bedrag.', $lineNumber),
                );
            }

            $subtotal += max(0.0, $lineTotal);

            if (($lineTotal > 0.0 || $unitAmount > 0.0) && ! preg_match('/^[A-Z]{3}$/', $lineCurrency)) {
                $blockers[] = array(
                    'code' => 'line_currency_invalid',
                    'message' => sprintf('Quote-regel %d bevat een ongeldige valuta.', $lineNumber),
                );
            }

            if ($lineCurrency !== '' && preg_match('/^[A-Z]{3}$/', $lineCurrency)) {
                $currency ??= $lineCurrency;
                if ($currency !== $lineCurrency) {
                    $blockers[] = array(
                        'code' => 'mixed_currency',
                        'message' => 'Quote kan niet verzendklaar worden gezet met gemengde valuta in commerciële regels.',
                    );
                }
            }
        }

        try {
            $discountAmount = $this->resolveDiscountAmount($version);
        } catch (InvalidArgumentException $exception) {
            $blockers[] = array(
                'code' => 'quote_discount_invalid',
                'message' => $exception->getMessage(),
            );
            return $blockers;
        }

        if ($discountAmount > 0.0 && $subtotal <= 0.0) {
            $blockers[] = array(
                'code' => 'quote_discount_without_subtotal',
                'message' => 'Quote kan niet verzendklaar worden gezet met korting zonder commerciële subtotaalregels.',
            );
        } elseif ($discountAmount > $subtotal) {
            $blockers[] = array(
                'code' => 'quote_discount_exceeds_subtotal',
                'message' => 'Quote kan niet verzendklaar worden gezet omdat de korting hoger is dan het offerte-subtotaal.',
            );
        }

        return $blockers;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function inspectWooCommercialReadiness(array $lines): array
    {
        $blockers = array();
        foreach ($lines as $line) {
            if (! $this->isDirectCheckoutLine($line)) {
                continue;
            }

            $lineNumber = (int) ($line['line_number'] ?? 0);
            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId <= 0) {
                $blockers[] = array(
                    'code' => 'woo_product_missing',
                    'message' => sprintf('Quote-regel %d mist een Woo product voor directe checkout.', $lineNumber),
                );
                continue;
            }

            if (! function_exists('wc_get_product')) {
                continue;
            }

            $product = wc_get_product($productId);
            if (! is_object($product)) {
                $blockers[] = array(
                    'code' => 'woo_product_unavailable',
                    'message' => sprintf('Woo product %d is niet meer beschikbaar voor quote-regel %d.', $productId, $lineNumber),
                );
                continue;
            }

            if (method_exists($product, 'exists') && ! $product->exists()) {
                $blockers[] = array(
                    'code' => 'woo_product_unavailable',
                    'message' => sprintf('Woo product %d is niet meer beschikbaar voor quote-regel %d.', $productId, $lineNumber),
                );
                continue;
            }

            if (method_exists($product, 'is_purchasable') && ! $product->is_purchasable()) {
                $blockers[] = array(
                    'code' => 'woo_product_not_purchasable',
                    'message' => sprintf('Woo product %d is niet beschikbaar voor directe checkout in quote-regel %d.', $productId, $lineNumber),
                );
                continue;
            }

            if (method_exists($product, 'get_status')) {
                $status = (string) $product->get_status();
                if ($status !== '' && ! in_array($status, array('publish', 'private'), true)) {
                    $blockers[] = array(
                        'code' => 'woo_product_status_invalid',
                        'message' => sprintf('Woo product %d heeft een ongeldige publicatiestatus voor quote-regel %d.', $productId, $lineNumber),
                    );
                    continue;
                }
            }

            if (method_exists($product, 'get_tax_status')) {
                $taxStatus = trim((string) $product->get_tax_status());
                if ($taxStatus === '' || ! in_array($taxStatus, array('taxable', 'shipping', 'none'), true)) {
                    $blockers[] = array(
                        'code' => 'woo_product_tax_invalid',
                        'message' => sprintf('Woo product %d heeft een ongeldige btw-configuratie voor quote-regel %d.', $productId, $lineNumber),
                    );
                }
            }
        }

        return $blockers;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array{code:string,message:string}>
     */
    private function inspectQuoteLines(array $lines): array
    {
        if ($lines !== array()) {
            return array();
        }

        return array(array(
            'code' => 'quote_lines_missing',
            'message' => 'Quote kan niet verzendklaar worden gezet zonder commerciële regels.',
        ));
    }

    /**
     * @param array<string, mixed> $line
     */
    private function isDirectCheckoutLine(array $line): bool
    {
        $productId = (int) ($line['product_id'] ?? 0);
        $lineTotal = $this->normalizeAmount($line['line_total_snapshot'] ?? null);
        $unitAmount = $this->normalizeAmount($line['unit_amount_snapshot'] ?? null);
        $pricingConfidence = trim((string) ($line['pricing_confidence'] ?? ''));

        if ($productId <= 0) {
            return false;
        }

        if ($lineTotal <= 0.0 && $unitAmount <= 0.0) {
            return false;
        }

        return $pricingConfidence !== 'unknown';
    }

    private function normalizeAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Quote bevat een ongeldig commercieel bedrag.');
        }

        $amount = (float) $value;
        if (! is_finite($amount)) {
            throw new InvalidArgumentException('Quote bevat een ongeldig commercieel bedrag.');
        }

        return round($amount, 2);
    }

    /**
     * @param array<string, mixed> $version
     */
    private function resolveDiscountAmount(array $version): float
    {
        $pricingSnapshot = is_array($version['pricing_snapshot_json'] ?? null)
            ? $version['pricing_snapshot_json']
            : array();
        $adjustments = is_array($pricingSnapshot['commercial_adjustments'] ?? null)
            ? $pricingSnapshot['commercial_adjustments']
            : array();
        if (! array_key_exists('discount_amount', $adjustments) || $adjustments['discount_amount'] === '' || $adjustments['discount_amount'] === null) {
            return 0.0;
        }

        $amount = $this->normalizeAmount($adjustments['discount_amount']);
        if ($amount < 0.0) {
            throw new InvalidArgumentException('Quote bevat een ongeldige negatieve korting.');
        }

        return $amount;
    }
}
