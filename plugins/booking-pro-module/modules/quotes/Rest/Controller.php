<?php

declare(strict_types=1);

namespace BSP\Quotes\Rest;

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteAssumptionService;
use BSP\Quotes\Service\QuoteConversionService;
use BSP\Quotes\Service\QuoteExecutionAdapterService;
use BSP\Quotes\Service\QuoteExecutionLaunchService;
use BSP\Quotes\Service\QuoteExecutionLookupService;
use BSP\Quotes\Service\QuoteExecutionRunnerService;
use BSP\Quotes\Service\QuoteCommunicationService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteFollowupService;
use BSP\Quotes\Service\HandoffValidationException;
use BSP\Quotes\Service\QuoteHandoffAdapterService;
use BSP\Quotes\Service\QuoteHandoffPreparationService;
use BSP\Quotes\Service\QuoteRequestOrderBridgeService;
use BSP\Quotes\Service\QuoteWooCartHydrationService;
use BSP\Quotes\Service\QuoteRequestService;
use BSP\Quotes\Service\QuoteReviewService;
use BSP\Quotes\Service\QuoteSendService;
use BSP\Quotes\Service\WooCartLaunchGateway;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;

final class Controller
{
    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('bsp/v1', '/quote-requests', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'createQuoteRequest'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quote-requests/(?P<id>\d+)/convert', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'convertQuoteRequest'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/(?P<id>\d+)/handoff-ready', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'markHandoffReady'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/(?P<id>\d+)/resnapshot/prepare', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'prepareResnapshot'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/(?P<id>\d+)/handoff/package', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'buildHandoffPackage'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/(?P<id>\d+)/execution/payload', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'buildExecutionPayload'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/(?P<id>\d+)/execution/validate', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'validateExecutionPayload'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/(?P<id>\d+)/execution/launch', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'buildExecutionLaunch'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/(?P<id>\d+)/execution/hydrate-cart', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'hydrateWooCart'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/(?P<id>\d+)/execution/create-request-order', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'createRequestOrder'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/(?P<id>\d+)/review/request', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'requestReview'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/(?P<id>\d+)/review/approve', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'approveReview'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/(?P<id>\d+)/send/mark-manual', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'markSentManual'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/(?P<id>\d+)/send/reopen', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'reopenSend'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/(?P<id>\d+)/followups', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'createFollowup'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quote-followups/(?P<id>\d+)/complete', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'completeFollowup'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quote-followups/(?P<id>\d+)/reschedule', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'rescheduleFollowup'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quote-followups/(?P<id>\d+)/reopen', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'reopenFollowup'),
            'permission_callback' => array(__CLASS__, 'canManageQuotes'),
        ));

        register_rest_route('bsp/v1', '/quotes/messages/inbound', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'ingestInboundMessage'),
            'permission_callback' => array(__CLASS__, 'canIngestInboundQuoteMessages'),
        ));
    }

    public static function createQuoteRequest(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository = new QuoteRepository();
            $events     = new QuoteEventLogger($repository);
            $service    = new QuoteRequestService($repository, $events);

            $payload = $request->get_json_params();
            $payload = is_array($payload) ? $payload : array();
            $payload['actor_id'] = function_exists('get_current_user_id') ? (int) get_current_user_id() : null;

            return $service->create($payload);
        });
    }

    public static function convertQuoteRequest(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository  = new QuoteRepository();
            $events      = new QuoteEventLogger($repository);
            $assumptions = new QuoteAssumptionService($repository, $events);
            $service     = new QuoteConversionService($repository, $assumptions, $events);

            return $service->convertRequestToQuote(
                (int) $request['id'],
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function markHandoffReady(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository  = new QuoteRepository();
            $events      = new QuoteEventLogger($repository);
            $assumptions = new QuoteAssumptionService($repository, $events);
            $service     = new QuoteConversionService($repository, $assumptions, $events);

            return $service->markReadyForResnapshot(
                (int) $request['id'],
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function prepareResnapshot(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository  = new QuoteRepository();
            $events      = new QuoteEventLogger($repository);
            $assumptions = new QuoteAssumptionService($repository, $events);
            $lookup      = new QuoteExecutionLookupService();
            $service     = new QuoteHandoffPreparationService($repository, $assumptions, $events, $lookup);

            return $service->prepareResnapshot(
                (int) $request['id'],
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function buildHandoffPackage(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository  = new QuoteRepository();
            $events      = new QuoteEventLogger($repository);
            $service     = new QuoteHandoffAdapterService($repository, $events);

            return $service->buildControlledPackage(
                (int) $request['id'],
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function buildExecutionPayload(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository  = new QuoteRepository();
            $events      = new QuoteEventLogger($repository);
            $service     = new QuoteExecutionAdapterService($repository, $events);

            return $service->buildCartOrderPrep(
                (int) $request['id'],
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function validateExecutionPayload(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository  = new QuoteRepository();
            $events      = new QuoteEventLogger($repository);
            $lookup      = new QuoteExecutionLookupService();
            $service     = new QuoteExecutionRunnerService($repository, $events, $lookup);

            return $service->validateCartReady(
                (int) $request['id'],
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function buildExecutionLaunch(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository  = new QuoteRepository();
            $events      = new QuoteEventLogger($repository);
            $service     = new QuoteExecutionLaunchService($repository, $events);

            return $service->buildWooCartSessionPrep(
                (int) $request['id'],
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function hydrateWooCart(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository  = new QuoteRepository();
            $events      = new QuoteEventLogger($repository);
            $gateway     = new WooCartLaunchGateway();
            $service     = new QuoteWooCartHydrationService($gateway, $repository, $events);

            $payload = $request->get_json_params();
            $payload = is_array($payload) ? $payload : array();

            return $service->hydrateLaunchToCart(
                (int) $request['id'],
                trim((string) ($payload['launch_token'] ?? '')),
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function createRequestOrder(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository  = new QuoteRepository();
            $events      = new QuoteEventLogger($repository);
            $service     = new QuoteRequestOrderBridgeService($repository, $events);

            $payload = $request->get_json_params();
            $payload = is_array($payload) ? $payload : array();
            $force = ! empty($payload['force']);

            return $service->createWooRequestOrder(
                (int) $request['id'],
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
                $force
            );
        });
    }

    public static function requestReview(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository = new QuoteRepository();
            $events     = new QuoteEventLogger($repository);
            $followups  = new QuoteFollowupService($repository, $events);
            $service    = new QuoteReviewService($repository, $events, $followups);

            return $service->requestReview(
                (int) $request['id'],
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function approveReview(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository = new QuoteRepository();
            $events     = new QuoteEventLogger($repository);
            $followups  = new QuoteFollowupService($repository, $events);
            $service    = new QuoteReviewService($repository, $events, $followups);

            return $service->approve(
                (int) $request['id'],
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function createFollowup(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository = new QuoteRepository();
            $events     = new QuoteEventLogger($repository);
            $service    = new QuoteFollowupService($repository, $events);

            $payload = $request->get_json_params();
            $payload = is_array($payload) ? $payload : array();
            $payload['quote_id'] = (int) $request['id'];
            $payload['actor_id'] = function_exists('get_current_user_id') ? (int) get_current_user_id() : null;

            return $service->create($payload);
        });
    }

    public static function markSentManual(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository = new QuoteRepository();
            $events     = new QuoteEventLogger($repository);
            $service    = new QuoteSendService($repository, $events);

            $payload = $request->get_json_params();
            $payload = is_array($payload) ? $payload : array();

            return $service->markSentManual(
                (int) $request['id'],
                trim((string) ($payload['channel'] ?? 'manual')),
                trim((string) ($payload['note'] ?? '')),
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function reopenSend(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository = new QuoteRepository();
            $events     = new QuoteEventLogger($repository);
            $service    = new QuoteSendService($repository, $events);

            $payload = $request->get_json_params();
            $payload = is_array($payload) ? $payload : array();

            return $service->reopenSend(
                (int) $request['id'],
                trim((string) ($payload['note'] ?? '')),
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function completeFollowup(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository = new QuoteRepository();
            $events     = new QuoteEventLogger($repository);
            $service    = new QuoteFollowupService($repository, $events);

            return $service->complete(
                (int) $request['id'],
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function rescheduleFollowup(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository = new QuoteRepository();
            $service = new QuoteFollowupService($repository, new QuoteEventLogger($repository));
            $payload = $request->get_json_params();

            return $service->reschedule(
                (int) $request['id'],
                is_array($payload) ? $payload : array(),
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function reopenFollowup(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository = new QuoteRepository();
            $service = new QuoteFollowupService($repository, new QuoteEventLogger($repository));
            $payload = $request->get_json_params();

            return $service->reopen(
                (int) $request['id'],
                is_array($payload) ? $payload : array(),
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function ingestInboundMessage(WP_REST_Request $request)
    {
        return self::wrap(static function () use ($request): array {
            $repository = new QuoteRepository();
            $events     = new QuoteEventLogger($repository);
            $service    = new QuoteCommunicationService($repository, $events);

            $payload = self::extractInboundPayload($request);

            return $service->ingestInboundMessage(
                $payload,
                function_exists('get_current_user_id') ? (int) get_current_user_id() : null
            );
        });
    }

    public static function canManageQuotes(): bool
    {
        return function_exists('current_user_can')
            && (current_user_can('manage_woocommerce') || current_user_can('manage_options'));
    }

    public static function canIngestInboundQuoteMessages(WP_REST_Request $request): bool
    {
        $configuredSecret = function_exists('apply_filters')
            ? trim((string) apply_filters('bsp/quotes/inbound_secret', ''))
            : '';

        if ($configuredSecret === '') {
            return self::canManageQuotes();
        }

        $providedSecret = trim((string) ($request->get_header('x-bsp-quote-inbound-secret') ?: $request->get_param('secret')));

        return $providedSecret !== '' && hash_equals($configuredSecret, $providedSecret);
    }

    private static function wrap(callable $callback)
    {
        try {
            $result = $callback();
            return function_exists('rest_ensure_response') ? rest_ensure_response($result) : $result;
        } catch (HandoffValidationException $exception) {
            $error = new WP_Error(
                $exception->restCode(),
                $exception->getMessage(),
                array(
                    'status' => $exception->status(),
                    'details' => $exception->context(),
                )
            );
            return function_exists('rest_ensure_response') ? rest_ensure_response($error) : $error;
        } catch (InvalidArgumentException $exception) {
            $error = new WP_Error('bsp_quotes_invalid', $exception->getMessage(), array('status' => 400));
            return function_exists('rest_ensure_response') ? rest_ensure_response($error) : $error;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractInboundPayload(WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();
        if (is_array($payload) && $payload !== array()) {
            return $payload;
        }

        if (method_exists($request, 'get_params')) {
            $params = $request->get_params();
            if (is_array($params) && $params !== array()) {
                return $params;
            }
        }

        $payload = array();
        foreach (array(
            'message_id',
            'provider_message_id',
            'subject',
            'body',
            'text',
            'html',
            'from',
            'from_name',
            'from_email',
            'to',
            'to_email',
            'recipient',
            'in_reply_to',
            'references',
            'headers',
            'received_at',
            'MessageID',
            'TextBody',
            'HtmlBody',
            'From',
            'To',
            'Headers',
            'OriginalRecipient',
            'Message-Id',
            'body-plain',
            'stripped-text',
            'sender',
        ) as $key) {
            $value = $request->get_param($key);
            if ($value === null || $value === '') {
                continue;
            }
            $payload[$key] = $value;
        }

        return $payload;
    }
}
