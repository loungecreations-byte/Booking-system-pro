<?php

declare(strict_types=1);

namespace BSP\Quotes\Admin;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteAssumptionService;
use BSP\Quotes\Service\QuoteAdminStatusSummaryService;
use BSP\Quotes\Service\QuoteCommunicationService;
use BSP\Quotes\Service\QuoteConversionService;
use BSP\Quotes\Service\QuoteExecutionLookupService;
use BSP\Quotes\Service\QuoteExecutionLaunchService;
use BSP\Quotes\Service\QuoteExecutionRunnerService;
use BSP\Quotes\Service\DashboardBlockerService;
use BSP\Quotes\Service\QuoteBusinessRuleValidator;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteExecutionAdapterService;
use BSP\Quotes\Service\QuoteImmutabilityGuard;
use BSP\Quotes\Service\QuoteWooCartHydrationService;
use BSP\Quotes\Service\QuoteFollowupService;
use BSP\Quotes\Service\QuoteHandoffAdapterService;
use BSP\Quotes\Service\QuoteHandoffPreparationService;
use BSP\Quotes\Service\QuoteOperationsDraftService;
use BSP\Quotes\Service\QuoteProposalSendDecisionService;
use BSP\Quotes\Service\QuoteRequestService;
use BSP\Quotes\Service\QuoteReviewService;
use BSP\Quotes\Service\QuoteSendService;
use BSP\Quotes\Service\QuoteSendReadinessValidator;
use BSP\Quotes\Service\WooCartLaunchGateway;

use function add_query_arg;
use function add_menu_page;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;

final class QuoteWorkspaceRenderer
{
    private static function assertAccess(): void
    {
        if (! Controller::canManageQuotes()) {
            wp_die(esc_html__('U heeft geen toegang tot Quote OS.', 'sbdp'), 403);
        }
    }

    public static function renderQuoteRequestsPage(): void
    {
        self::assertAccess();

        $repository = new QuoteRepository();
        $requests   = $repository->listQuoteRequests();
        $quotes     = $repository->listQuotes();

        $showTestData = isset($_GET['show_testdata']) && (string) $_GET['show_testdata'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $activeView = isset($_GET['inbox_view']) ? sanitize_key((string) $_GET['inbox_view']) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (! in_array($activeView, array('all', 'new', 'action', 'ready', 'waiting', 'booked', 'problem'), true)) {
            $activeView = 'all';
        }

        $allRows = self::buildQuoteRequestInboxRows($repository, $requests, $quotes);
        $hiddenTestCount = count(array_values(array_filter($allRows, static fn (array $row): bool => ! empty($row['is_test_data']))));
        $rows = $showTestData
            ? $allRows
            : array_values(array_filter($allRows, static fn (array $row): bool => empty($row['is_test_data'])));
        $metricCounts = self::buildQuoteRequestInboxMetrics($rows);
        $filteredRows = self::filterQuoteRequestInboxRows($rows, $activeView);
        $actionNeededRows = array_values(array_filter($filteredRows, static fn (array $row): bool => ! empty($row['is_action_needed'])));

        echo '<div class="wrap"><h1>' . esc_html__('Quote Requests Inbox', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Operator-overzicht voor intake, opvolging en volgende stap per aanvraag.', 'sbdp') . '</p>';
        QuoteBuilderRenderer::renderAdminStyles();
        self::renderNotices();

        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__actions bsp-quote-admin__actions--between">';
        echo '<details class="bsp-quote-admin__request-create">';
        echo '<summary class="button button-primary">' . esc_html__('+ Nieuwe aanvraag', 'sbdp') . '</summary>';
        self::renderCreateRequestForm(false);
        echo '</details>';

        $toggleQuery = array('page' => 'sbdp_quote_requests', 'show_testdata' => $showTestData ? '0' : '1', 'inbox_view' => $activeView);
        $toggleLabel = $showTestData ? __('Verberg testdata', 'sbdp') : __('Toon testdata', 'sbdp');
        echo '<div class="bsp-quote-admin__actions bsp-quote-admin__actions--stacked">';
        echo '<a class="button" href="' . esc_url(add_query_arg($toggleQuery, admin_url('admin.php'))) . '">' . esc_html($toggleLabel) . '</a>';
        if (! $showTestData && $hiddenTestCount > 0) {
            echo '<span class="bsp-quote-admin__muted">' . esc_html(sprintf(__(' %d testregel(s) verborgen', 'sbdp'), $hiddenTestCount)) . '</span>';
        }
        echo '</div>';
        echo '</div></div></section>';

        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__overview-stat-grid">';
        echo self::renderRequestInboxMetricCard(__('Nieuwe aanvragen', 'sbdp'), $metricCounts['new'], 'new', $activeView, $showTestData);
        echo self::renderRequestInboxMetricCard(__('Actie nodig', 'sbdp'), $metricCounts['action'], 'action', $activeView, $showTestData);
        echo self::renderRequestInboxMetricCard(__('Klaar voor offerte', 'sbdp'), $metricCounts['ready'], 'ready', $activeView, $showTestData);
        echo self::renderRequestInboxMetricCard(__('Wacht op klant', 'sbdp'), $metricCounts['waiting'], 'waiting', $activeView, $showTestData);
        echo self::renderRequestInboxMetricCard(__('Geboekt', 'sbdp'), $metricCounts['booked'], 'booked', $activeView, $showTestData);
        echo self::renderRequestInboxMetricCard(__('Probleem', 'sbdp'), $metricCounts['problem'], 'problem', $activeView, $showTestData);
        echo self::renderRequestInboxMetricCard(__('Alles', 'sbdp'), count($rows), 'all', $activeView, $showTestData);
        echo '</div></div></section>';

        self::renderQuoteRequestFocusPanel($actionNeededRows);

        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('Actie nodig', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Eerst deze aanvragen/offertes afhandelen.', 'sbdp') . '</p></div><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__('Aanvraag', 'sbdp') . '</th><th>' . esc_html__('Klant', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Volgende actie', 'sbdp') . '</th><th>' . esc_html__('Actie', 'sbdp') . '</th></tr></thead><tbody>';

        if ($actionNeededRows === array()) {
            echo '<tr><td colspan="5">' . esc_html__('Geen urgente acties in de huidige filter.', 'sbdp') . '</td></tr>';
        } else {
            foreach (array_slice($actionNeededRows, 0, 10) as $row) {
                self::renderQuoteRequestPriorityRow($row);
            }
        }

        echo '</tbody></table></div></div></section>';

        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('Alle aanvragen', 'sbdp') . '</h3></div><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__('Aanvraag', 'sbdp') . '</th><th>' . esc_html__('Klant', 'sbdp') . '</th><th>' . esc_html__('Datum & groep', 'sbdp') . '</th><th>' . esc_html__('Waarde', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Volgende actie', 'sbdp') . '</th><th>' . esc_html__('Laatste update', 'sbdp') . '</th><th>' . esc_html__('Actie', 'sbdp') . '</th></tr></thead><tbody>';

        if ($filteredRows === array()) {
            echo '<tr><td colspan="8">' . esc_html__('Geen aanvragen in deze filter.', 'sbdp') . '</td></tr>';
        } else {
            foreach ($filteredRows as $row) {
                self::renderQuoteRequestInboxRow($row);
            }
        }

        echo '</tbody></table></div></div></section>';
        echo '</div>';
    }

    public static function renderQuoteOperationsPage(): void
    {
        self::assertAccess();

        $repository = new QuoteRepository();
        $quotes = $repository->listQuotes();
        $buckets = array(
            'new_requests' => array('label' => __('Nieuwe aanvragen', 'sbdp'), 'rows' => array()),
            'waiting_supplier' => array('label' => __('Wachten op partner', 'sbdp'), 'rows' => array()),
            'supplier_confirmed' => array('label' => __('Partner bevestigd', 'sbdp'), 'rows' => array()),
            'ready_to_send' => array('label' => __('Offerte klaar voor verzending', 'sbdp'), 'rows' => array()),
            'accepted_unpaid' => array('label' => __('Akkoord, nog geen betaling', 'sbdp'), 'rows' => array()),
            'paid' => array('label' => __('Betaling ontvangen', 'sbdp'), 'rows' => array()),
        );

        foreach ($repository->listQuoteRequests() as $request) {
            if (! is_array($request) || (string) ($request['status'] ?? 'new') !== 'new') {
                continue;
            }
            $buckets['new_requests']['rows'][] = array(
                'reference' => (string) ($request['request_reference'] ?? ('REQ-' . (int) ($request['id'] ?? 0))),
                'customer' => (string) (($request['requester_name'] ?? '') ?: ($request['requester_email'] ?? __('Onbekende klant', 'sbdp'))),
                'status' => __('Nieuwe aanvraag', 'sbdp'),
                'date' => (string) ($request['preferred_date'] ?? ''),
                'partner_status' => __('n.v.t.', 'sbdp'),
                'last_update' => (string) ($request['updated_at'] ?? ($request['created_at'] ?? '')),
                'action' => __('Converteer of wijs eigenaar toe', 'sbdp'),
                'url' => add_query_arg(array('page' => 'sbdp_quote_requests'), admin_url('admin.php')),
            );
        }

        foreach ($quotes as $quote) {
            if (! is_array($quote)) {
                continue;
            }
            $row = self::operationsRow($repository, $quote);
            foreach (self::operationsBucketsForQuote($repository, $quote) as $bucket) {
                $buckets[$bucket]['rows'][] = $row;
            }
        }

        $active = isset($_GET['ops_view']) ? sanitize_key((string) $_GET['ops_view']) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($active !== 'all' && ! isset($buckets[$active])) {
            $active = 'all';
        }
        $filters = array(
            'partner' => isset($_GET['ops_partner']) ? sanitize_key((string) $_GET['ops_partner']) : 'all', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'date' => isset($_GET['ops_date']) ? sanitize_text_field((string) $_GET['ops_date']) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'q' => isset($_GET['ops_q']) ? sanitize_text_field((string) $_GET['ops_q']) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        );

        echo '<div class="wrap"><h1>' . esc_html__('Operations Workspace', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Decision-first cockpit bovenop bestaande Quote OS-data. Geen aparte CRM- of checkoutlaag.', 'sbdp') . '</p>';
        QuoteBuilderRenderer::renderAdminStyles();
        self::renderNotices();
        self::renderOperationsFilters($active, $filters);
        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-body"><div class="bsp-quote-admin__overview-stat-grid">';
        foreach ($buckets as $key => $bucket) {
            $url = add_query_arg(array('page' => 'sbdp_quote_operations', 'ops_view' => $key), admin_url('admin.php'));
            echo '<a class="bsp-quote-admin__metric-card ' . esc_attr($active === $key ? 'is-active' : '') . '" href="' . esc_url($url) . '">';
            echo '<span>' . esc_html((string) $bucket['label']) . '</span><strong>' . esc_html((string) count((array) $bucket['rows'])) . '</strong></a>';
        }
        echo '</div></div></section>';

        $visibleBuckets = $active === 'all' ? $buckets : array($active => $buckets[$active]);
        foreach ($visibleBuckets as $bucket) {
            $rows = self::filterOperationsRows((array) $bucket['rows'], $filters);
            echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html((string) $bucket['label']) . '</h3></div><div class="bsp-quote-admin__panel-body">';
            echo '<div class="bsp-quote-admin__table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__('Referentie', 'sbdp') . '</th><th>' . esc_html__('Klant', 'sbdp') . '</th><th>' . esc_html__('Datum', 'sbdp') . '</th><th>' . esc_html__('Partner', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Volgende actie', 'sbdp') . '</th><th>' . esc_html__('Open', 'sbdp') . '</th></tr></thead><tbody>';
            if ($rows === array()) {
                echo '<tr><td colspan="7">' . esc_html__('Geen items in deze bucket.', 'sbdp') . '</td></tr>';
            } else {
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    echo '<tr><td><strong>' . esc_html((string) ($row['reference'] ?? '')) . '</strong><br><small class="bsp-quote-admin__muted">' . esc_html((string) ($row['last_update'] ?? '')) . '</small></td><td>' . esc_html((string) ($row['customer'] ?? '')) . '</td><td>' . esc_html((string) (($row['date'] ?? '') ?: __('In overleg', 'sbdp'))) . '</td><td>' . esc_html((string) ($row['partner_status'] ?? '')) . '</td><td>' . esc_html((string) ($row['status'] ?? '')) . '</td><td>' . esc_html((string) ($row['action'] ?? '')) . '</td><td><a class="button button-small" href="' . esc_url((string) ($row['url'] ?? '#')) . '">' . esc_html__('Open', 'sbdp') . '</a></td></tr>';
                }
            }
            echo '</tbody></table></div></div></section>';
        }
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $quote
     * @return array<int,string>
     */
    private static function renderOperationsFilters(string $active, array $filters): void
    {
        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-body">';
        echo '<form method="get" class="bsp-quote-admin__actions bsp-quote-admin__actions--between">';
        echo '<input type="hidden" name="page" value="sbdp_quote_operations">';
        echo '<input type="hidden" name="ops_view" value="' . esc_attr($active) . '">';
        echo '<label>' . esc_html__('Zoeken', 'sbdp') . '<input type="search" name="ops_q" value="' . esc_attr((string) ($filters['q'] ?? '')) . '" placeholder="' . esc_attr__('Referentie of klant', 'sbdp') . '"></label>';
        echo '<label>' . esc_html__('Datum', 'sbdp') . '<input type="date" name="ops_date" value="' . esc_attr((string) ($filters['date'] ?? '')) . '"></label>';
        echo '<label>' . esc_html__('Partnerstatus', 'sbdp') . '<select name="ops_partner">';
        $options = array(
            'all' => __('Alle statussen', 'sbdp'),
            'waiting' => __('Wacht op partner', 'sbdp'),
            'confirmed' => __('Bevestigd', 'sbdp'),
            'declined' => __('Niet beschikbaar', 'sbdp'),
            'alternative' => __('Alternatief', 'sbdp'),
            'none' => __('Geen partneractie', 'sbdp'),
        );
        foreach ($options as $value => $label) {
            $selected = (string) ($filters['partner'] ?? 'all') === $value ? ' selected' : '';
            echo '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Filter', 'sbdp') . '</button>';
        echo '<a class="button" href="' . esc_url(add_query_arg(array('page' => 'sbdp_quote_operations', 'ops_view' => $active), admin_url('admin.php'))) . '">' . esc_html__('Reset', 'sbdp') . '</a>';
        echo '</form></div></section>';
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,string> $filters
     * @return array<int,array<string,mixed>>
     */
    private static function filterOperationsRows(array $rows, array $filters): array
    {
        $partner = (string) ($filters['partner'] ?? 'all');
        $date = trim((string) ($filters['date'] ?? ''));
        $query = strtolower(trim((string) ($filters['q'] ?? '')));

        return array_values(array_filter($rows, static function (array $row) use ($partner, $date, $query): bool {
            if ($date !== '' && (string) ($row['date'] ?? '') !== $date) {
                return false;
            }
            if ($partner !== 'all' && (string) ($row['partner_filter'] ?? 'none') !== $partner) {
                return false;
            }
            if ($query !== '') {
                $haystack = strtolower((string) ($row['reference'] ?? '') . ' ' . (string) ($row['customer'] ?? '') . ' ' . (string) ($row['status'] ?? '') . ' ' . (string) ($row['action'] ?? ''));
                if (! str_contains($haystack, $query)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param array<string,mixed> $quote
     * @return array<int,string>
     */
    private static function operationsBucketsForQuote(QuoteRepositoryInterface $repository, array $quote): array
    {
        $buckets = array();
        $status = (string) ($quote['status'] ?? '');
        $sendStatus = (string) ($quote['send_status'] ?? '');
        $handoffStatus = (string) ($quote['handoff_status'] ?? '');
        $lines = self::operationsQuoteLines($repository, $quote);

        $supplierWaiting = false;
        $supplierConfirmed = false;
        foreach ($lines as $line) {
            $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
            $supplierStatus = (string) ($snapshot['supplierStatus'] ?? '');
            if (in_array($supplierStatus, array('supplier_confirmation_required', 'supplier_option_requested', 'supplier_option_held', 'supplier_alternative_proposed', 'supplier_unavailable', 'supplier_declined'), true)) {
                $supplierWaiting = true;
            }
            if ($supplierStatus === 'supplier_booking_confirmed') {
                $supplierConfirmed = true;
            }
        }

        if ($supplierWaiting) {
            $buckets[] = 'waiting_supplier';
        }
        if ($supplierConfirmed) {
            $buckets[] = 'supplier_confirmed';
        }
        if ($sendStatus === 'ready_to_send' || $status === 'reviewed') {
            $buckets[] = 'ready_to_send';
        }
        if ($status === 'accepted' && $handoffStatus !== 'woo_payment_completed') {
            $buckets[] = 'accepted_unpaid';
        }
        if ($handoffStatus === 'woo_payment_completed') {
            $buckets[] = 'paid';
        }

        return array_values(array_unique($buckets));
    }

    /**
     * @param array<string,mixed> $quote
     * @return array<string,string>
     */
    private static function operationsRow(QuoteRepositoryInterface $repository, array $quote): array
    {
        $request = null;
        $requestId = (int) ($quote['quote_request_id'] ?? 0);
        if ($requestId > 0) {
            $request = $repository->findQuoteRequest($requestId);
        }
        $customer = is_array($request)
            ? (string) (($request['requester_name'] ?? '') ?: ($request['requester_email'] ?? __('Onbekende klant', 'sbdp')))
            : __('Onbekende klant', 'sbdp');
        $status = (string) ($quote['status'] ?? 'draft');
        $handoff = (string) ($quote['handoff_status'] ?? 'not_ready');
        $lines = self::operationsQuoteLines($repository, $quote);
        $partner = self::operationsPartnerStatus($lines);
        $tab = $partner['filter'] === 'waiting' || $partner['filter'] === 'alternative' || $partner['filter'] === 'declined'
            ? 'build'
            : (((string) ($quote['send_status'] ?? '') === 'ready_to_send') ? 'communication' : 'dashboard');

        return array(
            'reference' => (string) ($quote['quote_reference'] ?? ('Q-' . (int) ($quote['id'] ?? 0))),
            'customer' => $customer,
            'date' => is_array($request) ? (string) ($request['preferred_date'] ?? '') : '',
            'partner_status' => $partner['label'],
            'partner_filter' => $partner['filter'],
            'last_update' => (string) ($quote['updated_at'] ?? ''),
            'status' => $status . ' / ' . $handoff,
            'action' => self::operationsNextAction($repository, $quote),
            'url' => self::workspaceTabUrl((int) ($quote['id'] ?? 0), $tab),
        );
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array{label:string,filter:string}
     */
    private static function operationsPartnerStatus(array $lines): array
    {
        $hasWaiting = false;
        $hasConfirmed = false;
        foreach ($lines as $line) {
            $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
            $status = (string) ($snapshot['supplierStatus'] ?? '');
            if ($status === 'supplier_alternative_proposed') {
                return array('label' => __('Alternatief voorgesteld', 'sbdp'), 'filter' => 'alternative');
            }
            if (in_array($status, array('supplier_unavailable', 'supplier_declined'), true)) {
                return array('label' => __('Niet beschikbaar', 'sbdp'), 'filter' => 'declined');
            }
            if (in_array($status, array('supplier_confirmation_required', 'supplier_option_requested', 'supplier_option_held'), true)) {
                $hasWaiting = true;
            }
            if ($status === 'supplier_booking_confirmed') {
                $hasConfirmed = true;
            }
        }

        if ($hasWaiting) {
            return array('label' => __('Wacht op partner', 'sbdp'), 'filter' => 'waiting');
        }
        if ($hasConfirmed) {
            return array('label' => __('Bevestigd', 'sbdp'), 'filter' => 'confirmed');
        }

        return array('label' => __('Geen partneractie', 'sbdp'), 'filter' => 'none');
    }

    /**
     * @param array<string,mixed> $quote
     */
    private static function operationsNextAction(QuoteRepositoryInterface $repository, array $quote): string
    {
        $lines = self::operationsQuoteLines($repository, $quote);
        foreach ($lines as $line) {
            $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
            if (in_array((string) ($snapshot['supplierStatus'] ?? ''), array('supplier_confirmation_required', 'supplier_option_requested', 'supplier_alternative_proposed', 'supplier_unavailable', 'supplier_declined'), true)) {
                return __('Volg partnerbevestiging op', 'sbdp');
            }
        }
        if ((string) ($quote['send_status'] ?? '') === 'ready_to_send') {
            return __('Verstuur offerte', 'sbdp');
        }
        if ((string) ($quote['status'] ?? '') === 'accepted' && (string) ($quote['handoff_status'] ?? '') !== 'woo_payment_completed') {
            return __('Rond betaling/orderflow af', 'sbdp');
        }
        if ((string) ($quote['handoff_status'] ?? '') === 'woo_payment_completed') {
            return __('Maak operationele opvolging af', 'sbdp');
        }
        return __('Controleer quote', 'sbdp');
    }

    /**
     * @param array<string,mixed> $quote
     * @return array<int,array<string,mixed>>
     */
    private static function operationsQuoteLines(QuoteRepositoryInterface $repository, array $quote): array
    {
        $versionId = (int) ($quote['current_version_id'] ?? 0);
        return $versionId > 0 ? $repository->listQuoteLines($versionId) : array();
    }

    /**
     * @param array<int, array<string, mixed>> $requests
     * @param array<int, array<string, mixed>> $quotes
     * @return array<int, array<string, mixed>>
     */
    private static function buildQuoteRequestInboxRows(QuoteRepositoryInterface $repository, array $requests, array $quotes): array
    {
        $quotesByRequestId = array();
        foreach ($quotes as $quote) {
            if (! is_array($quote)) {
                continue;
            }

            $requestId = (int) ($quote['quote_request_id'] ?? 0);
            if ($requestId <= 0) {
                continue;
            }

            if (! isset($quotesByRequestId[$requestId])) {
                $quotesByRequestId[$requestId] = $quote;
                continue;
            }

            $existingUpdatedAt = (string) ($quotesByRequestId[$requestId]['updated_at'] ?? '');
            $candidateUpdatedAt = (string) ($quote['updated_at'] ?? '');
            if ($candidateUpdatedAt > $existingUpdatedAt) {
                $quotesByRequestId[$requestId] = $quote;
            }
        }

        $rows = array();
        foreach ($requests as $request) {
            if (! is_array($request)) {
                continue;
            }

            $requestId = (int) ($request['id'] ?? 0);
            if ($requestId <= 0) {
                continue;
            }

            $quote = isset($quotesByRequestId[$requestId]) && is_array($quotesByRequestId[$requestId])
                ? $quotesByRequestId[$requestId]
                : null;
            $requester = self::extractRequesterContext($request);

            $currentVersion = null;
            $lines = array();
            $sendReadiness = array('ready' => false, 'blockers' => array());
            $openSendAssumptions = 0;
            $hasOpenSendBlockers = false;
            $handoffFailed = false;

            $pricingConfidence = (string) ($request['pricing_confidence'] ?? 'unknown');
            $availabilityConfidence = (string) ($request['availability_confidence'] ?? 'unknown');

            if (is_array($quote)) {
                $currentVersion = self::resolveCurrentQuoteVersion($repository, $quote);
                if (is_array($currentVersion)) {
                    $pricingConfidence = (string) ($currentVersion['pricing_confidence'] ?? $pricingConfidence);
                    $availabilityConfidence = (string) ($currentVersion['availability_confidence'] ?? $availabilityConfidence);
                    $lines = $repository->listQuoteLines((int) ($currentVersion['id'] ?? 0));
                }

                $sendReadiness = self::inspectQuoteSendReadiness((int) ($quote['id'] ?? 0), $quote, $currentVersion, $repository);
                $assumptions = $repository->listQuoteAssumptions((int) ($quote['id'] ?? 0));
                foreach ($assumptions as $assumption) {
                    if (! is_array($assumption)) {
                        continue;
                    }
                    if ((string) ($assumption['status'] ?? 'open') === 'open' && ! empty($assumption['blocks_send'])) {
                        $openSendAssumptions++;
                    }
                }

                $sendReadinessCodes = array_map(
                    static fn (array $blocker): string => (string) ($blocker['code'] ?? ''),
                    array_values(array_filter((array) ($sendReadiness['blockers'] ?? array()), static fn ($blocker): bool => is_array($blocker)))
                );
                $hasOpenSendBlockers = $openSendAssumptions > 0 || in_array('send_assumption_open', $sendReadinessCodes, true);

                $handoffStatus = (string) ($quote['handoff_status'] ?? 'not_ready');
                $handoffFailed = in_array($handoffStatus, array('resnapshot_blocked', 'failed', 'error'), true)
                    || str_contains($handoffStatus, 'blocked')
                    || str_contains($handoffStatus, 'failed');
            }

            $lineCount = count($lines);
            $hasQuoteLines = $lineCount > 0;
            $isPricingUnknown = $pricingConfidence === 'unknown';
            $isAvailabilityUnknown = ! in_array($availabilityConfidence, array('confirmed'), true);
            $requestIsNew = (string) ($request['status'] ?? 'new') === 'new' && ! is_array($quote);

            $humanStatus = self::mapQuoteRequestHumanStatus($request, $quote, $hasQuoteLines, $pricingConfidence, $availabilityConfidence, $hasOpenSendBlockers, $handoffFailed);
            $nextAction = self::mapQuoteRequestNextAction($humanStatus, $requestIsNew, $hasQuoteLines, $pricingConfidence, $availabilityConfidence, $quote, $handoffFailed);

            $rows[] = array(
                'request' => $request,
                'quote' => $quote,
                'requester' => $requester,
                'human_status' => $humanStatus,
                'next_action' => $nextAction,
                'pricing_confidence' => $pricingConfidence,
                'availability_confidence' => $availabilityConfidence,
                'line_count' => $lineCount,
                'has_quote_lines' => $hasQuoteLines,
                'open_send_assumptions' => $openSendAssumptions,
                'has_open_send_blockers' => $hasOpenSendBlockers,
                'handoff_failed' => $handoffFailed,
                'request_is_new' => $requestIsNew,
                'is_action_needed' => $requestIsNew
                    || ! $hasQuoteLines
                    || $isPricingUnknown
                    || $isAvailabilityUnknown
                    || $hasOpenSendBlockers
                    || $handoffFailed,
                'is_test_data' => self::isLikelyTestData($request, $requester),
                'value_display' => self::resolveQuoteRequestValueDisplay($lines),
                'last_update' => self::resolveQuoteRequestLastUpdate($request, $quote),
                'links' => self::buildQuoteRequestLinks($request, $quote),
            );
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string) ($right['last_update'] ?? ''), (string) ($left['last_update'] ?? ''));
        });

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{new:int,action:int,ready:int,waiting:int,booked:int,problem:int}
     */
    private static function buildQuoteRequestInboxMetrics(array $rows): array
    {
        $counts = array('new' => 0, 'action' => 0, 'ready' => 0, 'waiting' => 0, 'booked' => 0, 'problem' => 0);
        foreach ($rows as $row) {
            $status = (string) ($row['human_status'] ?? 'Aanvullen nodig');
            if ($status === 'Nieuwe aanvraag') {
                $counts['new']++;
            }
            if (! empty($row['is_action_needed'])) {
                $counts['action']++;
            }
            if ($status === 'Klaar om te versturen') {
                $counts['ready']++;
            }
            if ($status === 'Wacht op klant') {
                $counts['waiting']++;
            }
            if ($status === 'Geboekt') {
                $counts['booked']++;
            }
            if ($status === 'Probleem') {
                $counts['problem']++;
            }
        }

        return $counts;
    }

    private static function renderRequestInboxMetricCard(string $label, int $count, string $view, string $activeView, bool $showTestData): string
    {
        $url = add_query_arg(
            array(
                'page' => 'sbdp_quote_requests',
                'inbox_view' => $view,
                'show_testdata' => $showTestData ? '1' : '0',
            ),
            admin_url('admin.php')
        );
        $class = 'bsp-quote-admin__overview-stat' . ($activeView === $view ? ' is-active' : '');

        return '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $count) . '</strong></a>';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function filterQuoteRequestInboxRows(array $rows, string $activeView): array
    {
        if ($activeView === 'all') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($activeView): bool {
            $status = (string) ($row['human_status'] ?? '');
            return match ($activeView) {
                'new' => $status === 'Nieuwe aanvraag',
                'action' => ! empty($row['is_action_needed']),
                'ready' => $status === 'Klaar om te versturen',
                'waiting' => $status === 'Wacht op klant',
                'booked' => $status === 'Geboekt',
                'problem' => $status === 'Probleem',
                default => true,
            };
        }));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderQuoteRequestPriorityRow(array $row): void
    {
        $request = is_array($row['request'] ?? null) ? $row['request'] : array();
        $requester = is_array($row['requester'] ?? null) ? $row['requester'] : array();
        $reason = self::quoteRequestActionReason($row);

        echo '<tr>';
        echo '<td><strong>' . esc_html((string) ($request['request_reference'] ?? '')) . '</strong><br><span class="bsp-quote-admin__muted">' . esc_html((string) ($request['request_summary'] ?? '')) . '</span></td>';
        echo '<td><strong>' . esc_html((string) (($requester['name'] ?? '') ?: __('Onbekend', 'sbdp'))) . '</strong><br><span class="bsp-quote-admin__muted">' . esc_html((string) ($requester['email'] ?? '')) . '</span></td>';
        echo '<td>' . self::renderQuoteRequestHumanStatusBadge((string) ($row['human_status'] ?? 'Aanvullen nodig')) . '</td>';
        echo '<td><strong>' . esc_html((string) ($row['next_action'] ?? __('Geen actie nodig', 'sbdp'))) . '</strong>';
        if ($reason !== '') {
            echo '<br><span class="bsp-quote-admin__muted">' . esc_html($reason) . '</span>';
        }
        echo '</td>';
        echo '<td>' . self::renderQuoteRequestActionCell($row) . '</td>';
        echo '</tr>';
    }

    /**
     * @param array<int, array<string, mixed>> $actionNeededRows
     */
    private static function renderQuoteRequestFocusPanel(array $actionNeededRows): void
    {
        if ($actionNeededRows === array()) {
            return;
        }

        $first = $actionNeededRows[0];
        $request = is_array($first['request'] ?? null) ? $first['request'] : array();
        $requester = is_array($first['requester'] ?? null) ? $first['requester'] : array();
        $reason = self::quoteRequestActionReason($first);

        echo '<section class="postbox bsp-quote-admin__panel bsp-quote-admin__overview-next"><div class="bsp-quote-admin__panel-body">';
        echo '<span class="bsp-quote-admin__field-label">' . esc_html__('Eerst doen', 'sbdp') . '</span>';
        echo '<strong>' . esc_html((string) ($first['next_action'] ?? __('Beoordeel aanvraag', 'sbdp'))) . '</strong>';
        echo '<p>' . esc_html((string) ($request['request_reference'] ?? '')) . ' · ' . esc_html((string) (($requester['name'] ?? '') ?: __('Onbekende klant', 'sbdp'))) . '</p>';
        if ($reason !== '') {
            echo '<p class="bsp-quote-admin__muted">' . esc_html($reason) . '</p>';
        }
        echo self::renderQuoteRequestActionCell($first);
        echo '</div></section>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function quoteRequestActionReason(array $row): string
    {
        if (! empty($row['request_is_new'])) {
            return 'Nieuwe aanvraag zonder gekoppelde offerte.';
        }
        if (empty($row['has_quote_lines'])) {
            return 'Nog geen programmaregels of prijsregels aanwezig.';
        }
        if ((string) ($row['pricing_confidence'] ?? 'unknown') === 'unknown') {
            return 'Prijs is nog onbekend en moet commercieel bevestigd worden.';
        }
        if (! in_array((string) ($row['availability_confidence'] ?? 'unknown'), array('confirmed'), true)) {
            return 'Beschikbaarheid is nog niet bevestigd.';
        }
        if (! empty($row['has_open_send_blockers'])) {
            return 'Er staan nog open send-blockers op de offerte.';
        }
        if (! empty($row['handoff_failed'])) {
            return 'Handoff/executie status geeft een blokkade.';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderQuoteRequestInboxRow(array $row): void
    {
        $request = is_array($row['request'] ?? null) ? $row['request'] : array();
        $requester = is_array($row['requester'] ?? null) ? $row['requester'] : array();
        $preferredDate = (string) ($request['preferred_date'] ?? '');
        $groupSize = (int) ($request['group_size'] ?? 0);

        echo '<tr>';
        echo '<td><strong>' . esc_html((string) ($request['request_reference'] ?? '')) . '</strong>';
        echo '<br><span class="bsp-quote-admin__muted">' . esc_html((string) ($request['request_summary'] ?? '')) . '</span>';
        if (! empty($row['is_test_data'])) {
            echo '<br>' . self::renderInlineBadge(__('Testdata', 'sbdp'), 'is-neutral');
        }
        echo '</td>';

        $contact = array_filter(array(
            (string) ($requester['email'] ?? ''),
            (string) ($requester['phone'] ?? ''),
            (string) ($requester['company'] ?? ''),
        ));
        echo '<td><strong>' . esc_html((string) (($requester['name'] ?? '') ?: __('Onbekend', 'sbdp'))) . '</strong>';
        if ($contact !== array()) {
            echo '<br><span class="bsp-quote-admin__muted">' . esc_html(implode(' | ', $contact)) . '</span>';
        }
        echo '</td>';

        echo '<td><strong>' . esc_html($preferredDate !== '' ? $preferredDate : __('Geen datum', 'sbdp')) . '</strong>';
        echo '<br><span class="bsp-quote-admin__muted">' . esc_html($groupSize > 0 ? sprintf(__('%d personen', 'sbdp'), $groupSize) : __('Groep open', 'sbdp')) . '</span></td>';

        echo '<td><strong>' . esc_html((string) ($row['value_display'] ?? __('Nog niet bepaald', 'sbdp'))) . '</strong>';
        echo '<br>' . self::renderQuoteRequestConfidenceBadges((string) ($row['pricing_confidence'] ?? 'unknown'), (string) ($row['availability_confidence'] ?? 'unknown'));
        echo '</td>';

        echo '<td>' . self::renderQuoteRequestHumanStatusBadge((string) ($row['human_status'] ?? 'Aanvullen nodig')) . '</td>';
        echo '<td><strong>' . esc_html((string) ($row['next_action'] ?? __('Geen actie nodig', 'sbdp'))) . '</strong></td>';
        echo '<td>' . esc_html((string) ($row['last_update'] ?? '')) . '</td>';
        echo '<td>' . self::renderQuoteRequestActionCell($row) . '</td>';
        echo '</tr>';
    }

    private static function mapQuoteRequestHumanStatus(
        array $request,
        ?array $quote,
        bool $hasQuoteLines,
        string $pricingConfidence,
        string $availabilityConfidence,
        bool $hasOpenSendBlockers,
        bool $handoffFailed
    ): string {
        if ($handoffFailed) {
            return 'Probleem';
        }

        $wooOrderId = (int) ($quote['woo_order_id'] ?? 0);
        if ($wooOrderId > 0) {
            return 'Geboekt';
        }

        $quoteStatus = (string) ($quote['status'] ?? '');
        if (in_array($quoteStatus, array('accepted', 'confirmed'), true) && $wooOrderId <= 0) {
            return 'Akkoord / betaling';
        }

        $sendStatus = (string) ($quote['send_status'] ?? '');
        if (in_array($quoteStatus, array('sent'), true) || in_array($sendStatus, array('sent', 'sent_manual'), true)) {
            return 'Wacht op klant';
        }

        $reviewStatus = (string) ($quote['review_status'] ?? 'not_started');
        if ($reviewStatus === 'approved' && $sendStatus === 'ready_to_send' && ! $hasOpenSendBlockers) {
            return 'Klaar om te versturen';
        }

        if (is_array($quote) && (! $hasQuoteLines || $pricingConfidence === 'unknown' || $availabilityConfidence === 'unknown' || $hasOpenSendBlockers)) {
            return 'Aanvullen nodig';
        }

        if ((string) ($request['status'] ?? 'new') === 'new' && ! is_array($quote)) {
            return 'Nieuwe aanvraag';
        }

        return 'Aanvullen nodig';
    }

    private static function mapQuoteRequestNextAction(
        string $humanStatus,
        bool $requestIsNew,
        bool $hasQuoteLines,
        string $pricingConfidence,
        string $availabilityConfidence,
        ?array $quote,
        bool $handoffFailed
    ): string {
        if ($requestIsNew) {
            return 'Beoordeel aanvraag';
        }
        if (! $hasQuoteLines) {
            return 'Programma + prijs toevoegen';
        }
        if (in_array($pricingConfidence, array('unknown', 'snapshot', 'projected', 'directional'), true)) {
            return 'Prijs bevestigen';
        }
        if (! in_array($availabilityConfidence, array('confirmed'), true)) {
            return 'Beschikbaarheid bevestigen';
        }
        if ($humanStatus === 'Klaar om te versturen') {
            return 'Offerte versturen';
        }
        if ($humanStatus === 'Wacht op klant') {
            return 'Klant opvolgen';
        }
        if ($humanStatus === 'Akkoord / betaling') {
            return 'Betaling controleren';
        }
        if ($humanStatus === 'Geboekt' || in_array((string) ($quote['status'] ?? ''), array('confirmed'), true)) {
            return 'Uitvoering voorbereiden';
        }
        if ($handoffFailed || $humanStatus === 'Probleem') {
            return 'Uitvoering voorbereiden';
        }

        return 'Geen actie nodig';
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private static function resolveQuoteRequestValueDisplay(array $lines): string
    {
        $total = 0.0;
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $total += (float) ($line['line_total_snapshot'] ?? 0.0);
        }

        if ($total <= 0.0) {
            return (string) __('Nog niet bepaald', 'sbdp');
        }

        return self::formatMoney($total, 'EUR');
    }

    private static function resolveQuoteRequestLastUpdate(array $request, ?array $quote): string
    {
        $requestUpdated = (string) ($request['updated_at'] ?? $request['created_at'] ?? '');
        $quoteUpdated = is_array($quote) ? (string) ($quote['updated_at'] ?? $quote['created_at'] ?? '') : '';

        if ($quoteUpdated === '' || $requestUpdated >= $quoteUpdated) {
            return $requestUpdated;
        }

        return $quoteUpdated;
    }

    /**
     * @return array<string, string>
     */
    private static function buildQuoteRequestLinks(array $request, ?array $quote): array
    {
        $requestId = (int) ($request['id'] ?? 0);
        $links = array(
            'request' => add_query_arg(array('page' => 'sbdp_quote_requests', 'request_id' => $requestId), admin_url('admin.php')),
            'quote' => '',
            'build' => '',
            'communication' => '',
            'order' => '',
        );

        if (! is_array($quote)) {
            return $links;
        }

        $quoteId = (int) ($quote['id'] ?? 0);
        if ($quoteId <= 0) {
            return $links;
        }

        $links['quote'] = add_query_arg(array('page' => 'sbdp_quotes', 'quote_id' => $quoteId, 'workspace_tab' => 'dashboard'), admin_url('admin.php'));
        $links['build'] = add_query_arg(array('page' => 'sbdp_quotes', 'quote_id' => $quoteId, 'workspace_tab' => 'build'), admin_url('admin.php'));
        $links['communication'] = add_query_arg(array('page' => 'sbdp_quotes', 'quote_id' => $quoteId, 'workspace_tab' => 'communication'), admin_url('admin.php'));

        if (! empty($quote['woo_order_id'])) {
            $links['order'] = add_query_arg(array('post' => (int) $quote['woo_order_id'], 'action' => 'edit'), admin_url('post.php'));
        }

        return $links;
    }

    private static function renderQuoteRequestHumanStatusBadge(string $humanStatus): string
    {
        $class = match ($humanStatus) {
            'Geboekt', 'Klaar om te versturen' => 'is-good',
            'Probleem', 'Aanvullen nodig' => 'is-warn',
            default => 'is-neutral',
        };

        return self::renderInlineBadge($humanStatus, $class);
    }

    private static function renderQuoteRequestConfidenceBadges(string $pricingConfidence, string $availabilityConfidence): string
    {
        $pricingLabel = match ($pricingConfidence) {
            'execution_verified', 'confirmed' => __('Prijs: bevestigd', 'sbdp'),
            'snapshot', 'projected', 'directional' => __('Prijs: richtprijs', 'sbdp'),
            default => __('Prijs: onbekend', 'sbdp'),
        };
        $availabilityLabel = match ($availabilityConfidence) {
            'confirmed' => __('Beschikbaarheid: bevestigd', 'sbdp'),
            default => __('Beschikbaarheid: onbekend', 'sbdp'),
        };

        $pricingClass = in_array($pricingConfidence, array('execution_verified', 'confirmed'), true) ? 'is-good' : ($pricingConfidence === 'unknown' ? 'is-neutral' : 'is-warn');
        $availabilityClass = $availabilityConfidence === 'confirmed' ? 'is-good' : 'is-neutral';

        return self::renderInlineBadge($pricingLabel, $pricingClass)
            . ' '
            . self::renderInlineBadge($availabilityLabel, $availabilityClass);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderQuoteRequestActionCell(array $row): string
    {
        $links = is_array($row['links'] ?? null) ? $row['links'] : array();
        $nextAction = (string) ($row['next_action'] ?? 'Geen actie nodig');
        $request = is_array($row['request'] ?? null) ? $row['request'] : array();
        $quote = is_array($row['quote'] ?? null) ? $row['quote'] : null;
        $requestId = (int) ($request['id'] ?? 0);

        $primary = '';
        if ($nextAction === 'Beoordeel aanvraag') {
            $primary = '<a class="button button-secondary" href="' . esc_url((string) ($links['request'] ?? '')) . '">' . esc_html__('Open aanvraag', 'sbdp') . '</a>';
        } elseif (in_array($nextAction, array('Programma + prijs toevoegen', 'Prijs bevestigen', 'Beschikbaarheid bevestigen'), true)) {
            $primary = '<a class="button button-primary" href="' . esc_url((string) ($links['build'] ?? '')) . '">' . esc_html__('Offerte aanvullen', 'sbdp') . '</a>';
        } elseif ($nextAction === 'Offerte versturen') {
            $primary = '<a class="button button-primary" href="' . esc_url((string) ($links['communication'] ?? '')) . '">' . esc_html__('Verstuur voorstel', 'sbdp') . '</a>';
        } elseif ($nextAction === 'Uitvoering voorbereiden' && (string) ($links['order'] ?? '') !== '') {
            $primary = '<a class="button button-secondary" href="' . esc_url((string) $links['order']) . '">' . esc_html__('Bekijk order', 'sbdp') . '</a>';
        } else {
            $primary = '<a class="button button-secondary" href="' . esc_url((string) (($links['quote'] ?? '') !== '' ? $links['quote'] : ($links['request'] ?? ''))) . '">' . esc_html__('Open offerte', 'sbdp') . '</a>';
        }

        $secondary = '';
        if ($quote === null && $requestId > 0) {
            $secondary = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">'
                . wp_nonce_field('sbdp_quote_request_convert', '_wpnonce', true, false)
                . '<input type="hidden" name="action" value="sbdp_quote_request_convert">'
                . '<input type="hidden" name="quote_request_id" value="' . esc_attr((string) $requestId) . '">'
                . '<button class="button-link" type="submit">' . esc_html__('Offerte aanmaken', 'sbdp') . '</button>'
                . '</form>';
        } elseif ((string) ($links['quote'] ?? '') !== '') {
            $secondary = '<a class="button-link" href="' . esc_url((string) $links['quote']) . '">' . esc_html__('Open offerte', 'sbdp') . '</a>';
        }

        return '<div class="bsp-quote-admin__actions bsp-quote-admin__actions--stacked">' . $primary . $secondary . '</div>';
    }

    private static function isLikelyTestData(array $request, array $requester): bool
    {
        $signals = 0;
        $email = strtolower(trim((string) ($requester['email'] ?? '')));
        if ($email !== '' && (str_contains($email, 'example.test') || str_contains($email, 'qa') || str_contains($email, 'test'))) {
            $signals++;
        }

        $textCorpus = strtolower(implode(' ', array(
            (string) ($request['request_reference'] ?? ''),
            (string) ($request['request_summary'] ?? ''),
            (string) ($requester['name'] ?? ''),
        )));
        if ($textCorpus !== '' && preg_match('/\b(qa|test|dummy|codex|smoke)\b/', $textCorpus) === 1) {
            $signals++;
        }

        $sourcePayload = $request['source_payload'] ?? null;
        if (is_array($sourcePayload) && ! empty($sourcePayload['is_test'])) {
            $signals++;
        }

        return $signals >= 2;
    }

    public static function renderQuotesPage(): void
    {
        self::assertAccess();

        $repository = new QuoteRepository();
        $quoteId    = isset($_GET['quote_id']) ? (int) $_GET['quote_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $quotes       = $repository->listQuotes();
        $overviewRows = self::buildQuoteOverviewRows($repository, $quotes);

        QuoteBuilderRenderer::renderAdminStyles();
        self::renderNotices();

        echo '<div class="wrap bsp-quote-admin__command-wrap">';
        echo '<div class="bsp-quote-admin__command-shell">';

        // LEFT: compact quote sidebar
        echo '<aside class="bsp-quote-admin__quote-sidebar">';
        echo '<div class="bsp-quote-admin__sidebar-header"><strong>Quotes</strong></div>';
        echo '<nav class="bsp-quote-admin__sidebar-list">';
        foreach ($overviewRows as $row) {
            $rowQuote    = is_array($row['quote'] ?? null) ? $row['quote'] : array();
            $rowQuoteId  = (int) ($rowQuote['id'] ?? 0);
            $rowReq      = is_array($row['requester'] ?? null) ? $row['requester'] : array();
            $clientName  = trim((string) ($rowReq['name'] ?? ''));
            if ($clientName === '') {
                $clientName = __('Onbekend', 'sbdp');
            }
            $category    = (string) ($row['category'] ?? 'action');
            $focusLabel  = (string) ($row['focus_label'] ?? '');
            $reqArr      = is_array($row['request'] ?? null) ? $row['request'] : array();
            $eventDate   = trim((string) ($reqArr['preferred_date'] ?? ''));
            $ref         = (string) ($rowQuote['quote_reference'] ?? '#' . $rowQuoteId);
            $isActive    = $rowQuoteId === $quoteId;
            $url         = esc_url(add_query_arg(array('page' => 'sbdp_quotes', 'quote_id' => $rowQuoteId), admin_url('admin.php')));
            $urgencyMap  = array('action' => 'action', 'assumptions' => 'assumptions', 'ready' => 'ready', 'done' => 'done');
            $urgencyClass = 'bsp-quote-admin__sidebar-urgency--' . ($urgencyMap[$category] ?? 'action');
            echo '<a class="bsp-quote-admin__sidebar-item' . ($isActive ? ' is-active' : '') . '" href="' . $url . '">';
            echo '<span class="bsp-quote-admin__sidebar-item-name">' . esc_html($clientName) . '</span>';
            echo '<span class="bsp-quote-admin__sidebar-item-meta">' . esc_html($ref) . ($eventDate !== '' ? ' · ' . esc_html($eventDate) : '') . '</span>';
            if ($focusLabel !== '') {
                echo '<span class="bsp-quote-admin__sidebar-item-focus">';
                echo '<span class="bsp-quote-admin__sidebar-urgency ' . esc_attr($urgencyClass) . '"></span>';
                echo '<span class="bsp-quote-admin__sidebar-focus-label">' . esc_html($focusLabel) . '</span>';
                echo '</span>';
            }
            echo '</a>';
        }
        if ($overviewRows === array()) {
            echo '<p class="bsp-quote-admin__empty-state">' . esc_html__('Nog geen quotes.', 'sbdp') . '</p>';
        }
        echo '</nav>';
        echo '</aside>';

        // RIGHT: detail or splash
        echo '<main class="bsp-quote-admin__command-main">';
        if ($quoteId > 0) {
            self::renderQuoteWorkspaceContent($repository, $quoteId);
        } else {
            echo '<div class="bsp-quote-admin__command-splash">';
            echo '<h2>' . esc_html__('Selecteer een quote', 'sbdp') . '</h2>';
            echo '<p>' . esc_html__('Klik op een quote in de linkerkolom om de workspace te openen.', 'sbdp') . '</p>';
            echo '</div>';
        }
        echo '</main>';

        echo '</div>'; // command-shell
        echo '</div>'; // wrap
    }

    /**
     * @param array<int, array<string, mixed>> $quotes
     * @return array<int, array<string, mixed>>
     */
    public static function buildQuoteOverviewRows(QuoteRepositoryInterface $repository, array $quotes): array
    {
        $rows = array();
        $dashboardService = new DashboardBlockerService();
        $businessValidator = new QuoteBusinessRuleValidator($repository);

        foreach ($quotes as $quote) {
            if (! is_array($quote)) {
                continue;
            }

            $quoteId = (int) ($quote['id'] ?? 0);
            if ($quoteId <= 0) {
                continue;
            }

            $request = isset($quote['quote_request_id']) ? $repository->findQuoteRequest((int) $quote['quote_request_id']) : null;
            $requester = is_array($request) ? self::extractRequesterContext($request) : array();
            $currentVersion = self::resolveCurrentQuoteVersion($repository, $quote);
            $assumptions = $repository->listQuoteAssumptions($quoteId);
            $sendReadiness = self::inspectQuoteSendReadiness($quoteId, $quote, $currentVersion, $repository);
            try {
                $businessValidation = $businessValidator->validateComplete($quoteId);
            } catch (\Throwable) {
                $businessValidation = array(
                    'valid' => false,
                    'violations' => array(
                        array(
                            'code' => 'business_context_invalid',
                            'severity' => 'error',
                            'message' => __('Quote-context kon niet volledig worden gelezen.', 'sbdp'),
                        ),
                    ),
                );
            }

            $quoteCommerciallyEditable = ! in_array((string) ($quote['status'] ?? ''), QuoteImmutabilityGuard::CONTENT_FROZEN_STATUSES, true);
            $dashboardState = $dashboardService->buildState($sendReadiness, $businessValidation, $assumptions, $quoteCommerciallyEditable);
            $category = self::quoteOverviewCategory($dashboardState, $quote);
            $primaryBlocker = is_array($dashboardState['primary_blocker'] ?? null) ? $dashboardState['primary_blocker'] : array();
            $state = (string) ($dashboardState['state'] ?? 'blocked');

            $focusLabel = match ($state) {
                'assumptions' => __('Controleer & bevestig', 'sbdp'),
                'ready' => __('Verzendklaar', 'sbdp'),
                'locked' => __('Afgehandeld / audit', 'sbdp'),
                default => (string) ($primaryBlocker['label'] ?? __('Actie nodig', 'sbdp')),
            };
            $focusDescription = match ($state) {
                'assumptions' => sprintf(__('%d open bevestiging(en)', 'sbdp'), count((array) ($dashboardState['assumptions'] ?? array()))),
                'ready' => __('Open Communication en verstuur via de bestaande send-flow.', 'sbdp'),
                'locked' => __('Commercieel bevroren. Geen directe actie in overzicht.', 'sbdp'),
                default => (string) ($primaryBlocker['message'] ?? __('Open de quote voor de volgende stap.', 'sbdp')),
            };

            $rows[] = array(
                'quote' => $quote,
                'request' => $request,
                'requester' => $requester,
                'dashboard_state' => $dashboardState,
                'category' => $category,
                'focus_label' => $focusLabel,
                'focus_description' => $focusDescription,
                'amount_label' => $currentVersion !== null && (string) ($currentVersion['pricing_confidence'] ?? 'unknown') === 'execution_verified' && (string) ($currentVersion['availability_confidence'] ?? 'unknown') === 'confirmed'
                    ? __('Offerteprijs', 'sbdp')
                    : __('Voorstelbedrag onder voorbehoud', 'sbdp'),
                'priority' => self::quoteOverviewPriority($category, $state),
                'detail_url' => add_query_arg(array('page' => 'sbdp_quotes', 'quote_id' => $quoteId), admin_url('admin.php')),
                'request_url' => is_array($request)
                    ? add_query_arg(array('page' => 'sbdp_quote_requests', 'request_id' => (int) ($request['id'] ?? 0)), admin_url('admin.php'))
                    : '',
            );
        }

        usort($rows, static function (array $left, array $right): int {
            $priority = (int) ($left['priority'] ?? 99) <=> (int) ($right['priority'] ?? 99);
            if ($priority !== 0) {
                return $priority;
            }

            return strcmp((string) ($right['quote']['updated_at'] ?? ''), (string) ($left['quote']['updated_at'] ?? ''));
        });

        return $rows;
    }

    /**
     * @param array<string, mixed> $dashboardState
     * @param array<string, mixed> $quote
     */
    private static function quoteOverviewCategory(array $dashboardState, array $quote): string
    {
        $state = (string) ($dashboardState['state'] ?? 'blocked');
        if ($state === 'ready') {
            return 'ready';
        }
        if ($state === 'assumptions') {
            return 'assumptions';
        }
        if ($state === 'locked' || in_array((string) ($quote['status'] ?? ''), array('sent', 'accepted', 'confirmed'), true)) {
            return 'done';
        }

        return 'action';
    }

    private static function quoteOverviewPriority(string $category, string $state): int
    {
        if ($category === 'action') {
            return 10;
        }
        if ($category === 'assumptions') {
            return 20;
        }
        if ($category === 'ready') {
            return 30;
        }
        if ($state === 'locked') {
            return 80;
        }

        return 90;
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<string, mixed>|null
     */
    private static function resolveCurrentQuoteVersion(QuoteRepositoryInterface $repository, array $quote): ?array
    {
        $currentVersionId = (int) ($quote['current_version_id'] ?? 0);
        if ($currentVersionId > 0) {
            $version = $repository->findQuoteVersion($currentVersionId);
            if (is_array($version)) {
                return $version;
            }
        }

        $versions = $repository->listQuoteVersions((int) ($quote['id'] ?? 0));
        return $versions[0] ?? null;
    }

    public static function renderQuoteInboxPage(): void
    {
        self::assertAccess();

        $repository = new QuoteRepository();
        $failures = self::sortInboxFailures($repository->listQuoteMessageFailures());
        $quotes = $repository->listQuotes();

        echo '<div class="wrap"><h1>' . esc_html__('Quote Inbox', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Inbound replies die niet automatisch aan een quote konden worden gekoppeld. Dit voorkomt commercieel verlies bij slechte mail headers of ontbrekende thread-data.', 'sbdp') . '</p>';
        QuoteBuilderRenderer::renderAdminStyles();
        self::renderNotices();

        $openFailures = array_values(array_filter($failures, static fn (array $failure): bool => (string) ($failure['status'] ?? 'open') === 'open'));
        $waitingOverDay = array_values(array_filter($openFailures, static fn (array $failure): bool => self::failureAgeInHours($failure) >= 24));
        $waitingOverThreeDays = array_values(array_filter($openFailures, static fn (array $failure): bool => self::failureAgeInHours($failure) >= 72));
        echo '<div class="bsp-quote-admin__stats">';
        echo self::renderStatCard(__('Open inbox issues', 'sbdp'), (string) count($openFailures));
        echo self::renderStatCard(__('Waiting >24h', 'sbdp'), (string) count($waitingOverDay));
        echo self::renderStatCard(__('Waiting >72h', 'sbdp'), (string) count($waitingOverThreeDays));
        echo self::renderStatCard(__('Resolved', 'sbdp'), (string) max(0, count($failures) - count($openFailures)));
        echo '</div>';

        echo '<div class="bsp-quote-admin__thread">';
        if ($failures === array()) {
            echo '<div class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-body"><p>' . esc_html__('Geen unmatched inbound replies.', 'sbdp') . '</p></div></div>';
        } else {
            foreach ($failures as $failure) {
                $status = (string) ($failure['status'] ?? 'open');
                $reason = (string) ($failure['failure_reason'] ?? 'unmatched_quote');
                $body = trim((string) ($failure['body'] ?? ''));
                $guessedQuoteReference = (string) ($failure['guessed_quote_reference'] ?? '');
                $suggestedQuote = null;
                if ($guessedQuoteReference !== '') {
                    foreach ($quotes as $quote) {
                        if ((string) ($quote['quote_reference'] ?? '') === $guessedQuoteReference) {
                            $suggestedQuote = $quote;
                            break;
                        }
                    }
                }
                $triage = self::buildInboxFailureTriage($failure, $suggestedQuote);
                echo '<article class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-body">';
                echo '<div class="bsp-quote-admin__thread-meta">';
                echo self::renderInlineBadge($status, $status === 'resolved' ? 'is-good' : 'is-warn');
                echo self::renderInlineBadge($reason, 'is-neutral');
                echo self::renderInlineBadge((string) ($triage['age_label'] ?? __('Nieuw', 'sbdp')), (string) ($triage['age_badge_class'] ?? 'is-neutral'));
                echo '<strong>' . esc_html((string) (($failure['subject'] ?? '') !== '' ? $failure['subject'] : __('Zonder onderwerp', 'sbdp'))) . '</strong>';
                echo '<span class="bsp-quote-admin__muted">' . esc_html((string) ($failure['created_at'] ?? '')) . '</span>';
                echo '</div>';
                echo '<div class="bsp-quote-admin__cell-stack">';
                echo '<span class="bsp-quote-admin__muted">' . esc_html(sprintf(
                    __('Van: %s <%s> | Aan: %s', 'sbdp'),
                    (string) ($failure['from_name'] ?? ''),
                    (string) ($failure['from_email'] ?? ''),
                    (string) ($failure['to_email'] ?? '')
                )) . '</span>';
                if (! empty($failure['guessed_quote_reference'])) {
                    echo '<span class="bsp-quote-admin__muted">' . esc_html(sprintf(__('Gedetecteerde quote reference: %s', 'sbdp'), (string) $failure['guessed_quote_reference'])) . '</span>';
                }
                if (! empty($failure['provider_message_id'])) {
                    echo '<span class="bsp-quote-admin__muted">' . esc_html(sprintf(__('Message-ID: %s', 'sbdp'), (string) $failure['provider_message_id'])) . '</span>';
                }
                echo '</div>';
                if (! empty($triage['action_title']) || ! empty($triage['action_description'])) {
                    echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--compact">';
                    if (! empty($triage['action_title'])) {
                        echo '<strong>' . esc_html((string) $triage['action_title']) . '</strong>';
                    }
                    if (! empty($triage['action_description'])) {
                        echo '<p>' . esc_html((string) $triage['action_description']) . '</p>';
                    }
                    echo '</div>';
                }
                if ($suggestedQuote !== null) {
                    $suggestedQuoteUrl = add_query_arg(array('page' => 'sbdp_quotes', 'quote_id' => (int) ($suggestedQuote['id'] ?? 0)), admin_url('admin.php'));
                    echo '<div class="bsp-quote-admin__actions bsp-quote-admin__thread-actions">';
                    echo self::renderInlineBadge(__('Voorgestelde quote', 'sbdp'), 'is-good');
                    echo '<a class="button button-secondary" href="' . esc_url($suggestedQuoteUrl) . '">' . esc_html(sprintf(__('Open %s', 'sbdp'), (string) ($suggestedQuote['quote_reference'] ?? ''))) . '</a>';
                    echo '</div>';
                }
                if ($body !== '') {
                    echo '<div class="bsp-quote-admin__message-body">' . nl2br(esc_html($body)) . '</div>';
                }

                if ($status === 'open') {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form bsp-quote-admin__stack-form--muted">';
                    \wp_nonce_field('sbdp_quote_resolve_inbound_failure');
                    echo '<input type="hidden" name="action" value="sbdp_quote_resolve_inbound_failure"><input type="hidden" name="failure_id" value="' . esc_attr((string) ($failure['id'] ?? 0)) . '">';
                    echo '<label>' . esc_html__('Koppel aan quote', 'sbdp') . '<select name="quote_id" required><option value="">' . esc_html__('Selecteer quote', 'sbdp') . '</option>';
                    foreach ($quotes as $quote) {
                        $selected = ((string) ($quote['quote_reference'] ?? '') === $guessedQuoteReference) ? ' selected' : '';
                        $label = (string) (($quote['quote_reference'] ?? '') . ' | #' . ($quote['id'] ?? 0));
                        if ($selected !== '') {
                            $label .= ' | ' . __('suggested match', 'sbdp');
                        }
                        echo '<option value="' . esc_attr((string) ($quote['id'] ?? 0)) . '"' . $selected . '>' . esc_html($label) . '</option>';
                    }
                    echo '</select></label><button class="button button-primary" type="submit">' . esc_html__('Koppel en verwerk reply', 'sbdp') . '</button></form>';
                } elseif (! empty($failure['linked_quote_id'])) {
                    $detailUrl = add_query_arg(array('page' => 'sbdp_quotes', 'quote_id' => (int) $failure['linked_quote_id']), admin_url('admin.php'));
                    echo '<div class="bsp-quote-admin__actions bsp-quote-admin__thread-actions"><a class="button button-secondary" href="' . esc_url($detailUrl) . '">' . esc_html__('Open gekoppelde quote', 'sbdp') . '</a></div>';
                }

                echo '</div></article>';
            }
        }
        echo '</div></div>';
    }

    public static function renderQuoteAiMailSettingsPage(): void
    {
        self::assertAccess();

        $inboundSecret = (string) \get_option('bsp_inbound_mail_secret', '');
        $openAiModel = (string) \get_option('bsp_openai_model', 'gpt-4o');
        $openAiApiKey = (string) \get_option('bsp_openai_api_key', '');
        $openAiStatus = self::readOpenAiStatus();
        $maskedApiKey = $openAiApiKey !== '' ? str_repeat('*', max(0, strlen($openAiApiKey) - 4)) . substr($openAiApiKey, -4) : '';
        $endpoint = \function_exists('rest_url')
            ? (string) \rest_url('bsp/v1/inbound-mail')
            : '/wp-json/bsp/v1/inbound-mail';

        echo '<div class="wrap"><h1>' . esc_html__('Quote AI & Mail', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Beheer hier de inbound mail bridge en de OpenAI-draftconfiguratie voor de quotes-module.', 'sbdp') . '</p>';
        QuoteBuilderRenderer::renderAdminStyles();
        self::renderNotices();

        echo '<div class="bsp-quote-admin__workspace">';
        echo '<div class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('Configuratie', 'sbdp') . '</h3><p>' . esc_html__('Slaat de webhook-secret en OpenAI-instellingen op in WordPress options.', 'sbdp') . '</p></div><div class="bsp-quote-admin__panel-body">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form">';
        \wp_nonce_field('sbdp_quote_save_ai_mail_settings');
        echo '<input type="hidden" name="action" value="sbdp_quote_save_ai_mail_settings">';
        echo '<label><span class="bsp-quote-admin__field-label">' . esc_html__('Inbound mail secret', 'sbdp') . '</span><input type="text" class="regular-text" name="bsp_inbound_mail_secret" value="' . esc_attr($inboundSecret) . '" autocomplete="off"></label>';
        echo '<p class="description">' . esc_html__('Webhook callers moeten deze exacte waarde meesturen in de X-BSP-Mail-Secret header.', 'sbdp') . '</p>';
        echo '<label><span class="bsp-quote-admin__field-label">' . esc_html__('OpenAI model', 'sbdp') . '</span><input type="text" class="regular-text" name="bsp_openai_model" value="' . esc_attr($openAiModel !== '' ? $openAiModel : 'gpt-4o') . '"></label>';
        echo '<label><span class="bsp-quote-admin__field-label">' . esc_html__('OpenAI API key', 'sbdp') . '</span><input type="password" class="regular-text" name="bsp_openai_api_key" value="' . esc_attr($openAiApiKey) . '" autocomplete="new-password"></label>';
        if ($maskedApiKey !== '') {
            echo '<p class="description">' . esc_html(sprintf(__('Huidige key opgeslagen: %s', 'sbdp'), $maskedApiKey)) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__('Leeg laten betekent: AI-filters vallen stil terug op de bestaande template-drafts.', 'sbdp') . '</p>';
        }
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Instellingen opslaan', 'sbdp') . '</button></p>';
        echo '</form></div></div>';

        if ($openAiStatus !== null) {
            echo '<div class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('Laatste OpenAI status', 'sbdp') . '</h3><p>' . esc_html__('Laat de laatste bekende AI-callstatus zien voor proposal-, reply- en summary-drafts.', 'sbdp') . '</p></div><div class="bsp-quote-admin__panel-body">';
            echo self::renderOpenAiStatusNotice($openAiStatus);
            echo '</div></div>';
        }

        echo '<div class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('Runtime checks', 'sbdp') . '</h3><p>' . esc_html__('Gebruik deze checklist om de bridge in staging of productie gecontroleerd te activeren.', 'sbdp') . '</p></div><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__readiness-summary"><strong>' . esc_html__('REST endpoint', 'sbdp') . '</strong><p>' . esc_html($endpoint) . '</p></div>';
        echo '<ul class="bsp-quote-admin__checklist">';
        echo '<li><strong>' . esc_html__('1. Security', 'sbdp') . '</strong><span>' . esc_html__('Doe eerst een POST zonder header en verifieer dat het endpoint 403 teruggeeft.', 'sbdp') . '</span></li>';
        echo '<li><strong>' . esc_html__('2. Aanvragen flow', 'sbdp') . '</strong><span>' . esc_html__('Stuur een unmatched mail naar aanvragen@dagjedenbosch.nl en controleer of een nieuw quote request ontstaat.', 'sbdp') . '</span></li>';
        echo '<li><strong>' . esc_html__('3. Inbox flow', 'sbdp') . '</strong><span>' . esc_html__('Stuur een unmatched mail naar info@ of inkoop@ en controleer of Quote Inbox een failure toont.', 'sbdp') . '</span></li>';
        echo '<li><strong>' . esc_html__('4. AI fallback', 'sbdp') . '</strong><span>' . esc_html__('Laat de API key leeg en verifieer dat voorstel/reply/samenvatting ongewijzigd via template blijven werken.', 'sbdp') . '</span></li>';
        echo '</ul>';
        echo '<p><code>' . esc_html('pwsh -File scripts/test-inbound-mail-bridge.ps1 -BaseUrl https://jouwdomein.nl -Secret jouwsecret') . '</code></p>';
        echo '</div></div>';
        echo '</div></div>';
    }

    private static function renderQuoteWorkspaceContent(QuoteRepository $repository, int $quoteId): void
    {
        $quote = $repository->findQuote($quoteId);
        if ($quote === null) {
            echo '<div class="bsp-quote-admin__command-splash"><h2>' . esc_html__('Quote niet gevonden.', 'sbdp') . '</h2></div>';
            return;
        }
        self::renderQuoteWorkspaceInner($repository, $quoteId, $quote);
    }

    private static function renderQuoteWorkspaceInner(QuoteRepository $repository, int $quoteId, array $quote): void
    {
        // (body continues below — no outer wrap or duplicate styles)
        if (false) { return; } // dummy to satisfy linter for early-return style

        $request = isset($quote['quote_request_id']) ? $repository->findQuoteRequest((int) $quote['quote_request_id']) : null;
        $versions       = $repository->listQuoteVersions($quoteId);
        $currentVersion = null;
        foreach ($versions as $version) {
            if ((int) ($version['id'] ?? 0) === (int) ($quote['current_version_id'] ?? 0)) {
                $currentVersion = $version;
                break;
            }
        }
        if ($currentVersion === null && $versions !== array()) {
            $currentVersion = $versions[0];
        }

        $lines = $currentVersion !== null ? $repository->listQuoteLines((int) $currentVersion['id']) : array();

        if (is_array($request)) {
            self::resolveIntakeAssumptions(
                $repository,
                $quoteId,
                max(0, (int) ($request['group_size'] ?? 0)),
                trim((string) ($request['preferred_date'] ?? '')),
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        }

        $assumptions = $repository->listQuoteAssumptions($quoteId);
        $openAssumptions = array_values(array_filter($assumptions, static function ($assumption): bool {
            return is_array($assumption) && (string) ($assumption['status'] ?? 'open') === 'open';
        }));
        $followups = $repository->listQuoteFollowups($quoteId);
        $events = $repository->listQuoteEvents($quoteId);
        $messages = $repository->listQuoteMessages($quoteId);
        $requester = $request !== null ? self::extractRequesterContext($request) : array();
        $contactSummary = array_filter(array(
            (string) ($requester['email'] ?? ''),
            (string) ($requester['phone'] ?? ''),
        ));
        $formattedAddress = self::formatRequesterAddress($requester);
        $lineSummary = self::summarizeQuoteLines($lines, $currentVersion);
        $proposalProgram = self::buildQuoteProgram($lines, (string) ($lineSummary['currency'] ?? 'EUR'));
        $proposalReadiness = self::buildQuoteProposalReadiness($quote, $currentVersion, $lines, $assumptions);
        $sendReadiness = self::inspectQuoteSendReadiness($quoteId, $quote, $currentVersion, $repository);
        $sendDecision = (new QuoteProposalSendDecisionService($repository))->decide($quoteId);
        $businessValidation = (new QuoteBusinessRuleValidator($repository))->validateComplete($quoteId);
        $communicationState = self::buildQuoteCommunicationState($quote, $currentVersion, $messages, $assumptions, $sendReadiness, $sendDecision);
        $commercialIntakeNotice = self::buildCommercialIntakeNoticeState($lines, $followups, $assumptions);
        $openAiStatus = self::readOpenAiStatus();
        $workspaceState = self::buildQuoteWorkspaceState($quote, $currentVersion, $lines, $assumptions, $followups, $communicationState, $sendReadiness, $proposalReadiness);
        $quoteCommerciallyEditable = ! in_array((string) ($quote['status'] ?? ''), QuoteImmutabilityGuard::CONTENT_FROZEN_STATUSES, true);
        $operatorDecision = self::buildQuoteOperatorDecision($quote, $proposalReadiness, $workspaceState, $communicationState);
        $messageDrafts = self::resolveQuoteMessageDrafts($messages);
        $currentPayload = isset($currentVersion['handoff_payload_json']) && is_array($currentVersion['handoff_payload_json'])
            ? $currentVersion['handoff_payload_json']
            : array();
        $currentTab = self::resolveWorkspaceTab();
        $totalLines = (int) ($proposalProgram['stats']['total_lines'] ?? count($lines));
        $scheduledLines = (int) ($proposalProgram['stats']['scheduled_lines'] ?? 0);
        $confirmedLines = (int) ($proposalProgram['stats']['confirmed_lines'] ?? 0);
        $pricingConfidence = $currentVersion !== null ? (string) ($currentVersion['pricing_confidence'] ?? 'unknown') : 'unknown';
        $availabilityConfidence = $currentVersion !== null ? (string) ($currentVersion['availability_confidence'] ?? 'unknown') : 'unknown';
        $pricingConfirmed = $pricingConfidence === 'execution_verified';
        $availabilityConfirmed = $availabilityConfidence === 'confirmed';
        $proposalReady = ! empty($proposalReadiness['ready']);
        $sendAllowed = ! empty($sendDecision['can_send']);
        $sendCheckItems = self::buildWorkspaceSendCheckItems(
            $totalLines,
            $scheduledLines,
            $pricingConfidence,
            $availabilityConfidence,
            $proposalReady,
            $sendReadiness,
            $communicationState
        );
        $handoffAllowed = in_array((string) ($quote['status'] ?? ''), array('accepted', 'confirmed'), true) && (int) ($quote['approved_version_id'] ?? 0) > 0;
        if ($currentTab === 'handoff' && ! $handoffAllowed) {
            $currentTab = 'dashboard';
        }
        // Filter out stale auto-assumptions whose underlying condition is already resolved on the current version.
        $filteredAssumptions = array_values(array_filter($assumptions, static function (array $a) use ($availabilityConfirmed, $pricingConfirmed): bool {
            $type = (string) ($a['assumption_type'] ?? '');
            if ($availabilityConfirmed && $type === 'uncertain_availability') {
                return false;
            }
            if ($pricingConfirmed && $type === 'uncertain_pricing') {
                return false;
            }
            return true;
        }));
        $workspaceAlerts = self::buildQuoteWorkspaceAlerts($quoteId, $sendReadiness, $businessValidation, $filteredAssumptions, $followups, $communicationState, $quoteCommerciallyEditable);
        $workspaceBlockers = is_array($workspaceAlerts['blockers'] ?? null) ? $workspaceAlerts['blockers'] : array();
        $sendAllowed = $sendAllowed && $workspaceBlockers === array();
        $sendDecision['can_send'] = $sendAllowed;
        $adminStatusSummary = (new QuoteAdminStatusSummaryService($repository))->summarize($quoteId, array('send_allowed' => $sendAllowed));
        $amountLabel = $pricingConfirmed && $availabilityConfirmed && $sendAllowed
            ? __('Offerteprijs', 'sbdp')
            : __('Voorstelbedrag onder voorbehoud', 'sbdp');
        $primaryAction = self::resolveQuotePrimaryAction($quote, $workspaceState, $sendAllowed, $handoffAllowed);
        echo '<div class="bsp-quote-admin__workspace">';
        self::renderCommercialIntakeNotice($commercialIntakeNotice);
        self::renderQuoteControlDashboard(
            $quoteId,
            $quote,
            $request,
            $requester,
            $formattedAddress,
            $currentVersion,
            $lines,
            $lineSummary,
            $proposalProgram,
            $sendReadiness,
            $workspaceAlerts,
            $communicationState,
            $messages,
            $events,
            $messageDrafts,
            $proposalReadiness,
            $pricingConfidence,
            $availabilityConfidence,
            $sendAllowed,
            $quoteCommerciallyEditable,
            $adminStatusSummary
        );
        echo '</div></div>';

        return;

        if ($currentTab === 'proposal') {
            echo '<div class="bsp-quote-admin__workspace-grid">';
            echo '<div class="bsp-quote-admin__workspace-main">';

            echo '<section id="quote-proposal-program" class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('Proposal Program', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Dit is het voorgestelde dagprogramma op basis van de vastgelegde quote-versie en line snapshots. Geen live Woo-truth wordt hier teruggeschreven of verzonnen.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';
        if ($currentVersion !== null) {
            echo '<div class="bsp-quote-admin__proposal-summary">';
            echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Voorsteltitel', 'sbdp') . '</span><strong>' . esc_html((string) (($currentVersion['proposal_title'] ?? '') !== '' ? $currentVersion['proposal_title'] : __('Nog zonder titel', 'sbdp'))) . '</strong></div>';
            echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Versie', 'sbdp') . '</span><strong>' . esc_html(sprintf('#%s', (string) ($currentVersion['version_number'] ?? '1'))) . '</strong></div>';
            echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Snapshot type', 'sbdp') . '</span><strong>' . esc_html((string) ($currentVersion['snapshot_type'] ?? 'initial')) . '</strong></div>';
            echo '</div>';
        }
        echo '<div class="bsp-quote-admin__totals-grid">';
        echo self::renderStatCard(__('Stops', 'sbdp'), (string) ($proposalProgram['stats']['total_lines'] ?? count($lines)));
        echo self::renderStatCard(__('Met tijd', 'sbdp'), sprintf('%d / %d', (int) ($proposalProgram['stats']['scheduled_lines'] ?? 0), (int) ($proposalProgram['stats']['total_lines'] ?? count($lines))));
        echo self::renderStatCard(__('Prijs snapshot', 'sbdp'), sprintf('%d / %d', (int) ($proposalProgram['stats']['priced_lines'] ?? 0), (int) ($proposalProgram['stats']['total_lines'] ?? count($lines))));
        echo self::renderStatCard(__('Onder voorbehoud', 'sbdp'), (string) ($proposalProgram['stats']['provisional_lines'] ?? 0));
        echo '</div>';
        if (($proposalProgram['groups'] ?? array()) === array()) {
            echo '<p>' . esc_html__('Nog geen programmaregels beschikbaar in deze quote-versie.', 'sbdp') . '</p>';
        } else {
            echo '<div class="bsp-quote-admin__program">';
            foreach ((array) $proposalProgram['groups'] as $group) {
                echo '<section class="bsp-quote-admin__program-day">';
                echo '<div class="bsp-quote-admin__program-day-header"><h4>' . esc_html((string) ($group['label'] ?? __('Datum nog open', 'sbdp'))) . '</h4><span class="bsp-quote-admin__muted">' . esc_html(sprintf(__('%d onderdeel/onderdelen', 'sbdp'), count((array) ($group['items'] ?? array())))) . '</span></div>';
                echo '<div class="bsp-quote-admin__program-list">';
                foreach ((array) ($group['items'] ?? array()) as $item) {
                    echo '<article class="bsp-quote-admin__program-item">';
                    echo '<div class="bsp-quote-admin__program-time">';
                    if (! empty($item['time_label'])) {
                        echo self::renderInlineBadge((string) $item['time_label'], (string) ($item['time_badge_class'] ?? 'is-neutral'));
                    }
                    echo '<strong>' . esc_html((string) ($item['time_primary'] ?? __('Planning nog open', 'sbdp'))) . '</strong>';
                    if (! empty($item['time_secondary'])) {
                        echo '<span>' . esc_html((string) $item['time_secondary']) . '</span>';
                    }
                    echo '</div>';
                    echo '<div class="bsp-quote-admin__program-body">';
                    echo '<div class="bsp-quote-admin__badge-row">';
                    echo self::renderInlineBadge((string) ($item['availability_label'] ?? __('Onder voorbehoud', 'sbdp')), (string) ($item['availability_badge_class'] ?? 'is-warn'));
                    echo self::renderInlineBadge((string) ($item['pricing_label'] ?? __('Snapshot', 'sbdp')), (string) ($item['pricing_badge_class'] ?? 'is-neutral'));
                    if (! empty($item['optional_label'])) {
                        echo self::renderInlineBadge((string) $item['optional_label'], 'is-neutral');
                    }
                    echo '</div>';
                    echo '<h4>' . esc_html((string) ($item['title'] ?? __('Programmastop', 'sbdp'))) . '</h4>';
                    if (($item['option_labels'] ?? array()) !== array()) {
                        echo '<p class="bsp-quote-admin__program-subtitle">' . esc_html(sprintf(__('Geselecteerde optie: %s', 'sbdp'), implode(' | ', (array) $item['option_labels']))) . '</p>';
                    }
                    echo '<div class="bsp-quote-admin__cell-stack">';
                    foreach ((array) ($item['detail_bits'] ?? array()) as $detailBit) {
                        echo '<span>' . esc_html((string) $detailBit) . '</span>';
                    }
                    echo '</div>';
                    echo '<div class="bsp-quote-admin__program-price">';
                    echo '<strong>' . esc_html((string) ($item['price_primary'] ?? __('Prijs nog open', 'sbdp'))) . '</strong>';
                    if (! empty($item['price_secondary'])) {
                        echo '<span class="bsp-quote-admin__muted">' . esc_html((string) $item['price_secondary']) . '</span>';
                    }
                    echo '</div>';
                    if (! empty($item['availability_note'])) {
                        echo '<p class="bsp-quote-admin__muted bsp-quote-admin__program-note">' . esc_html((string) $item['availability_note']) . '</p>';
                    }
                    echo '</div></article>';
                }
                echo '</div></section>';
            }
            echo '</div>';
        }
        echo '</div></section>';

            echo '<section id="quote-assumptions" class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('Assumptions', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Expliciete onzekerheden blijven zichtbaar en blokkeren review, send of handoff waar nodig.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';
        $openAssumptions = array_values(array_filter($assumptions, static function ($assumption): bool {
            return is_array($assumption) && (string) ($assumption['status'] ?? 'open') === 'open';
        }));
        if ($openAssumptions === array()) {
            echo '<p>' . esc_html__('Geen open assumptions.', 'sbdp') . '</p>';
        } else {
            echo '<ul class="bsp-quote-admin__checklist">';
            foreach ($openAssumptions as $assumption) {
                $flags = array();
                if (! empty($assumption['blocks_review'])) {
                    $flags[] = 'blocks review';
                }
                if (! empty($assumption['blocks_send'])) {
                    $flags[] = 'blocks send';
                }
                if (! empty($assumption['blocks_handoff'])) {
                    $flags[] = 'blocks handoff';
                }
                echo '<li><strong>' . esc_html((string) ($assumption['assumption_type'] ?? 'assumption')) . '</strong><span>' . esc_html((string) ($assumption['message'] ?? '')) . '</span>';
                if ($flags !== array()) {
                    echo '<span class="bsp-quote-admin__muted">' . esc_html(implode(' | ', $flags)) . '</span>';
                }
                if (
                    $quoteCommerciallyEditable
                    &&
                    (string) ($assumption['status'] ?? 'open') === 'open'
                    && in_array((string) ($assumption['assumption_type'] ?? ''), array('uncertain_pricing', 'uncertain_availability'), true)
                ) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form bsp-quote-admin__stack-form--muted">';
                    \wp_nonce_field('sbdp_quote_resolve_assumption');
                    echo '<input type="hidden" name="action" value="sbdp_quote_resolve_assumption"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="assumption_id" value="' . esc_attr((string) ($assumption['id'] ?? 0)) . '"><input type="hidden" name="workspace_tab" value="' . esc_attr($currentTab) . '">';
                    echo '<label>' . esc_html__('Operatornotitie bij vrijgave', 'sbdp') . '<textarea class="large-text" rows="3" name="resolution_note" required placeholder="' . esc_attr__('Bijv. prijs bevestigd met leverancier op 2026-05-08 / beschikbaarheid gecontroleerd in execution-validatie.', 'sbdp') . '"></textarea></label>';
                    echo '<p class="bsp-quote-admin__muted">' . esc_html__('Notitie verplicht: leg kort vast hoe je deze blocker hebt gecontroleerd.', 'sbdp') . '</p>';
                    echo '<button class="button button-secondary" type="submit">' . esc_html__('Markeer als gecontroleerd', 'sbdp') . '</button></form>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div></section>';
            echo '</div>';

            echo '<aside class="bsp-quote-admin__workspace-side">';
            echo '<section id="quote-operator-decision" class="postbox bsp-quote-admin__panel bsp-quote-admin__panel--operator"><div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('Operator Decision', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Eerst status begrijpen, daarna alleen de eerstvolgende stap uitvoeren. De proposal blijft leidend; Woo en booking blijven buiten deze laag.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';
            echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--operator">';
            echo self::renderInlineBadge((string) ($operatorDecision['status_label'] ?? __('Intern concept', 'sbdp')), (string) ($operatorDecision['status_badge_class'] ?? 'is-neutral'));
            if (! empty($operatorDecision['thread_label'])) {
                echo self::renderInlineBadge((string) $operatorDecision['thread_label'], (string) ($operatorDecision['thread_badge_class'] ?? 'is-neutral'));
            }
            echo '<strong>' . esc_html((string) ($operatorDecision['title'] ?? __('Nog niet klantklaar', 'sbdp'))) . '</strong>';
            echo '<p>' . esc_html((string) ($operatorDecision['status_description'] ?? '')) . '</p>';
            echo '</div>';
            echo '<div class="bsp-quote-admin__decision-grid">';
            echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Wat mist nog', 'sbdp') . '</span>';
            if (($operatorDecision['missing_items'] ?? array()) === array()) {
                echo '<p class="bsp-quote-admin__muted">' . esc_html__('Geen open blockers op workspace-niveau. Controleer alleen nog de klantcommunicatie voordat je verzendt.', 'sbdp') . '</p>';
            } else {
                echo '<ul class="bsp-quote-admin__checklist bsp-quote-admin__checklist--compact">';
                foreach ((array) $operatorDecision['missing_items'] as $item) {
                    echo '<li><strong>' . esc_html((string) ($item['title'] ?? '')) . '</strong><span>' . esc_html((string) ($item['description'] ?? '')) . '</span></li>';
                }
                echo '</ul>';
            }
            echo '</div>';
            echo '<div class="bsp-quote-admin__decision-action"><span class="bsp-quote-admin__field-label">' . esc_html__('Primaire volgende stap', 'sbdp') . '</span><strong>' . esc_html((string) ($workspaceState['next_action']['title'] ?? __('Werk quote bij', 'sbdp'))) . '</strong><p>' . esc_html((string) ($workspaceState['next_action']['description'] ?? '')) . '</p>' . self::renderQuotePrimaryAction($quoteId, is_array($workspaceState['next_action'] ?? null) ? $workspaceState['next_action'] : array('cta' => 'review_request')) . '</div>';
            echo '</div>';
            echo '</div></section>';
            echo '</aside></div>';
        } elseif ($currentTab === 'communication') {
            self::renderQuoteCommunicationWorkflow($quoteId, $requester, $lineSummary, $messages, $communicationState, $messageDrafts, $openAiStatus, $quoteCommerciallyEditable);
        } elseif ($currentTab === 'handoff') {
            echo '<div class="bsp-quote-admin__workspace-stack">';
            self::renderQuoteHandoffWorkspacePanel($quoteId, $quote, $currentVersion, $currentPayload);
            echo '</div>';
        } elseif ($currentTab === 'history') {
            echo '<div class="bsp-quote-admin__workspace-stack">';
            
            // 1. Event Timeline (vaak handig om als eerste te zien in geschiedenis)
            echo '<details class="postbox bsp-quote-admin__panel bsp-quote-admin__panel--collapsible" open><summary class="bsp-quote-admin__panel-toggle"><span><strong>' . esc_html__('Event Timeline', 'sbdp') . '</strong><small>' . esc_html__('Alle quote-events blijven beschikbaar onder de operatorlaag.', 'sbdp') . '</small></span></summary><div class="bsp-quote-admin__panel-body">';
            if ($events === array()) {
                echo '<p>' . esc_html__('Nog geen events.', 'sbdp') . '</p>';
            } else {
                echo '<ul class="bsp-quote-admin__timeline">';
                foreach ($events as $event) {
                    echo '<li><strong>' . esc_html((string) ($event['occurred_at'] ?? '')) . '</strong><span>' . esc_html((string) ($event['event_type'] ?? '')) . '</span><span class="bsp-quote-admin__muted">' . esc_html((string) ($event['message'] ?? '')) . '</span></li>';
                }
                echo '</ul>';
            }
            echo '</div></details>';

            // 2. Pricing & Line Records Snapshot Data
            echo '<details id="quote-commercial" class="postbox bsp-quote-admin__panel bsp-quote-admin__panel--collapsible"><summary class="bsp-quote-admin__panel-toggle"><span><strong>' . esc_html__('Pricing & Line Records', 'sbdp') . '</strong><small>' . esc_html__('Onderliggende quote-regels en snapshots voor detailcontrole.', 'sbdp') . '</small></span></summary><div class="bsp-quote-admin__panel-body">';
            if ($currentVersion !== null) {
                echo '<div class="bsp-quote-admin__proposal-summary">';
                echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Prijs confidence', 'sbdp') . '</span><strong>' . esc_html((string) ($currentVersion['pricing_confidence'] ?? 'unknown')) . '</strong></div>';
                echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Beschikbaarheid confidence', 'sbdp') . '</span><strong>' . esc_html((string) ($currentVersion['availability_confidence'] ?? 'unknown')) . '</strong></div>';
                echo '</div>';
            }
            echo '<div class="bsp-quote-admin__totals-grid">';
            echo self::renderStatCard(__('Regels', 'sbdp'), (string) count($lines));
            echo self::renderStatCard(__('Geprijsde regels', 'sbdp'), count($lines) > 0 ? sprintf('%d / %d', (int) ($lineSummary['priced_lines'] ?? 0), count($lines)) : '0 / 0');
            echo self::renderStatCard(__('Snapshot subtotaal', 'sbdp'), (string) ($lineSummary['subtotal_label'] ?? __('Nog niet bepaald', 'sbdp')));
            echo self::renderStatCard(__('BTW / definitief totaal', 'sbdp'), __('Volgt pas in Woo / execution', 'sbdp'));
            echo '</div>';
            echo '<p class="bsp-quote-admin__muted">' . esc_html__('Geen definitieve prijs- of beschikbaarheidszekerheid wordt hier verzonnen. Snapshot-waarden blijven expliciet gelabeld totdat de execution-laag ze bevestigt.', 'sbdp') . '</p>';
            echo '<div class="bsp-quote-admin__table-wrap"><table class="widefat striped bsp-quote-admin__lines-table"><thead><tr><th>#</th><th>' . esc_html__('Regel', 'sbdp') . '</th><th>' . esc_html__('Planning', 'sbdp') . '</th><th>' . esc_html__('Commercieel', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th></tr></thead><tbody>';
            if ($lines === array()) {
                echo '<tr><td colspan="5">' . esc_html__('Geen regels opgeslagen.', 'sbdp') . '</td></tr>';
            }
            foreach ($lines as $line) {
                $lineTotal = isset($line['line_total_snapshot']) ? (float) $line['line_total_snapshot'] : null;
                $unitAmount = isset($line['unit_amount_snapshot']) ? (float) $line['unit_amount_snapshot'] : null;
                $currency = (string) (($line['currency'] ?? '') ?: ($lineSummary['currency'] ?? 'EUR'));
                $scheduleBits = array_filter(array(
                    (string) ($line['service_date'] ?? ''),
                    trim((string) (($line['start_time'] ?? '') . ((isset($line['end_time']) && (string) $line['end_time'] !== '') ? ' - ' . (string) $line['end_time'] : ''))),
                ));
                $detailBits = array_filter(array(
                    (int) ($line['product_id'] ?? 0) > 0 ? sprintf(__('Product #%d', 'sbdp'), (int) $line['product_id']) : __('Nog niet gemapt op product', 'sbdp'),
                    (int) ($line['vendor_id'] ?? 0) > 0 ? sprintf(__('Leverancier #%d', 'sbdp'), (int) $line['vendor_id']) : '',
                    sprintf(__('Aantal %d', 'sbdp'), max(1, (int) ($line['quantity'] ?? 1))),
                    (int) ($line['participants'] ?? 0) > 0 ? sprintf(__('%d deelnemers', 'sbdp'), (int) $line['participants']) : __('Deelnemers nog open', 'sbdp'),
                ));
                echo '<tr>';
                echo '<td>' . esc_html((string) ($line['line_number'] ?? '')) . '</td>';
                echo '<td><strong>' . esc_html((string) ($line['title'] ?? '')) . '</strong><div class="bsp-quote-admin__cell-stack">';
                foreach ($detailBits as $detailBit) {
                    echo '<span class="bsp-quote-admin__muted">' . esc_html($detailBit) . '</span>';
                }
                echo '</div></td><td>';
                if ($scheduleBits === array()) {
                    echo '<span class="bsp-quote-admin__muted">' . esc_html__('Nog geen datum/tijd vastgelegd', 'sbdp') . '</span>';
                } else {
                    echo '<div class="bsp-quote-admin__cell-stack">';
                    foreach ($scheduleBits as $scheduleBit) {
                        echo '<span>' . esc_html($scheduleBit) . '</span>';
                    }
                    echo '</div>';
                }
                echo '</td><td><div class="bsp-quote-admin__cell-stack">';
                if ($lineTotal !== null) {
                    echo '<strong>' . esc_html(self::formatMoney($lineTotal, $currency)) . '</strong>';
                    if ($unitAmount !== null) {
                        echo '<span class="bsp-quote-admin__muted">' . esc_html(sprintf(__('Per stuk %s', 'sbdp'), self::formatMoney($unitAmount, $currency))) . '</span>';
                    }
                } else {
                    echo '<strong>' . esc_html__('Nog geen prijs-snapshot', 'sbdp') . '</strong>';
                }
                echo '<span class="bsp-quote-admin__muted">' . esc_html((string) ($line['pricing_mode'] ?? 'directional')) . '</span>';
                echo '</div></td><td>';
                echo self::renderInlineBadge((string) ($line['line_status'] ?? 'mapped'), self::statusBadgeClass((string) ($line['line_status'] ?? 'mapped')));
                echo self::renderInlineBadge((string) ($line['pricing_confidence'] ?? 'unknown'), self::confidenceBadgeClass((string) ($line['pricing_confidence'] ?? 'unknown')));
                echo self::renderInlineBadge((string) ($line['availability_confidence'] ?? 'unknown'), self::confidenceBadgeClass((string) ($line['availability_confidence'] ?? 'unknown')));
                self::renderPartnerLineActions($quoteId, $line, $messages);
                echo '</td></tr>';
            }
            echo '</tbody></table></div></div></details>';

            // 3. Manual Inbound Logging
            echo '<details class="postbox bsp-quote-admin__panel bsp-quote-admin__panel--collapsible"><summary class="bsp-quote-admin__panel-toggle"><span><strong>' . esc_html__('Manual Inbound Logging', 'sbdp') . '</strong><small>' . esc_html__('Gebruik dit alleen voor handmatige mailbox-herstelacties of auditwerk.', 'sbdp') . '</small></span></summary><div class="bsp-quote-admin__panel-body">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form">';
            \wp_nonce_field('sbdp_quote_log_inbound_message');
            echo '<input type="hidden" name="action" value="sbdp_quote_log_inbound_message"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><label>' . esc_html__('Handmatig inbound reply loggen', 'sbdp') . '<input class="regular-text" type="text" name="from_name" placeholder="' . esc_attr__('Naam afzender', 'sbdp') . '"></label>';
            echo '<label>' . esc_html__('Afzender e-mail', 'sbdp') . '<input class="regular-text" type="email" name="from_email"></label>';
            echo '<label>' . esc_html__('Ontvanger e-mail', 'sbdp') . '<input class="regular-text" type="email" name="to_email" value="' . esc_attr((string) ($requester['email'] ?? '')) . '"></label>';
            echo '<label>' . esc_html__('Onderwerp', 'sbdp') . '<input class="regular-text" type="text" name="subject"></label>';
            echo '<label>' . esc_html__('Message-ID', 'sbdp') . '<input class="regular-text" type="text" name="message_id"></label>';
            echo '<label>' . esc_html__('In-Reply-To', 'sbdp') . '<input class="regular-text" type="text" name="in_reply_to"></label>';
            echo '<label>' . esc_html__('References', 'sbdp') . '<input class="regular-text" type="text" name="references"></label>';
            echo '<label>' . esc_html__('Berichttekst', 'sbdp') . '<textarea class="large-text" rows="5" name="body"></textarea></label>';
            echo '<button class="button" type="submit">' . esc_html__('Log klantreply', 'sbdp') . '</button></form></div></details>';

            if ($handoffAllowed) {
                echo '<details id="quote-handoff" class="postbox bsp-quote-admin__panel bsp-quote-admin__panel--muted bsp-quote-admin__panel--collapsible"><summary class="bsp-quote-admin__panel-toggle"><span><strong>' . esc_html__('Handoff auditdetails', 'sbdp') . '</strong><small>' . esc_html__('Technische execution-details blijven standaard ingeklapt.', 'sbdp') . '</small></span></summary><div class="bsp-quote-admin__panel-body">';
                echo '<div class="bsp-quote-admin__badge-row">';
                echo self::renderInlineBadge((string) ($quote['handoff_status'] ?? 'not_ready'), self::workflowBadgeClass((string) ($quote['handoff_status'] ?? 'not_ready')));
                if ($currentPayload !== array() && isset($currentPayload['ready_for_execution'])) {
                    echo self::renderInlineBadge(! empty($currentPayload['ready_for_execution']) ? __('execution ready', 'sbdp') : __('execution blocked', 'sbdp'), ! empty($currentPayload['ready_for_execution']) ? 'is-good' : 'is-warn');
                }
                echo '</div>';
                echo '<p><a class="button button-secondary" href="' . esc_url(self::workspaceTabUrl($quoteId, 'handoff')) . '">' . esc_html__('Open handoff-tab', 'sbdp') . '</a></p>';
                echo '</div></details>';
            }
            echo '</div>';
        } else {
            echo '<p>' . esc_html__('Tab niet gevonden.', 'sbdp') . '</p>';
        }
        echo '</div></div>';
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed>|null        $currentVersion
     * @return array<string, mixed>
     */
    private static function renderPartnerLineActions(int $quoteId, array $line, array $messages): void
    {
        $lineId = (int) ($line['id'] ?? 0);
        if ($quoteId <= 0 || $lineId <= 0) {
            return;
        }

        $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
        $partner = is_array($snapshot['partnerConfirmation'] ?? null) ? $snapshot['partnerConfirmation'] : array();
        $hasToken = ! empty($partner['tokenHash']) && ! empty($partner['tokenId']);
        $revoked = ! empty($partner['revoked']);
        $supplierStatus = (string) ($snapshot['supplierStatus'] ?? '');
        $draft = self::findSupplierRequestMessageForLine($quoteId, $lineId, $messages);
        $partnerUrl = is_array($draft) ? self::extractPartnerUrl((string) ($draft['body'] ?? '')) : '';
        $sent = is_array($draft) && (string) ($draft['status'] ?? '') === 'sent';

        echo '<div class="bsp-quote-admin__cell-stack bsp-quote-admin__partner-actions">';
        echo '<span class="bsp-quote-admin__muted">' . esc_html__('Partnerflow', 'sbdp') . ': ' . esc_html(self::partnerFlowLabel($supplierStatus, $hasToken, $revoked, $sent)) . '</span>';
        if ($hasToken) {
            echo '<span class="bsp-quote-admin__muted">' . esc_html(sprintf(__('Token %s', 'sbdp'), (string) ($partner['tokenId'] ?? ''))) . '</span>';
        }
        if ((string) ($partner['sentAt'] ?? '') !== '') {
            echo '<span class="bsp-quote-admin__muted">' . esc_html(sprintf(__('Verstuurd %s', 'sbdp'), (string) $partner['sentAt'])) . '</span>';
        }
        if ((string) ($partner['respondedAt'] ?? '') !== '') {
            echo '<span class="bsp-quote-admin__muted">' . esc_html(sprintf(__('Reactie %s', 'sbdp'), (string) $partner['respondedAt'])) . '</span>';
        }
        if ($partnerUrl !== '') {
            echo '<a class="button button-small" href="' . esc_url($partnerUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Open partnerlink', 'sbdp') . '</a>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo wp_nonce_field('sbdp_quote_line_supplier_request_draft', '_wpnonce', true, false);
        echo '<input type="hidden" name="action" value="sbdp_quote_line_supplier_request_draft">';
        echo '<input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
        echo '<input type="hidden" name="line_id" value="' . esc_attr((string) $lineId) . '">';
        echo '<button type="submit" class="button button-small">' . esc_html($hasToken && ! $revoked ? __('Nieuwe link + concept', 'sbdp') : __('Partnerlink + concept', 'sbdp')) . '</button>';
        echo '</form>';

        if ($hasToken && ! $revoked && is_array($draft) && (string) ($draft['status'] ?? '') === 'draft') {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo wp_nonce_field('sbdp_quote_line_supplier_request_send', '_wpnonce', true, false);
            echo '<input type="hidden" name="action" value="sbdp_quote_line_supplier_request_send">';
            echo '<input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
            echo '<input type="hidden" name="line_id" value="' . esc_attr((string) $lineId) . '">';
            echo '<button type="submit" class="button button-primary button-small">' . esc_html__('Verstuur partnerverzoek', 'sbdp') . '</button>';
            echo '</form>';
        }

        if ($hasToken && ! $revoked) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo wp_nonce_field('sbdp_quote_line_partner_token_revoke', '_wpnonce', true, false);
            echo '<input type="hidden" name="action" value="sbdp_quote_line_partner_token_revoke">';
            echo '<input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
            echo '<input type="hidden" name="line_id" value="' . esc_attr((string) $lineId) . '">';
            echo '<button type="submit" class="button button-small">' . esc_html__('Trek link in', 'sbdp') . '</button>';
            echo '</form>';
        }
        echo '</div>';
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private static function findSupplierRequestMessageForLine(int $quoteId, int $lineId, array $messages): ?array
    {
        $threadSuffix = '-supplier-line-' . $lineId;
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            if ((int) ($message['quote_id'] ?? 0) !== $quoteId) {
                continue;
            }
            if ((string) ($message['message_type'] ?? '') !== 'supplier_confirmation_request') {
                continue;
            }
            if (! str_ends_with((string) ($message['thread_token'] ?? ''), $threadSuffix)) {
                continue;
            }
            return $message;
        }

        return null;
    }

    private static function extractPartnerUrl(string $body): string
    {
        if (preg_match('/ddb_partner_confirmation=([A-Za-z0-9_.=-]+)/', $body, $matches) !== 1) {
            return '';
        }
        if (preg_match('/https?:\/\/[^\s]+ddb_partner_confirmation=[^\s]+/', $body, $urlMatches) === 1) {
            return rtrim((string) $urlMatches[0], ".,;)");
        }

        return '';
    }

    private static function partnerFlowLabel(string $supplierStatus, bool $hasToken, bool $revoked, bool $sent): string
    {
        if ($revoked) {
            return __('link ingetrokken', 'sbdp');
        }
        if ($supplierStatus === 'supplier_booking_confirmed') {
            return __('partner bevestigd', 'sbdp');
        }
        if (in_array($supplierStatus, array('supplier_unavailable', 'supplier_declined'), true)) {
            return __('partner niet beschikbaar', 'sbdp');
        }
        if ($supplierStatus === 'supplier_alternative_proposed') {
            return __('alternatief voorgesteld', 'sbdp');
        }
        if ($sent) {
            return __('verzoek verstuurd', 'sbdp');
        }
        if ($hasToken) {
            return __('link/concept klaar', 'sbdp');
        }

        return __('nog niet gestart', 'sbdp');
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed>|null        $currentVersion
     * @return array<string, mixed>
     */
    public static function summarizeQuoteLines(array $lines, ?array $currentVersion): array
    {
        $currency = 'EUR';
        $subtotal = 0.0;
        $pricedLines = 0;
        $totalLines = 0;
        $commercialAdjustments = self::resolveQuoteCommercialAdjustments($currentVersion);
        $discountAmount = (float) ($commercialAdjustments['discount_amount'] ?? 0.0);

        foreach ($lines as $line) {
            $totalLines++;
            if (! empty($line['currency']) && is_string($line['currency'])) {
                $currency = (string) $line['currency'];
            }
            if (! isset($line['line_total_snapshot']) || $line['line_total_snapshot'] === null || $line['line_total_snapshot'] === '') {
                continue;
            }

            $subtotal += (float) $line['line_total_snapshot'];
            $pricedLines++;
        }

        if ($currentVersion !== null && isset($currentVersion['handoff_payload_json']['totals']['currency']) && is_string($currentVersion['handoff_payload_json']['totals']['currency'])) {
            $currency = (string) $currentVersion['handoff_payload_json']['totals']['currency'];
        }

        $total = null;
        if ($totalLines === 0) {
            $subtotalLabel = __('Nog niet bepaald', 'sbdp');
            $totalLabel = __('Nog niet bepaald', 'sbdp');
        } elseif ($pricedLines === 0) {
            $subtotalLabel = __('Prijs op aanvraag', 'sbdp');
            $totalLabel = __('Prijs op aanvraag', 'sbdp');
        } elseif ($pricedLines < $totalLines) {
            $subtotalLabel = sprintf(__('Deels geprijsd: %s', 'sbdp'), self::formatMoney($subtotal, $currency));
            $totalLabel = $subtotalLabel;
        } else {
            $total = max(0.0, $subtotal - $discountAmount);
            $subtotalLabel = self::formatMoney($subtotal, $currency);
            $totalLabel = self::formatMoney($total, $currency);
        }

        return array(
            'currency'       => $currency,
            'priced_lines'   => $pricedLines,
            'total_lines'     => $totalLines,
            'subtotal_label' => $subtotalLabel,
            'discount_amount' => $discountAmount,
            'discount_label' => (string) ($commercialAdjustments['discount_label'] ?? __('Korting', 'sbdp')),
            'subtotal_amount' => $subtotal,
            'total_amount'    => $total,
            'total_label'    => $totalLabel,
        );
    }

    /**
     * @param array<string, mixed>|null $currentVersion
     * @return array<string, mixed>
     */
    private static function resolveQuoteCommercialAdjustments(?array $currentVersion): array
    {
        $pricingSnapshot = is_array($currentVersion['pricing_snapshot_json'] ?? null)
            ? $currentVersion['pricing_snapshot_json']
            : array();
        $adjustments = is_array($pricingSnapshot['commercial_adjustments'] ?? null)
            ? $pricingSnapshot['commercial_adjustments']
            : array();
        $discountAmount = isset($adjustments['discount_amount']) && is_numeric($adjustments['discount_amount'])
            ? max(0.0, round((float) $adjustments['discount_amount'], 2))
            : 0.0;
        $discountLabel = trim((string) ($adjustments['discount_label'] ?? __('Korting', 'sbdp')));

        return array(
            'type' => 'fixed_amount',
            'discount_amount' => $discountAmount,
            'discount_label' => $discountLabel !== '' ? $discountLabel : __('Korting', 'sbdp'),
            'currency' => trim((string) (($adjustments['currency'] ?? '') ?: 'EUR')),
        );
    }

    private static function buildQuoteProgram(array $lines, string $defaultCurrency): array
    {
        usort($lines, static function (array $left, array $right): int {
            $leftDate = (string) ($left['service_date'] ?? '');
            $rightDate = (string) ($right['service_date'] ?? '');
            $dateCompare = strcmp($leftDate !== '' ? $leftDate : '9999-99-99', $rightDate !== '' ? $rightDate : '9999-99-99');
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            $leftTime = self::resolveProposalLineStartTime($left);
            $rightTime = self::resolveProposalLineStartTime($right);
            $timeCompare = strcmp($leftTime !== '' ? $leftTime : '99:99', $rightTime !== '' ? $rightTime : '99:99');
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            return ((int) ($left['sort_order'] ?? ($left['line_number'] ?? 0))) <=> ((int) ($right['sort_order'] ?? ($right['line_number'] ?? 0)));
        });

        $groups = array();
        $stats = array(
            'total_lines' => count($lines),
            'scheduled_lines' => 0,
            'priced_lines' => 0,
            'confirmed_lines' => 0,
            'provisional_lines' => 0,
        );

        foreach ($lines as $line) {
            $programLine = self::buildQuoteProgramLine($line, $defaultCurrency);
            $groupKey = (string) $programLine['group_key'];
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = array(
                    'label' => (string) $programLine['group_label'],
                    'items' => array(),
                );
            }
            $groups[$groupKey]['items'][] = $programLine;

            if (! empty($programLine['is_scheduled'])) {
                $stats['scheduled_lines']++;
            }
            if (! empty($programLine['has_price'])) {
                $stats['priced_lines']++;
            }
            if (! empty($programLine['is_confirmed'])) {
                $stats['confirmed_lines']++;
            }
            if (! empty($programLine['is_provisional'])) {
                $stats['provisional_lines']++;
            }
        }

        return array(
            'stats' => $stats,
            'groups' => array_values($groups),
        );
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private static function buildQuoteProgramLine(array $line, string $defaultCurrency): array
    {
        $date = trim((string) ($line['service_date'] ?? ''));
        $startTime = self::resolveProposalLineStartTime($line);
        $endTime = self::resolveProposalLineEndTime($line);
        $durationMinutes = self::resolveProgramLineDurationMinutes($line);
        $optionLabels = self::resolveProgramOptionLabels($line);
        $validatedSlotLabel = self::resolveProgramValidatedSlotLabel($line, $date, $startTime, $endTime);
        $timingPresentation = self::buildProgramTimingPresentation($startTime, $endTime, $validatedSlotLabel, (string) ($line['availability_confidence'] ?? 'unknown'), $date);
        $lineTotal = isset($line['line_total_snapshot']) && $line['line_total_snapshot'] !== '' ? (float) $line['line_total_snapshot'] : null;
        $unitAmount = isset($line['unit_amount_snapshot']) && $line['unit_amount_snapshot'] !== '' ? (float) $line['unit_amount_snapshot'] : null;
        $currency = (string) (($line['currency'] ?? '') ?: $defaultCurrency);
        $participants = max(0, (int) ($line['participants'] ?? 0));
        $quantity = max(1, (int) ($line['quantity'] ?? 1));
        $pricingConfidence = (string) ($line['pricing_confidence'] ?? 'unknown');
        $availabilityConfidence = (string) ($line['availability_confidence'] ?? 'unknown');
        $isConfirmed = $availabilityConfidence === 'confirmed';
        $isProvisional = $availabilityConfidence !== 'confirmed' || $pricingConfidence !== 'execution_verified';

        $detailBits = array();
        if ($participants > 0) {
            $detailBits[] = sprintf(__('%d deelnemers', 'sbdp'), $participants);
        } else {
            $detailBits[] = __('Deelnemers nog open', 'sbdp');
        }
        $detailBits[] = sprintf(__('Aantal %d', 'sbdp'), $quantity);
        if ($durationMinutes !== null) {
            $detailBits[] = sprintf(__('Duur %d min', 'sbdp'), $durationMinutes);
        }
        if ($validatedSlotLabel !== '' && $validatedSlotLabel !== (string) ($timingPresentation['primary'] ?? '')) {
            $detailBits[] = sprintf(__('Gevalideerd slot %s', 'sbdp'), $validatedSlotLabel);
        }

        $availabilityLabel = $isConfirmed ? __('Beschikbaarheid bevestigd', 'sbdp') : __('Beschikbaarheid onder voorbehoud', 'sbdp');
        $availabilityNote = $isConfirmed
            ? __('Deze regel had op snapshotmoment een bevestigd tijdslot of positieve execution-validatie.', 'sbdp')
            : __('Behandel tijd en beschikbaarheid als richtinggevend totdat execution of leverancier deze bevestigt.', 'sbdp');
        $pricingLabel = $pricingConfidence === 'execution_verified' ? __('Prijs bevestigd', 'sbdp') : __('Prijs nog controleren', 'sbdp');

        return array(
            'group_key' => $date !== '' ? $date : 'open',
            'group_label' => $date !== '' ? $date : __('Datum nog open', 'sbdp'),
            'time_label' => (string) ($timingPresentation['label'] ?? __('Planning nog open', 'sbdp')),
            'time_badge_class' => (string) ($timingPresentation['badge_class'] ?? 'is-neutral'),
            'time_primary' => (string) ($timingPresentation['primary'] ?? __('Planning nog open', 'sbdp')),
            'time_secondary' => (string) ($timingPresentation['secondary'] ?? ''),
            'title' => (string) (($line['title'] ?? '') !== '' ? $line['title'] : __('Programmastop', 'sbdp')),
            'option_labels' => $optionLabels,
            'detail_bits' => $detailBits,
            'price_primary' => $lineTotal !== null ? self::formatMoney($lineTotal, $currency) : __('Prijs nog open', 'sbdp'),
            'price_secondary' => $unitAmount !== null ? sprintf(__('Per stuk %s', 'sbdp'), self::formatMoney($unitAmount, $currency)) : '',
            'availability_label' => $availabilityLabel,
            'availability_badge_class' => $isConfirmed ? 'is-good' : 'is-warn',
            'availability_note' => $availabilityNote,
            'pricing_label' => $pricingLabel,
            'pricing_badge_class' => $pricingConfidence === 'execution_verified' ? 'is-good' : 'is-neutral',
            'optional_label' => ! empty($line['is_optional']) ? __('Optioneel', 'sbdp') : '',
            'is_scheduled' => ! empty($timingPresentation['is_scheduled']),
            'has_price' => $lineTotal !== null,
            'is_confirmed' => $isConfirmed,
            'is_provisional' => $isProvisional,
        );
    }

    /**
     * @return array{label: string, badge_class: string, primary: string, secondary: string, is_scheduled: bool}
     */
    private static function buildProgramTimingPresentation(
        string $startTime,
        string $endTime,
        string $validatedSlotLabel,
        string $availabilityConfidence,
        string $serviceDate
    ): array {
        $rangeLabel = self::formatProgramTimeRange($startTime, $endTime, $validatedSlotLabel);

        if ($rangeLabel !== '' && $availabilityConfidence === 'confirmed') {
            return array(
                'label' => __('Bevestigd tijdslot', 'sbdp'),
                'badge_class' => 'is-good',
                'primary' => $rangeLabel,
                'secondary' => __('Tijd en slot waren bevestigd op snapshotmoment.', 'sbdp'),
                'is_scheduled' => true,
            );
        }

        if ($rangeLabel !== '' && ($availabilityConfidence === 'projected' || $availabilityConfidence === 'snapshot')) {
            return array(
                'label' => __('Voorgesteld tijdslot', 'sbdp'),
                'badge_class' => 'is-neutral',
                'primary' => $rangeLabel,
                'secondary' => __('Nog onder voorbehoud totdat beschikbaarheid wordt bevestigd.', 'sbdp'),
                'is_scheduled' => true,
            );
        }

        if ($rangeLabel !== '') {
            return array(
                'label' => __('Onder review', 'sbdp'),
                'badge_class' => 'is-warn',
                'primary' => $rangeLabel,
                'secondary' => __('Controleer dit tijdslot nog voordat je het als klantafspraak behandelt.', 'sbdp'),
                'is_scheduled' => true,
            );
        }

        if ($serviceDate !== '') {
            return array(
                'label' => __('Tijd nog plannen', 'sbdp'),
                'badge_class' => 'is-warn',
                'primary' => __('Tijdslot nog invullen', 'sbdp'),
                'secondary' => __('Datum staat al vast, maar een bruikbaar tijdslot ontbreekt nog.', 'sbdp'),
                'is_scheduled' => false,
            );
        }

        return array(
            'label' => __('Planning ontbreekt', 'sbdp'),
            'badge_class' => 'is-warn',
            'primary' => __('Plan datum en tijd', 'sbdp'),
            'secondary' => __('Deze programmaregel heeft nog geen datum of tijd om met de klant te delen.', 'sbdp'),
            'is_scheduled' => false,
        );
    }

    private static function formatProgramTimeRange(string $startTime, string $endTime, string $validatedSlotLabel): string
    {
        if ($startTime !== '' && $endTime !== '') {
            return sprintf('%s - %s', $startTime, $endTime);
        }

        if ($startTime !== '') {
            return sprintf(__('Start %s', 'sbdp'), $startTime);
        }

        if ($endTime !== '') {
            return sprintf(__('Tot %s', 'sbdp'), $endTime);
        }

        return $validatedSlotLabel;
    }

    /**
     * @param array<string, mixed> $line
     * @return array<int, string>
     */
    private static function resolveProgramOptionLabels(array $line): array
    {
        $labels = array();

        $canonicalLabels = isset($line['selected_option_labels_json']) && is_array($line['selected_option_labels_json'])
            ? $line['selected_option_labels_json']
            : array();
        foreach ($canonicalLabels as $candidate) {
            if (! is_scalar($candidate) || $candidate === null) {
                continue;
            }

            $label = trim((string) $candidate);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        $externalLabel = trim((string) ($line['external_label'] ?? ''));
        if ($externalLabel !== '') {
            $labels[] = $externalLabel;
        }

        $pricingSnapshot = isset($line['pricing_snapshot_json']) && is_array($line['pricing_snapshot_json'])
            ? $line['pricing_snapshot_json']
            : array();
        $availabilitySnapshot = isset($line['availability_snapshot_json']) && is_array($line['availability_snapshot_json'])
            ? $line['availability_snapshot_json']
            : array();

        foreach (array('selection_label', 'selection_name', 'option_label', 'option_name', 'resource_label', 'resource_name', 'variant_label', 'variant_name') as $key) {
            foreach (array($pricingSnapshot, $availabilitySnapshot) as $snapshot) {
                if (! isset($snapshot[$key]) || ! is_scalar($snapshot[$key])) {
                    continue;
                }

                $candidate = trim((string) $snapshot[$key]);
                if ($candidate !== '') {
                    $labels[] = $candidate;
                }
            }
        }

        return array_values(array_unique(array_filter($labels)));
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function resolveProgramValidatedSlotLabel(array $line, string $serviceDate, string $startTime, string $endTime): string
    {
        $slotLabel = trim((string) ($line['validated_slot_label'] ?? ''));
        if ($slotLabel !== '') {
            return $slotLabel;
        }

        $timeRange = '';
        if ($startTime !== '' && $endTime !== '') {
            $timeRange = sprintf('%s-%s', $startTime, $endTime);
        } elseif ($startTime !== '') {
            $timeRange = $startTime;
        } elseif ($endTime !== '') {
            $timeRange = $endTime;
        }

        if ($serviceDate !== '' && $timeRange !== '') {
            return sprintf('%s %s', $serviceDate, $timeRange);
        }

        return $timeRange !== '' ? $timeRange : $serviceDate;
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function resolveProgramLineDurationMinutes(array $line): ?int
    {
        $duration = isset($line['duration_minutes']) ? (int) $line['duration_minutes'] : 0;
        if ($duration > 0) {
            return $duration;
        }

        $start = self::resolveProposalLineStartTime($line);
        $end = self::resolveProposalLineEndTime($line);
        if (! preg_match('/^(\d{2}):(\d{2})$/', $start, $startMatches) || ! preg_match('/^(\d{2}):(\d{2})$/', $end, $endMatches)) {
            return null;
        }

        $startMinutes = (((int) $startMatches[1]) * 60) + (int) $startMatches[2];
        $endMinutes = (((int) $endMatches[1]) * 60) + (int) $endMatches[2];

        return $endMinutes > $startMinutes ? $endMinutes - $startMinutes : null;
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function resolveProposalLineStartTime(array $line): string
    {
        return trim((string) ($line['proposed_start_time'] ?? ($line['start_time'] ?? '')));
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function quoteLinePricingControlStatus(array $line): string
    {
        $snapshot = is_array($line['pricing_snapshot_json'] ?? null) ? $line['pricing_snapshot_json'] : array();
        $status = (string) ($snapshot['control_status'] ?? '');
        if (in_array($status, array('needs_check', 'confirmed', 'under_reservation'), true)) {
            return $status;
        }

        return (string) ($line['pricing_confidence'] ?? 'unknown') === 'execution_verified'
            ? 'confirmed'
            : (((string) ($line['pricing_confidence'] ?? 'unknown') === 'snapshot') ? 'under_reservation' : 'needs_check');
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function quoteLineAvailabilityControlStatus(array $line): string
    {
        $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
        $status = (string) ($snapshot['control_status'] ?? '');
        if (in_array($status, array('needs_check', 'confirmed', 'under_reservation', 'unavailable'), true)) {
            return $status;
        }

        if ((string) ($line['line_status'] ?? '') === 'unavailable') {
            return 'unavailable';
        }

        return (string) ($line['availability_confidence'] ?? 'unknown') === 'confirmed'
            ? 'confirmed'
            : (in_array((string) ($line['availability_confidence'] ?? 'unknown'), array('snapshot', 'projected'), true) ? 'under_reservation' : 'needs_check');
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function resolveProposalLineEndTime(array $line): string
    {
        return trim((string) ($line['proposed_end_time'] ?? ($line['end_time'] ?? '')));
    }

    /**
     * @param array<string, mixed>      $quote
     * @param array<string, mixed>|null $currentVersion
     * @param array<int, array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $assumptions
     * @param array<int, array<string, mixed>> $followups
     * @return array<string, mixed>
     */
    private static function buildQuoteWorkspaceState(
        array $quote,
        ?array $currentVersion,
        array $lines,
        array $assumptions,
        array $followups,
        array $communicationState,
        array $sendReadiness,
        array $proposalReadiness
    ): array {
        $blockers = array();
        $waiting = array();
        $mappedLines = 0;
        $projectedPricingLines = 0;
        $projectedAvailabilityLines = 0;
        $unscheduledLines = 0;

        if ($currentVersion === null) {
            $blockers[] = __('Er is nog geen actieve quote-versie beschikbaar.', 'sbdp');
        }
        if ($lines === array()) {
            $blockers[] = __('De quote bevat nog geen commerciële regels.', 'sbdp');
        }

        foreach ($lines as $line) {
            if ((int) ($line['product_id'] ?? 0) > 0) {
                $mappedLines++;
            }
            if (self::quoteLinePricingControlStatus($line) === 'needs_check') {
                $projectedPricingLines++;
            }
            if (in_array(self::quoteLineAvailabilityControlStatus($line), array('needs_check', 'unavailable'), true)) {
                $projectedAvailabilityLines++;
            }
            if (trim((string) ($line['service_date'] ?? '')) === '' || self::resolveProposalLineStartTime($line) === '') {
                $unscheduledLines++;
            }
        }

        foreach ($assumptions as $assumption) {
            if ((string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }

            $message = (string) ($assumption['message'] ?? '');
            if ($message === '') {
                continue;
            }

            if (! empty($assumption['blocks_review']) || ! empty($assumption['blocks_send'])) {
                $blockers[] = $message;
                continue;
            }

            $waiting[] = $message;
        }

        if ((string) ($quote['review_status'] ?? 'not_started') === 'pending_review') {
            $waiting[] = __('De quote wacht op interne review.', 'sbdp');
        }
        if ((string) ($quote['review_status'] ?? 'not_started') === 'approved' && (string) ($quote['send_status'] ?? 'not_ready') === 'ready_to_send') {
            $waiting[] = __('De quote is commercieel gereed maar nog niet als verzonden geregistreerd.', 'sbdp');
        }
        if ($currentVersion !== null && (string) ($currentVersion['availability_confidence'] ?? 'unknown') !== 'confirmed') {
            $waiting[] = __('Beschikbaarheid is nog niet overal bevestigd of staat onder voorbehoud.', 'sbdp');
        }
        if ($currentVersion !== null && (string) ($currentVersion['pricing_confidence'] ?? 'unknown') !== 'execution_verified') {
            $waiting[] = __('Prijs is nog niet overal bevestigd of staat onder voorbehoud.', 'sbdp');
        }

        $openFollowups = 0;
        foreach ($followups as $followup) {
            if ((string) ($followup['status'] ?? 'open') === 'open') {
                $openFollowups++;
            }
        }
        if ($openFollowups > 0) {
            $waiting[] = sprintf(__('Er staan nog %d open follow-ups.', 'sbdp'), $openFollowups);
        }

        $cta = 'review_request';
        $nextTitle = __('Vraag review aan', 'sbdp');
        $nextDescription = __('Zodra de commerciële opzet klopt, zet je de quote door naar interne review.', 'sbdp');
        $readinessLabel = __('Nog niet verzendklaar', 'sbdp');
        $readinessDescription = __('Werk open punten weg en maak de review-flow leidend voordat je naar verzenden of handoff kijkt.', 'sbdp');
        $nextAction = array(
            'title' => $nextTitle,
            'description' => $nextDescription,
            'cta' => $cta,
        );

        $pricingMissing = $currentVersion === null || (string) ($currentVersion['pricing_confidence'] ?? 'unknown') !== 'execution_verified' || $projectedPricingLines > 0;
        $availabilityMissing = $currentVersion === null || (string) ($currentVersion['availability_confidence'] ?? 'unknown') !== 'confirmed' || $projectedAvailabilityLines > 0;
        $proposalReady = ! empty($proposalReadiness['ready']);
        $customerReplyOpen = (string) ($communicationState['thread_label'] ?? '') === __('Waiting on us', 'sbdp') && ! empty($communicationState['latest_inbound_message_id']);

        if ($lines === array()) {
            $nextTitle = __('Programma toevoegen', 'sbdp');
            $nextDescription = __('Voeg eerst programmaregels toe voordat prijs, beschikbaarheid of klantreacties leidend worden.', 'sbdp');
            $readinessLabel = __('Programma ontbreekt', 'sbdp');
            $readinessDescription = __('De offerte kan nog niet worden verstuurd zonder programmaregels.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'build',
            );
        } elseif ($pricingMissing) {
            $nextTitle = __('Prijs bevestigen', 'sbdp');
            $nextDescription = __('Controleer de prijs per onderdeel in Programma. Klantreacties komen pas daarna als primaire actie.', 'sbdp');
            $readinessLabel = __('Prijs nog niet bevestigd', 'sbdp');
            $readinessDescription = __('Deze offerte kan nog niet worden verstuurd omdat de prijs nog niet definitief is bevestigd.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'build',
            );
        } elseif ($availabilityMissing) {
            $nextTitle = __('Beschikbaarheid bevestigen', 'sbdp');
            $nextDescription = __('Controleer datum, tijd en capaciteit per onderdeel voordat communicatie de hoofdactie wordt.', 'sbdp');
            $readinessLabel = __('Beschikbaarheid nog niet bevestigd', 'sbdp');
            $readinessDescription = __('Deze offerte kan nog niet worden verstuurd omdat beschikbaarheid nog moet worden bevestigd.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'build',
            );
        } elseif (! $proposalReady) {
            $nextTitle = __('Voorstel controleren', 'sbdp');
            $nextDescription = __('Controleer programma, voorsteltekst en send-readiness voordat je klantcommunicatie afrondt.', 'sbdp');
            $readinessLabel = __('Voorstel nog controleren', 'sbdp');
            $readinessDescription = __('De commerciële checks zijn klaar; controleer nu de voorstelinhoud.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'proposal_readiness',
            );
        } elseif (! empty($sendReadiness['ready']) && (string) ($quote['review_status'] ?? 'not_started') !== 'approved') {
            $nextTitle = __('Controle afronden', 'sbdp');
            $nextDescription = __('Alle verplichte controles zijn groen. Rond de controle af om het voorstel vrij te geven voor verzending.', 'sbdp');
            $readinessLabel = __('Voorstel klaar voor verzending', 'sbdp');
            $readinessDescription = __('Geen aparte review-blokkade meer: de volgende stap is controle afronden.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'review_approve',
            );
        } elseif ($customerReplyOpen) {
            $nextTitle = __('Verwerk klantreactie', 'sbdp');
            $nextDescription = __('De klant heeft gereageerd. Maak eerst een antwoorddraft of samenvatting voordat je verdere workflowstappen neemt.', 'sbdp');
            $readinessLabel = __('Klant wacht op antwoord', 'sbdp');
            $readinessDescription = __('De thread heeft nu voorrang op interne vervolgacties.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'reply_draft',
                'message_id' => (int) $communicationState['latest_inbound_message_id'],
            );
        } elseif (! empty($sendReadiness['ready'])) {
            $nextTitle = __('Voorstel versturen', 'sbdp');
            $nextDescription = __('Alle primaire checks zijn klaar en de controle is afgerond. Verstuur of registreer nu het klantvoorstel.', 'sbdp');
            $readinessLabel = __('Klaar voor verzending', 'sbdp');
            $readinessDescription = __('Voorstel klaar voor verzending.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'send_mark_sent',
            );
        } elseif ($blockers !== array()) {
            $nextTitle = __('Werk blockers weg', 'sbdp');
            $nextDescription = __('Open punten blokkeren review of verzending en moeten eerst expliciet worden opgelost.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'assumptions',
            );
        } elseif ((string) ($quote['review_status'] ?? 'not_started') === 'pending_review') {
            $nextTitle = __('Rond review af', 'sbdp');
            $nextDescription = __('De quote staat in review en wacht op goedkeuring of terugkoppeling.', 'sbdp');
            $readinessLabel = __('Wacht op review', 'sbdp');
            $readinessDescription = __('De commerciële inhoud ligt klaar voor een reviewbeslissing.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'review_approve',
            );
        } elseif ((string) ($quote['review_status'] ?? 'not_started') === 'approved' && (string) ($quote['send_status'] ?? 'not_ready') === 'ready_to_send') {
            $hasCommercialCaveats = (
                $mappedLines !== count($lines)
                || $projectedPricingLines > 0
                || $projectedAvailabilityLines > 0
                || $unscheduledLines > 0
                || ($currentVersion !== null && (string) ($currentVersion['pricing_confidence'] ?? 'unknown') !== 'execution_verified')
                || ($currentVersion !== null && (string) ($currentVersion['availability_confidence'] ?? 'unknown') !== 'confirmed')
            );

            if ((string) ($communicationState['proposal_label'] ?? '') === __('Nothing sent', 'sbdp')) {
                $nextTitle = __('Maak voorstelmail', 'sbdp');
                $nextDescription = __('Review is afgerond. Leg nu eerst een echte voorstelmail vast voordat je deze quote als klantcommunicatie behandelt.', 'sbdp');
                $readinessLabel = $hasCommercialCaveats ? __('Verzendcheck nodig', 'sbdp') : __('Klaar voor klantcontact', 'sbdp');
                $readinessDescription = $hasCommercialCaveats
                    ? __('Er is nog een voorstelmail nodig en commerciële caveats moeten expliciet worden meegenomen.', 'sbdp')
                    : __('De inhoud staat klaar; maak nu de voorstelmail om het klantvoorstel echt te verzenden.', 'sbdp');
                $nextAction = array(
                    'title' => $nextTitle,
                    'description' => $nextDescription,
                    'cta' => 'proposal_draft',
                );
            } elseif ($hasCommercialCaveats) {
                $nextTitle = __('Doorloop verzendcheck', 'sbdp');
                $nextDescription = __('Review is goedgekeurd, maar de operator moet eerst de open proposal-readiness acties nalopen voordat deze versie veilig als verzonden wordt geregistreerd.', 'sbdp');
                $readinessLabel = __('Verzendcheck nodig', 'sbdp');
                $readinessDescription = __('De quote is verzendklaar, maar bevat nog commerciële aandachtspunten die expliciet moeten worden meegenomen in klantcommunicatie.', 'sbdp');
                $nextAction = array(
                    'title' => $nextTitle,
                    'description' => $nextDescription,
                    'cta' => 'proposal_readiness',
                );
            } else {
                $nextTitle = __('Registreer verzending', 'sbdp');
                $nextDescription = __('Review is goedgekeurd; de logische operatorstap is nu verzenden of handmatig als verzonden markeren.', 'sbdp');
                $readinessLabel = __('Klaar om te verzenden', 'sbdp');
                $readinessDescription = __('De quote heeft review doorlopen en staat klaar voor commerciële communicatie.', 'sbdp');
                $nextAction = array(
                    'title' => $nextTitle,
                    'description' => $nextDescription,
                    'cta' => 'send_mark_sent',
                );
            }
        } elseif ((string) ($quote['send_status'] ?? 'not_ready') === 'sent_manual' && (string) ($quote['handoff_status'] ?? 'not_ready') === 'not_ready') {
            $nextTitle = __('Bewaak opvolging', 'sbdp');
            $nextDescription = __('De quote is verzonden. Gebruik follow-ups om akkoord, vragen en vervolgstappen te managen.', 'sbdp');
            $readinessLabel = __('Verzonden, wacht op vervolg', 'sbdp');
            $readinessDescription = __('Communicatie loopt; geavanceerde handoff blijft beschikbaar maar is nog geen primaire operator-taak.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'followups',
            );
        } elseif ((string) ($quote['handoff_status'] ?? 'not_ready') === 'ready_for_resnapshot') {
            $nextTitle = __('Handoff staat klaar', 'sbdp');
            $nextDescription = __('De quote mag naar de gecontroleerde resnapshot-stap zodra dat operationeel nodig is.', 'sbdp');
            $readinessLabel = __('Handoff voorbereid', 'sbdp');
            $readinessDescription = __('De commerciële werkruimte is afgerond; execution-voorbereiding is beschikbaar onder de vouw.', 'sbdp');
            $nextAction = array(
                'title' => $nextTitle,
                'description' => $nextDescription,
                'cta' => 'handoff',
            );
        }

        return array(
            'blockers'              => array_values(array_unique($blockers)),
            'waiting'               => array_values(array_unique($waiting)),
            'readiness_label'       => $readinessLabel,
            'readiness_description' => $readinessDescription,
            'next_action'           => $nextAction,
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $proposalReadiness
     * @param array<string, mixed> $workspaceState
     * @param array<string, mixed> $communicationState
     * @return array<string, mixed>
     */
    private static function buildQuoteOperatorDecision(
        array $quote,
        array $proposalReadiness,
        array $workspaceState,
        array $communicationState
    ): array {
        $statusLabel = __('Intern concept', 'sbdp');
        $statusBadgeClass = 'is-neutral';
        $title = __('Werk de offerte nog intern uit', 'sbdp');
        $statusDescription = (string) ($proposalReadiness['description'] ?? __('Werk eerst review en ontbrekende commerciële details uit.', 'sbdp'));
        $threadLabel = '';
        $threadBadgeClass = 'is-neutral';

        if ((string) ($communicationState['thread_label'] ?? '') === __('Waiting on us', 'sbdp')) {
            $statusLabel = __('Klant heeft gereageerd', 'sbdp');
            $statusBadgeClass = 'is-warn';
            $title = __('De thread vraagt nu om operatoractie', 'sbdp');
            $statusDescription = (string) ($communicationState['description'] ?? '');
            $threadLabel = (string) ($communicationState['operator_action_age_label'] ?? '');
            $threadBadgeClass = (string) ($communicationState['operator_action_age_badge_class'] ?? 'is-neutral');
        } elseif ((string) ($communicationState['proposal_label'] ?? '') === __('Proposal sent', 'sbdp')) {
            $statusLabel = __('Wacht op klant', 'sbdp');
            $statusBadgeClass = 'is-neutral';
            $title = __('Voorstel is verstuurd en de thread loopt', 'sbdp');
            $statusDescription = (string) ($communicationState['description'] ?? '');
        } elseif ((string) ($quote['review_status'] ?? 'not_started') === 'approved') {
            if ((string) ($proposalReadiness['badge_class'] ?? 'is-neutral') === 'is-good') {
                $statusLabel = __('Klaar voor klant', 'sbdp');
                $statusBadgeClass = 'is-good';
                $title = __('Commercieel voorstel staat strak genoeg om te delen', 'sbdp');
            } else {
                $statusLabel = __('Richtinggevend voorstel', 'sbdp');
                $statusBadgeClass = 'is-warn';
                $title = __('Deelbaar, maar nog expliciet onder voorbehoud', 'sbdp');
            }
            $statusDescription = (string) ($proposalReadiness['description'] ?? $statusDescription);
        }

        $missingItems = array();
        foreach ((array) ($proposalReadiness['items'] ?? array()) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $missingItems[] = array(
                'title' => (string) ($item['title'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
            );
            if (count($missingItems) >= 4) {
                break;
            }
        }

        if ($missingItems === array()) {
            foreach ((array) ($workspaceState['blockers'] ?? array()) as $blocker) {
                $missingItems[] = array(
                    'title' => __('Blokkade', 'sbdp'),
                    'description' => (string) $blocker,
                );
                if (count($missingItems) >= 4) {
                    break;
                }
            }
        }

        return array(
            'status_label' => $statusLabel,
            'status_badge_class' => $statusBadgeClass,
            'title' => $title,
            'status_description' => $statusDescription,
            'thread_label' => $threadLabel,
            'thread_badge_class' => $threadBadgeClass,
            'missing_items' => $missingItems,
        );
    }

    /**
     * @param array<string, mixed>      $quote
     * @param array<string, mixed>|null $request
     * @param array<string, mixed>      $requester
     * @param array<string, mixed>|null $currentVersion
     * @param array<string, mixed>      $primaryAction
     */
    private static function renderQuoteDecisionStrip(
        int $quoteId,
        array $quote,
        ?array $request,
        array $requester,
        ?array $currentVersion,
        string $pricingConfidence,
        string $availabilityConfidence,
        array $primaryAction
    ): void {
        $reference = trim((string) ($quote['quote_reference'] ?? ''));
        $customerName = trim((string) ($requester['name'] ?? ''));
        $date = is_array($request) ? trim((string) ($request['preferred_date'] ?? '')) : '';
        $groupSize = is_array($request) ? max(0, (int) ($request['group_size'] ?? 0)) : 0;
        $status = self::humanQuoteStatusLabel($quote);
        $versionLabel = $currentVersion !== null
            ? sprintf(__('Versie %s', 'sbdp'), (string) ($currentVersion['version_number'] ?? '1'))
            : __('Nog geen versie', 'sbdp');

        echo '<section class="postbox bsp-quote-admin__decision-strip" aria-label="' . esc_attr__('Quote beslissing', 'sbdp') . '">';
        echo '<div class="bsp-quote-admin__decision-strip-main">';
        echo self::renderDecisionStripItem(__('Quote / klant', 'sbdp'), trim(($reference !== '' ? $reference : __('Geen referentie', 'sbdp')) . ' · ' . ($customerName !== '' ? $customerName : __('Onbekende klant', 'sbdp'))));
        echo self::renderDecisionStripItem(__('Datum', 'sbdp'), $date !== '' ? $date : __('Nog niet bevestigd', 'sbdp'));
        echo self::renderDecisionStripItem(__('Groep', 'sbdp'), $groupSize > 0 ? sprintf(__('%d personen', 'sbdp'), $groupSize) : __('Nog open', 'sbdp'));
        echo self::renderDecisionStripItem(__('Status', 'sbdp'), $status);
        echo self::renderDecisionStripItem(__('Prijs', 'sbdp'), self::humanPricingStatusLabel($pricingConfidence));
        echo self::renderDecisionStripItem(__('Beschikbaarheid', 'sbdp'), self::humanAvailabilityStatusLabel($availabilityConfidence));
        echo self::renderDecisionStripItem(__('Volgende actie', 'sbdp'), (string) ($primaryAction['title'] ?? __('Controleer quote', 'sbdp')));
        echo '</div>';
        echo '<div class="bsp-quote-admin__decision-strip-action">';
        echo '<span class="bsp-quote-admin__field-label">' . esc_html($versionLabel) . '</span>';
        echo self::renderQuotePrimaryAction($quoteId, $primaryAction);
        if (! empty($primaryAction['description'])) {
            echo '<small>' . esc_html((string) $primaryAction['description']) . '</small>';
        }
        echo '</div>';
        echo '</section>';
    }

    private static function renderDecisionStripItem(string $label, string $value): string
    {
        return '<div><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong></div>';
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $workspaceState
     * @return array<string, mixed>
     */
    private static function resolveQuotePrimaryAction(array $quote, array $workspaceState, bool $sendAllowed, bool $handoffAllowed): array
    {
        $status = (string) ($quote['status'] ?? 'draft');
        $reviewStatus = (string) ($quote['review_status'] ?? 'not_started');
        $sendStatus = (string) ($quote['send_status'] ?? 'not_ready');
        $hasBlockers = ((array) ($workspaceState['blockers'] ?? array())) !== array();

        if (in_array($status, array('declined', 'expired', 'cancelled'), true)) {
            return array('cta' => 'tab_link', 'tab' => 'history', 'title' => __('Afgesloten quote bekijken', 'sbdp'), 'label' => __('Bekijk audit', 'sbdp'));
        }

        if ($status === 'accepted') {
            return $handoffAllowed
                ? array('cta' => 'tab_link', 'tab' => 'handoff', 'title' => __('Bereid Woo-overdracht voor', 'sbdp'), 'label' => __('Open handoff', 'sbdp'))
                : array('cta' => 'tab_link', 'tab' => 'history', 'title' => __('Akkoord vastgelegd; handoff nog niet vrijgegeven', 'sbdp'), 'label' => __('Bekijk audit', 'sbdp'));
        }

        if (in_array($status, array('sent', 'sent_manual'), true)) {
            return array('cta' => 'tab_link', 'tab' => 'communication', 'title' => __('Wacht op klantreactie', 'sbdp'), 'label' => __('Bekijk berichten', 'sbdp'));
        }

        if ($sendAllowed) {
            return array('cta' => 'tab_link', 'tab' => 'communication', 'anchor' => 'quote-proposal-send-form', 'title' => __('Voorstel versturen', 'sbdp'), 'label' => __('Voorstel versturen', 'sbdp'), 'description' => __('Alle verplichte controles zijn afgerond.', 'sbdp'));
        }

        if ($sendStatus === 'ready_to_send' || $status === 'ready_to_send') {
            return $hasBlockers
                ? array('cta' => 'tab_link', 'tab' => 'build', 'anchor' => 'quote-blockers-card', 'title' => __('Los blockers op', 'sbdp'), 'label' => __('Controleer nu', 'sbdp'), 'description' => __('Nog niet verzendklaar: open blockers moeten eerst worden opgelost.', 'sbdp'))
                : array('cta' => 'tab_link', 'tab' => 'build', 'anchor' => 'quote-blockers-card', 'title' => __('Controleer verzendstatus', 'sbdp'), 'label' => __('Controleer nu', 'sbdp'), 'description' => __('Nog niet verzendklaar: de readiness-check is nog niet groen.', 'sbdp'));
        }

        if ($reviewStatus === 'pending_review' || $status === 'pending_review') {
            return array('cta' => 'review_approve', 'title' => __('Controle afronden', 'sbdp'), 'label' => __('Controle afronden', 'sbdp'));
        }

        $nextAction = is_array($workspaceState['next_action'] ?? null) ? $workspaceState['next_action'] : array();
        $cta = (string) ($nextAction['cta'] ?? '');
        if ($cta === 'reply_draft') {
            $nextAction['title'] = __('Klantreactie verwerken', 'sbdp');
            return $nextAction;
        }
        if ($cta === 'assumptions') {
            return array('cta' => 'tab_link', 'tab' => 'build', 'anchor' => 'quote-blockers-card', 'title' => __('Blockers oplossen', 'sbdp'), 'label' => __('Controleer nu', 'sbdp'), 'description' => (string) ($nextAction['description'] ?? __('Nog niet verzendklaar: los eerst de open punten op.', 'sbdp')));
        }
        if ($cta === 'build') {
            return array('cta' => 'tab_link', 'tab' => 'build', 'title' => (string) ($nextAction['title'] ?? __('Programma controleren', 'sbdp')), 'label' => __('Naar programma', 'sbdp'), 'description' => (string) ($nextAction['description'] ?? __('Nog niet verzendklaar: controleer eerst programma, prijs en beschikbaarheid.', 'sbdp')));
        }
        if ($cta === 'review_approve') {
            return array('cta' => 'review_approve', 'title' => __('Controle afronden', 'sbdp'), 'label' => __('Controle afronden', 'sbdp'), 'description' => (string) ($nextAction['description'] ?? __('Alle verplichte controles zijn groen.', 'sbdp')));
        }

        return array('cta' => 'tab_link', 'tab' => 'build', 'title' => __('Concept afronden', 'sbdp'), 'label' => __('Werk programma bij', 'sbdp'), 'description' => __('Nog niet verzendklaar: rond eerst de hoofdcontrole af.', 'sbdp'));
    }

    /**
     * @param array<string, mixed>|null $request
     * @param array<string, mixed>      $requester
     * @param array<int, string>        $contactSummary
     * @param array<string, mixed>      $proposalProgram
     * @param array<string, mixed>      $lineSummary
     * @param array<string, mixed>      $alerts
     */
    private static function renderQuoteWorkspaceSummaryCards(
        int $quoteId,
        array $quote,
        ?array $request,
        array $requester,
        string $formattedAddress,
        array $contactSummary,
        array $proposalProgram,
        array $lineSummary,
        string $amountLabel,
        string $pricingConfidence,
        string $availabilityConfidence,
        array $alerts,
        bool $sendAllowed
    ): void {
        $stats = is_array($proposalProgram['stats'] ?? null) ? $proposalProgram['stats'] : array();
        $blockers = is_array($alerts['blockers'] ?? null) ? $alerts['blockers'] : array();
        $partnerActions = is_array($alerts['partner_actions'] ?? null) ? $alerts['partner_actions'] : array();
        $warnings = is_array($alerts['warnings'] ?? null) ? $alerts['warnings'] : array();
        $summary = is_array($request) ? trim((string) ($request['request_summary'] ?? '')) : '';
        $date = is_array($request) ? trim((string) ($request['preferred_date'] ?? '')) : '';
        $groupSize = is_array($request) ? max(0, (int) ($request['group_size'] ?? 0)) : 0;
        $discountLabel = self::resolveQuoteDiscountLabel($lineSummary);
        $sendLabel = self::humanSendStatusLabel((string) ($quote['send_status'] ?? 'not_ready'), (string) ($quote['status'] ?? 'draft'), $sendAllowed);
        $nextAction = self::resolveSummaryNextActionLabel($blockers, $partnerActions, $warnings, (string) ($quote['send_status'] ?? 'not_ready'), (string) ($quote['status'] ?? 'draft'), $sendAllowed);

        echo '<section class="postbox bsp-quote-admin__summary-bar" aria-label="' . esc_attr__('Quote samenvatting', 'sbdp') . '">';
        echo '<div class="bsp-quote-admin__summary-bar-section">';
        echo '<h3>' . esc_html__('Klant', 'sbdp') . '</h3>';
        echo '<div class="bsp-quote-admin__summary-bar-grid">';
        echo self::renderSummaryBarItem(__('Naam', 'sbdp'), (string) (($requester['name'] ?? '') ?: __('Onbekend', 'sbdp')));
        echo self::renderSummaryBarItem(__('E-mail', 'sbdp'), (string) (($requester['email'] ?? '') ?: __('Ontbreekt', 'sbdp')));
        echo self::renderSummaryBarItem(__('Telefoon', 'sbdp'), (string) (($requester['phone'] ?? '') ?: __('Ontbreekt', 'sbdp')));
        if (trim((string) ($requester['company'] ?? '')) !== '') {
            echo self::renderSummaryBarItem(__('Bedrijf', 'sbdp'), (string) $requester['company']);
        }
        if ($formattedAddress !== '') {
            echo self::renderSummaryBarItem(__('Adres', 'sbdp'), $formattedAddress);
        }
        echo '</div>';
        if ($summary !== '') {
            echo '<p class="bsp-quote-admin__summary-bar-note">' . esc_html($summary) . '</p>';
        }
        self::renderCustomerContextModal($quoteId, $request, $requester, $summary);
        echo '</div>';

        echo '<div class="bsp-quote-admin__summary-bar-section">';
        echo '<h3>' . esc_html__('Prijs & programma', 'sbdp') . '</h3>';
        echo '<div class="bsp-quote-admin__summary-bar-grid">';
        echo self::renderSummaryBarItem(__('Onderdelen', 'sbdp'), (string) ((int) ($stats['total_lines'] ?? 0)));
        echo self::renderSummaryBarItem(__('Gepland', 'sbdp'), sprintf('%d / %d', (int) ($stats['scheduled_lines'] ?? 0), (int) ($stats['total_lines'] ?? 0)));
        echo self::renderSummaryBarItem(__('Subtotaal', 'sbdp'), (string) ($lineSummary['subtotal_label'] ?? __('Nog niet bepaald', 'sbdp')));
        echo self::renderSummaryBarItem(__('Korting', 'sbdp'), $discountLabel);
        echo self::renderSummaryBarItem($amountLabel, (string) ($lineSummary['total_label'] ?? __('Nog niet bepaald', 'sbdp')), true);
        echo self::renderSummaryBarItem(__('Verzendstatus', 'sbdp'), $sendLabel);
        echo '</div>';
        echo '<div class="bsp-quote-admin__summary-status-row">';
        echo self::renderInlineBadge(self::humanPricingStatusLabel($pricingConfidence), self::confidenceBadgeClass($pricingConfidence));
        echo self::renderInlineBadge(self::humanAvailabilityStatusLabel($availabilityConfidence), self::confidenceBadgeClass($availabilityConfidence));
        echo '<a class="button button-small" href="' . esc_url(self::workspaceTabUrl($quoteId, 'build')) . '">' . esc_html__('Open programma', 'sbdp') . '</a>';
        echo '</div>';
        echo '</div>';

        echo '<div class="bsp-quote-admin__summary-bar-section bsp-quote-admin__summary-bar-section--actions">';
        echo '<h3>' . esc_html__('Nog nodig vóór verzenden', 'sbdp') . '</h3>';
        echo '<div class="bsp-quote-admin__summary-action-counts">';
        echo self::renderInlineBadge(sprintf(_n('%d blocker', '%d blockers', count($blockers), 'sbdp'), count($blockers)), $blockers !== array() ? 'is-error' : 'is-good');
        echo self::renderInlineBadge(sprintf(_n('%d partneractie', '%d partneracties', count($partnerActions), 'sbdp'), count($partnerActions)), $partnerActions !== array() ? 'is-warn' : 'is-neutral');
        echo self::renderInlineBadge(sprintf(_n('%d waarschuwing', '%d waarschuwingen', count($warnings), 'sbdp'), count($warnings)), $warnings !== array() ? 'is-warn' : 'is-neutral');
        echo '</div>';
        echo '<strong class="bsp-quote-admin__summary-next-action">' . esc_html($nextAction) . '</strong>';
        self::renderQuoteAlertsCard($alerts);
        echo '</div>';
        echo '</section>';
    }

    private static function renderSummaryBarItem(string $label, string $value, bool $isPrimary = false): string
    {
        return '<div class="' . esc_attr('bsp-quote-admin__summary-bar-item' . ($isPrimary ? ' is-primary' : '')) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong></div>';
    }

    /**
     * @param array<string, mixed>|null $request
     * @param array<string, mixed>      $requester
     */
    private static function renderCustomerContextModal(int $quoteId, ?array $request, array $requester, string $summary): void
    {
        $modalId = 'bsp-quote-customer-modal-' . $quoteId;
        $date = is_array($request) ? trim((string) ($request['preferred_date'] ?? '')) : '';
        $groupSize = is_array($request) ? max(0, (int) ($request['group_size'] ?? 0)) : 0;

        echo '<button type="button" class="button button-small bsp-quote-admin__modal-open" data-modal-target="' . esc_attr($modalId) . '">' . esc_html__('Bewerk klantgegevens', 'sbdp') . '</button>';
        echo '<div id="' . esc_attr($modalId) . '" class="bsp-quote-admin__modal" hidden role="dialog" aria-modal="true" aria-labelledby="' . esc_attr($modalId . '-title') . '">';
        echo '<div class="bsp-quote-admin__modal-panel">';
        echo '<div class="bsp-quote-admin__modal-header"><h3 id="' . esc_attr($modalId . '-title') . '">' . esc_html__('Klantgegevens', 'sbdp') . '</h3><button type="button" class="button-link bsp-quote-admin__modal-close" data-modal-close="' . esc_attr($modalId) . '">×</button></div>';
        echo '<p class="bsp-quote-admin__muted">' . esc_html__('Naam, contact en aanvraagtekst zijn hier bewust read-only: er is nog geen aparte veilige save-action voor deze velden in de workspace. Datum en groepsgrootte gebruiken de bestaande intake-update.', 'sbdp') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__modal-grid">';
        \wp_nonce_field('sbdp_quote_update_intake');
        echo '<input type="hidden" name="action" value="sbdp_quote_update_intake"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
        echo '<label>' . esc_html__('Naam', 'sbdp') . '<input type="text" value="' . esc_attr((string) ($requester['name'] ?? '')) . '" readonly></label>';
        echo '<label>' . esc_html__('Bedrijf', 'sbdp') . '<input type="text" value="' . esc_attr((string) ($requester['company'] ?? '')) . '" readonly></label>';
        echo '<label>' . esc_html__('E-mail', 'sbdp') . '<input type="email" value="' . esc_attr((string) ($requester['email'] ?? '')) . '" readonly></label>';
        echo '<label>' . esc_html__('Telefoon', 'sbdp') . '<input type="text" value="' . esc_attr((string) ($requester['phone'] ?? '')) . '" readonly></label>';
        echo '<label>' . esc_html__('Voorkeursdatum', 'sbdp') . '<input type="date" name="preferred_date" value="' . esc_attr($date) . '"></label>';
        echo '<label>' . esc_html__('Aantal personen', 'sbdp') . '<input type="number" min="0" name="group_size" value="' . esc_attr((string) $groupSize) . '"></label>';
        echo '<label class="bsp-quote-admin__modal-span">' . esc_html__('Korte aanvraagomschrijving', 'sbdp') . '<textarea rows="4" readonly>' . esc_textarea($summary) . '</textarea></label>';
        echo '<div class="bsp-quote-admin__modal-actions"><button type="button" class="button button-secondary" data-modal-close="' . esc_attr($modalId) . '">' . esc_html__('Annuleren', 'sbdp') . '</button><button type="submit" class="button button-primary">' . esc_html__('Datum/groep opslaan', 'sbdp') . '</button></div>';
        echo '</form></div></div>';
        echo '<script>(function(){if(window.bspQuoteCustomerModalBound){return;}window.bspQuoteCustomerModalBound=true;document.addEventListener("click",function(event){var open=event.target.closest("[data-modal-target]");if(open){var modal=document.getElementById(open.getAttribute("data-modal-target"));if(modal){modal.hidden=false;var field=modal.querySelector("input:not([readonly]),textarea:not([readonly]),button");if(field){field.focus();}}}var close=event.target.closest("[data-modal-close]");if(close){var target=document.getElementById(close.getAttribute("data-modal-close"));if(target){target.hidden=true;}}if(event.target.classList&&event.target.classList.contains("bsp-quote-admin__modal")){event.target.hidden=true;}});document.addEventListener("keydown",function(event){if(event.key==="Escape"){document.querySelectorAll(".bsp-quote-admin__modal:not([hidden])").forEach(function(modal){modal.hidden=true;});}});})();</script>';
    }

    /**
     * @param array<string, mixed> $lineSummary
     */
    private static function resolveQuoteDiscountLabel(array $lineSummary): string
    {
        $discountAmount = isset($lineSummary['discount_amount']) && is_numeric($lineSummary['discount_amount'])
            ? (float) $lineSummary['discount_amount']
            : 0.0;
        if ($discountAmount > 0.0) {
            $currency = trim((string) (($lineSummary['currency'] ?? '') ?: 'EUR'));
            $label = trim((string) ($lineSummary['discount_label'] ?? __('Korting', 'sbdp')));

            return sprintf('%s: %s', $label !== '' ? $label : __('Korting', 'sbdp'), self::formatMoney($discountAmount, $currency));
        }

        $candidates = array(
            $lineSummary['discount_total_label'] ?? null,
            $lineSummary['discount_amount_label'] ?? null,
        );
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return __('Geen korting', 'sbdp');
    }

    private static function humanSendStatusLabel(string $sendStatus, string $quoteStatus, bool $sendAllowed = false): string
    {
        return match (true) {
            $quoteStatus === 'accepted' => __('Geaccepteerd', 'sbdp'),
            in_array($quoteStatus, array('sent', 'sent_manual'), true) => __('Verzonden', 'sbdp'),
            in_array($quoteStatus, array('revision_requested', 'needs_revision'), true) => __('Revisie gevraagd', 'sbdp'),
            $sendStatus === 'ready_to_send' && $sendAllowed => __('Klaar om te versturen', 'sbdp'),
            $sendStatus === 'ready_to_send' => __('Nog niet verzendklaar', 'sbdp'),
            default => __('Niet verzendklaar', 'sbdp'),
        };
    }

    /**
     * @param array<int, mixed> $blockers
     * @param array<int, mixed> $partnerActions
     * @param array<int, mixed> $warnings
     */
    private static function resolveSummaryNextActionLabel(array $blockers, array $partnerActions, array $warnings, string $sendStatus, string $quoteStatus, bool $sendAllowed = false): string
    {
        if ($blockers !== array()) {
            return __('Los blocker op', 'sbdp');
        }
        if ($partnerActions !== array()) {
            return __('Vraag partnerbevestiging aan', 'sbdp');
        }
        if ($sendStatus === 'ready_to_send' && $sendAllowed) {
            return __('Verstuur voorstel', 'sbdp');
        }
        if ($sendStatus === 'ready_to_send') {
            return __('Controleer verzendstatus', 'sbdp');
        }
        if ($quoteStatus === 'accepted') {
            return __('Bereid handoff voor', 'sbdp');
        }
        if (in_array($quoteStatus, array('sent', 'sent_manual'), true)) {
            return __('Bekijk voorstel / wacht op klant', 'sbdp');
        }
        if ($warnings !== array()) {
            return __('Controleer details', 'sbdp');
        }

        return __('Werk programma bij', 'sbdp');
    }

    /**
     * @param array<string, array<int, array{title:string,message:string}>|int> $alerts
     */
    private static function renderQuoteAlertsCard(array $alerts): void
    {
        $blockers = is_array($alerts['blockers'] ?? null) ? $alerts['blockers'] : array();
        $warnings = is_array($alerts['warnings'] ?? null) ? $alerts['warnings'] : array();
        $infos = is_array($alerts['infos'] ?? null) ? $alerts['infos'] : array();
        $partnerActions = is_array($alerts['partner_actions'] ?? null) ? $alerts['partner_actions'] : array();
        echo '<div id="quote-blockers-card" class="bsp-quote-admin__action-center">';
        if ($blockers === array() && $partnerActions === array() && $warnings === array()) {
            echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--compact"><strong>' . esc_html__('Geen blokkerende punten zichtbaar', 'sbdp') . '</strong><p>' . esc_html__('Controleer voorstel en communicatie voordat je de volgende stap uitvoert.', 'sbdp') . '</p></div>';
        } else {
            if ($blockers !== array()) {
                self::renderQuoteAlertList(__('Moet vóór verzenden', 'sbdp'), $blockers, 'is-blocker', 2);
            }
            if ($partnerActions !== array()) {
                self::renderQuoteAlertList(__('Partneracties', 'sbdp'), $partnerActions, 'is-partner', 2);
            }
            if ($warnings !== array()) {
                echo '<details class="bsp-quote-admin__advanced-panel bsp-quote-admin__warning-details"><summary>' . esc_html__('Toon waarschuwingen', 'sbdp') . '</summary>';
                self::renderQuoteAlertList(__('Waarschuwingen', 'sbdp'), $warnings, 'is-warning');
                echo '</details>';
            }
        }
        if ($infos !== array()) {
            echo '<details class="bsp-quote-admin__advanced-panel"><summary>' . esc_html__('Info', 'sbdp') . '</summary>';
            self::renderQuoteAlertList(__('Info', 'sbdp'), $infos, 'is-info');
            echo '</details>';
        }
        echo '</div>';
    }

    /**
     * @param array<int, array{title:string,message:string}> $items
     */
    private static function renderQuoteAlertList(string $title, array $items, string $className, int $visibleLimit = 4): void
    {
        if ($items === array()) {
            return;
        }
        echo '<div class="bsp-quote-admin__alert-list ' . esc_attr($className) . '"><strong>' . esc_html($title) . '</strong><ul>';
        foreach (array_slice($items, 0, max(1, $visibleLimit)) as $item) {
            echo '<li><span>' . esc_html((string) ($item['title'] ?? '')) . '</span><small>' . esc_html((string) ($item['message'] ?? '')) . '</small>';
            $actionHref = trim((string) ($item['action_href'] ?? ''));
            if ($actionHref !== '') {
                $actionLabel = trim((string) ($item['action_label'] ?? __('Open actie', 'sbdp')));
                echo '<a class="button button-small" href="' . esc_url($actionHref) . '">' . esc_html($actionLabel !== '' ? $actionLabel : __('Open actie', 'sbdp')) . '</a>';
            }
            echo '</li>';
        }
        if (count($items) > $visibleLimit) {
            echo '<li class="bsp-quote-admin__alert-more"><small>' . esc_html(sprintf(__('%d extra punt(en) onder details', 'sbdp'), count($items) - $visibleLimit)) . '</small></li>';
        }
        echo '</ul></div>';
    }

    /**
     * @param array<string, mixed>             $sendReadiness
     * @param array<string, mixed>             $businessValidation
     * @param array<int, array<string, mixed>> $assumptions
     * @param array<int, array<string, mixed>> $followups
     * @param array<string, mixed>             $communicationState
     * @return array{blockers:array<int,array{title:string,message:string}>,warnings:array<int,array{title:string,message:string}>,infos:array<int,array{title:string,message:string}>}
     */
    private static function buildQuoteWorkspaceAlerts(
        int $quoteId,
        array $sendReadiness,
        array $businessValidation,
        array $assumptions,
        array $followups,
        array $communicationState,
        bool $quoteCommerciallyEditable
    ): array {
        $blockers = array();
        $warnings = array();
        $infos = array();

        foreach ((array) ($sendReadiness['blockers'] ?? array()) as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }
            $blockerCode = (string) ($blocker['code'] ?? 'send_blocker');
            $isReviewBlocker = $blockerCode === 'review_not_approved';
            $blockers[] = array(
                'title'        => self::humanBlockerTitle($blockerCode),
                'message'      => (string) ($blocker['message'] ?? ''),
                'action_href'  => self::workspaceTabUrl($quoteId, $isReviewBlocker ? 'dashboard' : 'build'),
                'action_label' => $isReviewBlocker
                    ? __('Naar Overzicht', 'sbdp')
                    : __('Controleer in Programma & prijs', 'sbdp'),
            );
        }

        foreach ((array) ($businessValidation['violations'] ?? array()) as $violation) {
            if (! is_array($violation)) {
                continue;
            }
            $blockers[] = array(
                'title' => __('Validatie', 'sbdp'),
                'message' => (string) ($violation['message'] ?? ''),
            );
        }

        foreach ($assumptions as $assumption) {
            if (! is_array($assumption) || (string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }
            $target = (! empty($assumption['blocks_send']) || ! empty($assumption['blocks_handoff']) || ! empty($assumption['blocks_review'])) ? 'blockers' : 'warnings';
            ${$target}[] = array(
                'title' => self::humanAssumptionTitle((string) ($assumption['assumption_type'] ?? 'assumption')),
                'message' => (string) ($assumption['message'] ?? ''),
            );
        }

        if (! empty($communicationState['latest_inbound_message_id']) && (string) ($communicationState['thread_label'] ?? '') === __('Waiting on us', 'sbdp')) {
            $warnings[] = array(
                'title' => __('Klantreactie open', 'sbdp'),
                'message' => __('Verwerk de laatste klantreactie voordat je de volgende commerciële stap uitvoert.', 'sbdp'),
            );
        }

        foreach ($followups as $followup) {
            if (! is_array($followup) || (string) ($followup['status'] ?? 'open') !== 'open') {
                continue;
            }
            $warnings[] = array(
                'title' => __('Follow-up open', 'sbdp'),
                'message' => (string) ($followup['title'] ?? __('Open taak', 'sbdp')),
            );
        }

        if (! $quoteCommerciallyEditable) {
            $infos[] = array(
                'title' => __('Commercieel bevroren', 'sbdp'),
                'message' => __('Deze quote-versie is verzonden of geaccepteerd. Wijzigingen vragen een nieuwe versie.', 'sbdp'),
            );
        }

        return array(
            'blockers' => $blockers,
            'warnings' => $warnings,
            'infos' => $infos,
        );
    }

    private static function humanBlockerTitle(string $code): string
    {
        return match ($code) {
            'customer_email_invalid', 'no_customer' => __('Klantgegevens', 'sbdp'),
            'no_quote_lines', 'commercial_lines_missing' => __('Programma ontbreekt', 'sbdp'),
            'send_assumption_open' => __('Open commerciële check', 'sbdp'),
            'woo_product_missing', 'woo_product_not_found', 'woo_product_unavailable', 'woo_product_not_purchasable' => __('Woo-product niet klaar', 'sbdp'),
            'currency_mixed', 'discount_invalid' => __('Prijscontrole', 'sbdp'),
            'review_not_approved' => __('Review niet afgerond', 'sbdp'),
            'send_status_not_ready' => __('Offerte niet verzendklaar', 'sbdp'),
            default => __('Blocker', 'sbdp'),
        };
    }

    private static function humanAssumptionTitle(string $type): string
    {
        return match ($type) {
            'uncertain_pricing' => __('Prijs nog controleren', 'sbdp'),
            'uncertain_availability' => __('Beschikbaarheid nog controleren', 'sbdp'),
            'missing_group_size' => __('Groepsgrootte ontbreekt', 'sbdp'),
            'missing_date' => __('Datum ontbreekt', 'sbdp'),
            default => __('Open punt', 'sbdp'),
        };
    }

    /**
     * @param array<string, mixed> $quote
     */
    private static function humanQuoteStatusLabel(array $quote): string
    {
        $quoteStatus = (string) ($quote['status'] ?? 'draft');
        $reviewStatus = (string) ($quote['review_status'] ?? 'not_started');
        $sendStatus = (string) ($quote['send_status'] ?? 'not_ready');

        return match (true) {
            $quoteStatus === 'accepted' => __('Geaccepteerd', 'sbdp'),
            $quoteStatus === 'revision_requested' => __('Revisie gevraagd', 'sbdp'),
            $quoteStatus === 'expired' => __('Verlopen', 'sbdp'),
            in_array($quoteStatus, array('cancelled', 'declined'), true) => __('Afgesloten', 'sbdp'),
            $quoteStatus === 'sent' || $sendStatus === 'sent_manual' => __('Verzonden', 'sbdp'),
            $sendStatus === 'ready_to_send' || $quoteStatus === 'ready_to_send' => __('Klaar om te verzenden', 'sbdp'),
            $reviewStatus === 'pending_review' => __('Wacht op interne review', 'sbdp'),
            $reviewStatus === 'approved' => __('Review goedgekeurd', 'sbdp'),
            default => __('Concept in bewerking', 'sbdp'),
        };
    }

    private static function humanPricingStatusLabel(string $confidence): string
    {
        return match ($confidence) {
            'execution_verified' => __('Prijs bevestigd', 'sbdp'),
            'snapshot' => __('Onder voorbehoud', 'sbdp'),
            'projected', 'directional' => __('Richtprijs', 'sbdp'),
            default => __('Prijs nog controleren', 'sbdp'),
        };
    }

    private static function humanAvailabilityStatusLabel(string $confidence): string
    {
        return match ($confidence) {
            'confirmed' => __('Beschikbaarheid bevestigd', 'sbdp'),
            'limited' => __('Beperkt beschikbaar', 'sbdp'),
            'unavailable' => __('Niet beschikbaar', 'sbdp'),
            default => __('Beschikbaarheid nog controleren', 'sbdp'),
        };
    }

    /**
     * @param array<string, mixed>      $quote
     * @param array<string, mixed>|null $currentVersion
     * @param array<string, mixed>      $currentPayload
     */
    private static function renderQuoteHandoffWorkspacePanel(int $quoteId, array $quote, ?array $currentVersion, array $currentPayload): void
    {
        $approvedVersionId = (int) ($quote['approved_version_id'] ?? 0);
        if (! in_array((string) ($quote['status'] ?? ''), array('accepted', 'confirmed'), true) || $approvedVersionId <= 0) {
            echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><h3>' . esc_html__('Handoff nog niet beschikbaar', 'sbdp') . '</h3></div><div class="bsp-quote-admin__panel-body">';
            echo '<p>' . esc_html__('Woo-overdracht wordt pas zichtbaar nadat de klant akkoord heeft gegeven en een geaccepteerde versie is vastgezet.', 'sbdp') . '</p>';
            echo '</div></section>';
            return;
        }

        echo '<section id="quote-handoff" class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('WooCommerce handoff', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Alleen de geaccepteerde versie wordt gebruikt. WooCommerce blijft cart, checkout, payment, tax en order truth.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__proposal-summary">';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Geaccepteerde versie', 'sbdp') . '</span><strong>' . esc_html((string) $approvedVersionId) . '</strong></div>';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Handoffstatus', 'sbdp') . '</span><strong>' . esc_html(self::operatorStatusLabel((string) ($quote['handoff_status'] ?? 'not_ready'))) . '</strong></div>';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Actieve versie', 'sbdp') . '</span><strong>' . esc_html($currentVersion !== null ? sprintf('#%s', (string) ($currentVersion['version_number'] ?? '1')) : __('Onbekend', 'sbdp')) . '</strong></div>';
        echo '</div>';
        echo '<div class="bsp-quote-admin__actions bsp-quote-admin__actions--stacked">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_build_handoff_package', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_build_handoff_package"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-secondary" type="submit">' . esc_html__('Bereid overdrachtspakket voor', 'sbdp') . '</button></form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_build_execution_payload', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_build_execution_payload"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-secondary" type="submit">' . esc_html__('Maak Woo-voorbereiding', 'sbdp') . '</button></form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_validate_execution_payload', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_validate_execution_payload"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-secondary" type="submit">' . esc_html__('Valideer voor Woo', 'sbdp') . '</button></form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_build_execution_launch', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_build_execution_launch"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-secondary" type="submit">' . esc_html__('Maak checkout-start klaar', 'sbdp') . '</button></form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_hydrate_woo_cart', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_hydrate_woo_cart"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-secondary" type="submit">' . esc_html__('Open in Woo winkelwagen', 'sbdp') . '</button></form>';
        $bookingMasterId = (int) ($quote['booking_master_id'] ?? 0);
        if ($bookingMasterId > 0) {
            echo '<div class="bsp-quote-admin__readiness-summary"><strong>' . esc_html__('Operationele boeking aangemaakt', 'sbdp') . '</strong><p>' . esc_html(sprintf(__('Booking master #%d', 'sbdp'), $bookingMasterId)) . '</p></div>';
        } elseif ((string) ($quote['status'] ?? '') === 'confirmed' && (string) ($quote['handoff_status'] ?? '') === 'woo_cart_hydrated') {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_create_booking_bridge', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_create_booking_bridge"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-primary" type="submit">' . esc_html__('Maak operationele boeking', 'sbdp') . '</button></form>';
        } elseif ((string) ($quote['status'] ?? '') === 'confirmed') {
            echo '<p class="bsp-quote-admin__muted">' . esc_html__('Operationele boeking kan pas na gecontroleerde Woo winkelwagenvoorbereiding.', 'sbdp') . '</p>';
        }
        echo '</div>';
        echo '<details class="bsp-quote-admin__advanced-panel"><summary>' . esc_html__('Technische handoffdetails', 'sbdp') . '</summary>';
        echo '<div class="bsp-quote-admin__badge-row">' . self::renderInlineBadge((string) ($quote['handoff_status'] ?? 'not_ready'), self::workflowBadgeClass((string) ($quote['handoff_status'] ?? 'not_ready'))) . '</div>';
        if ($currentPayload !== array()) {
            echo '<pre class="bsp-quote-admin__debug-json">' . esc_html((string) wp_json_encode($currentPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        } else {
            echo '<p class="bsp-quote-admin__muted">' . esc_html__('Nog geen handoff-payload opgeslagen.', 'sbdp') . '</p>';
        }
        echo '</details>';
        echo '</div></section>';
    }

    private static function workspaceTabUrl(int $quoteId, string $tab): string
    {
        return add_query_arg(array(
            'page' => 'sbdp_quotes',
            'quote_id' => $quoteId,
            'workspace_tab' => self::normalizeWorkspaceTab($tab, 'dashboard'),
        ), admin_url('admin.php'));
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed>|null $currentVersion
     * @param array<int, array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $assumptions
     * @return array<string, mixed>
     */
    private static function buildQuoteProposalReadiness(
        array $quote,
        ?array $currentVersion,
        array $lines,
        array $assumptions
    ): array {
        $items = array();
        $blockingAssumptions = 0;
        $sendBlockingAssumptions = 0;
        $mappedLines = 0;
        $projectedPricingLines = 0;
        $projectedAvailabilityLines = 0;
        $unscheduledLines = 0;

        foreach ($assumptions as $assumption) {
            if ((string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }

            if (! empty($assumption['blocks_review']) || ! empty($assumption['blocks_send'])) {
                $blockingAssumptions++;
            }
            if (! empty($assumption['blocks_send'])) {
                $sendBlockingAssumptions++;
            }
        }

        foreach ($lines as $line) {
            if ((int) ($line['product_id'] ?? 0) > 0) {
                $mappedLines++;
            }
            if (self::quoteLinePricingControlStatus($line) === 'needs_check') {
                $projectedPricingLines++;
            }
            if (in_array(self::quoteLineAvailabilityControlStatus($line), array('needs_check', 'unavailable'), true)) {
                $projectedAvailabilityLines++;
            }
            if (trim((string) ($line['service_date'] ?? '')) === '' || self::resolveProposalLineStartTime($line) === '') {
                $unscheduledLines++;
            }
        }

        if ((string) ($quote['review_status'] ?? 'not_started') !== 'approved') {
            $reviewAction = ((string) ($quote['review_status'] ?? 'not_started') === 'pending_review')
                ? array(
                    'type'  => 'review_approve',
                    'label' => __('Keur review goed', 'sbdp'),
                )
                : array(
                    'type'  => 'review_request',
                    'label' => __('Vraag review aan', 'sbdp'),
                );
            $items[] = array(
                'title'       => __('Interne review ontbreekt', 'sbdp'),
                'description' => __('Deze versie is nog niet vrijgegeven voor klantcommunicatie. Rond eerst de review-flow af.', 'sbdp'),
                'action'      => $reviewAction,
            );
        }

        if ($blockingAssumptions > 0) {
            $items[] = array(
                'title'       => sprintf(__('Open punten: %d', 'sbdp'), $blockingAssumptions),
                'description' => __('Er staan assumptions open die review of verzending blokkeren en eerst expliciet opgelost moeten worden.', 'sbdp'),
                'action'      => array(
                    'type'          => 'followup_create',
                    'label'         => __('Maak blocker follow-up', 'sbdp'),
                    'title'         => __('Werk open quote-punten weg', 'sbdp'),
                    'note'          => __('Los de open controles op die review of verzending blokkeren en werk daarna de verzendcheck opnieuw bij.', 'sbdp'),
                    'priority'      => 'high',
                    'followup_type' => 'manual_review',
                    'secondary_href'=> '#quote-assumptions',
                    'secondary_label' => __('Bekijk assumptions', 'sbdp'),
                ),
            );
        }

        if ($mappedLines !== count($lines)) {
            $items[] = array(
                'title'       => sprintf(__('Regels nog niet volledig gemapt: %d van %d', 'sbdp'), max(0, count($lines) - $mappedLines), count($lines)),
                'description' => __('Niet-technische operators missen hier nog productzekerheid. Koppel alle regels voordat je dit als strak klantvoorstel behandelt.', 'sbdp'),
                'action'      => array(
                    'type'            => 'followup_create',
                    'label'           => __('Maak mapping-taak', 'sbdp'),
                    'title'           => __('Werk productmapping van quote-regels bij', 'sbdp'),
                    'note'            => __('Controleer de quote-regels en koppel alle nog open regels aan concrete producten voordat de offerte wordt gedeeld.', 'sbdp'),
                    'priority'        => 'high',
                    'followup_type'   => 'manual_review',
                    'secondary_href'  => '#quote-commercial',
                    'secondary_label' => __('Werk quote-regels bij', 'sbdp'),
                ),
            );
        }

        if ($projectedPricingLines > 0) {
            $items[] = array(
                'title'       => sprintf(__('Prijs nog richtinggevend op %d regel(s)', 'sbdp'), $projectedPricingLines),
                'description' => __('Maak in communicatie duidelijk dat prijs nog een snapshot of richtinggevend voorstel is, niet definitieve Woo-truth.', 'sbdp'),
                'action'      => array(
                    'type'            => 'followup_create',
                    'label'           => __('Plan prijscheck', 'sbdp'),
                    'title'           => __('Bevestig of label prijsvoorbehoud in offerte', 'sbdp'),
                    'note'            => __('Controleer alle richtinggevende prijsregels en zorg dat klantcommunicatie expliciet benoemt dat prijs nog een snapshot of onder voorbehoud is.', 'sbdp'),
                    'priority'        => 'normal',
                    'followup_type'   => 'manual_review',
                    'secondary_href'  => '#quote-commercial',
                    'secondary_label' => __('Controleer commercieel overzicht', 'sbdp'),
                ),
            );
        }

        if ($projectedAvailabilityLines > 0) {
            $items[] = array(
                'title'       => sprintf(__('Beschikbaarheid nog onbevestigd op %d regel(s)', 'sbdp'), $projectedAvailabilityLines),
                'description' => __('Beschikbaarheid moet richtinggevend of onder voorbehoud blijven totdat execution-validatie of leverancier dit bevestigt.', 'sbdp'),
                'action'      => array(
                    'type'            => 'followup_create',
                    'label'           => __('Plan beschikbaarheidscheck', 'sbdp'),
                    'title'           => __('Bevestig of label beschikbaarheidsvoorbehoud', 'sbdp'),
                    'note'            => __('Controleer welke regels nog projected beschikbaarheid hebben en zorg dat klantcommunicatie dit als voorbehoud behandelt totdat bevestiging binnen is.', 'sbdp'),
                    'priority'        => 'normal',
                    'followup_type'   => 'manual_review',
                    'secondary_href'  => '#quote-commercial',
                    'secondary_label' => __('Controleer commercieel overzicht', 'sbdp'),
                ),
            );
        }

        if ($unscheduledLines > 0) {
            $items[] = array(
                'title'       => sprintf(__('Planning nog onvolledig op %d regel(s)', 'sbdp'), $unscheduledLines),
                'description' => __('Datum of starttijd ontbreekt nog op één of meer regels. Dat maakt een voorstel minder direct bruikbaar voor operators en klanten.', 'sbdp'),
                'action'      => array(
                    'type'            => 'followup_create',
                    'label'           => __('Vraag planning aan', 'sbdp'),
                    'title'           => __('Werk ontbrekende datum of tijd in quote bij', 'sbdp'),
                    'note'            => __('Vraag ontbrekende datum, starttijd of serviceplanning op en werk de quote-regels daarna bij.', 'sbdp'),
                    'priority'        => 'high',
                    'followup_type'   => 'manual_review',
                    'secondary_href'  => '#quote-commercial',
                    'secondary_label' => __('Bekijk quote-regels', 'sbdp'),
                ),
            );
        }

        $label = __('Interne werkversie', 'sbdp');
        $title = __('Nog niet klaar als klantvoorstel', 'sbdp');
        $description = __('Gebruik deze versie nog intern. Eerst review, open punten en commerciële onduidelijkheden wegwerken.', 'sbdp');
        $badgeClass = 'is-neutral';

        if ((string) ($quote['review_status'] ?? 'not_started') === 'approved' && $sendBlockingAssumptions === 0) {
            if (
                $blockingAssumptions === 0
                && $mappedLines === count($lines)
                && $projectedPricingLines === 0
                && $projectedAvailabilityLines === 0
                && $unscheduledLines === 0
                && count($lines) > 0
            ) {
                $label = __('Klantvoorstel met bevestigde kern', 'sbdp');
                $title = __('Commercieel sterk genoeg om te versturen', 'sbdp');
                $description = __('De regels zijn gemapt, prijs en beschikbaarheid zijn bevestigd op workspaceniveau en de review-flow is afgerond.', 'sbdp');
                $badgeClass = 'is-good';
            } else {
                $label = __('Richtinggevend klantvoorstel', 'sbdp');
                $title = __('Deelbaar, maar expliciet onder voorbehoud', 'sbdp');
                $description = __('Deze quote kan commercieel worden gedeeld, maar operators moeten projected prijs of beschikbaarheid nog duidelijk als voorbehoud benoemen.', 'sbdp');
                $badgeClass = 'is-warn';
            }
        }

        $ready = (string) ($quote['review_status'] ?? 'not_started') === 'approved'
            && $items === array()
            && count($lines) > 0;

        return array(
            'label'       => $label,
            'title'       => $title,
            'description' => $description,
            'badge_class' => $badgeClass,
            'items'       => $items,
            'ready'       => $ready,
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed>|null $currentVersion
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $assumptions
     * @param array<string, mixed> $sendReadiness
     * @return array<string, mixed>
     */
    public static function buildQuoteCommunicationState(array $quote, ?array $currentVersion, array $messages, array $assumptions, array $sendReadiness, array $sendDecision = array()): array
    {
        $latestProposal = null;
        $latestInbound = null;
        $latestOutbound = null;
        foreach ($messages as $message) {
            if ((string) ($message['direction'] ?? '') === 'outbound' && (string) ($message['status'] ?? '') === 'sent') {
                $latestOutbound = $message;
                if ((string) ($message['message_type'] ?? '') === 'proposal') {
                    $latestProposal = $message;
                }
            }
            if ((string) ($message['direction'] ?? '') === 'inbound') {
                $latestInbound = $message;
            }
        }
        $decisionBlockers = isset($sendDecision['blockers']) && is_array($sendDecision['blockers']) ? $sendDecision['blockers'] : array();
        $proposalSendBlockers = $decisionBlockers !== array()
            ? self::formatSendDecisionBlockers($decisionBlockers)
            : self::formatSendReadinessBlockers($sendReadiness);

        $proposalLabel = __('Nothing sent', 'sbdp');
        $proposalBadgeClass = 'is-neutral';
        $threadLabel = __('No thread yet', 'sbdp');
        $threadBadgeClass = 'is-neutral';
        $headline = __('Nog geen voorstelmail verstuurd.', 'sbdp');
        $description = __('Gebruik de voorstelmail als eerste expliciete klantcommunicatie. Review/send truth blijft daarnaast apart zichtbaar.', 'sbdp');
        $operatorActionTitle = __('Eerst voorstel versturen', 'sbdp');
        $operatorActionDescription = __('Maak of controleer eerst de voorstelmail. Zonder echte outbound mail is er nog geen klantthread om op te werken.', 'sbdp');
        $operatorActionAgeLabel = '';
        $operatorActionAgeBadgeClass = 'is-neutral';
        $proposalSendReady = ! empty($sendDecision['proposal_send_ready']);
        $proposalCanCompleteControl = ! empty($sendDecision['can_complete_control']);
        $proposalCanSend = ! empty($sendDecision['can_send']);
        $proposalSendBlockReason = __('Nog nodig: controleer open punten.', 'sbdp');
        $replyReady = false;
        $replyBlockReason = __('Verstuur eerst een voorstelmail. Zonder verzonden voorstel bestaat er nog geen reply-thread.', 'sbdp');
        $proposalAlreadySent = $latestProposal !== null;

        if ($latestProposal !== null) {
            $proposalLabel = __('Proposal sent', 'sbdp');
            $proposalBadgeClass = 'is-good';
            $headline = __('Voorstelmail is verzonden.', 'sbdp');
            $description = __('De quote heeft nu een expliciete uitgaande communicatiehistorie met versie-linking.', 'sbdp');
            $operatorActionTitle = __('Wacht op klantreactie', 'sbdp');
            $operatorActionDescription = __('Er staat geen nieuw inbound bericht open. Houd de thread in de gaten of plan een follow-up buiten de reply-flow.', 'sbdp');

            if ($latestInbound !== null && self::messageOccurredAfter($latestInbound, $latestProposal)) {
                $replyReady = true;
                $replyBlockReason = '';
                $threadLabel = __('Waiting on us', 'sbdp');
                $threadBadgeClass = 'is-warn';
                $headline = __('Klant heeft gereageerd.', 'sbdp');
                $description = __('Er staat een inbound reply klaar. Vat deze samen of maak direct een antwoorddraft voordat je reageert.', 'sbdp');
                $operatorActionTitle = __('Reageer op de laatste klantreply', 'sbdp');
                $operatorActionDescription = __('Lees de laatste inbound reply, vat die samen en maak daarna een antwoorddraft zodat de thread niet blijft hangen bij operations.');
                $operatorActionAgeLabel = self::formatMessageAgeLabel($latestInbound);
                $operatorActionAgeBadgeClass = self::messageAgeBadgeClass($latestInbound);
            } elseif ($latestInbound !== null) {
                $threadLabel = __('Waiting on customer', 'sbdp');
                $threadBadgeClass = 'is-neutral';
                $description = __('De laatste klantreply is al opgevolgd met uitgaande communicatie. De thread wacht nu op de klant.', 'sbdp');
                $operatorActionTitle = __('Laatste reply is al opgevolgd', 'sbdp');
                $operatorActionDescription = __('De meest recente klantreply heeft al een uitgaande vervolgstap. Verdere actie is nu alleen nodig als je buiten de thread wilt nabellen of najagen.');
                $replyBlockReason = __('De laatste inbound klantreply is al opgevolgd met uitgaande communicatie. Wacht op een nieuwe klantreactie voordat je opnieuw een reply opstelt.', 'sbdp');
            } else {
                $threadLabel = __('Waiting on customer', 'sbdp');
                $threadBadgeClass = 'is-neutral';
                $description = __('Voorstel is verstuurd en er is nog geen klantreply zichtbaar in deze thread.', 'sbdp');
                $replyBlockReason = __('Er is nog geen inbound klantreply binnengekomen na het verzonden voorstel.', 'sbdp');
            }
        }

        if ((string) ($quote['send_status'] ?? 'not_ready') === 'ready_to_send' && $latestProposal === null) {
            $description = __('Review is afgerond, maar er is nog geen echte voorstelmail in de quote-thread vastgelegd.', 'sbdp');
        }

        if ($proposalAlreadySent) {
            $proposalSendBlockReason = __('Deze offerte kan nog niet worden verstuurd.', 'sbdp');
        } elseif ($proposalCanSend) {
            $proposalSendBlockReason = '';
        } elseif ($proposalCanCompleteControl) {
            $proposalSendBlockReason = __('Controle afgerond is de volgende stap. Daarna wordt voorstel versturen beschikbaar.', 'sbdp');
        } elseif (($proposalSendBlockers !== array())) {
            $proposalSendBlockReason = (string) ($proposalSendBlockers[0]['message'] ?? __('Quote is nog niet verzendklaar.', 'sbdp'));
        } elseif ((string) ($quote['send_status'] ?? 'not_ready') !== 'ready_to_send') {
            $proposalSendBlockReason = __('Deze offerte kan nog niet worden verstuurd.', 'sbdp');
        }

        return array(
            'proposal_label'             => $proposalLabel,
            'proposal_badge_class'       => $proposalBadgeClass,
            'thread_label'               => $threadLabel,
            'thread_badge_class'         => $threadBadgeClass,
            'headline'                   => $headline,
            'description'                => $description,
            'latest_inbound_message_id'  => $latestInbound['id'] ?? null,
            'operator_action_title'      => $operatorActionTitle,
            'operator_action_description' => $operatorActionDescription,
            'operator_action_age_label'  => $operatorActionAgeLabel,
            'operator_action_age_badge_class' => $operatorActionAgeBadgeClass,
            'proposal_send_ready'        => $proposalSendReady,
            'proposal_can_complete_control' => $proposalCanCompleteControl,
            'proposal_can_send'          => $proposalCanSend,
            'proposal_next_action'       => (string) ($sendDecision['next_action'] ?? ''),
            'proposal_already_sent'      => $proposalAlreadySent,
            'proposal_send_block_reason' => $proposalSendBlockReason,
            'proposal_send_blockers'     => $proposalSendBlockers,
            'reply_ready'                => $replyReady,
            'reply_block_reason'         => $replyBlockReason,
            'latest_outbound_version_label' => $latestProposal !== null && ! empty($latestProposal['quote_version_id'])
                ? __('Versie gekoppeld aan voorstelmail', 'sbdp')
                : ($currentVersion !== null && $latestProposal !== null
                    ? sprintf(__('Versie #%d verzonden', 'sbdp'), (int) ($currentVersion['version_number'] ?? 1))
                    : ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function inspectQuoteSendReadiness(int $quoteId, array $quote, ?array $currentVersion, QuoteRepositoryInterface $repository): array
    {
        $inspection = (new QuoteSendReadinessValidator($repository))->inspect($quoteId);

        return array(
            'ready'      => ! empty($inspection['ready']),
            'blockers'   => (array) ($inspection['blockers'] ?? array()),
            'version_id' => (int) ($currentVersion['id'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $sendReadiness
     * @return array<int, array{code:string,label:string,message:string}>
     */
    private static function formatSendReadinessBlockers(array $sendReadiness): array
    {
        $formatted = array();
        foreach ((array) ($sendReadiness['blockers'] ?? array()) as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }

            $code = (string) ($blocker['code'] ?? 'unknown');
            $formatted[] = array(
                'code' => $code,
                'label' => self::sendReadinessBlockerLabel($code),
                'message' => (string) ($blocker['message'] ?? ''),
            );
        }

        return $formatted;
    }

    /**
     * @param array<int, array<string, mixed>> $blockers
     * @return array<int, array{code:string,label:string,message:string}>
     */
    private static function formatSendDecisionBlockers(array $blockers): array
    {
        $formatted = array();
        foreach ($blockers as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }

            $code = (string) ($blocker['code'] ?? 'unknown');
            $formatted[] = array(
                'code' => $code,
                'label' => self::sendReadinessBlockerLabel($code),
                'message' => (string) ($blocker['message'] ?? ''),
            );
        }

        return $formatted;
    }

    private static function sendReadinessBlockerLabel(string $code): string
    {
        return match ($code) {
            'quote_lines_missing' => __('Geen programmaregels', 'sbdp'),
            'customer_email_invalid' => __('Klant e-mail ontbreekt', 'sbdp'),
            'customer_email_missing' => __('Klantmail ontbreekt', 'sbdp'),
            'send_assumption_open' => __('Open send-blocker', 'sbdp'),
            'pricing_confidence_missing' => __('Prijs nog niet bevestigd', 'sbdp'),
            'availability_confidence_missing' => __('Beschikbaarheid nog niet bevestigd', 'sbdp'),
            'proposal_customer_text_missing' => __('Voorsteltekst ontbreekt', 'sbdp'),
            'proposal_text_missing' => __('Voorsteltekst ontbreekt', 'sbdp'),
            'supplier_confirmation_missing' => __('Supplier confirmation ontbreekt', 'sbdp'),
            'line_amount_invalid' => __('Bedrag ongeldig', 'sbdp'),
            'line_amount_negative' => __('Ongeldig bedrag', 'sbdp'),
            'line_currency_invalid' => __('Ongeldige valuta', 'sbdp'),
            'mixed_currency' => __('Gemengde valuta', 'sbdp'),
            'woo_product_missing' => __('Woo product ontbreekt', 'sbdp'),
            'woo_product_unavailable' => __('Woo product niet beschikbaar', 'sbdp'),
            'woo_product_not_purchasable' => __('Woo product niet koopbaar', 'sbdp'),
            'woo_product_status_invalid' => __('Woo productstatus ongeldig', 'sbdp'),
            'woo_product_tax_invalid' => __('Woo btw-configuratie ongeldig', 'sbdp'),
            'review_not_approved' => __('Review niet afgerond', 'sbdp'),
            'send_status_not_ready' => __('Offerte kan nog niet worden verstuurd', 'sbdp'),
            default => __('Blokkade', 'sbdp'),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $followups
     * @param array<int, array<string, mixed>> $assumptions
     * @return array<string, mixed>
     */
    public static function buildCommercialIntakeNoticeState(array $lines, array $followups, array $assumptions): array
    {
        if ($lines !== array()) {
            return array('active' => false);
        }

        $openFollowups = array_values(array_filter($followups, static function ($followup): bool {
            return is_array($followup) && (string) ($followup['status'] ?? 'open') === 'open';
        }));
        $openSendAssumptions = array_values(array_filter($assumptions, static function ($assumption): bool {
            return is_array($assumption)
                && (string) ($assumption['status'] ?? 'open') === 'open'
                && in_array((string) ($assumption['assumption_type'] ?? ''), array('uncertain_pricing', 'uncertain_availability'), true);
        }));

        $followupSummaries = array();
        foreach ($openFollowups as $followup) {
            $followupSummaries[] = array(
                'title' => (string) ($followup['title'] ?? ''),
                'meta' => sprintf(
                    '%s | %s | %s',
                    (string) ($followup['followup_type'] ?? 'manual_review'),
                    (string) ($followup['priority'] ?? 'normal'),
                    (string) ($followup['status'] ?? 'open')
                ),
            );
        }

        $assumptionMessages = array();
        foreach ($openSendAssumptions as $assumption) {
            $assumptionMessages[] = (string) ($assumption['message'] ?? '');
        }

        return array(
            'active' => true,
            'title' => __('Commerciële opvolging nodig', 'sbdp'),
            'message' => __('Deze offerte bevat nog geen commerciële regels. Voeg eerst programmaregels, producten, datum/tijd en prijs toe.', 'sbdp'),
            'technical_status' => __('Geen technische fout: deze quote is nog een intake-shell en wacht op commerciële uitwerking.', 'sbdp'),
            'send_blocker_status' => __('Versturen blijft geblokkeerd totdat echte commerciële brondata is vastgelegd en bevestigd.', 'sbdp'),
            'followups' => $followupSummaries,
            'assumption_messages' => array_values(array_filter($assumptionMessages)),
        );
    }

    /**
     * @param array<string, mixed> $notice
     */
    private static function renderCommercialIntakeNotice(array $notice): void
    {
        if (empty($notice['active'])) {
            return;
        }

        echo '<section class="notice notice-warning bsp-quote-admin__workspace-notice"><p><strong>' . esc_html((string) ($notice['title'] ?? __('Commerciële opvolging nodig', 'sbdp'))) . '</strong> ' . esc_html((string) ($notice['message'] ?? '')) . '</p>';
        echo '<p>' . esc_html((string) ($notice['technical_status'] ?? '')) . '</p>';
        echo '<p>' . esc_html((string) ($notice['send_blocker_status'] ?? '')) . '</p>';

        if (($notice['followups'] ?? array()) !== array()) {
            echo '<ul class="bsp-quote-admin__checklist bsp-quote-admin__checklist--compact">';
            foreach ((array) ($notice['followups'] ?? array()) as $followup) {
                if (! is_array($followup)) {
                    continue;
                }
                echo '<li><strong>' . esc_html((string) ($followup['title'] ?? __('Open follow-up', 'sbdp'))) . '</strong><span>' . esc_html((string) ($followup['meta'] ?? '')) . '</span></li>';
            }
            echo '</ul>';
        }

        if (($notice['assumption_messages'] ?? array()) !== array()) {
            echo '<ul class="bsp-quote-admin__checklist bsp-quote-admin__checklist--compact">';
            foreach ((array) ($notice['assumption_messages'] ?? array()) as $message) {
                echo '<li><strong>' . esc_html__('Verzendblokkade', 'sbdp') . '</strong><span>' . esc_html((string) $message) . '</span></li>';
            }
            echo '</ul>';
        }

        echo '</section>';
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function formatMessageAgeLabel(array $message): string
    {
        $ageHours = self::messageAgeInHours($message);
        if ($ageHours >= 72) {
            return __('Klantreply ouder dan 72h', 'sbdp');
        }
        if ($ageHours >= 24) {
            return __('Klantreply ouder dan 24h', 'sbdp');
        }

        return __('Nieuwe klantreply', 'sbdp');
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function messageAgeBadgeClass(array $message): string
    {
        $ageHours = self::messageAgeInHours($message);
        if ($ageHours >= 72) {
            return 'is-warn';
        }

        return $ageHours >= 24 ? 'is-neutral' : 'is-good';
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function messageAgeInHours(array $message): int
    {
        $date = (string) (($message['received_at'] ?? '') ?: ($message['sent_at'] ?? '') ?: ($message['created_at'] ?? ''));
        if ($date === '') {
            return 0;
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return 0;
        }

        return (int) floor(max(0, time() - $timestamp) / HOUR_IN_SECONDS);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, array<string, mixed>>
     */
    private static function resolveQuoteMessageDrafts(array $messages): array
    {
        $drafts = array(
            'proposal' => array(),
            'reply'    => array(),
        );

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            if ((string) ($message['status'] ?? '') !== 'draft') {
                continue;
            }

            $type = (string) ($message['message_type'] ?? '');
            if (($type === 'proposal' || $type === 'reply') && $drafts[$type] === array()) {
                $drafts[$type] = $message;
            }
        }

        return $drafts;
    }

    /**
     * @param array<string, mixed> $requester
     * @param array<string, mixed> $lineSummary
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $communicationState
     * @param array<string, array<string, mixed>> $messageDrafts
     * @param array<string, mixed>|null $openAiStatus
     */
    private static function renderQuoteCommunicationWorkflow(
        int $quoteId,
        array $requester,
        array $lineSummary,
        array $messages,
        array $communicationState,
        array $messageDrafts,
        ?array $openAiStatus,
        bool $quoteCommerciallyEditable
    ): void {
        $latestInbound = self::findLatestQuoteMessage($messages, 'inbound');
        $latestProposal = self::findLatestQuoteMessage($messages, 'outbound', 'proposal', 'sent');
        $latestOutbound = self::findLatestQuoteMessage($messages, 'outbound', null, 'sent');
        $hasInboundAction = $latestInbound !== null && ! empty($communicationState['latest_inbound_message_id']);
        $inboundSummary = $latestInbound !== null
            ? self::messagePreview($latestInbound)
            : __('Er is nog geen klantreactie ontvangen in deze quote-thread.', 'sbdp');
        $inboundAt = $latestInbound !== null
            ? (string) (($latestInbound['received_at'] ?? '') ?: ($latestInbound['created_at'] ?? ''))
            : __('Nog niet ontvangen', 'sbdp');

        echo '<section id="quote-communication" class="postbox bsp-quote-admin__panel bsp-quote-admin__communication-workflow">';
        echo '<div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('Klantreactie verwerken', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Werk vanuit de laatste klantreactie naar één duidelijk antwoord. Oude communicatie staat onderaan ingeklapt.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';

        echo '<section class="bsp-quote-admin__customer-reply-panel">';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Actuele status', 'sbdp') . '</span><h3>' . esc_html($hasInboundAction ? __('Klant heeft gereageerd', 'sbdp') : __('Geen open klantreactie', 'sbdp')) . '</h3></div>';
        echo '<div class="bsp-quote-admin__customer-reply-grid">';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Ontvangen', 'sbdp') . '</span><strong>' . esc_html($inboundAt) . '</strong></div>';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Volgende actie', 'sbdp') . '</span><strong>' . esc_html((string) ($communicationState['operator_action_title'] ?? __('Controleer communicatie', 'sbdp'))) . '</strong></div>';
        if (! empty($communicationState['operator_action_age_label'])) {
            echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Wachttijd', 'sbdp') . '</span>' . self::renderInlineBadge((string) $communicationState['operator_action_age_label'], (string) ($communicationState['operator_action_age_badge_class'] ?? 'is-neutral')) . '</div>';
        }
        echo '</div>';
        echo '<p class="bsp-quote-admin__customer-reply-excerpt">' . esc_html($inboundSummary) . '</p>';
        if (! empty($communicationState['operator_action_description'])) {
            echo '<p class="bsp-quote-admin__muted">' . esc_html((string) $communicationState['operator_action_description']) . '</p>';
        }
        echo '<div class="bsp-quote-admin__actions">';
        if ($hasInboundAction) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">';
            \wp_nonce_field('sbdp_quote_summarize_message');
            echo '<input type="hidden" name="action" value="sbdp_quote_summarize_message"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) $communicationState['latest_inbound_message_id']) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button button-secondary" type="submit">' . esc_html__('Vat reactie samen', 'sbdp') . '</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">';
            \wp_nonce_field('sbdp_quote_generate_response_draft');
            echo '<input type="hidden" name="action" value="sbdp_quote_generate_response_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) $communicationState['latest_inbound_message_id']) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button button-secondary" type="submit">' . esc_html__('Maak antwoorddraft', 'sbdp') . '</button></form>';
            echo '<a class="button" href="#quote-reply-composer">' . esc_html__('Beantwoord handmatig', 'sbdp') . '</a>';
        } else {
            echo '<a class="button button-secondary" href="#quote-proposal-status">' . esc_html__('Controleer voorstelstatus', 'sbdp') . '</a>';
        }
        echo '</div></section>';

        echo '<section id="quote-reply-composer" class="bsp-quote-admin__composer-card">';
        echo '<div class="bsp-quote-admin__section-heading"><h4>' . esc_html__('Antwoord aan klant', 'sbdp') . '</h4><span>' . esc_html__('Concept of handmatig antwoord', 'sbdp') . '</span></div>';
        if ($communicationState['reply_ready'] === true) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form bsp-quote-admin__stack-form--composer">';
            \wp_nonce_field('sbdp_quote_send_message');
            echo '<input type="hidden" name="action" value="sbdp_quote_send_message"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_type" value="reply"><input type="hidden" name="draft_id" value="' . esc_attr((string) ($messageDrafts['reply']['id'] ?? '')) . '"><input type="hidden" name="reply_to_message_id" value="' . esc_attr((string) ($messageDrafts['reply']['in_reply_to_message_id'] ?? ($communicationState['latest_inbound_message_id'] ?? ''))) . '"><input type="hidden" name="workspace_tab" value="communication">';
            echo '<div class="bsp-quote-admin__composer-grid"><label>' . esc_html__('Aan', 'sbdp') . '<input class="regular-text" type="text" name="to_name" value="' . esc_attr((string) ($messageDrafts['reply']['to_name'] ?? ($requester['name'] ?? ''))) . '"></label>';
            echo '<label>' . esc_html__('E-mail', 'sbdp') . '<input class="regular-text" type="email" name="to_email" value="' . esc_attr((string) ($messageDrafts['reply']['to_email'] ?? ($requester['email'] ?? ''))) . '"></label></div>';
            echo '<label>' . esc_html__('Onderwerp', 'sbdp') . '<input class="regular-text" type="text" name="subject" value="' . esc_attr((string) ($messageDrafts['reply']['subject'] ?? '')) . '"></label>';
            echo '<label>' . esc_html__('Bericht', 'sbdp') . '<textarea class="large-text" rows="8" name="body">' . esc_html((string) ($messageDrafts['reply']['body'] ?? '')) . '</textarea></label>';
            echo '<button class="button button-primary" type="submit">' . esc_html__('Verstuur antwoord', 'sbdp') . '</button></form>';
        } else {
            echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--compact"><strong>' . esc_html__('Antwoord nog niet beschikbaar', 'sbdp') . '</strong><p>' . esc_html((string) ($communicationState['reply_block_reason'] ?? __('Er is nog geen open klantreactie om te beantwoorden.', 'sbdp'))) . '</p></div>';
        }
        echo '</section>';

        echo '<section id="quote-proposal-status" class="bsp-quote-admin__proposal-status-card">';
        echo '<div class="bsp-quote-admin__section-heading"><h4>' . esc_html__('Voorstelstatus', 'sbdp') . '</h4>' . self::renderInlineBadge((string) ($communicationState['proposal_label'] ?? __('Nothing sent', 'sbdp')), (string) ($communicationState['proposal_badge_class'] ?? 'is-neutral')) . '</div>';
        echo '<div class="bsp-quote-admin__proposal-status-grid">';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Voorstel verzonden', 'sbdp') . '</span><strong>' . esc_html($latestProposal !== null ? __('Ja', 'sbdp') : __('Nee', 'sbdp')) . '</strong></div>';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Datum/tijd', 'sbdp') . '</span><strong>' . esc_html($latestProposal !== null ? (string) (($latestProposal['sent_at'] ?? '') ?: ($latestProposal['created_at'] ?? '')) : __('Nog niet verzonden', 'sbdp')) . '</strong></div>';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Ontvanger', 'sbdp') . '</span><strong>' . esc_html($latestProposal !== null ? (string) ($latestProposal['to_email'] ?? '') : (string) ($requester['email'] ?? '')) . '</strong></div>';
        echo '<div><span class="bsp-quote-admin__field-label">' . esc_html__('Voorstelbedrag', 'sbdp') . '</span><strong>' . esc_html((string) ($lineSummary['total_label'] ?? __('Nog niet bepaald', 'sbdp'))) . '</strong></div>';
        echo '</div>';
        if ($latestProposal !== null) {
            echo '<p><a class="button button-secondary" href="#quote-communication-history">' . esc_html__('Bekijk voorstel', 'sbdp') . '</a></p>';
        } else {
            if ($communicationState['proposal_send_ready'] !== true) {
                echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--compact"><strong>' . esc_html__('Voorstel verzenden nog niet beschikbaar', 'sbdp') . '</strong><p>' . esc_html((string) ($communicationState['proposal_send_block_reason'] ?? __('Vraag eerst review aan en keur de quote goed.', 'sbdp'))) . '</p></div>';
            }
            echo '<div class="bsp-quote-admin__actions">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">';
            \wp_nonce_field('sbdp_quote_generate_proposal_draft');
            echo '<input type="hidden" name="action" value="sbdp_quote_generate_proposal_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button button-secondary" type="submit">' . esc_html__('Maak voorstelmail', 'sbdp') . '</button></form>';
            echo '</div>';
            $proposalSendFormOpen = $communicationState['proposal_send_ready'] === true ? ' open' : '';
            echo '<details class="bsp-quote-admin__advanced-panel"' . $proposalSendFormOpen . '><summary>' . esc_html__('Voorstelmail handmatig bekijken', 'sbdp') . '</summary>';
            echo '<form id="quote-proposal-send-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__stack-form">';
            \wp_nonce_field('sbdp_quote_send_message');
            echo '<input type="hidden" name="action" value="sbdp_quote_send_message"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_type" value="proposal"><input type="hidden" name="draft_id" value="' . esc_attr((string) ($messageDrafts['proposal']['id'] ?? '')) . '"><input type="hidden" name="workspace_tab" value="communication">';
            echo '<label>' . esc_html__('Aan', 'sbdp') . '<input class="regular-text" type="text" name="to_name" value="' . esc_attr((string) ($requester['name'] ?? '')) . '"></label>';
            echo '<label>' . esc_html__('E-mail', 'sbdp') . '<input class="regular-text" type="email" name="to_email" value="' . esc_attr((string) ($requester['email'] ?? '')) . '"></label>';
            echo '<label>' . esc_html__('Onderwerp', 'sbdp') . '<input class="regular-text" type="text" name="subject" value="' . esc_attr((string) ($messageDrafts['proposal']['subject'] ?? '')) . '"></label>';
            echo '<label>' . esc_html__('Bericht', 'sbdp') . '<textarea class="large-text" rows="8" name="body">' . esc_html((string) ($messageDrafts['proposal']['body'] ?? '')) . '</textarea></label>';
            if ($communicationState['proposal_send_ready'] === true) {
                echo '<button class="button button-primary" type="submit">' . esc_html__('Verstuur voorstelmail', 'sbdp') . '</button>';
            }
            echo '</form></details>';
        }
        echo '</section>';

        echo '<section id="quote-communication-history" class="bsp-quote-admin__timeline-card"><div class="bsp-quote-admin__section-heading"><h4>' . esc_html__('Communicatiehistorie', 'sbdp') . '</h4><span>' . esc_html__('Ingeklapt per bericht', 'sbdp') . '</span></div>';
        if ($messages === array()) {
            echo '<p class="bsp-quote-admin__muted">' . esc_html__('Nog geen quote-berichten opgeslagen.', 'sbdp') . '</p>';
        } else {
            echo '<div class="bsp-quote-admin__compact-timeline">';
            foreach (array_reverse($messages) as $index => $message) {
                echo self::renderQuoteMessageTimelineRow($quoteId, $message, $index === 0);
            }
            echo '</div>';
        }
        echo '</section>';

        echo '<details class="bsp-quote-admin__advanced-panel"><summary>' . esc_html__('Geavanceerd', 'sbdp') . '</summary>';
        echo '<div class="bsp-quote-admin__badge-row">';
        echo self::renderInlineBadge((string) ($communicationState['proposal_label'] ?? __('Nothing sent', 'sbdp')), (string) ($communicationState['proposal_badge_class'] ?? 'is-neutral'));
        echo self::renderInlineBadge((string) ($communicationState['thread_label'] ?? __('No thread yet', 'sbdp')), (string) ($communicationState['thread_badge_class'] ?? 'is-neutral'));
        if (! empty($communicationState['latest_outbound_version_label'])) {
            echo self::renderInlineBadge((string) $communicationState['latest_outbound_version_label'], 'is-neutral');
        }
        echo '</div>';
        if ($openAiStatus !== null) {
            echo self::renderOpenAiStatusNotice($openAiStatus);
        }
        if (($communicationState['proposal_send_blockers'] ?? array()) !== array()) {
            echo '<ul class="bsp-quote-admin__checklist bsp-quote-admin__checklist--compact">';
            foreach ((array) ($communicationState['proposal_send_blockers'] ?? array()) as $blocker) {
                if (is_array($blocker)) {
                    echo '<li><strong>' . esc_html((string) ($blocker['label'] ?? __('Blokkade', 'sbdp'))) . '</strong><span>' . esc_html((string) ($blocker['message'] ?? '')) . '</span></li>';
                }
            }
            echo '</ul>';
        }
        if ($latestOutbound !== null) {
            echo '<p class="bsp-quote-admin__muted">' . esc_html(sprintf(__('Laatste uitgaand bericht: %s', 'sbdp'), (string) (($latestOutbound['sent_at'] ?? '') ?: ($latestOutbound['created_at'] ?? '')))) . '</p>';
        }
        echo '</details>';
        echo '</div></section>';
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function renderQuoteMessageCard(int $quoteId, array $message): string
    {
        $direction = (string) ($message['direction'] ?? 'outbound');
        $directionClass = $direction === 'inbound' ? 'is-inbound' : 'is-outbound';
        $subject = trim((string) ($message['subject'] ?? ''));
        $body = trim((string) ($message['body'] ?? ''));
        $summary = trim((string) ($message['body_summary'] ?? ''));
        $metaBits = array_filter(array(
            (string) ($message['message_type'] ?? ''),
            (string) ($message['status'] ?? ''),
            ! empty($message['quote_version_id']) ? sprintf(__('versie %d', 'sbdp'), (int) $message['quote_version_id']) : '',
            $direction === 'inbound'
                ? trim((string) ($message['from_email'] ?? ''))
                : trim((string) ($message['to_email'] ?? '')),
        ));
        $occurredAt = (string) (($direction === 'inbound' ? ($message['received_at'] ?? '') : ($message['sent_at'] ?? '')) ?: ($message['created_at'] ?? ''));

        $html = '<article class="bsp-quote-admin__thread-item ' . esc_attr($directionClass) . '">';
        $html .= '<div class="bsp-quote-admin__thread-meta">';
        $html .= self::renderInlineBadge($direction === 'inbound' ? __('Inbound', 'sbdp') : __('Outbound', 'sbdp'), $direction === 'inbound' ? 'is-warn' : 'is-good');
        $html .= '<strong>' . esc_html($subject !== '' ? $subject : __('Zonder onderwerp', 'sbdp')) . '</strong>';
        if ($occurredAt !== '') {
            $html .= '<span class="bsp-quote-admin__muted">' . esc_html($occurredAt) . '</span>';
        }
        $html .= '</div>';
        if ($metaBits !== array()) {
            $html .= '<div class="bsp-quote-admin__cell-stack"><span class="bsp-quote-admin__muted">' . esc_html(implode(' | ', $metaBits)) . '</span></div>';
        }
        if ($summary !== '') {
            $html .= '<p class="bsp-quote-admin__message-summary"><strong>' . esc_html__('Samenvatting', 'sbdp') . ':</strong> ' . esc_html($summary) . '</p>';
        }
        if ($body !== '') {
            $html .= '<div class="bsp-quote-admin__message-body">' . nl2br(esc_html($body)) . '</div>';
        }
        if ($direction === 'inbound') {
            $html .= '<div class="bsp-quote-admin__actions bsp-quote-admin__thread-actions">';
            $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">' . wp_nonce_field('sbdp_quote_summarize_message', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_summarize_message"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) ($message['id'] ?? 0)) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button button-secondary" type="submit">' . esc_html__('Samenvatten', 'sbdp') . '</button></form>';
            $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">' . wp_nonce_field('sbdp_quote_generate_response_draft', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_generate_response_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) ($message['id'] ?? 0)) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button" type="submit">' . esc_html__('Antwoorddraft', 'sbdp') . '</button></form>';
            $html .= '</div>';
        }
        $html .= '</article>';

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, mixed>|null
     */
    private static function findLatestQuoteMessage(array $messages, string $direction, ?string $type = null, ?string $status = null): ?array
    {
        for ($index = count($messages) - 1; $index >= 0; --$index) {
            $message = $messages[$index];
            if ((string) ($message['direction'] ?? '') !== $direction) {
                continue;
            }
            if ($type !== null && (string) ($message['message_type'] ?? '') !== $type) {
                continue;
            }
            if ($status !== null && (string) ($message['status'] ?? '') !== $status) {
                continue;
            }

            return $message;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function messagePreview(array $message): string
    {
        $summary = trim((string) ($message['body_summary'] ?? ''));
        if ($summary !== '') {
            return $summary;
        }

        $body = trim((string) ($message['body'] ?? ''));
        if ($body === '') {
            return __('Geen tekst beschikbaar.', 'sbdp');
        }

        return function_exists('wp_html_excerpt')
            ? (string) wp_html_excerpt(wp_strip_all_tags($body), 240, '...')
            : substr($body, 0, 240);
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function renderQuoteMessageTimelineRow(int $quoteId, array $message, bool $open = false): string
    {
        $direction = (string) ($message['direction'] ?? 'outbound');
        $type = (string) ($message['message_type'] ?? '');
        $status = (string) ($message['status'] ?? '');
        $subject = trim((string) ($message['subject'] ?? ''));
        $body = trim((string) ($message['body'] ?? ''));
        $occurredAt = (string) (($direction === 'inbound' ? ($message['received_at'] ?? '') : ($message['sent_at'] ?? '')) ?: ($message['created_at'] ?? ''));
        $recipient = $direction === 'inbound'
            ? trim((string) ($message['from_email'] ?? ''))
            : trim((string) ($message['to_email'] ?? ''));
        if ($direction === 'inbound') {
            $label = __('Klantreactie ontvangen', 'sbdp');
            $badgeClass = 'is-warn';
        } elseif ($type === 'proposal') {
            $label = $status === 'sent' ? __('Voorstel verzonden', 'sbdp') : __('Voorstelconcept', 'sbdp');
            $badgeClass = $status === 'sent' ? 'is-good' : 'is-neutral';
        } else {
            $label = $status === 'sent' ? __('Antwoord verzonden', 'sbdp') : __('Antwoordconcept', 'sbdp');
            $badgeClass = $status === 'sent' ? 'is-good' : 'is-neutral';
        }

        $html = '<details class="bsp-quote-admin__timeline-row"' . ($open ? ' open' : '') . '>';
        $html .= '<summary><span>' . self::renderInlineBadge($label, $badgeClass) . '</span><strong>' . esc_html($subject !== '' ? $subject : __('Zonder onderwerp', 'sbdp')) . '</strong><small>' . esc_html(trim($occurredAt . ' ' . $recipient)) . '</small></summary>';
        $html .= '<p class="bsp-quote-admin__message-summary">' . esc_html(self::messagePreview($message)) . '</p>';
        if ($body !== '') {
            $html .= '<div class="bsp-quote-admin__message-body">' . nl2br(esc_html($body)) . '</div>';
        }
        if ($direction === 'inbound') {
            $html .= '<div class="bsp-quote-admin__actions bsp-quote-admin__thread-actions">';
            $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">' . wp_nonce_field('sbdp_quote_summarize_message', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_summarize_message"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) ($message['id'] ?? 0)) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button button-secondary" type="submit">' . esc_html__('Samenvatten', 'sbdp') . '</button></form>';
            $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__inline-form">' . wp_nonce_field('sbdp_quote_generate_response_draft', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_generate_response_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) ($message['id'] ?? 0)) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button" type="submit">' . esc_html__('Antwoorddraft', 'sbdp') . '</button></form>';
            $html .= '</div>';
        }
        $html .= '</details>';

        return $html;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private static function messageOccurredAfter(array $left, array $right): bool
    {
        $leftTime = (string) (($left['received_at'] ?? '') ?: ($left['sent_at'] ?? '') ?: ($left['created_at'] ?? ''));
        $rightTime = (string) (($right['received_at'] ?? '') ?: ($right['sent_at'] ?? '') ?: ($right['created_at'] ?? ''));

        return $leftTime !== '' && $rightTime !== '' && strcmp($leftTime, $rightTime) > 0;
    }

    /**
     * @param array<int, array<string, mixed>> $failures
     * @return array<int, array<string, mixed>>
     */
    private static function sortInboxFailures(array $failures): array
    {
        usort($failures, static function (array $left, array $right): int {
            $leftOpen = (string) ($left['status'] ?? 'open') === 'open' ? 0 : 1;
            $rightOpen = (string) ($right['status'] ?? 'open') === 'open' ? 0 : 1;

            if ($leftOpen !== $rightOpen) {
                return $leftOpen <=> $rightOpen;
            }

            $leftTime = self::failureTimestamp($left);
            $rightTime = self::failureTimestamp($right);

            if ($leftOpen === 0) {
                return $leftTime <=> $rightTime;
            }

            return $rightTime <=> $leftTime;
        });

        return $failures;
    }

    /**
     * @param array<string, mixed> $failure
     * @param array<string, mixed>|null $suggestedQuote
     * @return array<string, string>
     */
    private static function buildInboxFailureTriage(array $failure, ?array $suggestedQuote): array
    {
        $status = (string) ($failure['status'] ?? 'open');
        $reason = (string) ($failure['failure_reason'] ?? 'unmatched_quote');
        $ageHours = self::failureAgeInHours($failure);

        $ageLabel = __('Nieuw', 'sbdp');
        $ageBadgeClass = 'is-neutral';
        if ($status === 'open' && $ageHours >= 72) {
            $ageLabel = __('Ouder dan 72h', 'sbdp');
            $ageBadgeClass = 'is-warn';
        } elseif ($status === 'open' && $ageHours >= 24) {
            $ageLabel = __('Ouder dan 24h', 'sbdp');
            $ageBadgeClass = 'is-neutral';
        } elseif ($status !== 'open') {
            $ageLabel = __('Afgerond', 'sbdp');
            $ageBadgeClass = 'is-good';
        }

        if ($status !== 'open') {
            return array(
                'age_label' => $ageLabel,
                'age_badge_class' => $ageBadgeClass,
                'action_title' => __('Afgehandeld', 'sbdp'),
                'action_description' => __('Deze inbound reply is al gekoppeld. Gebruik de gekoppelde quote voor verdere opvolging.', 'sbdp'),
            );
        }

        if ($suggestedQuote !== null) {
            return array(
                'age_label' => $ageLabel,
                'age_badge_class' => $ageBadgeClass,
                'action_title' => __('Nu doen', 'sbdp'),
                'action_description' => __('Open eerst de voorgestelde quote, controleer afzender en context, en koppel daarna deze reply aan de thread.', 'sbdp'),
            );
        }

        if ($reason === 'invalid_sender') {
            return array(
                'age_label' => $ageLabel,
                'age_badge_class' => $ageBadgeClass,
                'action_title' => __('Nu doen', 'sbdp'),
                'action_description' => __('Verifieer eerst het afzenderadres. Koppel deze reply pas nadat duidelijk is welke klant of quote erbij hoort.', 'sbdp'),
            );
        }

        if ($reason === 'missing_content') {
            return array(
                'age_label' => $ageLabel,
                'age_badge_class' => $ageBadgeClass,
                'action_title' => __('Nu doen', 'sbdp'),
                'action_description' => __('Controleer headers en mailboxcontext. Zonder onderwerp of body is veilig koppelen lastiger en moet je eerst meer context verzamelen.', 'sbdp'),
            );
        }

        return array(
            'age_label' => $ageLabel,
            'age_badge_class' => $ageBadgeClass,
            'action_title' => __('Nu doen', 'sbdp'),
            'action_description' => __('Zoek de bedoelde quote op afzender, onderwerp en eventdatum. Koppel daarna de reply zodat de thread weer in de workspace zichtbaar is.', 'sbdp'),
        );
    }

    /**
     * @param array<string, mixed> $failure
     */
    private static function failureAgeInHours(array $failure): int
    {
        $timestamp = self::failureTimestamp($failure);
        if ($timestamp <= 0) {
            return 0;
        }

        return (int) floor(max(0, time() - $timestamp) / HOUR_IN_SECONDS);
    }

    /**
     * @param array<string, mixed> $failure
     */
    private static function failureTimestamp(array $failure): int
    {
        $date = trim((string) ($failure['created_at'] ?? ''));
        if ($date === '') {
            return 0;
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? 0 : $timestamp;
    }

    /**
     * @param array<string, mixed> $nextAction
     */
    private static function renderQuotePrimaryAction(int $quoteId, array $nextAction): string
    {
        $cta = (string) ($nextAction['cta'] ?? 'review_request');
        $labelOverride = trim((string) ($nextAction['label'] ?? ''));
        switch ($cta) {
            case 'none':
                return '';
            case 'tab_link':
                $tab = (string) ($nextAction['tab'] ?? 'dashboard');
                $anchor = trim((string) ($nextAction['anchor'] ?? ''));
                $href = self::workspaceTabUrl($quoteId, $tab) . ($anchor !== '' ? '#' . rawurlencode($anchor) : '');
                return '<a class="button button-primary" href="' . esc_url($href) . '">' . esc_html($labelOverride !== '' ? $labelOverride : __('Open', 'sbdp')) . '</a>';
            case 'build':
                return '<a class="button button-primary" href="' . esc_url(self::workspaceTabUrl($quoteId, 'build')) . '">' . esc_html($labelOverride !== '' ? $labelOverride : __('Naar Programma', 'sbdp')) . '</a>';
            case 'review_approve':
                return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_review_approve', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_review_approve"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-primary" type="submit">' . esc_html($labelOverride !== '' ? $labelOverride : __('Keur review goed', 'sbdp')) . '</button></form>';
            case 'send_mark_sent':
                return '<a class="button button-primary" href="' . esc_url(self::workspaceTabUrl($quoteId, 'communication') . '#quote-proposal-send-form') . '">' . esc_html($labelOverride !== '' ? $labelOverride : __('Voorstel versturen', 'sbdp')) . '</a>';
            case 'proposal_readiness':
                return '<a class="button button-primary" href="#quote-blockers-card">' . esc_html($labelOverride !== '' ? $labelOverride : __('Doorloop verzendcheck', 'sbdp')) . '</a>';
            case 'proposal_draft':
                return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_generate_proposal_draft', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_generate_proposal_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button button-primary" type="submit">' . esc_html($labelOverride !== '' ? $labelOverride : __('Maak voorstelmail', 'sbdp')) . '</button></form>';
            case 'reply_draft':
                return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_generate_response_draft', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_generate_response_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><input type="hidden" name="message_id" value="' . esc_attr((string) ((int) ($nextAction['message_id'] ?? 0))) . '"><input type="hidden" name="workspace_tab" value="communication"><button class="button button-primary" type="submit">' . esc_html($labelOverride !== '' ? $labelOverride : __('Verwerk klantreactie', 'sbdp')) . '</button></form>';
            case 'assumptions':
                return '<a class="button button-primary" href="#quote-blockers-card">' . esc_html($labelOverride !== '' ? $labelOverride : __('Bekijk blockers', 'sbdp')) . '</a>';
            case 'followups':
                return '<a class="button button-primary" href="' . esc_url(self::workspaceTabUrl($quoteId, 'dashboard')) . '#quote-blockers-card">' . esc_html($labelOverride !== '' ? $labelOverride : __('Open follow-ups', 'sbdp')) . '</a>';
            case 'handoff':
                return '<a class="button button-primary" href="' . esc_url(self::workspaceTabUrl($quoteId, 'handoff')) . '">' . esc_html($labelOverride !== '' ? $labelOverride : __('Open handoff', 'sbdp')) . '</a>';
            case 'review_request':
            default:
                return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('sbdp_quote_review_request', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sbdp_quote_review_request"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '"><button class="button button-primary" type="submit">' . esc_html($labelOverride !== '' ? $labelOverride : __('Vraag review aan', 'sbdp')) . '</button></form>';
        }
    }

    private static function resolveWorkspaceTab(): string
    {
        $tab = isset($_GET['workspace_tab']) ? (string) $_GET['workspace_tab'] : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return self::normalizeWorkspaceTab($tab, 'dashboard');
    }

    private static function normalizeWorkspaceTab(string $tab, string $default = 'dashboard'): string
    {
        $tab = sanitize_key($tab);
        $default = sanitize_key($default);
        $allowedTabs = array('dashboard', 'build', 'proposal', 'communication', 'handoff', 'history');

        if (! in_array($default, $allowedTabs, true)) {
            $default = 'dashboard';
        }

        return in_array($tab, $allowedTabs, true) ? $tab : $default;
    }

    private static function formatMoney(float $amount, string $currency): string
    {
        $formatted = \function_exists('number_format_i18n')
            ? (string) \number_format_i18n($amount, 2)
            : number_format($amount, 2, ',', '.');

        return trim($currency . ' ' . $formatted);
    }

    private static function renderCreateRequestForm(bool $withWrapper = true): void
    {
        if ($withWrapper) {
            echo '<div class="postbox bsp-quote-admin__panel">';
        }

        echo '<div class="bsp-quote-admin__request-form"><h2>' . esc_html__('Nieuwe aanvraag invoeren', 'sbdp') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        \wp_nonce_field('sbdp_quote_request_create');
        echo '<input type="hidden" name="action" value="sbdp_quote_request_create"><p><label>' . esc_html__('Naam', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_name"></label></p><p><label>' . esc_html__('E-mail', 'sbdp') . '<br><input class="regular-text" type="email" name="requester_email"></label></p><p><label>' . esc_html__('Bedrijf', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_company"></label></p><p><label>' . esc_html__('Telefoon', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_phone"></label></p><p><label>' . esc_html__('Straat en huisnummer', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_address_1"></label></p><p><label>' . esc_html__('Adresregel extra', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_address_2"></label></p><p><label>' . esc_html__('Postcode', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_postcode"></label></p><p><label>' . esc_html__('Plaats', 'sbdp') . '<br><input class="regular-text" type="text" name="requester_city"></label></p><p><label>' . esc_html__('Landcode', 'sbdp') . '<br><input class="small-text" type="text" name="requester_country" value="NL"></label></p><p><label>' . esc_html__('Groepsgrootte', 'sbdp') . '<br><input class="small-text" type="number" name="group_size" min="0" value="0"></label></p><p><label>' . esc_html__('Voorkeursdatum', 'sbdp') . '<br><input class="regular-text" type="date" name="preferred_date"></label></p><p><label>' . esc_html__('Klantnotitie', 'sbdp') . '<br><textarea class="large-text" name="requester_message" rows="3"></textarea></label></p><p><label>' . esc_html__('Samenvatting', 'sbdp') . '<br><textarea class="large-text" name="request_summary" rows="4" required></textarea></label></p><p><button class="button button-primary" type="submit">' . esc_html__('Quote request opslaan', 'sbdp') . '</button></p></form></div>';

        if ($withWrapper) {
            echo '</div>';
        }
    }

    private static function renderNotices(): void
    {
        $notices = array(
            'quote_request_created' => __('Quote request aangemaakt.', 'sbdp'),
            'quote_converted'       => __('Quote aangemaakt vanuit request.', 'sbdp'),
            'review_requested'      => __('Quote ter review aangeboden.', 'sbdp'),
            'review_approved'       => __('Quote review goedgekeurd.', 'sbdp'),
            'review_returned'       => __('Quote teruggezet naar draft.', 'sbdp'),
            'quote_marked_sent'     => __('Quote als handmatig verzonden gemarkeerd.', 'sbdp'),
            'quote_send_reopened'   => __('Quote teruggezet naar ready_to_send.', 'sbdp'),
            'quote_operations_saved' => __('Operations draft opgeslagen in de actieve quote-versie.', 'sbdp'),
            'quote_line_control_updated' => __('Controlestatus voor programmaregel opgeslagen.', 'sbdp'),
            'quote_line_supplier_updated' => __('Partnerstatus voor programmaregel opgeslagen.', 'sbdp'),
            'quote_line_supplier_request_draft' => __('Partnerverzoek als draft opgeslagen.', 'sbdp'),
            'quote_line_supplier_request_sent' => __('Partnerverzoek verstuurd en vastgelegd in de thread.', 'sbdp'),
            'quote_line_partner_token_revoked' => __('Partnerlink ingetrokken.', 'sbdp'),
            'quote_message_draft_generated' => __('Berichtdraft opgeslagen in de quote-thread.', 'sbdp'),
            'quote_message_summarized' => __('Inbound klantreply samengevat.', 'sbdp'),
            'quote_message_sent'    => __('Quote-mail verstuurd en vastgelegd in de thread.', 'sbdp'),
            'quote_message_send_failed' => __('Quote-mail kon niet worden verstuurd. Controleer de foutmelding en staging-mailconfiguratie.', 'sbdp'),
            'quote_inbound_logged'  => __('Inbound klantreply opgeslagen in de quote-thread.', 'sbdp'),
            'quote_intake_updated'  => __('Intakecontext bijgewerkt en intake-blockers opnieuw beoordeeld.', 'sbdp'),
            'quote_contact_updated' => __('Klantgegevens bijgewerkt.', 'sbdp'),
            'quote_assumption_resolved' => __('Commerciële assumption opgelost en vrijgegeven voor de volgende workflowstap.', 'sbdp'),
            'quote_inbound_resolved' => __('Inbound inbox-item gekoppeld en verwerkt.', 'sbdp'),
            'followup_created'      => __('Follow-up aangemaakt.', 'sbdp'),
            'followup_completed'    => __('Follow-up afgerond.', 'sbdp'),
            'handoff_ready'         => __('Handoff-voorbereiding opgeslagen.', 'sbdp'),
            'resnapshot_prepared'   => __('Execution-voorbereiding gecontroleerd.', 'sbdp'),
            'handoff_package_ready' => __('Handoffpakket voorbereid.', 'sbdp'),
            'execution_payload_ready' => __('Executionvoorbereiding bijgewerkt.', 'sbdp'),
            'execution_validated'   => __('Executionvoorbereiding runtime gecontroleerd.', 'sbdp'),
            'execution_launch_ready' => __('Woo-startvoorbereiding opgebouwd.', 'sbdp'),
            'woo_cart_hydrated'     => __('Woo winkelwagen voorbereid vanuit geaccepteerde offerte.', 'sbdp'),
            'operations_ready'      => __('Operationele boeking aangemaakt.', 'sbdp'),
            'quote_ai_mail_saved'   => __('Quote AI & Mail instellingen opgeslagen.', 'sbdp'),
        );

        foreach ($notices as $key => $message) {
            if (! isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                continue;
            }

            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }

        if (isset($_GET['quote_error']) && is_string($_GET['quote_error'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-error"><p>' . esc_html(rawurldecode((string) $_GET['quote_error'])) . '</p></div>';
        }

        if (isset($_GET['cart_url']) && is_string($_GET['cart_url'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $cartUrl = rawurldecode((string) $_GET['cart_url']);
            echo '<div class="notice notice-info"><p><a href="' . esc_url($cartUrl) . '">' . esc_html__('Open Woo winkelwagen', 'sbdp') . '</a></p></div>';
        }
    }

    private static function renderStatCard(string $label, string $value): string
    {
        return '<div class="bsp-quote-admin__stat"><span class="bsp-quote-admin__stat-value">' . esc_html($value) . '</span><span class="bsp-quote-admin__stat-label">' . esc_html($label) . '</span></div>';
    }

    private static function renderInlineBadge(string $label, string $className): string
    {
        return '<span class="bsp-quote-admin__badge ' . esc_attr($className) . '">' . esc_html($label) . '</span>';
    }

    /**
     * @param array<string, mixed> $sendReadiness
     * @param array<string, mixed> $communicationState
     * @return array<int, array{label:string,ok:bool,detail:string}>
     */
    private static function buildWorkspaceSendCheckItems(
        int $totalLines,
        int $scheduledLines,
        string $pricingConfidence,
        string $availabilityConfidence,
        bool $proposalReady,
        array $sendReadiness,
        array $communicationState
    ): array {
        $pricingConfirmed = $pricingConfidence === 'execution_verified';
        $availabilityConfirmed = $availabilityConfidence === 'confirmed';
        $sendAllowed = ! empty($communicationState['proposal_can_send']);
        $canCompleteControl = ! empty($communicationState['proposal_can_complete_control']);
        $openBlockers = count((array) ($communicationState['proposal_send_blockers'] ?? ($sendReadiness['blockers'] ?? array())));

        return array(
            array(
                'label' => __('Programma compleet', 'sbdp'),
                'ok' => $totalLines > 0 && $scheduledLines >= $totalLines,
                'detail' => sprintf('%d/%d gepland', $scheduledLines, $totalLines),
            ),
            array(
                'label' => $pricingConfirmed ? __('Prijs bevestigd', 'sbdp') : self::operatorPricingCheckLabel($pricingConfidence),
                'ok' => $pricingConfirmed,
                'detail' => $pricingConfirmed ? __('Definitief bevestigd', 'sbdp') : __('Nog niet definitief', 'sbdp'),
            ),
            array(
                'label' => $availabilityConfirmed ? __('Beschikbaarheid bevestigd', 'sbdp') : __('Beschikbaarheid: nog bevestigen', 'sbdp'),
                'ok' => $availabilityConfirmed,
                'detail' => $availabilityConfirmed ? __('Definitief bevestigd', 'sbdp') : __('Nog niet definitief', 'sbdp'),
            ),
            array(
                'label' => __('Klantreactie verwerkt', 'sbdp'),
                'ok' => empty($communicationState['latest_inbound_message_id']) || (string) ($communicationState['thread_label'] ?? '') !== __('Waiting on us', 'sbdp'),
                'detail' => (string) ($communicationState['thread_label'] ?? __('Geen open reactie', 'sbdp')),
            ),
            array(
                'label' => __('Voorsteltekst klaar', 'sbdp'),
                'ok' => $proposalReady,
                'detail' => $proposalReady ? __('Klaar', 'sbdp') : __('Nog controleren', 'sbdp'),
            ),
            array(
                'label' => $sendAllowed ? __('Voorstel versturen mogelijk', 'sbdp') : ($canCompleteControl ? __('Controle afronden', 'sbdp') : __('Nog nodig', 'sbdp')),
                'ok' => $sendAllowed || $canCompleteControl,
                'detail' => $sendAllowed ? __('Voorstel klaar voor verzending', 'sbdp') : ($canCompleteControl ? __('Alle verplichte controles zijn groen', 'sbdp') : sprintf(_n('%d punt open', '%d punten open', $openBlockers, 'sbdp'), $openBlockers)),
            ),
        );
    }

    private static function operatorPricingCheckLabel(string $confidence): string
    {
        return match ($confidence) {
            'snapshot', 'projected', 'directional' => __('Prijs: richtprijs', 'sbdp'),
            default => __('Prijs: nog bevestigen', 'sbdp'),
        };
    }

    private static function operatorStatusLabel(string $status): string
    {
        return match ($status) {
            'draft' => __('Concept', 'sbdp'),
            'not_started' => __('Niet gestart', 'sbdp'),
            'not_ready' => __('Niet verzendklaar', 'sbdp'),
            'blocked', 'action_required' => __('Actie nodig', 'sbdp'),
            'waiting_vendor' => __('Wacht op leverancier', 'sbdp'),
            'waiting_customer' => __('Wacht op klant', 'sbdp'),
            'ready', 'ready_to_send', 'approved' => __('Verzendklaar', 'sbdp'),
            'sent', 'sent_manual' => __('Verzonden', 'sbdp'),
            'accepted' => __('Geaccepteerd', 'sbdp'),
            'completed', 'closed', 'declined', 'cancelled' => __('Afgerond', 'sbdp'),
            'pending_review' => __('Wacht op review', 'sbdp'),
            'ready_for_resnapshot', 'execution_payload_ready', 'execution_validated', 'execution_launch_ready', 'woo_cart_hydrated' => __('Execution voorbereid', 'sbdp'),
            'operations_ready' => __('Operations gereed', 'sbdp'),
            default => __('Nog niet bevestigd', 'sbdp'),
        };
    }

    private static function renderInlineLink(string $url, string $label): string
    {
        return '<a class="bsp-quote-admin__link" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readOpenAiStatus(): ?array
    {
        $status = \get_option('bsp_quotes_openai_last_status', array());

        return is_array($status) && $status !== array() ? $status : null;
    }

    /**
     * @param array<string, mixed> $status
     */
    private static function renderOpenAiStatusNotice(array $status): string
    {
        $state = (string) ($status['state'] ?? 'unknown');
        $httpStatus = isset($status['http_status']) && $status['http_status'] !== null
            ? (string) (int) $status['http_status']
            : '';
        $message = trim((string) ($status['message'] ?? ''));
        $model = trim((string) ($status['model'] ?? ''));
        $updatedAt = trim((string) ($status['updated_at'] ?? ''));

        $className = match ($state) {
            'ok' => 'bsp-quote-admin__readiness-summary',
            'disabled' => 'bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--compact',
            default => 'bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--action',
        };

        $title = match ($state) {
            'ok' => __('OpenAI actief', 'sbdp'),
            'disabled' => __('OpenAI uitgeschakeld', 'sbdp'),
            default => __('OpenAI fallback actief', 'sbdp'),
        };

        $meta = array_filter(array(
            $model !== '' ? sprintf(__('model %s', 'sbdp'), $model) : '',
            $httpStatus !== '' ? sprintf(__('HTTP %s', 'sbdp'), $httpStatus) : '',
            $updatedAt !== '' ? $updatedAt : '',
        ));

        $html = '<div class="' . esc_attr($className) . '">';
        $html .= '<strong>' . esc_html($title) . '</strong>';
        if ($message !== '') {
            $html .= '<p>' . esc_html($message) . '</p>';
        }
        if ($meta !== array()) {
            $html .= '<p class="bsp-quote-admin__muted">' . esc_html(implode(' | ', $meta)) . '</p>';
        }
        $html .= '</div>';

        return $html;
    }

    private static function resolveIntakeAssumptions(
        QuoteRepository $repository,
        int $quoteId,
        int $groupSize,
        string $preferredDate,
        ?int $actorId
    ): void {
        $now = \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');

        foreach ($repository->listQuoteAssumptions($quoteId) as $assumption) {
            if (! is_array($assumption) || (string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }

            $type = (string) ($assumption['assumption_type'] ?? '');
            $shouldResolve = ($type === 'missing_group_size' && $groupSize > 0)
                || ($type === 'missing_date' && $preferredDate !== '');

            if (! $shouldResolve) {
                continue;
            }

            $repository->updateQuoteAssumption((int) ($assumption['id'] ?? 0), array(
                'status' => 'resolved',
                'resolution_note' => 'Automatisch opgelost na intake-update in quote-workspace.',
                'blocks_review' => 0,
                'blocks_send' => 0,
                'blocks_handoff' => 0,
                'resolved_at' => $now,
                'resolved_by' => $actorId,
            ));
        }
    }

    private static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'reviewed', 'converted_to_quote', 'mapped', 'sent' => 'is-good',
            'draft', 'new' => 'is-neutral',
            default => 'is-warn',
        };
    }

    private static function workflowBadgeClass(string $status): string
    {
        return match ($status) {
            'approved', 'ready_to_send', 'ready_for_resnapshot', 'execution_payload_ready', 'execution_validated', 'execution_launch_ready', 'woo_cart_hydrated', 'operations_ready' => 'is-good',
            'not_started', 'not_ready' => 'is-neutral',
            default => 'is-warn',
        };
    }

    private static function confidenceBadgeClass(string $status): string
    {
        return match ($status) {
            'execution_verified', 'confirmed' => 'is-good',
            'snapshot', 'projected' => 'is-warn',
            default => 'is-neutral',
        };
    }

    // ─── Quote Control Dashboard (single-screen, scopes 1-8) ──────────────────

    /**
     * Main entry point: renders the full single-screen control dashboard.
     *
     * @param array<int, array<string, mixed>> $lines
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $events
     * @param array<string, mixed>             $messageDrafts
     * @param array<string, mixed>             $lineSummary
     * @param array<string, mixed>             $proposalProgram
     * @param array<string, mixed>             $sendReadiness
     * @param array<string, mixed>             $workspaceAlerts
     * @param array<string, mixed>             $communicationState
     * @param array<string, mixed>             $proposalReadiness
     * @param array<string, mixed>             $adminStatusSummary
     */
    private static function renderQuoteControlDashboard(
        int $quoteId,
        array $quote,
        ?array $request,
        array $requester,
        string $formattedAddress,
        ?array $currentVersion,
        array $lines,
        array $lineSummary,
        array $proposalProgram,
        array $sendReadiness,
        array $workspaceAlerts,
        array $communicationState,
        array $messages,
        array $events,
        array $messageDrafts,
        array $proposalReadiness,
        string $pricingConfidence,
        string $availabilityConfidence,
        bool $sendAllowed,
        bool $quoteCommerciallyEditable,
        array $adminStatusSummary = array()
    ): void {
        $matrix = self::buildQcdApprovalMatrix(
            $requester,
            $lines,
            $lineSummary,
            $availabilityConfidence,
            $proposalReadiness,
            $messageDrafts,
            $communicationState,
            $currentVersion,
            $messages
        );
        $qcdSendAllowed = $sendAllowed && self::qcdApprovalMatrixAllowsSend($matrix);

        echo '<div class="bsp-qcd">';
        self::renderQcdDecisionBar($quoteId, $quote, $request, $requester, $currentVersion, $lineSummary, $matrix, $qcdSendAllowed, $sendReadiness, $pricingConfidence, $availabilityConfidence, $adminStatusSummary);
        self::renderQcdApprovalMatrix($matrix, $quoteId);
        echo '<div class="bsp-qcd__layout">';
        echo '<main class="bsp-qcd__main">';
        self::renderQcdCustomerCard($quoteId, $request, $requester, $formattedAddress, $quoteCommerciallyEditable);
        echo '<div class="bsp-qcd__program-body" id="qcd-program-editor">';
        QuoteBuilderRenderer::renderQuoteBuildWorkspace($quoteId, $quote, $request, $currentVersion, $lines);
        echo '</div>';
        self::renderQcdProposalPreview($quoteId, $quote, $currentVersion, $proposalReadiness, $messageDrafts, $lineSummary, $proposalProgram, $qcdSendAllowed, $sendReadiness, $communicationState, $messages);
        echo '</main>';
        echo '<aside class="bsp-qcd__side">';
        self::renderQcdReadinessCard($sendReadiness, $matrix);
        self::renderQcdMessagesCard($quoteId, $communicationState, $messages);
        self::renderQcdAuditCard($quoteId, $events, $currentVersion);
        echo '</aside>';
        echo '</div>';
        if ($quoteCommerciallyEditable) {
            $ctxAddress  = isset($requester['address']) && is_array($requester['address']) ? $requester['address'] : array();
            $ctxDate     = is_array($request) ? trim((string) ($request['preferred_date'] ?? '')) : '';
            $ctxGroup    = is_array($request) ? max(0, (int) ($request['group_size'] ?? 0)) : 0;
            $ctxSummary  = is_array($request) ? trim((string) ($request['request_summary'] ?? '')) : '';
            self::renderQcdCustomerModal($quoteId, $request, $requester, $ctxAddress, $ctxDate, $ctxGroup, $ctxSummary);
        }
        echo '</div>';
    }

    /**
     * Computes the approval-matrix data for the 6 control points.
     *
     * @param array<string, mixed>             $requester
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed>             $lineSummary
     * @param array<string, mixed>             $proposalReadiness
     * @param array<string, mixed>             $messageDrafts
     * @param array<string, mixed>             $communicationState
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, array<string, string>>
     */
    private static function buildQcdApprovalMatrix(
        array $requester,
        array $lines,
        array $lineSummary,
        string $availabilityConfidence,
        array $proposalReadiness,
        array $messageDrafts,
        array $communicationState,
        ?array $currentVersion,
        array $messages
    ): array {
        $hasLines  = $lines !== array();
        $totalLabel = (string) ($lineSummary['total_label'] ?? '');

        // ── 1. Klantgegevens ──────────────────────────────────────────────────
        $hasName  = trim((string) ($requester['name']  ?? '')) !== '';
        $hasEmail = trim((string) ($requester['email'] ?? '')) !== '';
        if ($hasName && $hasEmail) {
            $customerPoint = array('icon' => 'ok',    'status' => __('Compleet', 'sbdp'),                          'action' => '',                       'tab' => '');
        } elseif ($hasEmail) {
            $customerPoint = array('icon' => 'warn',  'status' => __('Naam ontbreekt', 'sbdp'),                    'action' => __('Vul naam in', 'sbdp'), 'tab' => 'dashboard');
        } elseif ($hasName) {
            $customerPoint = array('icon' => 'error', 'status' => __('E-mail ontbreekt — blokkeert verzenden', 'sbdp'), 'action' => __('E-mail invullen', 'sbdp'), 'tab' => 'dashboard');
        } else {
            $customerPoint = array('icon' => 'error', 'status' => __('Naam en e-mail ontbreken', 'sbdp'),          'action' => __('Vul klantgegevens in', 'sbdp'), 'tab' => 'dashboard');
        }

        // ── 2. Programma + technische prijsvalidatie ──────────────────────────
        $totalAmount = $lineSummary['total_amount'] ?? null;
        $hasValidTotal = is_numeric($totalAmount) && (float) $totalAmount > 0.0;
        $allLinesPriced = (int) ($lineSummary['total_lines'] ?? 0) > 0
            && (int) ($lineSummary['priced_lines'] ?? 0) === (int) ($lineSummary['total_lines'] ?? 0);
        if (! $hasLines) {
            $programPoint = array('icon' => 'error', 'status' => __('Geen programmaregels', 'sbdp'), 'action' => __('Voeg programma toe', 'sbdp'), 'tab' => 'build');
        } elseif (! $allLinesPriced || ! $hasValidTotal) {
            $programPoint = array('icon' => 'error', 'status' => __('Programmatotaal ontbreekt of is ongeldig', 'sbdp'), 'action' => __('Werk prijsregels bij', 'sbdp'), 'tab' => 'build');
        } else {
            $cnt       = count($lines);
            $scheduled = 0;
            foreach ($lines as $l) {
                if (trim((string) ($l['start_time'] ?? '')) !== '' || trim((string) ($l['service_date'] ?? '')) !== '') {
                    $scheduled++;
                }
            }
            if ($scheduled === $cnt) {
                $programPoint = array('icon' => 'ok',   'status' => sprintf(_n('%d onderdeel, totaal geldig', '%d onderdelen, totaal geldig', $cnt, 'sbdp'), $cnt), 'action' => '', 'tab' => 'build');
            } else {
                $programPoint = array('icon' => 'warn', 'status' => sprintf(__('%d van %d onderdelen gepland', 'sbdp'), $scheduled, $cnt), 'action' => __('Vul ontbrekende tijden in', 'sbdp'), 'tab' => 'build');
            }
        }

        // ── 3. Beschikbaarheid ────────────────────────────────────────────────
        $availPoint = self::qcdAvailabilityMatrixPoint($lines);

        // ── 4. Voorsteltekst ──────────────────────────────────────────────────
        $draftSubject  = trim((string) ($messageDrafts['proposal']['subject'] ?? ''));
        $draftBody     = trim((string) ($messageDrafts['proposal']['body'] ?? ''));
        $proposalTitle = $currentVersion !== null ? trim((string) ($currentVersion['proposal_title'] ?? '')) : '';
        $proposalSummary = $currentVersion !== null ? trim((string) ($currentVersion['proposal_summary'] ?? '')) : '';
        $unsafeProposalTerms = self::qcdProposalTextSanitizerTerms(array($draftSubject, $draftBody, $proposalTitle, $proposalSummary));
        if ($unsafeProposalTerms !== array()) {
            $proposalPoint = array('icon' => 'error', 'status' => __('Interne systeemtekst gevonden', 'sbdp'), 'action' => __('Pas voorsteltekst aan', 'sbdp'), 'tab' => 'proposal');
        } elseif ($draftSubject !== '' && $draftBody !== '') {
            $proposalPoint = array('icon' => 'ok',   'status' => __('Voorstel gereed', 'sbdp'),                 'action' => __('Bekijk voorstel', 'sbdp'),    'tab' => 'proposal');
        } elseif ($proposalTitle !== '' || $proposalSummary !== '') {
            $proposalPoint = array('icon' => 'warn', 'status' => __('Voorsteltekst aanwezig, controleren', 'sbdp'), 'action' => __('Controleer voorstel', 'sbdp'), 'tab' => 'proposal');
        } else {
            $proposalPoint = array('icon' => 'warn', 'status' => __('Nog geen voorsteltekst', 'sbdp'),          'action' => __('Maak voorstel', 'sbdp'),       'tab' => 'proposal');
        }

        // ── 5. Communicatie ───────────────────────────────────────────────────
        $waitingOnUs = ((string) ($communicationState['thread_label'] ?? '')) === __('Waiting on us', 'sbdp');
        if ($messages === array()) {
            $commPoint = array('icon' => 'na',   'status' => __('Nog geen berichten', 'sbdp'),       'action' => '',                             'tab' => 'communication');
        } elseif ($waitingOnUs) {
            $commPoint = array('icon' => 'warn', 'status' => __('Klant heeft gereageerd', 'sbdp'),   'action' => __('Antwoord voorbereiden', 'sbdp'), 'tab' => 'communication');
        } else {
            $commPoint = array('icon' => 'ok',   'status' => __('Geen open klantreactie', 'sbdp'),   'action' => __('Bekijk berichten', 'sbdp'),  'tab' => 'communication');
        }

        return array(
            'customer'     => array_merge(array('label' => __('Klantgegevens', 'sbdp')),   $customerPoint),
            'program'      => array_merge(array('label' => __('Programma', 'sbdp')),       $programPoint),
            'availability' => array_merge(array('label' => __('Beschikbaarheid', 'sbdp')), $availPoint),
            'proposal'     => array_merge(array('label' => __('Voorsteltekst', 'sbdp')),   $proposalPoint),
            'communication'=> array_merge(array('label' => __('Communicatie', 'sbdp')),    $commPoint),
            'audit'        => array('label' => __('Audit/historie', 'sbdp'), 'icon' => 'na', 'status' => __('Compact zichtbaar', 'sbdp'), 'action' => '', 'tab' => 'history'),
        );
    }

    /**
     * @param array<int, string> $texts
     * @return array<int, string>
     */
    private static function qcdProposalTextSanitizerTerms(array $texts): array
    {
        if (! class_exists(\BSP\Quotes\Service\QuoteCommunicationService::class)) {
            return array();
        }

        return \BSP\Quotes\Service\QuoteCommunicationService::detectInternalCustomerTextTerms(implode("\n", $texts));
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private static function qcdHasUnavailableLine(array $lines): bool
    {
        foreach ($lines as $line) {
            if ((string) ($line['line_status'] ?? '') === 'unavailable') {
                return true;
            }
            $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
            if ((string) ($snapshot['control_status'] ?? '') === 'unavailable') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, string>
     */
    private static function qcdAvailabilityMatrixPoint(array $lines): array
    {
        if ($lines === array()) {
            return array('icon' => 'na', 'status' => __('Nog geen programma', 'sbdp'), 'action' => '', 'tab' => 'build');
        }

        $needsCheck = false;
        foreach ($lines as $line) {
            $status = self::qcdLineAvailabilityControlStatus($line);
            if ($status === 'unavailable') {
                return array('icon' => 'error', 'status' => __('Niet beschikbaar', 'sbdp'), 'action' => __('Los beschikbaarheid op', 'sbdp'), 'tab' => 'build');
            }
            if ($status === 'needs_check') {
                $needsCheck = true;
            }
        }

        if ($needsCheck) {
            return array('icon' => 'warn', 'status' => __('Beschikbaarheid controleren', 'sbdp'), 'action' => __('Controleer beschikbaarheid', 'sbdp'), 'tab' => 'build');
        }

        return array('icon' => 'ok', 'status' => __('Beschikbaar of n.v.t.', 'sbdp'), 'action' => '', 'tab' => 'build');
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function qcdLineAvailabilityControlStatus(array $line): string
    {
        $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
        $status = (string) ($snapshot['control_status'] ?? '');
        if (in_array($status, array('needs_check', 'confirmed', 'under_reservation', 'unavailable'), true)) {
            return $status;
        }
        if ((string) ($line['line_status'] ?? '') === 'unavailable') {
            return 'unavailable';
        }

        return (string) ($line['availability_confidence'] ?? 'unknown') === 'confirmed'
            ? 'confirmed'
            : 'needs_check';
    }

    /**
     * @param array<string, array<string, string>> $matrix
     */
    private static function qcdApprovalMatrixAllowsSend(array $matrix): bool
    {
        foreach ($matrix as $point) {
            if (in_array((string) ($point['icon'] ?? 'na'), array('error', 'warn'), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scope 1 — Decision Bar: shows final status, key info, and primary CTA.
     *
     * @param array<string, mixed>             $quote
     * @param array<string, mixed>             $lineSummary
     * @param array<string, array<string, string>> $matrix
     * @param array<string, mixed>             $sendReadiness
     * @param array<string, mixed>             $adminStatusSummary
     */
    private static function renderQcdDecisionBar(
        int $quoteId,
        array $quote,
        ?array $request,
        array $requester,
        ?array $currentVersion,
        array $lineSummary,
        array $matrix,
        bool $sendAllowed,
        array $sendReadiness,
        string $pricingConfidence = 'unknown',
        string $availabilityConfidence = 'unknown',
        array $adminStatusSummary = array()
    ): void {
        $reference   = trim((string) ($quote['quote_reference'] ?? ''));
        $customer    = trim((string) ($requester['name'] ?? ''));
        $date        = is_array($request) ? trim((string) ($request['preferred_date'] ?? '')) : '';
        $groupSize   = is_array($request) ? max(0, (int) ($request['group_size'] ?? 0)) : 0;
        $totalLabel  = (string) ($lineSummary['total_label'] ?? __('Bedrag nog open', 'sbdp'));
        $versionNum  = $currentVersion !== null ? (string) ($currentVersion['version_number'] ?? '1') : '1';

        // Compute tri-state final status from matrix
        $errorPoints = array_filter($matrix, static fn (array $p): bool => (string) ($p['icon'] ?? '') === 'error');
        $warnPoints  = array_filter($matrix, static fn (array $p): bool => (string) ($p['icon'] ?? '') === 'warn');

        // Find primary blocker tab for CTA
        $primaryBlockerTab = 'dashboard';
        foreach ($errorPoints as $pt) {
            if (($pt['tab'] ?? '') !== '') { $primaryBlockerTab = (string) $pt['tab']; break; }
        }
        $primaryWarnTab = 'build';
        foreach ($warnPoints as $pt) {
            if (($pt['tab'] ?? '') !== '') { $primaryWarnTab = (string) $pt['tab']; break; }
        }

        if ($sendAllowed) {
            $finalStatus = __('Klaar voor verzenden', 'sbdp');
            $statusClass = 'bsp-qcd__status--ok';
            $ctaLabel    = __('Voorstel versturen', 'sbdp');
            $ctaUrl      = self::workspaceTabUrl($quoteId, 'communication');
            $nextAction  = __('Alle verplichte controles zijn afgerond.', 'sbdp');
        } elseif ($errorPoints !== array()) {
            $finalStatus = __('Niet verzendklaar', 'sbdp');
            $statusClass = 'bsp-qcd__status--error';
            $ctaLabel    = __('Los blokkades op', 'sbdp');
            $ctaUrl      = self::workspaceTabUrl($quoteId, $primaryBlockerTab);
            $firstError  = reset($errorPoints);
            $nextAction  = (string) ($firstError['status'] ?? __('Los open punten op', 'sbdp'));
        } else {
            $finalStatus = __('Controle nodig', 'sbdp');
            $statusClass = 'bsp-qcd__status--warn';
            $ctaLabel    = __('Controleer nu', 'sbdp');
            $ctaUrl      = self::workspaceTabUrl($quoteId, $primaryWarnTab);
            $firstWarn   = reset($warnPoints);
            $nextAction  = (string) ($firstWarn['status'] ?? self::qcdPrimarySendReadinessReason($sendReadiness));
        }

        $priceLabel = self::humanPricingStatusLabel($pricingConfidence);
        $availLabel = self::humanAvailabilityStatusLabel($availabilityConfidence);

        echo '<div class="bsp-qcd__decision-bar">';
        echo '<div class="bsp-qcd__db-col">';
        echo '<span>' . esc_html__('Quote / Klant', 'sbdp') . '</span>';
        echo '<strong>' . esc_html($reference !== '' ? $reference : sprintf('Q-%d', $quoteId)) . '</strong>';
        echo '<small>' . esc_html($customer !== '' ? $customer : __('Onbekende klant', 'sbdp')) . '</small>';
        echo '</div>';
        echo '<div class="bsp-qcd__db-col">';
        echo '<span>' . esc_html__('Datum', 'sbdp') . '</span>';
        echo '<strong>' . esc_html($date !== '' ? $date : '—') . '</strong>';
        echo '</div>';
        echo '<div class="bsp-qcd__db-col">';
        echo '<span>' . esc_html__('Groep', 'sbdp') . '</span>';
        echo '<strong>' . esc_html($groupSize > 0 ? sprintf(_n('%d persoon', '%d personen', $groupSize, 'sbdp'), $groupSize) : '—') . '</strong>';
        echo '</div>';
        echo '<div class="bsp-qcd__db-col">';
        echo '<span>' . esc_html__('Status', 'sbdp') . '</span>';
        echo '<strong class="bsp-qcd__final-status ' . esc_attr($statusClass) . '">' . esc_html($finalStatus) . '</strong>';
        echo '</div>';
        echo '<div class="bsp-qcd__db-col">';
        echo '<span>' . esc_html__('Prijs', 'sbdp') . '</span>';
        echo '<strong>' . esc_html($totalLabel) . '</strong>';
        echo '<small class="' . esc_attr(self::confidenceBadgeClass($pricingConfidence)) . '">' . esc_html($priceLabel) . '</small>';
        echo '</div>';
        echo '<div class="bsp-qcd__db-col">';
        echo '<span>' . esc_html__('Beschikbaarheid', 'sbdp') . '</span>';
        echo '<strong class="' . esc_attr(self::confidenceBadgeClass($availabilityConfidence)) . '">' . esc_html($availLabel) . '</strong>';
        echo '</div>';
        echo '<div class="bsp-qcd__db-col bsp-qcd__db-col--action">';
        echo '<span>' . esc_html__('Volgende actie', 'sbdp') . '</span>';
        echo '<strong>' . esc_html($nextAction) . '</strong>';
        echo '<small>' . esc_html(sprintf(__('Versie %s', 'sbdp'), $versionNum)) . '</small>';
        if ((string) ($quote['handoff_status'] ?? '') === 'ready_to_confirm' && (string) ($quote['status'] ?? '') === 'accepted') {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo wp_nonce_field('sbdp_quote_confirm_ready', '_wpnonce', true, false);
            echo '<input type="hidden" name="action" value="sbdp_quote_confirm_ready">';
            echo '<input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
            echo '<button type="submit" class="button button-primary bsp-qcd__primary-btn">' . esc_html__('Bevestig quote', 'sbdp') . '</button>';
            echo '</form>';
        } else {
            echo '<a href="' . esc_url($ctaUrl) . '" class="button button-primary bsp-qcd__primary-btn">' . esc_html($ctaLabel) . '</a>';
        }
        echo '</div>';
        echo '</div>';
        self::renderQcdAdminStatusSummary($adminStatusSummary);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private static function renderQcdAdminStatusSummary(array $summary): void
    {
        if ($summary === array()) {
            return;
        }

        $chainSteps = array_values(array_filter((array) ($summary['chain_steps'] ?? array()), static fn ($step): bool => is_array($step)));
        $metaChips = array_values(array_filter((array) ($summary['meta_chips'] ?? array()), static fn ($chip): bool => is_array($chip)));
        $blockerChips = array_values(array_filter((array) ($summary['blocker_chips'] ?? array()), static fn ($chip): bool => is_array($chip)));
        $communicationChips = array_values(array_filter((array) ($summary['communication_chips'] ?? array()), static fn ($chip): bool => is_array($chip)));
        $cta = is_array($summary['cta_visibility'] ?? null) ? $summary['cta_visibility'] : array();

        echo '<section class="sbdp-qcd-status-summary" aria-label="' . esc_attr__('Quote ketenstatus', 'sbdp') . '">';
        echo '<div class="sbdp-qcd-chain" role="list" aria-label="' . esc_attr__('Quote flow stappen', 'sbdp') . '">';
        foreach ($chainSteps as $step) {
            $status = (string) ($step['status'] ?? 'pending');
            echo '<span class="sbdp-qcd-chain-step is-' . esc_attr($status) . '" role="listitem">';
            echo '<span class="sbdp-qcd-chain-dot" aria-hidden="true"></span>';
            echo '<span class="sbdp-qcd-chain-label">' . esc_html((string) ($step['label'] ?? '')) . '</span>';
            echo '<span class="sbdp-qcd-chain-state">' . esc_html($status) . '</span>';
            echo '</span>';
        }
        echo '</div>';

        echo '<div class="sbdp-qcd-status-summary__body">';
        echo '<div class="sbdp-qcd-next-action">';
        echo '<span>' . esc_html__('Volgende veilige actie', 'sbdp') . '</span>';
        echo '<strong>' . esc_html((string) ($summary['next_action'] ?? __('Controleer quote', 'sbdp'))) . '</strong>';
        $reason = trim((string) ($summary['next_action_reason'] ?? ''));
        if ($reason !== '') {
            echo '<small>' . esc_html($reason) . '</small>';
        }
        echo '</div>';

        echo '<div class="sbdp-qcd-chip-panel">';
        if ($metaChips !== array()) {
            echo '<div class="sbdp-qcd-chip-group" aria-label="' . esc_attr__('Quote IDs', 'sbdp') . '">';
            foreach ($metaChips as $chip) {
                echo self::renderQcdMetaChip($chip);
            }
            echo '</div>';
        }
        if ($blockerChips !== array()) {
            echo '<div class="sbdp-qcd-chip-group sbdp-qcd-chip-group--blockers" aria-label="' . esc_attr__('Operations en supplier blokkades', 'sbdp') . '">';
            echo '<span class="sbdp-qcd-chip-group-label">' . esc_html__('Operations', 'sbdp') . '</span>';
            foreach ($blockerChips as $chip) {
                echo self::renderQcdBlockerChip($chip);
            }
            echo '</div>';
        }
        if ($communicationChips !== array()) {
            echo '<div class="sbdp-qcd-chip-group sbdp-qcd-chip-group--communication" aria-label="' . esc_attr__('Communicatie status', 'sbdp') . '">';
            echo '<span class="sbdp-qcd-chip-group-label">' . esc_html__('Communicatie', 'sbdp') . '</span>';
            foreach ($communicationChips as $chip) {
                echo self::renderQcdCommunicationChip($chip);
            }
            echo '</div>';
        }
        echo '<div class="sbdp-qcd-cta-explain" aria-label="' . esc_attr__('CTA zichtbaarheid', 'sbdp') . '">';
        echo self::renderQcdCtaState(__('Bevestig quote', 'sbdp'), ! empty($cta['confirm_quote']));
        echo self::renderQcdCtaState(__('Open Woo winkelwagen', 'sbdp'), ! empty($cta['open_woo_cart']));
        echo self::renderQcdCtaState(__('Maak operationele boeking', 'sbdp'), ! empty($cta['create_booking_bridge']));
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</section>';
    }

    /**
     * @param array<string, mixed> $chip
     */
    private static function renderQcdMetaChip(array $chip): string
    {
        $label = trim((string) ($chip['label'] ?? ''));
        $value = trim((string) ($chip['value'] ?? ''));
        $url = trim((string) ($chip['url'] ?? ''));
        if ($label === '' || $value === '') {
            return '';
        }

        $inner = '<span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong>';
        if ($url !== '') {
            return '<a class="sbdp-qcd-meta-chip" href="' . esc_url($url) . '">' . $inner . '</a>';
        }

        return '<span class="sbdp-qcd-meta-chip">' . $inner . '</span>';
    }

    /**
     * @param array<string, mixed> $chip
     */
    private static function renderQcdBlockerChip(array $chip): string
    {
        $label = trim((string) ($chip['label'] ?? ''));
        if ($label === '') {
            return '';
        }

        return '<span class="sbdp-qcd-blocker-chip">' . esc_html($label) . '</span>';
    }

    /**
     * @param array<string, mixed> $chip
     */
    private static function renderQcdCommunicationChip(array $chip): string
    {
        $label = trim((string) ($chip['label'] ?? ''));
        if ($label === '') {
            return '';
        }

        $status = (string) ($chip['status'] ?? 'notice');
        return '<span class="sbdp-qcd-communication-chip is-' . esc_attr($status) . '">' . esc_html($label) . '</span>';
    }

    private static function renderQcdCtaState(string $label, bool $visible): string
    {
        return '<span class="sbdp-qcd-cta-state ' . esc_attr($visible ? 'is-visible' : 'is-hidden') . '"><strong>' . esc_html($label) . '</strong><em>' . esc_html($visible ? __('zichtbaar', 'sbdp') : __('verborgen', 'sbdp')) . '</em></span>';
    }

    /**
     * @param array<string, mixed> $sendReadiness
     */
    private static function qcdPrimarySendReadinessReason(array $sendReadiness): string
    {
        foreach ((array) ($sendReadiness['blockers'] ?? array()) as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }
            $code = (string) ($blocker['code'] ?? '');
            if ($code === 'review_not_approved') {
                return __('Interne review ontbreekt', 'sbdp');
            }
            if ($code === 'send_status_not_ready') {
                return __('Voorsteltekst nog niet vrijgegeven', 'sbdp');
            }
            $message = trim((string) ($blocker['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return __('Controleer open punten', 'sbdp');
    }

    /**
     * Scope 2 — Context Grid: 3-column KLANT | PRIJS & PROGRAMMA | NOG NODIG.
     *
     * @param array<string, mixed>                 $request
     * @param array<string, mixed>                 $requester
     * @param array<string, mixed>                 $lineSummary
     * @param array<string, mixed>                 $proposalProgram
     * @param array<string, array<string, string>> $matrix
     * @param array<string, mixed>                 $workspaceAlerts
     */
    private static function renderQcdContextGrid(
        int $quoteId,
        ?array $request,
        array $requester,
        array $lineSummary,
        array $proposalProgram,
        array $matrix,
        array $workspaceAlerts,
        bool $sendAllowed,
        bool $quoteCommerciallyEditable
    ): void {
        // KLANT data
        $name      = trim((string) ($requester['name']    ?? ''));
        $company   = trim((string) ($requester['company'] ?? ''));
        $email     = trim((string) ($requester['email']   ?? ''));
        $phone     = trim((string) ($requester['phone']   ?? ''));
        $summary   = is_array($request) ? trim((string) ($request['request_summary'] ?? '')) : '';
        $modalId   = 'bsp-qcd-customer-modal-' . $quoteId;

        // PRIJS & PROGRAMMA data
        $stats          = is_array($proposalProgram['stats'] ?? null) ? $proposalProgram['stats'] : array();
        $totalLines     = (int) ($stats['total_lines'] ?? $lineSummary['total_lines'] ?? 0);
        $scheduledLines = (int) ($stats['scheduled_lines'] ?? 0);
        $subtotal       = (string) ($lineSummary['subtotal_label'] ?? $lineSummary['total_label'] ?? '—');
        $total          = (string) ($lineSummary['total_label'] ?? '—');
        $discountLabel  = (string) ($lineSummary['discount_label'] ?? '');
        $discountAmount = (string) ($lineSummary['discount_amount_label'] ?? '');

        // NOG NODIG data
        $blockers       = is_array($workspaceAlerts['blockers'] ?? null) ? (array) $workspaceAlerts['blockers'] : array();
        $partnerActions = is_array($workspaceAlerts['partner_actions'] ?? null) ? (array) $workspaceAlerts['partner_actions'] : array();
        $warnings       = is_array($workspaceAlerts['warnings'] ?? null) ? (array) $workspaceAlerts['warnings'] : array();
        $blockerCount   = count($blockers);
        $partnerCount   = count($partnerActions);
        $warnCount      = count($warnings);

        echo '<div class="bsp-qcd__context-grid">';

        // ── Column 1: KLANT ───────────────────────────────────────────────────
        echo '<div class="bsp-qcd__context-col bsp-qcd__context-klant">';
        echo '<h4 class="bsp-qcd__context-heading">' . esc_html__('Klant', 'sbdp') . '</h4>';
        echo '<div class="bsp-qcd__cf-list">';
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Naam', 'sbdp') . '</span><strong>' . esc_html($name !== '' ? $name : __('Ontbreekt', 'sbdp')) . '</strong></div>';
        if ($company !== '') {
            echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Bedrijf', 'sbdp') . '</span><strong>' . esc_html($company) . '</strong></div>';
        }
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('E-mail', 'sbdp') . '</span><strong>' . ($email !== '' ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : esc_html__('Ontbreekt', 'sbdp')) . '</strong></div>';
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Telefoon', 'sbdp') . '</span><strong>' . esc_html($phone !== '' ? $phone : __('Ontbreekt', 'sbdp')) . '</strong></div>';
        if ($summary !== '') {
            echo '<div class="bsp-qcd__cf bsp-qcd__cf--wide"><span>' . esc_html__('Omschrijving', 'sbdp') . '</span><strong>' . esc_html(\wp_trim_words($summary, 18)) . '</strong></div>';
        }
        echo '</div>';
        if ($quoteCommerciallyEditable) {
            echo '<button type="button" class="button button-small bsp-quote-admin__modal-open" data-modal-target="' . esc_attr($modalId) . '">' . esc_html__('Bewerk klantgegevens', 'sbdp') . '</button>';
        }
        echo '</div>';

        // ── Column 2: PRIJS & PROGRAMMA ───────────────────────────────────────
        echo '<div class="bsp-qcd__context-col bsp-qcd__context-prijs">';
        echo '<h4 class="bsp-qcd__context-heading">' . esc_html__('Prijs & programma', 'sbdp') . '</h4>';
        echo '<div class="bsp-qcd__cf-list">';
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Onderdelen', 'sbdp') . '</span><strong>' . esc_html((string) $totalLines) . '</strong></div>';
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Gepland', 'sbdp') . '</span><strong>' . esc_html($totalLines > 0 ? sprintf('%d/%d', $scheduledLines, $totalLines) : '—') . '</strong></div>';
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Subtotaal', 'sbdp') . '</span><strong>' . esc_html($subtotal) . '</strong></div>';
        if ($discountAmount !== '') {
            echo '<div class="bsp-qcd__cf"><span>' . esc_html($discountLabel !== '' ? $discountLabel : __('Korting', 'sbdp')) . '</span><strong>' . esc_html($discountAmount) . '</strong></div>';
        }
        echo '<div class="bsp-qcd__cf bsp-qcd__cf--primary"><span>' . esc_html__('Voorstelbed.', 'sbdp') . '</span><strong>' . esc_html($total) . '</strong></div>';
        echo '</div>';
        echo '<a class="button button-small" href="#qcd-program-editor">' . esc_html__('Open programma', 'sbdp') . '</a>';
        echo '</div>';

        // ── Column 3: NOG NODIG VOOR VERZENDEN ───────────────────────────────
        echo '<div class="bsp-qcd__context-col bsp-qcd__context-nodig">';
        echo '<h4 class="bsp-qcd__context-heading">' . esc_html__('Nog nodig voor verzenden', 'sbdp') . '</h4>';

        // Badge counts row
        echo '<div class="bsp-qcd__nodig-counts">';
        $blockerBadge = $blockerCount > 0 ? 'bsp-qcd__nodig-badge--error' : 'bsp-qcd__nodig-badge--ok';
        echo '<span class="bsp-qcd__nodig-badge ' . esc_attr($blockerBadge) . '">' . esc_html(sprintf(_n('%d blokker', '%d blockers', $blockerCount, 'sbdp'), $blockerCount)) . '</span>';
        if ($partnerCount > 0) {
            echo '<span class="bsp-qcd__nodig-badge bsp-qcd__nodig-badge--warn">' . esc_html(sprintf(_n('%d partneractie', '%d partneracties', $partnerCount, 'sbdp'), $partnerCount)) . '</span>';
        }
        if ($warnCount > 0) {
            echo '<span class="bsp-qcd__nodig-badge bsp-qcd__nodig-badge--warn">' . esc_html(sprintf(_n('%d waarschuwing', '%d waarschuwingen', $warnCount, 'sbdp'), $warnCount)) . '</span>';
        }
        echo '</div>';

        // Top blockers (max 3)
        $shownBlockers = array_slice($blockers, 0, 3);
        if ($shownBlockers !== array()) {
            echo '<p class="bsp-qcd__nodig-must">' . esc_html__('Moet voor verzenden:', 'sbdp') . '</p>';
            echo '<ul class="bsp-qcd__nodig-list">';
            foreach ($shownBlockers as $blocker) {
                if (! is_array($blocker)) {
                    continue;
                }
                $title  = (string) ($blocker['title'] ?? __('Open punt', 'sbdp'));
                $href   = (string) ($blocker['action_href'] ?? '');
                $action = (string) ($blocker['action_label'] ?? '');
                echo '<li class="bsp-qcd__nodig-item">';
                echo '<span class="bsp-qcd__nodig-icon">✕</span>';
                echo '<span>' . esc_html($title);
                if ($href !== '' && $action !== '') {
                    echo ' <a href="' . esc_url($href) . '">' . esc_html($action) . ' →</a>';
                }
                echo '</span></li>';
            }
            echo '</ul>';
        }

        // Partner actions (max 2)
        $shownPartner = array_slice($partnerActions, 0, 2);
        foreach ($shownPartner as $pa) {
            if (! is_array($pa)) {
                continue;
            }
            $paHref = (string) ($pa['action_href'] ?? '');
            echo '<p class="bsp-qcd__nodig-partner">' . esc_html((string) ($pa['title'] ?? ''));
            if ($paHref !== '') {
                echo ' <a href="' . esc_url($paHref) . '">' . esc_html__('Open partnerstatus', 'sbdp') . '</a>';
            }
            echo '</p>';
        }

        if ($blockers === array() && $partnerActions === array()) {
            // Check matrix for any blocking/warn points
            $matrixBlockers = array_filter($matrix, static fn (array $p): bool => in_array((string) ($p['icon'] ?? ''), array('error', 'warn'), true));
            if ($matrixBlockers !== array()) {
                echo '<ul class="bsp-qcd__nodig-list">';
                foreach (array_slice($matrixBlockers, 0, 3) as $point) {
                    echo '<li class="bsp-qcd__nodig-item"><span class="bsp-qcd__nodig-icon">' . esc_html((string) ($point['icon'] ?? '') === 'error' ? '✕' : '!') . '</span>';
                    echo '<span>' . esc_html((string) ($point['label'] ?? '')) . ' — ' . esc_html((string) ($point['status'] ?? ''));
                    $tab = (string) ($point['tab'] ?? '');
                    if ($tab !== '') {
                        echo ' <a href="' . esc_url(self::workspaceTabUrl($quoteId, $tab)) . '">' . esc_html((string) ($point['action'] ?? 'Controleer')) . ' →</a>';
                    }
                    echo '</span></li>';
                }
                echo '</ul>';
            } else {
                echo '<p class="bsp-qcd__nodig-ok">✓ ' . esc_html__('Alle controlepunten akkoord.', 'sbdp') . '</p>';
            }
        }

        if ($sendAllowed) {
            echo '<a class="button button-primary button-small bsp-qcd__nodig-cta" href="' . esc_url(self::workspaceTabUrl($quoteId, 'communication') . '#quote-proposal-send-form') . '">' . esc_html__('Voorstel versturen', 'sbdp') . '</a>';
        }

        echo '</div>'; // .bsp-qcd__context-nodig
        echo '</div>'; // .bsp-qcd__context-grid
    }

    /**
     * Scope 3 — Approval Matrix: 6 control points each with ✓/!/✕/–.
     *
     * @param array<string, array<string, string>> $matrix
     */
    private static function renderQcdApprovalMatrix(array $matrix, int $quoteId): void
    {
        $iconMap = array(
            'ok'    => array('char' => '✓', 'class' => 'is-good', 'label' => __('Akkoord', 'sbdp')),
            'warn'  => array('char' => '!', 'class' => 'is-warn',    'label' => __('Controle nodig', 'sbdp')),
            'error' => array('char' => '✕', 'class' => 'is-error',   'label' => __('Blokkeert verzending', 'sbdp')),
            'na'    => array('char' => '–', 'class' => 'is-neutral',  'label' => __('Niet van toepassing', 'sbdp')),
        );

        echo '<section class="postbox bsp-qcd__matrix-section"><div class="bsp-qcd__matrix-header"><h3>' . esc_html__('Controle overzicht', 'sbdp') . '</h3></div>';
        echo '<div class="bsp-qcd__matrix-grid">';

        foreach ($matrix as $key => $point) {
            $iconKey    = (string) ($point['icon'] ?? 'na');
            $icon       = $iconMap[$iconKey] ?? $iconMap['na'];
            $label      = (string) ($point['label'] ?? '');
            $status     = (string) ($point['status'] ?? '');
            $actionText = (string) ($point['action'] ?? '');
            $tab        = (string) ($point['tab'] ?? '');

            echo '<div class="bsp-qcd__matrix-item bsp-qcd__matrix-item--' . esc_attr($iconKey) . '">';
            echo '<span class="bsp-qcd__matrix-icon ' . esc_attr($icon['class']) . '" title="' . esc_attr($icon['label']) . '">' . esc_html($icon['char']) . '</span>';
            echo '<div class="bsp-qcd__matrix-body">';
            echo '<strong class="bsp-qcd__matrix-label">' . esc_html($label) . '</strong>';
            echo '<span class="bsp-qcd__matrix-status">' . esc_html($status) . '</span>';
            if ($actionText !== '') {
                $href = $tab !== '' ? esc_url(self::workspaceTabUrl($quoteId, $tab)) : '#';
                echo '<a class="bsp-qcd__matrix-action" href="' . $href . '">' . esc_html($actionText) . ' →</a>';
            }
            echo '</div></div>';
        }

        echo '</div></section>';
    }

    /**
     * Scope 4 — Program Timeline: sorted by start_time, per-line status icons.
     *
     * @param array<int, array<string, mixed>> $lines
     */
    private static function renderQcdProgramTimeline(array $lines, string $currency, int $quoteId): void
    {
        echo '<section class="postbox bsp-qcd__program-section">';
        echo '<div class="bsp-qcd__program-header">';
        echo '<h3>' . esc_html__('Programma', 'sbdp') . '</h3>';
        echo '<a class="button button-small" href="' . esc_url(self::workspaceTabUrl($quoteId, 'build')) . '">' . esc_html__('Bewerk programma', 'sbdp') . '</a>';
        echo '</div>';

        if ($lines === array()) {
            echo '<p class="bsp-qcd__empty">' . esc_html__('Nog geen programmaregels. Voeg activiteiten toe via "Bewerk programma".', 'sbdp') . '</p>';
            echo '</section>';
            return;
        }

        // Sort lines by start_time
        $sorted = $lines;
        usort($sorted, static function (array $a, array $b): int {
            $ta = trim((string) ($a['service_date'] ?? '')) . ' ' . trim((string) ($a['start_time'] ?? ''));
            $tb = trim((string) ($b['service_date'] ?? '')) . ' ' . trim((string) ($b['start_time'] ?? ''));
            return strcmp($ta, $tb);
        });

        echo '<div class="bsp-qcd__timeline">';
        foreach ($sorted as $idx => $line) {
            $num          = (int) ($line['line_number'] ?? ($idx + 1));
            $title        = trim((string) ($line['title'] ?? __('Activiteit', 'sbdp')));
            $date         = trim((string) ($line['service_date'] ?? ''));
            $startTime    = trim((string) ($line['proposed_start_time'] ?? ($line['start_time'] ?? '')));
            $endTime      = trim((string) ($line['proposed_end_time'] ?? ($line['end_time'] ?? '')));
            $participants = max(0, (int) ($line['participants'] ?? 0));
            $lineTotal    = isset($line['line_total_snapshot']) && $line['line_total_snapshot'] !== null ? (float) $line['line_total_snapshot'] : null;
            $unitAmount   = isset($line['unit_amount_snapshot']) && $line['unit_amount_snapshot'] !== null ? (float) $line['unit_amount_snapshot'] : null;
            $lineCurrency = (string) (($line['currency'] ?? '') ?: $currency);
            $pConf        = (string) ($line['pricing_confidence'] ?? 'unknown');
            $aConf        = (string) ($line['availability_confidence'] ?? 'unknown');

            // Time label
            if ($startTime !== '' && $endTime !== '') {
                $timeLabel = sprintf('%s – %s', $startTime, $endTime);
            } elseif ($startTime !== '') {
                $timeLabel = $startTime;
            } elseif ($date !== '') {
                $timeLabel = $date;
            } else {
                $timeLabel = __('Tijd nog open', 'sbdp');
            }

            // Price/availability icon
            $pIcon = $pConf === 'execution_verified' ? '✓' : ($pConf === 'unknown' ? '?' : '!');
            $pClass = $pConf === 'execution_verified' ? 'is-good' : 'is-warn';
            $aIcon = $aConf === 'confirmed' ? '✓' : ($aConf === 'unknown' ? '?' : '!');
            $aClass = $aConf === 'confirmed' ? 'is-good' : 'is-warn';

            echo '<div class="bsp-qcd__timeline-item">';
            echo '<div class="bsp-qcd__tl-num">' . esc_html((string) $num) . '</div>';
            echo '<div class="bsp-qcd__tl-time">' . esc_html($timeLabel) . '</div>';
            echo '<div class="bsp-qcd__tl-body">';
            echo '<strong class="bsp-qcd__tl-title">' . esc_html($title) . '</strong>';
            $details = array();
            if ($participants > 0) {
                $details[] = sprintf(_n('%d persoon', '%d personen', $participants, 'sbdp'), $participants);
            }
            if ($unitAmount !== null && $participants > 0) {
                $details[] = self::formatMoney($unitAmount, $lineCurrency) . ' p.p.';
            }
            if ($lineTotal !== null) {
                $details[] = self::formatMoney($lineTotal, $lineCurrency) . ' totaal';
            }
            if ($details !== array()) {
                echo '<span class="bsp-qcd__tl-detail">' . esc_html(implode(' · ', $details)) . '</span>';
            }
            echo '</div>';
            echo '<div class="bsp-qcd__tl-status">';
            echo '<span class="bsp-qcd__tl-badge ' . esc_attr($pClass) . '" title="' . esc_attr__('Prijsstatus', 'sbdp') . '">';
            echo 'Prijs ' . esc_html($pIcon);
            echo '</span>';
            echo '<span class="bsp-qcd__tl-badge ' . esc_attr($aClass) . '" title="' . esc_attr__('Beschikbaarheidsstatus', 'sbdp') . '">';
            echo 'Beschikb. ' . esc_html($aIcon);
            echo '</span>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '</section>';
    }

    /**
     * Scope 5 — Customer & NAW card: direct visibility + editable modal.
     *
     * @param array<string, mixed>      $requester
     */
    private static function renderQcdCustomerCard(
        int $quoteId,
        ?array $request,
        array $requester,
        string $formattedAddress,
        bool $quoteCommerciallyEditable
    ): void {
        $name        = trim((string) ($requester['name']    ?? ''));
        $company     = trim((string) ($requester['company'] ?? ''));
        $email       = trim((string) ($requester['email']   ?? ''));
        $phone       = trim((string) ($requester['phone']   ?? ''));
        $date        = is_array($request) ? trim((string) ($request['preferred_date'] ?? '')) : '';
        $groupSize   = is_array($request) ? max(0, (int) ($request['group_size'] ?? 0)) : 0;
        $summary     = is_array($request) ? trim((string) ($request['request_summary'] ?? '')) : '';
        $address     = isset($requester['address']) && is_array($requester['address']) ? $requester['address'] : array();
        $street      = trim((string) ($address['address_1'] ?? ''));
        $postcode    = trim((string) ($address['postcode'] ?? ''));
        $city        = trim((string) ($address['city'] ?? ''));
        $country     = trim((string) ($address['country'] ?? ''));

        echo '<section class="postbox bsp-qcd__customer-section">';
        echo '<div class="bsp-qcd__customer-header">';
        echo '<h3>' . esc_html__('Klant & NAW', 'sbdp') . '</h3>';
        if ($quoteCommerciallyEditable) {
            $modalId = 'bsp-qcd-customer-modal-' . $quoteId;
            echo '<button type="button" class="button button-small bsp-quote-admin__modal-open" data-modal-target="' . esc_attr($modalId) . '">' . esc_html__('Bewerk klantgegevens', 'sbdp') . '</button>';
        }
        echo '</div>';

        echo '<div class="bsp-qcd__customer-grid">';
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Naam', 'sbdp') . '</span><strong>' . esc_html($name !== '' ? $name : __('Ontbreekt', 'sbdp')) . '</strong></div>';
        if ($company !== '') {
            echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Bedrijf', 'sbdp') . '</span><strong>' . esc_html($company) . '</strong></div>';
        }
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('E-mail', 'sbdp') . '</span><strong>' . ($email !== '' ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : esc_html__('Ontbreekt', 'sbdp')) . '</strong></div>';
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Telefoon', 'sbdp') . '</span><strong>' . esc_html($phone !== '' ? $phone : __('Ontbreekt', 'sbdp')) . '</strong></div>';
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Datum', 'sbdp') . '</span><strong>' . esc_html($date !== '' ? $date : __('Nog open', 'sbdp')) . '</strong></div>';
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Personen', 'sbdp') . '</span><strong>' . ($groupSize > 0 ? esc_html(sprintf(_n('%d persoon', '%d personen', $groupSize, 'sbdp'), $groupSize)) : esc_html__('Nog open', 'sbdp')) . '</strong></div>';
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Straat + huisnummer', 'sbdp') . '</span><strong>' . esc_html($street !== '' ? $street : __('Ontbreekt', 'sbdp')) . '</strong></div>';
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Postcode', 'sbdp') . '</span><strong>' . esc_html($postcode !== '' ? $postcode : __('Ontbreekt', 'sbdp')) . '</strong></div>';
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Plaats', 'sbdp') . '</span><strong>' . esc_html($city !== '' ? $city : __('Ontbreekt', 'sbdp')) . '</strong></div>';
        echo '<div class="bsp-qcd__cf"><span>' . esc_html__('Land', 'sbdp') . '</span><strong>' . esc_html($country !== '' ? $country : __('Ontbreekt', 'sbdp')) . '</strong></div>';
        if ($formattedAddress !== '') {
            echo '<div class="bsp-qcd__cf bsp-qcd__cf--wide"><span>' . esc_html__('Adresregel', 'sbdp') . '</span><strong>' . esc_html($formattedAddress) . '</strong></div>';
        }
        if ($summary !== '') {
            echo '<div class="bsp-qcd__cf bsp-qcd__cf--wide"><span>' . esc_html__('Omschrijving', 'sbdp') . '</span><strong>' . esc_html($summary) . '</strong></div>';
        }
        echo '</div>';

        if ($quoteCommerciallyEditable) {
            self::renderQcdCustomerModal($quoteId, $request, $requester, $address, $date, $groupSize, $summary);
        }
        echo '</section>';
    }

    /**
     * Renders the fully editable customer modal for QCD.
     *
     * @param array<string, mixed>      $requester
     * @param array<string, mixed>      $address
     */
    private static function renderQcdCustomerModal(
        int $quoteId,
        ?array $request,
        array $requester,
        array $address,
        string $date,
        int $groupSize,
        string $summary
    ): void {
        $modalId = 'bsp-qcd-customer-modal-' . $quoteId;
        echo '<div id="' . esc_attr($modalId) . '" class="bsp-quote-admin__modal" hidden role="dialog" aria-modal="true" aria-labelledby="' . esc_attr($modalId . '-title') . '">';
        echo '<div class="bsp-quote-admin__modal-panel bsp-qcd__modal-panel">';
        echo '<div class="bsp-quote-admin__modal-header"><h3 id="' . esc_attr($modalId . '-title') . '">' . esc_html__('Klantgegevens bewerken', 'sbdp') . '</h3>';
        echo '<button type="button" class="button-link bsp-quote-admin__modal-close" data-modal-close="' . esc_attr($modalId) . '">×</button></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-qcd__modal-form">';
        \wp_nonce_field('sbdp_quote_update_customer_contact');
        echo '<input type="hidden" name="action" value="sbdp_quote_update_customer_contact">';
        echo '<input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
        echo '<div class="bsp-qcd__modal-grid">';
        echo '<label>' . esc_html__('Naam', 'sbdp') . '<input type="text" name="requester_name" value="' . esc_attr((string) ($requester['name'] ?? '')) . '" class="regular-text"></label>';
        echo '<label>' . esc_html__('Bedrijf', 'sbdp') . '<input type="text" name="requester_company" value="' . esc_attr((string) ($requester['company'] ?? '')) . '" class="regular-text"></label>';
        echo '<label>' . esc_html__('E-mail', 'sbdp') . '<input type="email" name="requester_email" value="' . esc_attr((string) ($requester['email'] ?? '')) . '" class="regular-text"></label>';
        echo '<label>' . esc_html__('Telefoon', 'sbdp') . '<input type="text" name="requester_phone" value="' . esc_attr((string) ($requester['phone'] ?? '')) . '" class="regular-text"></label>';
        echo '<label>' . esc_html__('Voorkeursdatum', 'sbdp') . '<input type="date" name="preferred_date" value="' . esc_attr($date) . '" class="regular-text"></label>';
        echo '<label>' . esc_html__('Aantal personen', 'sbdp') . '<input type="number" name="group_size" value="' . esc_attr((string) $groupSize) . '" min="0" class="small-text"></label>';
        echo '<label>' . esc_html__('Straat + huisnummer', 'sbdp') . '<input type="text" name="requester_address_1" value="' . esc_attr((string) ($address['address_1'] ?? '')) . '" class="regular-text"></label>';
        echo '<label>' . esc_html__('Postcode', 'sbdp') . '<input type="text" name="requester_postcode" value="' . esc_attr((string) ($address['postcode'] ?? '')) . '" class="small-text"></label>';
        echo '<label>' . esc_html__('Plaats', 'sbdp') . '<input type="text" name="requester_city" value="' . esc_attr((string) ($address['city'] ?? '')) . '" class="regular-text"></label>';
        echo '<label>' . esc_html__('Land', 'sbdp') . '<input type="text" name="requester_country" value="' . esc_attr((string) ($address['country'] ?? 'NL')) . '" class="small-text"></label>';
        echo '<label class="bsp-qcd__modal-wide">' . esc_html__('Aanvraagomschrijving', 'sbdp') . '<textarea name="request_summary" rows="3" class="large-text">' . esc_textarea($summary) . '</textarea></label>';
        echo '</div>';
        echo '<div class="bsp-quote-admin__modal-actions">';
        echo '<button type="button" class="button button-secondary" data-modal-close="' . esc_attr($modalId) . '">' . esc_html__('Annuleren', 'sbdp') . '</button>';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Klantgegevens opslaan', 'sbdp') . '</button>';
        echo '</div></form></div></div>';
        echo '<script>(function(){if(window.bspQuoteCustomerModalBound){return;}window.bspQuoteCustomerModalBound=true;document.addEventListener("click",function(event){var open=event.target.closest("[data-modal-target]");if(open){var modal=document.getElementById(open.getAttribute("data-modal-target"));if(modal){modal.hidden=false;var field=modal.querySelector("input:not([readonly]),textarea:not([readonly]),button");if(field){field.focus();}}}var close=event.target.closest("[data-modal-close]");if(close){var target=document.getElementById(close.getAttribute("data-modal-close"));if(target){target.hidden=true;}}if(event.target.classList&&event.target.classList.contains("bsp-quote-admin__modal")){event.target.hidden=true;}});document.addEventListener("keydown",function(event){if(event.key==="Escape"){document.querySelectorAll(".bsp-quote-admin__modal:not([hidden])").forEach(function(modal){modal.hidden=true;});}});})();</script>';
    }

    /**
     * Scope 6 — Proposal Preview card: inline readiness + readability.
     *
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $proposalReadiness
     * @param array<string, mixed> $messageDrafts
     * @param array<string, mixed> $lineSummary
     * @param array<string, mixed> $proposalProgram
     */
    private static function renderQcdProposalPreview(
        int $quoteId,
        array $quote,
        ?array $currentVersion,
        array $proposalReadiness,
        array $messageDrafts,
        array $lineSummary,
        array $proposalProgram,
        bool $sendAllowed,
        array $sendReadiness,
        array $communicationState,
        array $messages
    ): void {
        $proposalTitle   = $currentVersion !== null ? trim((string) ($currentVersion['proposal_title'] ?? '')) : '';
        $proposalSummary = $currentVersion !== null ? trim((string) ($currentVersion['proposal_summary'] ?? '')) : '';
        $draftSubject    = trim((string) ($messageDrafts['proposal']['subject'] ?? ''));
        $draftBody       = trim((string) ($messageDrafts['proposal']['body'] ?? ''));
        $totalLabel      = (string) ($lineSummary['total_label'] ?? '');
        $proposalReady   = ! empty($proposalReadiness['ready']);
        $versionNum      = $currentVersion !== null ? (string) ($currentVersion['version_number'] ?? '1') : '1';
        $updatedAt       = $currentVersion !== null ? trim((string) ($currentVersion['updated_at'] ?? '')) : '';
        $stats           = is_array($proposalProgram['stats'] ?? null) ? $proposalProgram['stats'] : array();
        $readinessItems  = is_array($proposalReadiness['items'] ?? null) ? array_slice((array) $proposalReadiness['items'], 0, 4) : array();
        $readinessItems = array_slice($readinessItems, 0, 5);

        $subject     = $draftSubject !== '' ? $draftSubject : ($proposalTitle !== '' ? $proposalTitle : __('Nog geen voorstelonderwerp', 'sbdp'));
        $statusLabel = ! empty($communicationState['proposal_can_send'])
            ? __('Klaar voor verzending', 'sbdp')
            : (! empty($communicationState['proposal_can_complete_control'])
                ? __('Controle afronden', 'sbdp')
                : ($proposalReady ? __('Voorstel klaar', 'sbdp') : __('Controle nodig', 'sbdp')));
        $bodySnippet = $draftBody !== ''
            ? \wp_trim_words($draftBody, 38)
            : ($proposalSummary !== '' ? \wp_trim_words($proposalSummary, 38) : __('Nog geen voorsteltekst vastgelegd.', 'sbdp'));
        $programSummary = sprintf(
            __('%d onderdeel(en), %d gepland, %d geprijsd', 'sbdp'),
            (int) ($stats['total_lines'] ?? 0),
            (int) ($stats['scheduled_lines'] ?? 0),
            (int) ($stats['priced_lines'] ?? 0)
        );
        $caveat = $sendAllowed
            ? __('Definitieve bevestiging volgt na akkoord en laatste controle.', 'sbdp')
            : __('Voorlopig voorstel: definitieve bevestiging en beschikbaarheid zijn onder voorbehoud.', 'sbdp');
        $sanitizerTerms = self::qcdProposalTextSanitizerTerms(array($subject, $bodySnippet, $caveat, $draftBody, $proposalSummary));
        $latestProposal = self::findLatestQuoteMessage($messages, 'outbound', 'proposal', 'sent');
        $latestInbound = self::findLatestQuoteMessage($messages, 'inbound');
        $mailStatus = self::qcdMailStatusState($communicationState, $latestProposal, $latestInbound);
        $sendBlockReason = trim((string) ($communicationState['proposal_send_block_reason'] ?? ''));

        echo '<section class="bsp-qcd__bottom-row bsp-qcd__proposal-row" id="qcd-customer-mail">';
        echo '<div class="bsp-qcd__bottom-row-header">';
        echo '<span class="bsp-qcd__bottom-row-title">' . esc_html__('Klantmail & voorstel', 'sbdp') . '</span>';
        echo '<span class="bsp-qcd__bottom-row-meta">';
        echo '<span class="bsp-qcd__card-status ' . esc_attr($proposalReady ? 'is-good' : 'is-warn') . '" data-qcd-proposal-status>' . esc_html($statusLabel) . '</span>';
        echo ' · ' . esc_html(sprintf(__('versie #%s%s', 'sbdp'), $versionNum, $updatedAt !== '' ? ' · ' . sprintf(__('bijgewerkt %s', 'sbdp'), $updatedAt) : ''));
        echo '</span>';
        echo '</div>';
        echo '<div class="bsp-qcd__bottom-row-body">';
        echo '<div class="bsp-qcd__mail-status-rail" aria-label="' . esc_attr__('Mailstatus', 'sbdp') . '">';
        foreach ($mailStatus['steps'] as $step) {
            echo '<span class="bsp-qcd__mail-step ' . esc_attr((string) ($step['class'] ?? '')) . '">' . esc_html((string) ($step['label'] ?? '')) . '</span>';
        }
        echo '</div>';
        echo '<p class="bsp-qcd__mail-truth">' . esc_html((string) ($mailStatus['description'] ?? '')) . '</p>';
        echo '<div class="bsp-qcd__card-grid">';
        echo self::renderSummaryBarItem(__('Status', 'sbdp'), $statusLabel, ! $proposalReady);
        echo '<div class="bsp-quote-admin__summary-bar-item"><span>' . esc_html__('Onderwerp', 'sbdp') . '</span><strong data-qcd-proposal-subject>' . esc_html($subject) . '</strong></div>';
        echo self::renderSummaryBarItem(__('Prijsregel', 'sbdp'), $totalLabel !== '' ? $totalLabel : '—');
        echo self::renderSummaryBarItem(__('Programma', 'sbdp'), $programSummary);
        echo '</div><div class="bsp-qcd__proposal-copy">';
        echo '<p><strong>' . esc_html__('Korte tekst', 'sbdp') . '</strong><br><span data-qcd-proposal-body>' . esc_html($bodySnippet) . '</span></p>';
        echo '<p><strong>' . esc_html__('Voorwaarden / voorbehoud', 'sbdp') . '</strong><br><span data-qcd-proposal-terms>' . esc_html($caveat) . '</span></p>';
        echo '</div><ul class="bsp-qcd__readiness-list" data-qcd-proposal-readiness>';
        if ($sanitizerTerms !== array()) {
            echo '<li><span class="bsp-qcd__readiness-icon is-warn">!</span><strong>' . esc_html__('Interne systeemtekst gevonden', 'sbdp') . '</strong><span>' . esc_html__('Pas de klanttekst aan voordat je verzendt.', 'sbdp') . '</span></li>';
        }
        if ($readinessItems === array() && $sanitizerTerms === array()) {
            echo '<li><span class="bsp-qcd__readiness-icon is-good">✓</span><strong>' . esc_html__('Voorstelcontrole', 'sbdp') . '</strong><span>' . esc_html__('Geen open voorstelpunten gevonden.', 'sbdp') . '</span></li>';
        } else {
            foreach ($readinessItems as $item) {
                $title = (string) ($item['title'] ?? __('Controlepunt', 'sbdp'));
                $description = (string) ($item['description'] ?? '');
                echo '<li><span class="bsp-qcd__readiness-icon is-warn">!</span><strong>' . esc_html($title) . '</strong><span>' . esc_html($description) . '</span></li>';
            }
        }
        echo '</ul><div class="bsp-qcd__card-actions">';
        if (! empty($communicationState['proposal_can_complete_control'])) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-qcd__inline-review-form">';
                echo wp_nonce_field('sbdp_quote_review_approve', '_wpnonce', true, false);
                echo '<input type="hidden" name="action" value="sbdp_quote_review_approve">';
                echo '<input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
                echo '<button type="submit" class="button button-primary button-small">' . esc_html__('Controle afgerond', 'sbdp') . '</button>';
                echo '</form>';
        } elseif (empty($communicationState['proposal_can_send']) && (string) ($quote['review_status'] ?? 'not_started') !== 'approved') {
                echo '<button type="button" class="button button-secondary button-small" disabled title="' . esc_attr($sendBlockReason !== '' ? $sendBlockReason : __('Rond eerst alle verzendchecks af.', 'sbdp')) . '">' . esc_html__('Controle afgerond', 'sbdp') . '</button>';
        }
        if ($sendAllowed) {
            echo ' <a class="button button-primary button-small" href="' . esc_url(self::workspaceTabUrl($quoteId, 'communication') . '#quote-proposal-send-form') . '">' . esc_html__('Voorstel versturen', 'sbdp') . '</a>';
        } else {
            echo '<button type="button" class="button button-secondary button-small" disabled title="' . esc_attr($sendBlockReason !== '' ? $sendBlockReason : __('Nog niet verzendklaar.', 'sbdp')) . '">' . esc_html__('Voorstel versturen', 'sbdp') . '</button>';
            echo '<span class="bsp-qcd__send-disabled-reason">' . esc_html($sendBlockReason !== '' ? sprintf(__('Nog nodig: %s', 'sbdp'), $sendBlockReason) : __('Nog nodig: controleer open punten.', 'sbdp')) . '</span>';
        }
        echo '</div>';
        self::renderQcdProposalInlineEditor($quoteId, $subject, $proposalSummary, $draftBody, $totalLabel, $caveat);
        echo '</div>';
        echo '</section>';
    }

    /**
     * @param array<string, mixed>|null $latestProposal
     * @param array<string, mixed>|null $latestInbound
     * @return array{description:string,steps:array<int,array{label:string,class:string}>}
     */
    private static function qcdMailStatusState(array $communicationState, ?array $latestProposal, ?array $latestInbound): array
    {
        $steps = array(
            array('label' => __('Niet verzonden', 'sbdp'), 'class' => $latestProposal === null ? 'is-current' : 'is-done'),
            array('label' => __('Concept klaar', 'sbdp'), 'class' => ! empty($communicationState['proposal_send_ready']) && $latestProposal === null ? 'is-current' : ($latestProposal !== null ? 'is-done' : '')),
            array('label' => __('Verzonden via WordPress', 'sbdp'), 'class' => $latestProposal !== null ? 'is-done' : ''),
            array('label' => __('SMTP onbekend', 'sbdp'), 'class' => 'is-unknown'),
            array('label' => __('Reactie ontvangen', 'sbdp'), 'class' => $latestInbound !== null ? 'is-current' : ''),
        );

        if ($latestProposal !== null) {
            $sentAt = trim((string) (($latestProposal['sent_at'] ?? '') ?: ($latestProposal['created_at'] ?? '')));
            return array(
                'steps' => $steps,
                'description' => $sentAt !== ''
                    ? sprintf(__('WordPress heeft een verzendpoging geregistreerd op %s. Echte aflevering vraagt SMTP-provider logging, bounce- en replytracking.', 'sbdp'), $sentAt)
                    : __('WordPress heeft een verzendpoging geregistreerd. Echte aflevering vraagt SMTP-provider logging, bounce- en replytracking.', 'sbdp'),
            );
        }

        return array(
            'steps' => $steps,
            'description' => __('Nog niet verzonden. WordPress kan straks alleen de verzendpoging vastleggen; echte aflevering is pas bewijsbaar met SMTP-provider logging, bounce- en replytracking.', 'sbdp'),
        );
    }

    private static function renderQcdProposalInlineEditor(int $quoteId, string $subject, string $intro, string $programText, string $priceRule, string $terms): void
    {
        $ajaxUrl = function_exists('admin_url') ? admin_url('admin-ajax.php') : '';
        $saveNonce = function_exists('wp_create_nonce') ? (string) wp_create_nonce('sbdp_quote_update_proposal_text') : '';
        $aiNonce = function_exists('wp_create_nonce') ? (string) wp_create_nonce('sbdp_quote_suggest_proposal_text') : '';
        $closing = __('Met vriendelijke groet, DagjeDenBosch', 'sbdp');

        echo '<div class="bsp-qcd__proposal-editor-inline">';
        echo '<div class="bsp-qcd__proposal-editor-head"><div><h4>' . esc_html__('Voorsteltekst bewerken', 'sbdp') . '</h4><p class="bsp-quote-admin__muted">' . esc_html__('AI helpt alleen met tekst. Controleer en sla expliciet op; review blijft verplicht.', 'sbdp') . '</p></div>';
        echo '<div class="bsp-qcd__proposal-ai-actions" data-qcd-ai-actions>';
        echo '<button type="button" class="button button-secondary button-small" data-qcd-proposal-ai="generate">' . esc_html__('Genereer klantmail', 'sbdp') . '</button>';
        echo '<button type="button" class="button button-secondary button-small" data-qcd-proposal-ai="warmer">' . esc_html__('Maak warmer', 'sbdp') . '</button>';
        echo '<button type="button" class="button button-secondary button-small" data-qcd-proposal-ai="shorter">' . esc_html__('Korter', 'sbdp') . '</button>';
        echo '<button type="button" class="button button-secondary button-small" data-qcd-proposal-ai="formal">' . esc_html__('Zakelijker', 'sbdp') . '</button>';
        echo '<button type="button" class="button button-secondary button-small" data-qcd-proposal-ai="caveat">' . esc_html__('Voorbehoud', 'sbdp') . '</button>';
        echo '<button type="button" class="button button-secondary button-small" data-qcd-proposal-ai="tone">' . esc_html__('Controleer toon', 'sbdp') . '</button>';
        echo '</div></div>';
        echo '<form class="bsp-qcd__proposal-form" data-qcd-proposal-form data-ajax-url="' . esc_url($ajaxUrl) . '" data-ai-nonce="' . esc_attr($aiNonce) . '">';
        echo '<input type="hidden" name="action" value="sbdp_quote_update_proposal_text">';
        echo '<input type="hidden" name="_ajax_nonce" value="' . esc_attr($saveNonce) . '">';
        echo '<input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
        echo '<div class="bsp-qcd__proposal-editor-grid">';
        echo '<label class="bsp-qcd__proposal-editor-wide">' . esc_html__('Onderwerp', 'sbdp') . '<input type="text" name="subject" value="' . esc_attr($subject) . '" class="regular-text" required></label>';
        echo '<label>' . esc_html__('Intro / korte klanttekst', 'sbdp') . '<textarea name="intro" rows="4" class="large-text">' . esc_textarea($intro) . '</textarea></label>';
        echo '<label>' . esc_html__('Voorbehoud / voorwaardenregel', 'sbdp') . '<textarea name="terms" rows="4" class="large-text">' . esc_textarea($terms) . '</textarea></label>';
        echo '<label class="bsp-qcd__proposal-editor-wide">' . esc_html__('Voorsteltekst / programmatekst', 'sbdp') . '<textarea name="program_text" rows="8" class="large-text" required>' . esc_textarea($programText) . '</textarea></label>';
        echo '<label>' . esc_html__('Prijsregel', 'sbdp') . '<textarea name="price_rule" rows="2" class="large-text">' . esc_textarea($priceRule !== '' ? sprintf(__('Offerteprijs: %s', 'sbdp'), $priceRule) : '') . '</textarea></label>';
        echo '<label>' . esc_html__('Afsluittekst', 'sbdp') . '<textarea name="closing" rows="2" class="large-text">' . esc_textarea($closing) . '</textarea></label>';
        echo '<label>' . esc_html__('Interne notitie', 'sbdp') . '<textarea name="internal_note" rows="2" class="large-text"></textarea></label>';
        echo '</div>';
        echo '<p class="bsp-qcd__proposal-form-message" data-qcd-proposal-message hidden></p>';
        echo '<div class="bsp-qcd__modal-actions"><button type="submit" class="button button-primary">' . esc_html__('Voorsteltekst opslaan', 'sbdp') . '</button></div>';
        echo '</form>';
        echo '</div>';
        self::renderQcdProposalEditorScript();
    }

    private static function renderQcdProposalEditorScript(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;
        echo <<<'HTML'
<script>
(function(){
    if (window.bspQuoteProposalEditorBound) { return; }
    window.bspQuoteProposalEditorBound = true;

    function setMessage(form, text, className) {
        var message = form.querySelector("[data-qcd-proposal-message]");
        if (!message) { return; }
        message.hidden = false;
        message.textContent = text;
        message.className = "bsp-qcd__proposal-form-message" + (className ? " " + className : "");
    }

    function applyDraft(form, data) {
        if (data.subject && form.elements.subject) { form.elements.subject.value = data.subject; }
        if (data.intro && form.elements.intro) { form.elements.intro.value = data.intro; }
        if (data.body && form.elements.program_text) { form.elements.program_text.value = data.body; }
        if (data.priceRule && form.elements.price_rule) { form.elements.price_rule.value = data.priceRule; }
        if (data.terms && form.elements.terms) { form.elements.terms.value = data.terms; }
        if (data.closing && form.elements.closing) { form.elements.closing.value = data.closing; }
    }

    document.addEventListener("click", function(event) {
        var trigger = event.target.closest("[data-qcd-proposal-ai]");
        if (!trigger) { return; }
        var editor = trigger.closest(".bsp-qcd__proposal-editor-inline");
        var form = editor ? editor.querySelector("[data-qcd-proposal-form]") : null;
        if (!form) { return; }
        event.preventDefault();
        var data = new FormData(form);
        data.set("action", "sbdp_quote_suggest_proposal_text");
        data.set("_ajax_nonce", form.getAttribute("data-ai-nonce") || "");
        data.set("mode", trigger.getAttribute("data-qcd-proposal-ai") || "improve");
        trigger.disabled = true;
        setMessage(form, "AI-voorstel ophalen...", "");
        fetch(form.getAttribute("data-ajax-url") || window.ajaxurl, { method: "POST", credentials: "same-origin", body: data })
            .then(function(response){ return response.json(); })
            .then(function(payload){
                if (!payload || !payload.success) {
                    throw new Error(payload && payload.data && payload.data.message ? payload.data.message : "AI-voorstel kon niet worden geladen.");
                }
                applyDraft(form, payload.data || {});
                var aiTerms = payload.data && Array.isArray(payload.data.sanitizerTerms) ? payload.data.sanitizerTerms : [];
                setMessage(form, aiTerms.length ? "Interne systeemtekst gevonden. Pas de klanttekst aan voor verzending." : ((payload.data && payload.data.message) || "AI-voorstel geladen. Controleer en sla expliciet op."), aiTerms.length ? "is-error" : "is-success");
            })
            .catch(function(error){ setMessage(form, error.message, "is-error"); })
            .finally(function(){ trigger.disabled = false; });
    });

    document.addEventListener("submit", function(event) {
        var form = event.target.closest("[data-qcd-proposal-form]");
        if (!form) { return; }
        event.preventDefault();
        setMessage(form, "Opslaan...", "");
        var button = form.querySelector("button[type=submit]");
        if (button) { button.disabled = true; }
        fetch(form.getAttribute("data-ajax-url") || window.ajaxurl, { method: "POST", credentials: "same-origin", body: new FormData(form) })
            .then(function(response){ return response.json(); })
            .then(function(payload){
                if (!payload || !payload.success) {
                    throw new Error(payload && payload.data && payload.data.message ? payload.data.message : "Voorsteltekst kon niet worden opgeslagen.");
                }
                var data = payload.data || {};
                var subject = document.querySelector("[data-qcd-proposal-subject]");
                if (subject) { subject.textContent = data.subject || ""; }
                var body = document.querySelector("[data-qcd-proposal-body]");
                if (body) { body.textContent = data.summary || data.body || ""; }
                var terms = document.querySelector("[data-qcd-proposal-terms]");
                if (terms) { terms.textContent = data.terms || ""; }
                var status = document.querySelector("[data-qcd-proposal-status]");
                if (status) {
                    status.textContent = data.statusLabel || "Niet verzendklaar";
                    status.classList.remove("is-good");
                    status.classList.remove("is-warn");
                    status.classList.add(data.sendReady ? "is-good" : "is-warn");
                }
                var readiness = document.querySelector("[data-qcd-proposal-readiness]");
                if (readiness) {
                    readiness.innerHTML = '<li><span class="bsp-qcd__readiness-icon ' + (data.sendReady ? 'is-good' : 'is-warn') + '">' + (data.sendReady ? '✓' : '!') + '</span><strong>' + (data.readinessTitle || (data.sendReady ? "Voorstelmail klaar" : "Nog niet verzendklaar")) + '</strong><span>' + (data.readinessDescription || (data.sendReady ? "Alle verplichte controles zijn groen." : "Controleer open punten voordat je verzendt.")) + '</span></li>';
                }
                var audit = document.querySelector(".bsp-qcd__audit-list");
                if (audit) {
                    var li = document.createElement("li");
                    li.className = "bsp-qcd__audit-item";
                    li.innerHTML = '<span class="bsp-qcd__audit-time bsp-quote-admin__muted">nu</span><span class="bsp-qcd__audit-type">' + (data.eventType || "quote_proposal_text_updated") + '</span><span class="bsp-qcd__audit-msg bsp-quote-admin__muted">' + (data.eventMessage || "Voorsteltekst bijgewerkt.") + '</span>';
                    audit.prepend(li);
                }
                var sanitizerTerms = Array.isArray(data.sanitizerTerms) ? data.sanitizerTerms : [];
                if (sanitizerTerms.length && readiness) {
                    readiness.innerHTML = '<li><span class="bsp-qcd__readiness-icon is-warn">!</span><strong>Interne systeemtekst gevonden</strong><span>Pas de klanttekst aan voordat je verzendt.</span></li>' + readiness.innerHTML;
                }
                setMessage(form, sanitizerTerms.length ? (data.sanitizerMessage || "Interne systeemtekst gevonden in klantvoorstel.") : (data.message || "Voorsteltekst opgeslagen."), sanitizerTerms.length ? "is-error" : "is-success");
            })
            .catch(function(error){ setMessage(form, error.message, "is-error"); })
            .finally(function(){ if (button) { button.disabled = false; } });
    });
})();
</script>
HTML;
    }

    /**
     * @param array<string, mixed> $sendReadiness
     * @param array<string, array<string, string>> $matrix
     */
    private static function renderQcdReadinessCard(array $sendReadiness, array $matrix): void
    {
        $blockingPoints = array_values(array_filter($matrix, static fn (array $point): bool => in_array((string) ($point['icon'] ?? ''), array('error', 'warn'), true)));
        $sendBlockers = array_values(array_filter((array) ($sendReadiness['blockers'] ?? array()), static fn ($blocker): bool => is_array($blocker)));
        $ready = ! empty($sendReadiness['ready']) && $blockingPoints === array() && $sendBlockers === array();

        echo '<section class="postbox bsp-qcd__info-card bsp-qcd__readiness-card">';
        echo '<div class="bsp-qcd__card-header"><div><h3>' . esc_html__('Verzendcheck', 'sbdp') . '</h3><p>' . esc_html__('Compacte controle vóór verzenden.', 'sbdp') . '</p></div>';
        echo '<span class="bsp-qcd__card-status ' . esc_attr($ready ? 'is-good' : 'is-warn') . '">' . esc_html($ready ? __('Klaar', 'sbdp') : __('Controle nodig', 'sbdp')) . '</span></div>';

        echo '<ul class="bsp-qcd__readiness-list">';
        if ($ready) {
            echo '<li><span class="bsp-qcd__readiness-icon is-good">✓</span><strong>' . esc_html__('Klaar voor verzending', 'sbdp') . '</strong><span>' . esc_html__('Alle verplichte controles zijn groen.', 'sbdp') . '</span></li>';
        } else {
            $items = array();
            foreach ($blockingPoints as $point) {
                $items[] = array(
                    'title' => (string) ($point['label'] ?? __('Controlepunt', 'sbdp')),
                    'description' => (string) ($point['status'] ?? ''),
                );
            }
            foreach ($sendBlockers as $blocker) {
                $code = (string) ($blocker['code'] ?? '');
                $items[] = array(
                    'title' => $code === 'review_not_approved' ? __('Vrijgave nodig', 'sbdp') : __('Nog nodig voor verzenden', 'sbdp'),
                    'description' => (string) ($blocker['message'] ?? __('Controleer open punten.', 'sbdp')),
                );
            }
            foreach (array_slice($items, 0, 5) as $item) {
                echo '<li><span class="bsp-qcd__readiness-icon is-warn">!</span><strong>' . esc_html((string) $item['title']) . '</strong><span>' . esc_html((string) $item['description']) . '</span></li>';
            }
        }
        echo '</ul></section>';
    }

    /**
     * Scope 7 — Messages summary card.
     *
     * @param array<string, mixed>             $communicationState
     * @param array<int, array<string, mixed>> $messages
     */
    private static function renderQcdMessagesCard(int $quoteId, array $communicationState, array $messages): void
    {
        $threadLabel   = (string) ($communicationState['thread_label'] ?? '');
        $proposalLabel = (string) ($communicationState['proposal_label'] ?? '');
        $latestMsg     = $messages !== array() ? end($messages) : null;
        $waitingOnUs   = $threadLabel === __('Waiting on us', 'sbdp');

        $statusDisplay  = $threadLabel !== '' ? $threadLabel : __('No thread yet', 'sbdp');
        $proposalDisplay = $proposalLabel !== '' ? $proposalLabel : __('Nothing sent', 'sbdp');

        $lastMsgDate = '';
        if (is_array($latestMsg)) {
            $lastMsgDate = trim((string) (($latestMsg['sent_at'] ?? '') ?: ($latestMsg['created_at'] ?? '')));
        }
        $subject = is_array($latestMsg) ? (string) ($latestMsg['subject'] ?? __('Bericht zonder onderwerp', 'sbdp')) : '';
        $snippet = is_array($latestMsg)
            ? \wp_trim_words((string) ($latestMsg['body_summary'] ?? $latestMsg['body'] ?? ''), 32)
            : __('Er zijn nog geen voorstelmails of klantreacties vastgelegd.', 'sbdp');

        echo '<section class="bsp-qcd__bottom-row bsp-qcd__messages-row">';
        echo '<div class="bsp-qcd__bottom-row-header">';
        echo '<span class="bsp-qcd__bottom-row-title">' . esc_html__('Berichten & klantreacties', 'sbdp') . '</span>';
        echo '<span class="bsp-qcd__bottom-row-meta">';
        echo '<span class="bsp-qcd__card-status ' . esc_attr($waitingOnUs ? 'is-warn' : 'is-neutral') . '">' . esc_html($statusDisplay) . '</span>';
        echo $lastMsgDate !== '' ? ' · ' . esc_html(sprintf(__('laatste bericht %s', 'sbdp'), $lastMsgDate)) : ' · ' . esc_html__('geen berichten', 'sbdp');
        echo '</span>';
        echo '</div>';
        echo '<div class="bsp-qcd__bottom-row-body">';
        echo '<div class="bsp-qcd__card-grid">';
        echo self::renderSummaryBarItem(__('Status', 'sbdp'), $statusDisplay, $waitingOnUs);
        echo self::renderSummaryBarItem(__('Voorstel', 'sbdp'), $proposalDisplay);
        echo self::renderSummaryBarItem(__('Laatste bericht', 'sbdp'), $lastMsgDate !== '' ? $lastMsgDate : __('Nog geen bericht', 'sbdp'));
        echo '</div><div class="bsp-qcd__message-snippet">';
        if ($subject !== '') {
            echo '<strong>' . esc_html($subject) . '</strong>';
        }
        echo '<p>' . esc_html($snippet) . '</p></div>';
        echo '<div class="bsp-qcd__card-actions">';
        echo '<a class="button button-secondary button-small" href="' . esc_url(self::workspaceTabUrl($quoteId, 'communication')) . '">' . esc_html__('Bekijk alle berichten / reageer', 'sbdp') . '</a>';
        echo '</div>';
        echo '</div>';
        echo '</section>';
    }

    /**
     * Scope 8 — Compact Audit card: last 5 events + link to full audit.
     *
     * @param array<int, array<string, mixed>> $events
     */
    private static function renderQcdAuditCard(int $quoteId, array $events, ?array $currentVersion): void
    {
        $recentEvents = array_slice($events, 0, 5);
        $versionNum   = $currentVersion !== null ? (string) ($currentVersion['version_number'] ?? '1') : '—';
        $lastEvent    = $recentEvents !== array() ? $recentEvents[0] : null;
        $lastEventAt  = is_array($lastEvent) ? trim((string) ($lastEvent['occurred_at'] ?? '')) : '';

        echo '<section class="bsp-qcd__bottom-row bsp-qcd__audit-row">';
        echo '<div class="bsp-qcd__bottom-row-header">';
        echo '<span class="bsp-qcd__bottom-row-title">' . esc_html__('Versies & audit', 'sbdp') . '</span>';
        echo '<span class="bsp-qcd__bottom-row-meta">';
        echo esc_html(sprintf(__('versie #%s', 'sbdp'), $versionNum));
        echo $lastEventAt !== '' ? ' · ' . esc_html(sprintf(__('laatste wijziging %s', 'sbdp'), $lastEventAt)) : '';
        echo '</span>';
        echo '</div>';
        echo '<div class="bsp-qcd__bottom-row-body">';
        if ($recentEvents === array()) {
            echo '<p class="bsp-qcd__empty">' . esc_html__('Nog geen auditregels.', 'sbdp') . '</p>';
        } else {
            echo '<ul class="bsp-qcd__audit-list">';
            foreach ($recentEvents as $event) {
                $occurred = (string) ($event['occurred_at'] ?? '');
                $type     = (string) ($event['event_type'] ?? '');
                $message  = (string) ($event['message'] ?? '');
                echo '<li class="bsp-qcd__audit-item">';
                echo '<span class="bsp-qcd__audit-time bsp-quote-admin__muted">' . esc_html($occurred) . '</span>';
                echo '<span class="bsp-qcd__audit-type">' . esc_html($type) . '</span>';
                if ($message !== '') {
                    echo '<span class="bsp-qcd__audit-msg bsp-quote-admin__muted">' . esc_html($message) . '</span>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '<div class="bsp-qcd__card-actions">';
        echo '<a class="button button-secondary button-small" href="' . esc_url(self::workspaceTabUrl($quoteId, 'history')) . '">' . esc_html__('Toon volledige audit', 'sbdp') . '</a>';
        echo '</div>';
        echo '</div>';
        echo '</section>';
    }

    // ─── End Quote Control Dashboard ──────────────────────────────────────────

    private static function extractRequesterContext(array $request): array
    {
        $requester = isset($request['normalized_payload']['requester']) && is_array($request['normalized_payload']['requester'])
            ? $request['normalized_payload']['requester']
            : array();

        if (! isset($requester['name'])) {
            $requester['name'] = (string) ($request['requester_name'] ?? '');
        }
        if (! isset($requester['email'])) {
            $requester['email'] = (string) ($request['requester_email'] ?? '');
        }
        if (! isset($requester['phone'])) {
            $requester['phone'] = (string) ($request['requester_phone'] ?? '');
        }
        if (! isset($requester['company'])) {
            $requester['company'] = (string) ($request['requester_company'] ?? '');
        }

        return $requester;
    }

    /**
     * @param array<string, mixed> $requester
     */
    private static function formatRequesterAddress(array $requester): string
    {
        $address = isset($requester['address']) && is_array($requester['address']) ? $requester['address'] : array();
        if ($address === array()) {
            return '';
        }

        $cityLine = trim((string) (($address['postcode'] ?? '') . ' ' . ($address['city'] ?? '')));

        return implode(', ', array_filter(array(
            (string) ($address['address_1'] ?? ''),
            (string) ($address['address_2'] ?? ''),
            $cityLine,
            (string) ($address['country'] ?? ''),
        )));
    }
}
