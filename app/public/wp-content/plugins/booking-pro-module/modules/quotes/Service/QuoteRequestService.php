<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteRequestService
{
    public function __construct(private QuoteRepositoryInterface $repository, private QuoteEventLogger $events)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $summary = trim((string) ($input['request_summary'] ?? ''));
        if ($summary === '') {
            throw new InvalidArgumentException('Request summary is required.');
        }

        $sourceType = $this->normalizeString($input['source_type'] ?? 'admin_manual');
        $groupSize  = max(0, (int) ($input['group_size'] ?? 0));
        $normalizedPayload = $this->buildNormalizedPayload($input);

        $request = $this->repository->createQuoteRequest(array(
            'request_reference'        => $this->buildReference('QR'),
            'source_type'              => $sourceType !== '' ? $sourceType : 'admin_manual',
            'status'                   => 'new',
            'request_summary'          => $summary,
            'requester_name'           => trim((string) ($input['requester_name'] ?? '')),
            'requester_email'          => trim((string) ($input['requester_email'] ?? '')),
            'requester_phone'          => trim((string) ($input['requester_phone'] ?? '')),
            'requester_company'        => trim((string) ($input['requester_company'] ?? '')),
            'event_type'               => trim((string) ($input['event_type'] ?? '')),
            'product_type'             => trim((string) ($input['product_type'] ?? '')),
            'group_size'               => $groupSize,
            'preferred_date'           => $this->normalizeDate($input['preferred_date'] ?? null),
            'preferred_start_time'     => $this->normalizeTime($input['preferred_start_time'] ?? null),
            'preferred_end_time'       => $this->normalizeTime($input['preferred_end_time'] ?? null),
            'customer_id'              => $this->normalizeInt($input['customer_id'] ?? null),
            'planner_plan_id'          => $this->normalizeInt($input['planner_plan_id'] ?? null),
            'assigned_user_id'         => $this->normalizeInt($input['assigned_user_id'] ?? null),
            'classification'           => $this->classify($groupSize),
            'complexity_score'         => $this->scoreComplexity($groupSize, $normalizedPayload),
            'pricing_confidence'       => $this->resolvePricingConfidence($normalizedPayload),
            'availability_confidence'  => $this->resolveAvailabilityConfidence($normalizedPayload),
            'review_required'          => 1,
            'source_payload'           => is_array($input['source_payload'] ?? null) ? $input['source_payload'] : array(),
            'normalized_payload'       => $normalizedPayload,
        ));

        $this->events->log(
            'quote_request_created',
            (int) $request['id'],
            null,
            null,
            $this->normalizeInt($input['actor_id'] ?? null),
            'Quote request aangemaakt.',
            array('source_type' => $request['source_type'])
        );

        return $request;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildNormalizedPayload(array $input): array
    {
        $payload = is_array($input['normalized_payload'] ?? null) ? $input['normalized_payload'] : array();
        if (! isset($payload['items']) || ! is_array($payload['items'])) {
            $payload['items'] = $this->extractItems($input);
        }
        if (! isset($payload['requester']) || ! is_array($payload['requester'])) {
            $payload['requester'] = $this->buildRequesterContext($input);
        }
        if (! isset($payload['notes'])) {
            $message = trim((string) ($input['requester_message'] ?? $input['message'] ?? ''));
            if ($message !== '') {
                $payload['notes'] = $message;
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(array $input): array
    {
        $candidates = array();
        foreach (array('items', 'source_payload', 'normalized_payload') as $key) {
            if (isset($input[$key]) && is_array($input[$key])) {
                $candidates[] = $input[$key];
            }
        }

        foreach ($candidates as $candidate) {
            if (isset($candidate[0]) && is_array($candidate[0])) {
                return array_values(array_filter(array_map(array($this, 'normalizeItem'), $candidate)));
            }

            if (isset($candidate['items']) && is_array($candidate['items'])) {
                return array_values(array_filter(array_map(array($this, 'normalizeItem'), $candidate['items'])));
            }

            if (isset($candidate['meta']['planner_items']) && is_array($candidate['meta']['planner_items'])) {
                return array_values(array_filter(array_map(array($this, 'normalizePlannerItem'), $candidate['meta']['planner_items'])));
            }
        }

        return array();
    }

    /**
     * @param mixed $item
     * @return array<string, mixed>
     */
    private function normalizeItem($item): array
    {
        if (! is_array($item)) {
            return array();
        }

        return array(
            'product_id'               => $this->normalizeInt($item['product_id'] ?? null),
            'vendor_id'                => $this->normalizeInt($item['vendor_id'] ?? null),
            'resource_id'              => $this->normalizeInt($item['resource_id'] ?? null),
            'title'                    => trim((string) ($item['title'] ?? '')),
            'selected_option_labels'   => $this->normalizeOptionLabels($item['selected_option_labels'] ?? ($item['selected_option_labels_json'] ?? array())),
            'validated_slot_label'     => $this->normalizeString($item['validated_slot_label'] ?? ($item['slot_label'] ?? '')),
            'service_date'             => $this->normalizeDate($item['date'] ?? ($item['service_date'] ?? null)),
            'start_time'               => $this->normalizeTime($item['start_time'] ?? ($item['start'] ?? null)),
            'end_time'                 => $this->normalizeTime($item['end_time'] ?? ($item['end'] ?? null)),
            'participants'             => max(0, (int) ($item['participants'] ?? $item['people'] ?? 0)),
            'quantity'                 => max(1, (int) ($item['quantity'] ?? 1)),
            'line_total_snapshot'      => isset($item['line_total_snapshot']) ? (float) $item['line_total_snapshot'] : null,
            'unit_amount_snapshot'     => isset($item['unit_amount_snapshot']) ? (float) $item['unit_amount_snapshot'] : null,
            'pricing_confidence'       => trim((string) ($item['pricing_confidence'] ?? 'unknown')),
            'availability_confidence'  => trim((string) ($item['availability_confidence'] ?? 'unknown')),
            'availability_snapshot_json' => isset($item['availability_snapshot_json']) && is_array($item['availability_snapshot_json'])
                ? $item['availability_snapshot_json']
                : array(),
        );
    }

    /**
     * @param mixed $item
     * @return array<string, mixed>
     */
    private function normalizePlannerItem($item): array
    {
        if (! is_array($item)) {
            return array();
        }

        $pricing = isset($item['bookingresolution']['pricing']) && is_array($item['bookingresolution']['pricing'])
            ? $item['bookingresolution']['pricing']
            : array();

        return array(
            'product_id'              => $this->normalizeInt($item['product_id'] ?? ($item['productid'] ?? null)),
            'vendor_id'               => $this->normalizeInt($item['vendor_id'] ?? null),
            'resource_id'             => $this->normalizeInt($item['resource_id'] ?? null),
            'title'                   => trim((string) ($item['title'] ?? '')),
            'selected_option_labels'  => $this->normalizeOptionLabels($item['selected_options'] ?? ($item['selected_option_labels'] ?? array())),
            'validated_slot_label'    => $this->normalizeString($item['validated_slot_label'] ?? ($item['slot_label'] ?? '')),
            'service_date'            => $this->normalizeDate($item['date'] ?? null),
            'start_time'              => $this->normalizeTime($item['starttime'] ?? null),
            'end_time'                => $this->normalizeTime($item['endtime'] ?? null),
            'participants'            => max(0, (int) ($item['participants'] ?? 0)),
            'quantity'                => 1,
            'line_total_snapshot'     => isset($pricing['dynamic']['total']) ? (float) $pricing['dynamic']['total'] : null,
            'unit_amount_snapshot'    => isset($pricing['per_person']) ? (float) $pricing['per_person'] : null,
            'pricing_confidence'      => 'snapshot',
            'availability_confidence' => 'projected',
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildRequesterContext(array $input): array
    {
        $requester = array(
            'name'    => trim((string) ($input['requester_name'] ?? '')),
            'email'   => trim((string) ($input['requester_email'] ?? '')),
            'phone'   => trim((string) ($input['requester_phone'] ?? '')),
            'company' => trim((string) ($input['requester_company'] ?? '')),
        );

        $address = is_array($input['requester_address'] ?? null) ? $input['requester_address'] : array();
        $normalizedAddress = array_filter(array(
            'address_1' => trim((string) ($address['address_1'] ?? '')),
            'address_2' => trim((string) ($address['address_2'] ?? '')),
            'postcode'  => trim((string) ($address['postcode'] ?? '')),
            'city'      => trim((string) ($address['city'] ?? '')),
            'state'     => trim((string) ($address['state'] ?? '')),
            'country'   => trim((string) ($address['country'] ?? '')),
        ), static fn ($value): bool => $value !== '');

        if ($normalizedAddress !== array()) {
            $requester['address'] = $normalizedAddress;
        }

        return array_filter($requester, static function ($value): bool {
            return ! (is_string($value) && $value === '');
        });
    }

    private function buildReference(string $prefix): string
    {
        $timestamp = gmdate('YmdHis');
        $entropy = strtoupper(substr(str_replace('.', '', uniqid('', true)), -8));

        return sprintf('%s-%s-%s', $prefix, $timestamp, $entropy);
    }

    private function normalizeString($value): string
    {
        return trim((string) $value);
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeOptionLabels($value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $labels = array();
        foreach ($value as $candidate) {
            if (is_scalar($candidate) || $candidate === null) {
                $label = trim((string) $candidate);
            } elseif (is_array($candidate)) {
                $label = '';
                foreach (array('label', 'name', 'title', 'value') as $key) {
                    if (! isset($candidate[$key]) || (! is_scalar($candidate[$key]) && $candidate[$key] !== null)) {
                        continue;
                    }

                    $label = trim((string) $candidate[$key]);
                    if ($label !== '') {
                        break;
                    }
                }
            } else {
                $label = '';
            }

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    private function normalizeInt($value): ?int
    {
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    private function normalizeDate($value): ?string
    {
        $date = trim((string) $value);
        if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return $date;
    }

    private function normalizeTime($value): ?string
    {
        $time = trim((string) $value);
        if ($time === '') {
            return null;
        }

        return substr($time, 0, 5);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolvePricingConfidence(array $payload): string
    {
        foreach ((array) ($payload['items'] ?? array()) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $confidence = trim((string) ($item['pricing_confidence'] ?? ''));
            if ($confidence !== '') {
                return $confidence;
            }
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveAvailabilityConfidence(array $payload): string
    {
        foreach ((array) ($payload['items'] ?? array()) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $confidence = trim((string) ($item['availability_confidence'] ?? ''));
            if ($confidence !== '') {
                return $confidence;
            }
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function scoreComplexity(int $groupSize, array $payload): int
    {
        $score = 0;
        if ($groupSize >= 10) {
            $score += 2;
        }

        $score += count((array) ($payload['items'] ?? array()));

        return $score;
    }

    private function classify(int $groupSize): string
    {
        if ($groupSize >= 20) {
            return 'complex';
        }

        if ($groupSize >= 10) {
            return 'review_required';
        }

        return 'standard';
    }
}
