<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

final class PlannerQuoteIntakeService
{
    private PlannerQuoteSummaryService $summary;

    public function __construct(
        private QuoteRequestService $requests,
        private QuoteConversionService $conversion,
        private QuoteFollowupService $followups,
        ?PlannerQuoteSummaryService $summary = null
    ) {
        $this->summary = $summary ?? new PlannerQuoteSummaryService();
    }

    /**
     * @param array<string, mixed> $planPayload
     * @param array<string, mixed> $contact
     * @return array{request: array<string, mixed>, quote: array<string, mixed>}
     */
    public function createFromPlannerPlan(int $planId, array $planPayload, array $contact, ?int $actorId = null): array
    {
        $normalizedPayload = array(
            'items' => $this->summary->buildNormalizedItems($planPayload),
            'totals' => $this->summary->buildNormalizedTotals($planPayload),
        );

        $request = $this->requests->create(array(
            'source_type'        => 'planner_offerte_form',
            'request_summary'    => $this->buildSummary($planPayload, $contact),
            'requester_name'     => trim((string) ($contact['name'] ?? '')),
            'requester_email'    => trim((string) ($contact['email'] ?? '')),
            'requester_phone'    => trim((string) ($contact['phone'] ?? '')),
            'requester_company'  => trim((string) ($contact['company'] ?? '')),
            'requester_address'  => is_array($contact['address'] ?? null) ? $contact['address'] : array(),
            'requester_message'  => trim((string) ($contact['message'] ?? '')),
            'group_size'         => $this->resolveGroupSize($planPayload),
            'preferred_date'     => $this->resolveDate($planPayload),
            'preferred_start_time' => $this->resolveTime($planPayload, 'start'),
            'preferred_end_time' => $this->resolveTime($planPayload, 'end'),
            'planner_plan_id'    => $planId,
            'product_type'       => 'planner_day',
            'source_payload'     => $planPayload,
            'normalized_payload' => $normalizedPayload,
            'actor_id'           => $actorId,
        ));

        $quote = $this->conversion->convertRequestToQuote((int) $request['id'], $actorId);
        $this->followups->createInitialReviewFollowup($quote, $actorId);

        return array(
            'request' => $request,
            'quote'   => $quote,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildSummary(array $payload, array $contact): string
    {
        $parts = array();

        $title = trim((string) ($payload['title'] ?? 'Dagplanning'));
        if ($title !== '') {
            $parts[] = $title;
        }

        $participants = $this->resolveGroupSize($payload);
        if ($participants > 0) {
            $parts[] = sprintf('%d personen', $participants);
        }

        $date = $this->resolveDate($payload);
        if ($date !== null) {
            $parts[] = $date;
        }

        $message = trim((string) ($contact['message'] ?? ''));
        if ($message !== '') {
            $parts[] = $message;
        }

        return implode(' | ', $parts);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveGroupSize(array $payload): int
    {
        $count = (int) ($payload['meta']['participant_count'] ?? 0);
        if ($count > 0) {
            return $count;
        }

        $plannerItems = $payload['meta']['planner_items'] ?? array();
        if (is_array($plannerItems)) {
            foreach ($plannerItems as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $participants = (int) ($item['participants'] ?? 0);
                if ($participants > 0) {
                    return $participants;
                }
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveDate(array $payload): ?string
    {
        $days = $payload['days'] ?? array();
        if (! is_array($days) || ! isset($days[0]) || ! is_array($days[0])) {
            return null;
        }

        $date = trim((string) ($days[0]['date'] ?? ''));
        return $date !== '' ? $date : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveTime(array $payload, string $edge): ?string
    {
        $plannerItems = $payload['meta']['planner_items'] ?? array();
        if (! is_array($plannerItems)) {
            return null;
        }

        foreach ($plannerItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $edge === 'end' ? 'endtime' : 'starttime';
            $time = trim((string) ($item[$key] ?? ''));
            if ($time !== '') {
                return substr($time, 0, 5);
            }
        }

        return null;
    }
}
