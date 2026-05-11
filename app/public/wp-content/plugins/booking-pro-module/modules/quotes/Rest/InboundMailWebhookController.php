<?php

declare(strict_types=1);

namespace BSP\Quotes\Rest;

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteRequestService;
use WP_Error;
use WP_REST_Request;

final class InboundMailWebhookController
{
    private const ALLOWED_RECIPIENTS = array(
        'info@dagjedenbosch.nl',
        'aanvragen@dagjedenbosch.nl',
        'inkoop@dagjedenbosch.nl',
    );

    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('bsp/v1', '/inbound-mail', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function handle(WP_REST_Request $request)
    {
        $configuredSecret = trim((string) get_option('bsp_inbound_mail_secret', ''));
        $providedSecret = trim((string) $request->get_header('X-BSP-Mail-Secret'));

        if ($configuredSecret === '' || $providedSecret === '' || ! hash_equals($configuredSecret, $providedSecret)) {
            return new WP_Error('forbidden', 'forbidden', array('status' => 403));
        }

        $toEmail = strtolower(trim((string) $request->get_param('to_email')));
        if (! in_array($toEmail, self::ALLOWED_RECIPIENTS, true)) {
            return new WP_Error('invalid_recipient', 'invalid_recipient', array('status' => 422));
        }

        $fromEmail = strtolower(trim((string) $request->get_param('from_email')));
        $fromName = trim((string) $request->get_param('from_name'));
        $subject = trim((string) $request->get_param('subject'));
        $body = trim((string) $request->get_param('body'));
        $providerMessageId = trim((string) $request->get_param('provider_message_id'));
        $inReplyToMessageId = trim((string) $request->get_param('in_reply_to_message_id'));

        if ($fromEmail === '') {
            return new WP_Error('missing_from_email', 'missing_from_email', array('status' => 422));
        }
        if ($subject === '') {
            return new WP_Error('missing_subject', 'missing_subject', array('status' => 422));
        }
        if ($body === '') {
            return new WP_Error('missing_body', 'missing_body', array('status' => 422));
        }

        $quoteReference = self::extractQuoteReference($subject);
        $repository = new QuoteRepository();

        if ($quoteReference !== null) {
            $quote = $repository->findQuoteByReference($quoteReference);
            if ($quote !== null) {
                $message = $repository->createQuoteMessage(array(
                    'quote_id'               => (int) ($quote['id'] ?? 0),
                    'quote_version_id'       => isset($quote['current_version_id']) && (int) $quote['current_version_id'] > 0 ? (int) $quote['current_version_id'] : null,
                    'direction'              => 'inbound',
                    'message_type'           => 'client_reply',
                    'channel'                => 'email',
                    'status'                 => 'received',
                    'subject'                => $subject,
                    'body'                   => $body,
                    'from_name'              => $fromName,
                    'from_email'             => $fromEmail,
                    'to_email'               => $toEmail,
                    'provider_message_id'    => $providerMessageId !== '' ? $providerMessageId : null,
                    'in_reply_to_message_id' => $inReplyToMessageId !== '' ? $inReplyToMessageId : null,
                    'received_at'            => self::currentUtcDateTime(),
                ));

                do_action('bsp/quotes/ai/inbound_received', (int) $quote['id'], (int) ($message['id'] ?? 0), $toEmail);

                return self::response(array(
                    'matched'    => true,
                    'quote_id'   => (int) $quote['id'],
                    'message_id' => (int) ($message['id'] ?? 0),
                ), 201);
            }
        }

        if ($toEmail === 'aanvragen@dagjedenbosch.nl') {
            $events = new QuoteEventLogger($repository);
            $service = new QuoteRequestService($repository, $events);
            $quoteRequest = $service->create(array(
                'source_type'     => 'email_inbound',
                'request_summary' => $subject . "\n\n" . mb_substr($body, 0, 500),
                'requester_name'  => $fromName,
                'requester_email' => $fromEmail,
                'source_payload'  => array(
                    'raw_subject'         => $subject,
                    'raw_body'            => $body,
                    'provider_message_id' => $providerMessageId,
                ),
            ));

            return self::response(array(
                'matched'          => false,
                'quote_request_id' => (int) ($quoteRequest['id'] ?? 0),
                'action'           => 'created_request',
            ), 201);
        }

        $failure = $repository->createQuoteMessageFailure(array(
            'direction'               => 'inbound',
            'channel'                 => 'email',
            'failure_reason'          => 'unmatched_quote',
            'subject'                 => $subject,
            'from_name'               => $fromName,
            'from_email'              => $fromEmail,
            'to_email'                => $toEmail,
            'body'                    => $body,
            'provider_message_id'     => $providerMessageId !== '' ? $providerMessageId : null,
            'in_reply_to_message_id'  => $inReplyToMessageId !== '' ? $inReplyToMessageId : null,
            'guessed_quote_reference' => $quoteReference,
            'status'                  => 'open',
            'payload_json'            => $request->get_params(),
        ));

        return self::response(array(
            'matched'    => false,
            'failure_id' => (int) ($failure['id'] ?? 0),
            'action'     => 'logged_failure',
        ), 202);
    }

    private static function extractQuoteReference(string $subject): ?string
    {
        if (preg_match('/\b(Q-[A-Z0-9\-]+)\b/i', $subject, $matches) !== 1) {
            return null;
        }

        return strtoupper(trim((string) $matches[1]));
    }

    private static function currentUtcDateTime(): string
    {
        return function_exists('current_time')
            ? (string) current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $payload
     * @return mixed
     */
    private static function response(array $payload, int $status)
    {
        if (class_exists('WP_REST_Response')) {
            return new \WP_REST_Response($payload, $status);
        }

        $payload['status'] = $status;

        return $payload;
    }
}
