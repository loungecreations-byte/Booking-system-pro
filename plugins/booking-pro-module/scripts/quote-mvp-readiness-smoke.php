<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$php = PHP_BINARY ?: 'php';

$checks = array(
    array(
        'label' => 'Quote renderer syntax',
        'command' => array($php, '-l', $root . '/modules/quotes/Admin/QuoteWorkspaceRenderer.php'),
    ),
    array(
        'label' => 'Quote readiness PHPUnit',
        'command' => array(
            $php,
            $root . '/vendor/bin/phpunit',
            '--configuration',
            $root . '/tests/phpunit.xml.dist',
            $root . '/tests/quotes/QuoteCommunicationReadinessUiTest.php',
            $root . '/tests/quotes/QuoteSendReadinessValidatorTest.php',
            $root . '/tests/quotes/QuoteModuleTest.php',
        ),
    ),
    array(
        'label' => 'Proposal send decision',
        'command' => array($php, $root . '/scripts/quote-proposal-send-decision-smoke.php'),
    ),
    array(
        'label' => 'Full MVP chain',
        'command' => array($php, $root . '/scripts/quote-full-mvp-chain-smoke.php'),
    ),
    array(
        'label' => 'Payment completion idempotency',
        'command' => array($php, $root . '/scripts/quote-payment-complete-smoke.php'),
    ),
    array(
        'label' => 'Booking bridge',
        'command' => array($php, $root . '/scripts/quote-booking-bridge-smoke.php'),
    ),
    array(
        'label' => 'Confirmation readiness',
        'command' => array($php, $root . '/scripts/quote-confirmation-readiness-smoke.php'),
    ),
    array(
        'label' => 'Quote confirmation',
        'command' => array($php, $root . '/scripts/quote-confirmation-smoke.php'),
    ),
);

$results = array();

foreach ($checks as $check) {
    $command = implode(' ', array_map('escapeshellarg', $check['command']));
    echo '==> ' . $check['label'] . PHP_EOL;
    passthru($command, $exitCode);

    $results[] = array(
        'label' => $check['label'],
        'ok' => $exitCode === 0,
    );

    if ($exitCode !== 0) {
        echo json_encode(array('ok' => false, 'failed' => $check['label'], 'results' => $results), JSON_PRETTY_PRINT) . PHP_EOL;
        exit($exitCode);
    }
}

echo json_encode(array(
    'ok' => true,
    'results' => $results,
    'staging_required' => array(
        'real_smtp_delivery',
        'mollie_test_webhook',
        'public_proposal_url_from_external_browser',
        'ticket_email_delivery',
    ),
), JSON_PRETTY_PRINT) . PHP_EOL;
