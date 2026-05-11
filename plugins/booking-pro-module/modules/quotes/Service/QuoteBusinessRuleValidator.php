<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteBusinessRuleValidator
{
    public function __construct(private QuoteRepositoryInterface $repository)
    {
    }

    /**
     * @return array{
     *     valid: bool,
     *     violations: array<int, array{
     *         code: string,
     *         severity: string,
     *         message: string,
     *         fix: string,
     *         fix_url: string|null
     *     }>,
     *     error_count: int,
     *     warning_count: int,
     *     checks: array<int, array{
     *         code: string,
     *         label: string,
     *         passed: bool,
     *         severity: string
     *     }>
     * }
     */
    public function validateComplete(int $quoteId): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        $requestId = (int) ($quote['quote_request_id'] ?? 0);
        $request = $requestId > 0 ? $this->repository->findQuoteRequest($requestId) : null;
        $versionId = (int) ($quote['current_version_id'] ?? 0);
        $version = $versionId > 0 ? $this->repository->findQuoteVersion($versionId) : null;
        $lines = $versionId > 0 ? $this->repository->listQuoteLines($versionId) : array();

        $violations = array();
        $checks = array();

        $hasCommercialLines = $lines !== array();
        $checks[] = array(
            'code' => 'commercial_lines',
            'label' => 'Commerciële regels aanwezig',
            'passed' => $hasCommercialLines,
            'severity' => 'error',
        );
        if (! $hasCommercialLines) {
            $violations[] = array(
                'code' => 'no_program',
                'severity' => 'error',
                'message' => 'Programmaregels ontbreken nog.',
                'fix' => 'Ga naar Build tab en voeg eerst programmaregels, producten, datum/tijd en prijs toe.',
                'fix_url' => 'build',
            );
        }

        $customerName = trim((string) ($request['requester_name'] ?? ''));
        $customerEmail = trim((string) ($request['requester_email'] ?? ''));
        $hasValidCustomer = $customerName !== '' && $customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL);
        $checks[] = array(
            'code' => 'customer_contact',
            'label' => 'Klantcontact compleet',
            'passed' => $hasValidCustomer,
            'severity' => 'error',
        );
        if (! $hasValidCustomer) {
            $violations[] = array(
                'code' => 'no_customer',
                'severity' => 'error',
                'message' => 'Klantcontact is onvolledig.',
                'fix' => 'Ga naar Follow-up / Workflow en werk naam en e-mail van de klant bij in Intake context.',
                'fix_url' => 'workflow',
            );
        }

        $preferredDate = trim((string) ($request['preferred_date'] ?? ''));
        $hasValidDate = $this->isValidFutureDate($preferredDate);
        $checks[] = array(
            'code' => 'preferred_date',
            'label' => 'Eventdatum is logisch',
            'passed' => $hasValidDate,
            'severity' => 'error',
        );
        if (! $hasValidDate) {
            $violations[] = array(
                'code' => 'date_invalid',
                'severity' => 'error',
                'message' => 'Voorkeursdatum ontbreekt, ligt in het verleden of valt buiten het ondersteunde bereik.',
                'fix' => 'Ga naar Follow-up / Workflow en zet een geldige toekomstige datum.',
                'fix_url' => 'workflow',
            );
        }

        $groupSize = (int) ($request['group_size'] ?? 0);
        $groupSizeLooksNormal = $groupSize >= 5 && $groupSize <= 500;
        $checks[] = array(
            'code' => 'group_size',
            'label' => 'Groepsgrootte binnen gebruikelijke bandbreedte',
            'passed' => $groupSizeLooksNormal,
            'severity' => 'warning',
        );
        if (! $groupSizeLooksNormal) {
            $violations[] = array(
                'code' => 'group_size_unusual',
                'severity' => 'warning',
                'message' => 'Groepsgrootte is ongebruikelijk voor deze flow.',
                'fix' => 'Controleer de groepsgrootte. Als deze commercieel moet wijzigen, maak dan een nieuwe of herziene quote in plaats van deze context direct te muteren.',
                'fix_url' => null,
            );
        }

        $versionLooksOperational = is_array($version)
            && trim((string) ($version['pricing_confidence'] ?? '')) !== ''
            && trim((string) ($version['availability_confidence'] ?? '')) !== '';
        $checks[] = array(
            'code' => 'version_context',
            'label' => 'Versiecontext aanwezig',
            'passed' => $versionLooksOperational,
            'severity' => 'warning',
        );
        if (! $versionLooksOperational) {
            $violations[] = array(
                'code' => 'version_context_incomplete',
                'severity' => 'warning',
                'message' => 'De actieve quote-versie mist nog commerciële context.',
                'fix' => 'Werk de actieve draftversie eerst commercieel uit voordat je review of verzending verwacht.',
                'fix_url' => 'build',
            );
        }

        $errorCount = count(array_filter($violations, static fn (array $violation): bool => $violation['severity'] === 'error'));
        $warningCount = count(array_filter($violations, static fn (array $violation): bool => $violation['severity'] === 'warning'));

        return array(
            'valid' => $errorCount === 0,
            'violations' => $violations,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'checks' => $checks,
        );
    }

    private function isValidFutureDate(string $date): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $timestamp = strtotime($date . ' 00:00:00');
        if ($timestamp === false) {
            return false;
        }

        $today = strtotime(gmdate('Y-m-d') . ' 00:00:00');
        $oneYearAhead = strtotime('+1 year', $today ?: time());

        return $today !== false && $timestamp >= $today && $timestamp <= $oneYearAhead;
    }
}
