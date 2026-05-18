<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

final class PlannerQuoteSummaryService
{
    public function __construct(private ?QuoteExecutionLookupService $lookup = null)
    {
        $this->lookup = $this->lookup ?? new QuoteExecutionLookupService();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     date: string,
     *     participants: int,
     *     currency: string,
     *     total: float,
     *     items: array<int, array<string, mixed>>
     * }
     */
    public function buildViewModel(array $payload): array
    {
        $participants = $this->resolveParticipants($payload);
        $date         = $this->resolveDate($payload);
        $items        = $this->extractItems($payload, $participants, $date);
        $currency     = 'EUR';
        $total        = 0.0;

        foreach ($items as $item) {
            $total += (float) ($item['line_total'] ?? 0.0);
            $itemCurrency = trim((string) ($item['currency'] ?? ''));
            if ($itemCurrency !== '') {
                $currency = $itemCurrency;
            }
        }

        return array(
            'date'         => $date,
            'participants' => $participants,
            'currency'     => $currency,
            'total'        => round($total, 2),
            'items'        => $items,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public function buildNormalizedItems(array $payload): array
    {
        $summary = $this->buildViewModel($payload);
        $items   = array();

        foreach ($summary['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = array(
                'product_id'              => (int) ($item['product_id'] ?? 0),
                'vendor_id'               => null,
                'resource_id'             => isset($item['resource_id']) ? (int) $item['resource_id'] : null,
                'title'                   => (string) ($item['title'] ?? ''),
                'selected_option_labels_json' => isset($item['selected_option_labels']) && is_array($item['selected_option_labels'])
                    ? array_values($item['selected_option_labels'])
                    : array(),
                'validated_slot_label'    => (string) ($item['validated_slot_label'] ?? ''),
                'service_date'            => (string) ($item['service_date'] ?? ''),
                'start_time'              => (string) ($item['start_time'] ?? ''),
                'end_time'                => (string) ($item['end_time'] ?? ''),
                'participants'            => (int) ($item['participants'] ?? 0),
                'quantity'                => (int) ($item['quantity'] ?? 1),
                'line_total_snapshot'     => round((float) ($item['line_total'] ?? 0.0), 2),
                'unit_amount_snapshot'    => round((float) ($item['unit_price'] ?? 0.0), 2),
                'pricing_confidence'      => (string) ($item['pricing_confidence'] ?? 'snapshot'),
                'availability_confidence' => (string) ($item['availability_confidence'] ?? 'projected'),
                'pricing_basis'           => (string) ($item['pricing_basis'] ?? 'group'),
                'pricing_basis_label'     => (string) ($item['pricing_basis_label'] ?? 'groepsprijs'),
                'currency'                => (string) ($item['currency'] ?? 'EUR'),
            );
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildNormalizedTotals(array $payload): array
    {
        $summary = $this->buildViewModel($payload);

        return array(
            'currency' => (string) $summary['currency'],
            'participants' => (int) $summary['participants'],
            'line_total_snapshot_sum' => round((float) $summary['total'], 2),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(array $payload, int $defaultParticipants, string $fallbackDate): array
    {
        $items        = array();
        $plannerItems = $payload['meta']['planner_items'] ?? null;
        $slotQueues   = $this->buildSlotQueues($payload);

        if (is_array($plannerItems) && $plannerItems !== array()) {
            foreach ($plannerItems as $plannerItem) {
                if (! is_array($plannerItem)) {
                    continue;
                }

                $item = $this->buildLineItem($plannerItem, $defaultParticipants, $fallbackDate);
                if ($item !== array()) {
                    $items[] = $item;
                }
            }

            return $this->finalizeItems($items, $slotQueues);
        }

        $days = $payload['days'] ?? array();
        if (! is_array($days)) {
            return array();
        }

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }

            $dayDate = isset($day['date']) ? trim((string) $day['date']) : $fallbackDate;
            foreach ((array) ($day['slots'] ?? array()) as $slot) {
                if (! is_array($slot)) {
                    continue;
                }

                $item = $this->buildLineItem($slot, $defaultParticipants, $dayDate);
                if ($item !== array()) {
                    $items[] = $item;
                }
            }
        }

        return $this->finalizeItems($items, $slotQueues);
    }

    /**
     * @param array<string, mixed> $rawItem
     * @return array<string, mixed>
     */
    private function buildLineItem(array $rawItem, int $defaultParticipants, string $fallbackDate): array
    {
        $productId = $this->toInt(
            $rawItem['product_id'] ?? ($rawItem['productId'] ?? ($rawItem['id'] ?? 0))
        );
        $title = $this->normalizeTitle((string) ($rawItem['title'] ?? ($rawItem['name'] ?? '')));

        if ($productId <= 0 && $title === '') {
            return array();
        }

        $participants = max(
            1,
            $this->toInt($rawItem['participants'] ?? ($rawItem['people'] ?? $defaultParticipants))
        );
        $serviceDate = $this->firstNonEmptyString(array($rawItem['date'] ?? null, $fallbackDate));
        $startTime   = $this->extractTime($this->firstNonEmptyString(array(
            $rawItem['start_time'] ?? null,
            $rawItem['startTime'] ?? null,
            $rawItem['starttime'] ?? null,
            $rawItem['start'] ?? null,
        )));
        $endTime     = $this->extractTime($this->firstNonEmptyString(array(
            $rawItem['end_time'] ?? null,
            $rawItem['endTime'] ?? null,
            $rawItem['endtime'] ?? null,
            $rawItem['end'] ?? null,
        )));
        $resourceId  = $this->toNullableInt($rawItem['resource_id'] ?? ($rawItem['resourceId'] ?? null));

        $line = array(
            'product_id'                   => $productId,
            'resource_id'                  => $resourceId,
            'title'                        => $title,
            'selected_option_labels'       => $this->extractSelectedOptionLabels($rawItem),
            'validated_slot_label'         => $this->extractValidatedSlotLabel($rawItem, $serviceDate, $startTime, $endTime),
            'service_date'                 => $serviceDate,
            'start_time'                   => $startTime,
            'end_time'                     => $endTime,
            'participants'                 => $participants,
            'quantity'                     => 1,
        );

        $pricing = $this->resolvePricing($line, $rawItem);
        return array_merge($line, $pricing, array(
            'display_price_label' => $this->resolveDisplayPriceLabel($rawItem, $pricing),
        ));
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $slotQueues
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function finalizeItems(array $items, array $slotQueues): array
    {
        $enriched = $this->enrichItemsWithSlotData($items, $slotQueues);

        foreach ($enriched as $index => &$item) {
            $item['__sort_index'] = $index;
        }
        unset($item);

        usort($enriched, function (array $left, array $right): int {
            $leftDate  = (string) ($left['service_date'] ?? '');
            $rightDate = (string) ($right['service_date'] ?? '');
            if ($leftDate !== $rightDate) {
                return strcmp($leftDate, $rightDate);
            }

            $leftStart  = (string) ($left['start_time'] ?? '');
            $rightStart = (string) ($right['start_time'] ?? '');
            if ($leftStart !== '' && $rightStart !== '' && $leftStart !== $rightStart) {
                return strcmp($leftStart, $rightStart);
            }

            if ($leftStart !== $rightStart) {
                return $leftStart === '' ? 1 : -1;
            }

            $leftTitle  = (string) ($left['title'] ?? '');
            $rightTitle = (string) ($right['title'] ?? '');
            if ($leftTitle !== $rightTitle) {
                return strcmp($leftTitle, $rightTitle);
            }

            return ((int) ($left['__sort_index'] ?? 0)) <=> ((int) ($right['__sort_index'] ?? 0));
        });

        foreach ($enriched as &$item) {
            unset($item['__sort_index']);
        }
        unset($item);

        return $enriched;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function buildSlotQueues(array $payload): array
    {
        $queues = array();
        $days = $payload['days'] ?? array();
        if (! is_array($days)) {
            return $queues;
        }

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }

            $serviceDate = trim((string) ($day['date'] ?? ''));
            foreach ((array) ($day['slots'] ?? array()) as $slot) {
                if (! is_array($slot)) {
                    continue;
                }

                $productId = $this->toInt($slot['product_id'] ?? ($slot['productId'] ?? 0));
                if ($productId <= 0) {
                    continue;
                }

                $startTime = $this->extractTime((string) ($slot['start_time'] ?? ($slot['startTime'] ?? ($slot['start'] ?? ''))));
                $endTime = $this->extractTime((string) ($slot['end_time'] ?? ($slot['endTime'] ?? ($slot['end'] ?? ''))));
                $resourceId = $this->toNullableInt($slot['resource_id'] ?? ($slot['resourceId'] ?? null));

                $key = $this->buildSlotQueueKey($productId, $resourceId, $serviceDate);
                if (! isset($queues[$key])) {
                    $queues[$key] = array();
                }

                $queues[$key][] = array(
                    'service_date' => $serviceDate,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'resource_id' => $resourceId,
                );
            }
        }

        foreach ($queues as &$queue) {
            usort($queue, function (array $left, array $right): int {
                return strcmp((string) ($left['start_time'] ?? ''), (string) ($right['start_time'] ?? ''));
            });
        }
        unset($queue);

        return $queues;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, array<int, array<string, mixed>>> $slotQueues
     * @return array<int, array<string, mixed>>
     */
    private function enrichItemsWithSlotData(array $items, array &$slotQueues): array
    {
        foreach ($items as &$item) {
            $needsTiming = trim((string) ($item['start_time'] ?? '')) === '' || trim((string) ($item['end_time'] ?? '')) === '';
            if (! $needsTiming) {
                continue;
            }

            $productId = $this->toInt($item['product_id'] ?? 0);
            $serviceDate = trim((string) ($item['service_date'] ?? ''));
            $resourceId = $this->toNullableInt($item['resource_id'] ?? null);
            if ($productId <= 0 || $serviceDate === '') {
                continue;
            }

            $key = $this->buildSlotQueueKey($productId, $resourceId, $serviceDate);
            if (! isset($slotQueues[$key][0]) || ! is_array($slotQueues[$key][0])) {
                continue;
            }

            $slot = array_shift($slotQueues[$key]);
            $item['start_time'] = trim((string) ($item['start_time'] ?? '')) !== '' ? (string) $item['start_time'] : (string) ($slot['start_time'] ?? '');
            $item['end_time'] = trim((string) ($item['end_time'] ?? '')) !== '' ? (string) $item['end_time'] : (string) ($slot['end_time'] ?? '');

            $timeRange = $this->buildTimeRangeLabel((string) $item['start_time'], (string) $item['end_time']);
            if ($timeRange !== '') {
                $item['validated_slot_label'] = sprintf('%s %s', $serviceDate, $timeRange);
            }
        }
        unset($item);

        return $items;
    }

    private function buildSlotQueueKey(int $productId, ?int $resourceId, string $serviceDate): string
    {
        return sprintf('%d|%d|%s', $productId, $resourceId ?? 0, trim($serviceDate));
    }

    /**
     * @param array<string, mixed> $rawItem
     * @return array<int, string>
     */
    private function extractSelectedOptionLabels(array $rawItem): array
    {
        $labels = array();

        foreach (array(
            $rawItem['selected_option_labels'] ?? null,
            $rawItem['option_labels'] ?? null,
            $rawItem['selected_options'] ?? null,
            $rawItem['options'] ?? null,
            $rawItem['variants'] ?? null,
        ) as $candidate) {
            if (is_array($candidate)) {
                foreach ($candidate as $value) {
                    $label = $this->normalizeOptionLabelValue($value);
                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }
            }
        }

        foreach (array('selected_option_label', 'option_label', 'variant_label', 'resource_label', 'selection_label') as $key) {
            $label = $this->normalizeOptionLabelValue($rawItem[$key] ?? null);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param mixed $value
     */
    private function normalizeOptionLabelValue($value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (! is_array($value)) {
            return '';
        }

        foreach (array('label', 'name', 'title', 'value') as $key) {
            if (! isset($value[$key]) || (! is_scalar($value[$key]) && $value[$key] !== null)) {
                continue;
            }

            $label = trim((string) $value[$key]);
            if ($label !== '') {
                return $label;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $rawItem
     */
    private function extractValidatedSlotLabel(array $rawItem, string $serviceDate, string $startTime, string $endTime): ?string
    {
        foreach (array(
            $rawItem['validated_slot_label'] ?? null,
            $rawItem['slot_label'] ?? null,
            $rawItem['slotLabel'] ?? null,
            $rawItem['timeslot_label'] ?? null,
            $rawItem['time_label'] ?? null,
        ) as $candidate) {
            if (! is_scalar($candidate) || $candidate === null) {
                continue;
            }

            $label = trim((string) $candidate);
            if ($label !== '') {
                return $label;
            }
        }

        $timeRange = $this->buildTimeRangeLabel($startTime, $endTime);
        if ($serviceDate !== '' && $timeRange !== '') {
            return sprintf('%s %s', $serviceDate, $timeRange);
        }

        if ($timeRange !== '') {
            return $timeRange;
        }

        return $serviceDate !== '' ? $serviceDate : null;
    }

    private function buildTimeRangeLabel(string $startTime, string $endTime): string
    {
        $start = trim($startTime);
        $end = trim($endTime);

        if ($start !== '' && $end !== '') {
            return sprintf('%s-%s', $start, $end);
        }

        return $start !== '' ? $start : $end;
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $rawItem
     * @return array<string, mixed>
     */
    private function resolvePricing(array $line, array $rawItem): array
    {
        $lookup = array();
        if ((int) ($line['product_id'] ?? 0) > 0 && (string) ($line['service_date'] ?? '') !== '' && (string) ($line['start_time'] ?? '') !== '') {
            $lookup = $this->lookup->lookupPricing($line);
        }

        $lookupPayload = isset($lookup['payload']) && is_array($lookup['payload']) ? $lookup['payload'] : array();
        $snapshot      = $this->resolveSnapshotPricing($rawItem);

        $unitPrice = $this->firstPositiveFloat(array(
            $snapshot['unit_price'] ?? null,
            $lookup['unit_amount_snapshot'] ?? null,
        ));
        $lineTotal = $this->firstPositiveFloat(array(
            $snapshot['line_total'] ?? null,
            $lookup['line_total_snapshot'] ?? null,
        ));

        $supportsPersons = $this->resolveSupportsPersons(
            $lookupPayload,
            $rawItem,
            $unitPrice,
            $lineTotal,
            (int) ($line['participants'] ?? 1)
        );

        if ($supportsPersons && $unitPrice > 0.0) {
            // Offerte-intake must show and store explicit p.p. pricing as unit x participants.
            $lineTotal = $unitPrice * (int) ($line['participants'] ?? 1);
        } elseif ($lineTotal <= 0.0 && $unitPrice > 0.0) {
            $lineTotal = $unitPrice;
        }

        if ($unitPrice <= 0.0 && $lineTotal > 0.0) {
            $unitPrice = $supportsPersons && (int) $line['participants'] > 0
                ? $lineTotal / (int) $line['participants']
                : $lineTotal;
        }

        $pricingBasis      = $supportsPersons ? 'per_person' : 'group';
        $pricingBasisLabel = $supportsPersons ? 'p.p.' : 'groepsprijs';
        $currency          = trim((string) ($lookup['currency'] ?? ($snapshot['currency'] ?? 'EUR')));
        if ($currency === '') {
            $currency = 'EUR';
        }

        return array(
            'pricing_basis'           => $pricingBasis,
            'pricing_basis_label'     => $pricingBasisLabel,
            'quantity'                => $supportsPersons ? max(1, (int) $line['participants']) : 1,
            'unit_price'              => round(max(0.0, $unitPrice), 2),
            'line_total'              => round(max(0.0, $lineTotal), 2),
            'currency'                => $currency,
            'pricing_confidence'      => trim((string) ($lookup['confidence'] ?? 'snapshot')) ?: 'snapshot',
            'availability_confidence' => 'projected',
        );
    }

    /**
     * @param array<string, mixed> $rawItem
     * @param array<string, mixed> $pricing
     */
    private function resolveDisplayPriceLabel(array $rawItem, array $pricing): ?string
    {
        $lineTotal = (float) ($pricing['line_total'] ?? 0.0);
        $unitPrice = (float) ($pricing['unit_price'] ?? 0.0);
        if ($lineTotal > 0.0 || $unitPrice > 0.0) {
            return null;
        }

        foreach (array('included', 'is_included', 'sbdp_included') as $key) {
            if (! array_key_exists($key, $rawItem)) {
                continue;
            }

            return filter_var($rawItem[$key], FILTER_VALIDATE_BOOLEAN) ? 'Inbegrepen' : 'Niet inbegrepen';
        }

        $routeIntent = strtolower(trim((string) (
            $rawItem['route_intent']
            ?? $rawItem['routeIntent']
            ?? ''
        )));
        $capability = strtoupper(trim((string) (
            $rawItem['booking_capability']
            ?? $rawItem['bookingcapability']
            ?? ''
        )));

        if (in_array($routeIntent, array('quote', 'request'), true) || in_array($capability, array('REQUEST', 'REQUEST_ONLY'), true)) {
            return 'Prijs op aanvraag';
        }

        $productId = $this->toInt(
            $rawItem['product_id'] ?? ($rawItem['productId'] ?? ($rawItem['productid'] ?? ($rawItem['id'] ?? 0)))
        );
        if ($productId > 0) {
            return 'Prijs op aanvraag';
        }

        return 'Inbegrepen';
    }

    /**
     * @param array<string, mixed> $rawItem
     * @return array{unit_price: float, line_total: float, currency: string}
     */
    private function resolveSnapshotPricing(array $rawItem): array
    {
        $participants = max(1, $this->toInt($rawItem['participants'] ?? ($rawItem['people'] ?? 1)));
        $pricingSets  = array();

        foreach (array('pricing', 'bookingResolution', 'bookingresolution', 'aggregate') as $key) {
            if (isset($rawItem[$key]) && is_array($rawItem[$key])) {
                $pricingSets[] = $rawItem[$key];
            }
        }

        $unitPrice = $this->firstPositiveFloat(array(
            $rawItem['unit_amount_snapshot'] ?? null,
            $rawItem['price_pp'] ?? null,
            $this->readNestedFloat($pricingSets, array('pricing', 'display_unit_price')),
            $this->readNestedFloat($pricingSets, array('pricing', 'display_per_person')),
            $this->readNestedFloat($pricingSets, array('pricing', 'unit_price')),
            $this->readNestedFloat($pricingSets, array('pricing', 'per_person')),
            $this->readNestedFloat($pricingSets, array('pricing', 'dynamic', 'unit_total')),
            $this->readNestedFloat($pricingSets, array('pricing', 'dynamic', 'unit', 'total')),
            $this->readNestedFloat($pricingSets, array('dynamic', 'unit_total')),
            $this->readNestedFloat($pricingSets, array('dynamic', 'unit', 'total'))
        ));

        $lineTotal = $this->firstPositiveFloat(array(
            $rawItem['line_total_snapshot'] ?? null,
            $rawItem['totalCost'] ?? null,
            $this->readNestedFloat($pricingSets, array('pricing', 'display_total')),
            $this->readNestedFloat($pricingSets, array('pricing', 'total')),
            $this->readNestedFloat($pricingSets, array('pricing', 'dynamic', 'total')),
            $this->readNestedFloat($pricingSets, array('display_total')),
            $this->readNestedFloat($pricingSets, array('total')),
            $this->readNestedFloat($pricingSets, array('dynamic', 'total'))
        ));

        if ($lineTotal <= 0.0 && $unitPrice > 0.0 && $this->snapshotLooksPerPerson($rawItem, $pricingSets)) {
            $lineTotal = $unitPrice * $participants;
        }

        $currency = trim((string) (
            $rawItem['currency']
            ?? $this->readNestedString($pricingSets, array('pricing', 'currency'))
            ?? $this->readNestedString($pricingSets, array('currency'))
            ?? 'EUR'
        ));

        return array(
            'unit_price' => round(max(0.0, $unitPrice), 2),
            'line_total' => round(max(0.0, $lineTotal), 2),
            'currency'   => $currency !== '' ? $currency : 'EUR',
        );
    }

    /**
     * @param array<string, mixed> $lookupPayload
     * @param array<string, mixed> $rawItem
     */
    private function resolveSupportsPersons(array $lookupPayload, array $rawItem, float $unitPrice, float $lineTotal, int $participants): bool
    {
        $flags = array(
            $this->readValue($lookupPayload, array('line_item', 'pricing', 'supports_persons')),
            $this->readValue($lookupPayload, array('supports_persons')),
            $rawItem['supports_persons'] ?? null,
            $this->readValue($rawItem, array('pricing', 'supports_persons')),
            $this->readValue($rawItem, array('bookingResolution', 'pricing', 'supports_persons')),
            $this->readValue($rawItem, array('bookingresolution', 'pricing', 'supports_persons')),
        );

        foreach ($flags as $flag) {
            if (is_bool($flag)) {
                return $flag;
            }

            if (is_string($flag)) {
                $normalized = strtolower(trim($flag));
                if (in_array($normalized, array('1', 'yes', 'true', 'on'), true)) {
                    return true;
                }
                if (in_array($normalized, array('0', 'no', 'false', 'off'), true)) {
                    return false;
                }
            }
        }

        if ($this->snapshotLooksPerPerson($rawItem, array($rawItem, $lookupPayload))) {
            return true;
        }

        return $participants > 1 && $unitPrice > 0.0 && abs($lineTotal - ($unitPrice * $participants)) < 0.01;
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     */
    private function snapshotLooksPerPerson(array $rawItem, array $sources): bool
    {
        $candidates = array(
            $rawItem['price_pp'] ?? null,
            $this->readNestedFloat($sources, array('pricing', 'per_person')),
            $this->readNestedFloat($sources, array('pricing', 'display_per_person')),
            $this->readNestedFloat($sources, array('pricing', 'unit_price')),
            $this->readNestedFloat($sources, array('per_person')),
            $this->readNestedFloat($sources, array('display_per_person')),
        );

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate > 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstPositiveFloat(array $values): float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $float = (float) $value;
                if ($float > 0.0) {
                    return $float;
                }
            }
        }

        return 0.0;
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     */
    private function readNestedFloat(array $sources, array $path): ?float
    {
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $value = $this->readValue($source, $path);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     */
    private function readNestedString(array $sources, array $path): ?string
    {
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $value = $this->readValue($source, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $source
     * @return mixed|null
     */
    private function readValue(array $source, array $path)
    {
        $value = $source;
        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveParticipants(array $payload): int
    {
        $count = $this->toInt($payload['meta']['participant_count'] ?? 0);
        if ($count > 0) {
            return $count;
        }

        $plannerItems = $payload['meta']['planner_items'] ?? array();
        if (is_array($plannerItems)) {
            foreach ($plannerItems as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $participants = $this->toInt($item['participants'] ?? ($item['people'] ?? 0));
                if ($participants > 0) {
                    return $participants;
                }
            }
        }

        return 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveDate(array $payload): string
    {
        $days = $payload['days'] ?? array();
        if (is_array($days) && isset($days[0]) && is_array($days[0])) {
            $date = trim((string) ($days[0]['date'] ?? ''));
            if ($date !== '') {
                return $date;
            }
        }

        $plannerItems = $payload['meta']['planner_items'] ?? array();
        if (is_array($plannerItems)) {
            foreach ($plannerItems as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $date = trim((string) ($item['date'] ?? ''));
                if ($date !== '') {
                    return $date;
                }
            }
        }

        return '';
    }

    private function extractTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/T(\d{2}:\d{2})(?::\d{2})?/', $value, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\b(\d{2}:\d{2})/', $value, $matches) === 1) {
            return $matches[1];
        }

        return substr($value, 0, 5);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstNonEmptyString(array $values): string
    {
        foreach ($values as $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $string = trim((string) $value);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        $map = array(
            'waling dinner' => 'Walking Dinner',
            'walking dinner' => 'Walking Dinner',
        );

        $lower = strtolower($title);
        if (isset($map[$lower])) {
            return $map[$lower];
        }

        return $title;
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function toNullableInt(mixed $value): ?int
    {
        $int = $this->toInt($value);
        return $int > 0 ? $int : null;
    }
}
