<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteFollowupService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InMemoryQuoteRepository.php';
require_once dirname(__DIR__, 2) . '/modules/quotes/Service/QuoteEventLogger.php';
require_once dirname(__DIR__, 2) . '/modules/quotes/Service/QuoteFollowupService.php';

final class QuoteFollowupOperationsTest extends TestCase
{
    private InMemoryQuoteRepository $repository;
    private QuoteFollowupService $service;
    private array $quote;

    protected function setUp(): void
    {
        $this->repository = new InMemoryQuoteRepository();
        $this->service = new QuoteFollowupService($this->repository, new QuoteEventLogger($this->repository));
        $this->quote = $this->repository->createQuote(array(
            'quote_reference' => 'Q-OPS-1',
            'quote_request_id' => 10,
            'status' => 'draft',
        ));
    }

    public function testInitialReviewTaskIsIdempotentWhileOpen(): void
    {
        $first = $this->service->createInitialReviewFollowup($this->quote, 4);
        $second = $this->service->createInitialReviewFollowup($this->quote, 4);

        self::assertSame($first['id'], $second['id']);
        self::assertTrue($second['idempotent_replay']);
        self::assertCount(1, $this->repository->listQuoteFollowups((int) $this->quote['id']));
    }

    public function testCompletingTaskTwiceIsIdempotent(): void
    {
        $task = $this->task();
        $this->service->complete((int) $task['id'], 4);
        $replay = $this->service->complete((int) $task['id'], 4);

        self::assertTrue($replay['idempotent_replay']);
        self::assertCount(1, array_filter(
            $this->repository->listQuoteEvents((int) $this->quote['id']),
            static fn (array $event): bool => ($event['event_type'] ?? '') === 'quote_followup_completed'
        ));
    }

    public function testOpenTaskCanBeRescheduledAndAssigned(): void
    {
        $task = $this->task();
        $updated = $this->service->reschedule((int) $task['id'], array(
            'due_at' => '2026-08-01 12:30:00',
            'priority' => 'urgent',
            'assigned_user_id' => 22,
        ), 4);

        self::assertSame(gmdate('Y-m-d H:i:s', (int) strtotime('2026-08-01 12:30:00')), $updated['due_at']);
        self::assertSame('urgent', $updated['priority']);
        self::assertSame(22, $updated['assigned_user_id']);
    }

    public function testCompletedTaskCanBeReopenedWithoutLosingOwnership(): void
    {
        $task = $this->task();
        $this->service->complete((int) $task['id'], 4);
        $reopened = $this->service->reopen((int) $task['id'], array('due_at' => '2026-08-02 09:00:00'), 7);

        self::assertSame('open', $reopened['status']);
        self::assertNull($reopened['completed_at']);
        self::assertSame(8, $reopened['assigned_user_id']);
    }

    public function testInvalidPriorityNormalizesAndCompletedTaskCannotBeRescheduled(): void
    {
        $task = $this->task('nonsense');
        self::assertSame('normal', $task['priority']);
        $this->service->complete((int) $task['id'], 4);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->reschedule((int) $task['id'], array('due_at' => '2026-08-03 10:00:00'), 4);
    }

    private function task(string $priority = 'high'): array
    {
        return $this->service->create(array(
            'quote_id' => (int) $this->quote['id'],
            'title' => 'Bel leverancier',
            'followup_type' => 'supplier_confirmation',
            'priority' => $priority,
            'assigned_user_id' => 8,
        ));
    }
}
