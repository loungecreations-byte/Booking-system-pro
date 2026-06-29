<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use PHPUnit\Framework\TestCase;
use SBDP_Private_Tours_Tickets;
use WP_Error;

require_once dirname(__DIR__, 2) . '/includes/class-sbdp-private-tours-tickets.php';

final class PrivateTourTicketAccessTest extends TestCase
{
    private string $table = 'wp_sbdp_private_tour_tickets';

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->storage[$this->table] = array();
        $GLOBALS['__test_transients'] = array();
        remove_all_filters('sbdp/private_tours/access_ttl');
        remove_all_filters('sbdp/private_tours/ticket_expiry_days');
    }

    public function testCreateTicketsCreatesOnePersonalTokenPerQuantity(): void
    {
        $tickets = SBDP_Private_Tours_Tickets::create_tickets(5, 9001, 7001, 'buyer@example.test', 10);

        $this->assertCount(10, $tickets);
        $this->assertCount(10, array_unique(array_column($tickets, 'token')));
        $this->assertCount(10, $GLOBALS['wpdb']->storage[$this->table]);
    }

    public function testTicketUrlContainsBearerTokenForPortalPrefill(): void
    {
        $url = SBDP_Private_Tours_Tickets::ticket_url('abcDEF123_-');

        $this->assertSame('https://example.test/private-tour-portal/?ticket=abcDEF123_-', $url);
    }

    public function testAccessWindowStartsOnFirstValidation(): void
    {
        add_filter('sbdp/private_tours/access_ttl', static fn (): int => 24 * HOUR_IN_SECONDS);

        $ticket = $this->insertTicket(array(
            'tour_id' => 5,
            'order_id' => 9001,
            'order_item_id' => 7001,
            'email' => 'buyer@example.test',
            'token' => 'token-1',
            'status' => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));

        $activated = SBDP_Private_Tours_Tickets::ensure_access_window((int) $ticket['id'], $ticket);

        $this->assertIsArray($activated);
        $this->assertNotEmpty($activated['activated_at']);
        $this->assertNotEmpty($activated['access_expires_at']);
        $this->assertGreaterThan(23 * HOUR_IN_SECONDS, SBDP_Private_Tours_Tickets::access_remaining_seconds($activated));
    }

    public function testExpiredAccessWindowRejectsTicket(): void
    {
        $ticket = $this->insertTicket(array(
            'tour_id' => 5,
            'order_id' => 9001,
            'order_item_id' => 7001,
            'email' => 'buyer@example.test',
            'token' => 'token-2',
            'status' => 'active',
            'created_at' => gmdate('Y-m-d H:i:s', time() - (3 * DAY_IN_SECONDS)),
            'activated_at' => gmdate('Y-m-d H:i:s', time() - (2 * DAY_IN_SECONDS)),
            'access_expires_at' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
        ));

        $result = SBDP_Private_Tours_Tickets::ensure_access_window((int) $ticket['id'], $ticket);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('sbdp_ticket_access_expired', $result->code);
    }

    public function testSessionTtlNeverExceedsRemainingTicketAccess(): void
    {
        $ticket = $this->insertTicket(array(
            'tour_id' => 5,
            'order_id' => 9001,
            'order_item_id' => 7001,
            'email' => 'buyer@example.test',
            'token' => 'token-3',
            'status' => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'activated_at' => gmdate('Y-m-d H:i:s'),
            'access_expires_at' => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS),
        ));

        $session = SBDP_Private_Tours_Tickets::create_session((int) $ticket['id'], SBDP_Private_Tours_Tickets::access_remaining_seconds($ticket));

        $this->assertNotSame('', $session);
        $this->assertLessThanOrEqual(HOUR_IN_SECONDS, $GLOBALS['__test_transients']['sbdp_private_session_' . $session]['expiration']);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function insertTicket(array $data): array
    {
        $defaults = array(
            'tour_id' => 5,
            'order_id' => 0,
            'order_item_id' => 0,
            'email' => '',
            'token' => 'token',
            'status' => 'active',
            'issued_to' => '',
            'progress' => null,
            'session_token' => '',
            'session_expires_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'redeemed_at' => null,
            'activated_at' => null,
            'access_expires_at' => null,
            'expires_at' => null,
        );

        $row = array_merge($defaults, $data);
        $GLOBALS['wpdb']->insert($this->table, $row);

        return $GLOBALS['wpdb']->storage[$this->table][array_key_last($GLOBALS['wpdb']->storage[$this->table])];
    }
}
