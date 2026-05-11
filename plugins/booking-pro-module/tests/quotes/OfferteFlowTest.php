<?php

declare(strict_types=1);

namespace {
    if (! function_exists('__')) {
        function __(string $text, string $domain = ''): string
        {
            unset($domain);
            return $text;
        }
    }

    if (! function_exists('_n')) {
        function _n(string $single, string $plural, int $number, string $domain = ''): string
        {
            unset($domain);
            return $number === 1 ? $single : $plural;
        }
    }

    if (! function_exists('is_email')) {
        function is_email(string $email): bool
        {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        }
    }

    require_once dirname(__DIR__, 2) . '/modules/bookings/Shortcodes/OfferteForm.php';
}

namespace BSP\Tests\Quotes {

use BSP\Quotes\Service\PlannerQuoteSummaryService;
use BSP\Quotes\Service\QuoteExecutionLookupService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OfferteFlowTest extends TestCase
{
    public function testSummaryCalculatesPerPersonLineTotalsAndGrandTotal(): void
    {
        $lookup = new class extends QuoteExecutionLookupService {
            public function lookupPricing(array $line): array
            {
                $pricingMap = array(
                    101 => array('unit_amount_snapshot' => 9.5, 'line_total_snapshot' => 95.0),
                    202 => array('unit_amount_snapshot' => 79.0, 'line_total_snapshot' => 790.0),
                );

                $pricing = $pricingMap[(int) ($line['product_id'] ?? 0)] ?? array('unit_amount_snapshot' => null, 'line_total_snapshot' => null);

                return array(
                    'confidence' => 'execution_verified',
                    'payload' => array(
                        'line_item' => array(
                            'pricing' => array(
                                'supports_persons' => true,
                            ),
                        ),
                    ),
                    'unit_amount_snapshot' => $pricing['unit_amount_snapshot'],
                    'line_total_snapshot' => $pricing['line_total_snapshot'],
                    'currency' => 'EUR',
                );
            }
        };

        $service = new PlannerQuoteSummaryService($lookup);
        $summary = $service->buildViewModel(array(
            'days' => array(
                array('date' => '2026-05-10'),
            ),
            'meta' => array(
                'participant_count' => 10,
                'planner_items' => array(
                    array(
                        'product_id' => 101,
                        'title' => 'Bossche Bol met koffie',
                        'participants' => 10,
                        'date' => '2026-05-10',
                        'starttime' => '10:00',
                        'endtime' => '11:00',
                    ),
                    array(
                        'product_id' => 202,
                        'title' => 'Walking Dinner',
                        'participants' => 10,
                        'date' => '2026-05-10',
                        'starttime' => '18:00',
                        'endtime' => '21:00',
                    ),
                ),
            ),
        ));

        $this->assertSame(10, $summary['participants']);
        $this->assertCount(2, $summary['items']);
        $this->assertSame('p.p.', $summary['items'][0]['pricing_basis_label']);
        $this->assertSame(10, $summary['items'][0]['quantity']);
        $this->assertSame(9.5, $summary['items'][0]['unit_price']);
        $this->assertSame(95.0, $summary['items'][0]['line_total']);
        $this->assertSame(79.0, $summary['items'][1]['unit_price']);
        $this->assertSame(790.0, $summary['items'][1]['line_total']);
        $this->assertSame(885.0, $summary['total']);
    }

    public function testSummaryPrefersStoredSnapshotOverFallbackLookupForUnitPrice(): void
    {
        $lookup = new class extends QuoteExecutionLookupService {
            public function lookupPricing(array $line): array
            {
                unset($line);

                return array(
                    'confidence' => 'execution_verified',
                    'payload' => array(
                        'line_item' => array(
                            'pricing' => array(
                                'supports_persons' => true,
                            ),
                        ),
                    ),
                    'unit_amount_snapshot' => 79.0,
                    'line_total_snapshot' => 790.0,
                    'currency' => 'EUR',
                );
            }
        };

        $service = new PlannerQuoteSummaryService($lookup);
        $summary = $service->buildViewModel(array(
            'days' => array(
                array('date' => '2026-05-10'),
            ),
            'meta' => array(
                'participant_count' => 10,
                'planner_items' => array(
                    array(
                        'product_id' => 101,
                        'title' => 'Bossche Bol met koffie',
                        'participants' => 10,
                        'date' => '2026-05-10',
                        'starttime' => '10:00',
                        'endtime' => '11:00',
                        'bookingresolution' => array(
                            'pricing' => array(
                                'per_person' => 9.5,
                            ),
                        ),
                    ),
                ),
            ),
        ));

        $this->assertSame(9.5, $summary['items'][0]['unit_price']);
        $this->assertSame(95.0, $summary['items'][0]['line_total']);
        $this->assertSame(95.0, $summary['total']);
    }

    public function testSummaryDoesNotMultiplyGroupPriceAndFixesKnownTypo(): void
    {
        $service = new PlannerQuoteSummaryService(new class extends QuoteExecutionLookupService {
            public function lookupPricing(array $line): array
            {
                unset($line);

                return array(
                    'confidence' => 'unknown',
                    'payload' => array(),
                    'unit_amount_snapshot' => null,
                    'line_total_snapshot' => null,
                    'currency' => 'EUR',
                );
            }
        });

        $summary = $service->buildViewModel(array(
            'days' => array(
                array('date' => '2026-06-02'),
            ),
            'meta' => array(
                'participant_count' => 10,
                'planner_items' => array(
                    array(
                        'product_id' => 303,
                        'title' => 'Waling Dinner',
                        'participants' => 10,
                        'date' => '2026-06-02',
                        'starttime' => '17:00',
                        'endtime' => '19:00',
                        'pricing' => array(
                            'total' => 250.0,
                            'supports_persons' => false,
                        ),
                    ),
                ),
            ),
        ));

        $this->assertSame('Walking Dinner', $summary['items'][0]['title']);
        $this->assertSame('groepsprijs', $summary['items'][0]['pricing_basis_label']);
        $this->assertSame(1, $summary['items'][0]['quantity']);
        $this->assertSame(250.0, $summary['items'][0]['unit_price']);
        $this->assertSame(250.0, $summary['items'][0]['line_total']);
        $this->assertSame(250.0, $summary['total']);
    }

    public function testSummaryKeepsRequestOnlyZeroPricedItemsVisible(): void
    {
        $service = new PlannerQuoteSummaryService(new class extends QuoteExecutionLookupService {
            public function lookupPricing(array $line): array
            {
                unset($line);

                return array(
                    'confidence' => 'unknown',
                    'payload' => array(),
                    'unit_amount_snapshot' => null,
                    'line_total_snapshot' => null,
                    'currency' => 'EUR',
                );
            }
        });

        $summary = $service->buildViewModel(array(
            'days' => array(
                array('date' => '2026-06-03'),
            ),
            'meta' => array(
                'participant_count' => 10,
                'planner_items' => array(
                    array(
                        'product_id' => 97,
                        'title' => 'Workshop worstenbroodjes',
                        'participants' => 10,
                        'date' => '2026-06-03',
                        'starttime' => '13:30',
                        'endtime' => '15:00',
                        'bookingcapability' => 'REQUEST_ONLY',
                    ),
                ),
            ),
        ));

        $this->assertCount(1, $summary['items']);
        $this->assertSame('Workshop worstenbroodjes', $summary['items'][0]['title']);
        $this->assertSame('Prijs op aanvraag', $summary['items'][0]['display_price_label']);
        $this->assertSame(0.0, $summary['items'][0]['line_total']);
    }

    public function testSummaryBackfillsScheduleFromDaySlotsAndSortsChronologically(): void
    {
        $lookup = new class extends QuoteExecutionLookupService {
            public function lookupPricing(array $line): array
            {
                $pricingMap = array(
                    350 => array('unit_amount_snapshot' => 9.5, 'line_total_snapshot' => 95.0),
                    1649 => array('unit_amount_snapshot' => 79.0, 'line_total_snapshot' => 790.0),
                    115 => array('unit_amount_snapshot' => 32.5, 'line_total_snapshot' => 325.0),
                );

                $pricing = $pricingMap[(int) ($line['product_id'] ?? 0)] ?? array('unit_amount_snapshot' => null, 'line_total_snapshot' => null);

                return array(
                    'confidence' => 'execution_verified',
                    'payload' => array(
                        'line_item' => array(
                            'pricing' => array(
                                'supports_persons' => true,
                            ),
                        ),
                    ),
                    'unit_amount_snapshot' => $pricing['unit_amount_snapshot'],
                    'line_total_snapshot' => $pricing['line_total_snapshot'],
                    'currency' => 'EUR',
                );
            }
        };

        $service = new PlannerQuoteSummaryService($lookup);
        $summary = $service->buildViewModel(array(
            'days' => array(
                array(
                    'date' => '2026-05-10',
                    'slots' => array(
                        array(
                            'product_id' => 1649,
                            'resource_id' => 0,
                            'start' => '11:30',
                            'end' => '14:30',
                            'people' => 10,
                        ),
                        array(
                            'product_id' => 350,
                            'resource_id' => 0,
                            'start' => '10:00',
                            'end' => '10:30',
                            'people' => 10,
                        ),
                        array(
                            'product_id' => 115,
                            'resource_id' => 81,
                            'start' => '19:00',
                            'end' => '21:00',
                            'people' => 10,
                        ),
                    ),
                ),
            ),
            'meta' => array(
                'participant_count' => 10,
                'planner_items' => array(
                    array(
                        'product_id' => 1649,
                        'title' => 'Walking Dinner',
                        'participants' => 10,
                        'date' => '2026-05-10',
                        'line_total_snapshot' => 790.0,
                        'price_pp' => 79.0,
                    ),
                    array(
                        'product_id' => 115,
                        'resource_id' => 81,
                        'title' => 'E-chopper tour',
                        'participants' => 10,
                        'date' => '2026-05-10',
                        'line_total_snapshot' => 325.0,
                        'price_pp' => 32.5,
                    ),
                    array(
                        'product_id' => 350,
                        'title' => 'Bossche Bol met koffie',
                        'participants' => 10,
                        'date' => '2026-05-10',
                        'line_total_snapshot' => 95.0,
                        'price_pp' => 9.5,
                    ),
                ),
            ),
        ));

        $this->assertCount(3, $summary['items']);
        $this->assertSame('Bossche Bol met koffie', $summary['items'][0]['title']);
        $this->assertSame('10:00', $summary['items'][0]['start_time']);
        $this->assertSame('10:30', $summary['items'][0]['end_time']);
        $this->assertSame('Walking Dinner', $summary['items'][1]['title']);
        $this->assertSame('11:30', $summary['items'][1]['start_time']);
        $this->assertSame('14:30', $summary['items'][1]['end_time']);
        $this->assertSame('E-chopper tour', $summary['items'][2]['title']);
        $this->assertSame('19:00', $summary['items'][2]['start_time']);
        $this->assertSame('21:00', $summary['items'][2]['end_time']);
    }

    public function testCreateQuoteFromPlanAllowsMissingPhoneAndStoresConsistentSnapshots(): void
    {
        $method = new ReflectionMethod(\BSP\Bookings\Shortcodes\OfferteForm::class, 'createQuoteFromPlan');
        $method->setAccessible(true);

        $result = $method->invoke(
            null,
            42,
            array(
                'title' => 'Planner offerte',
                'days' => array(
                    array('date' => '2026-07-01'),
                ),
                'meta' => array(
                    'participant_count' => 10,
                    'planner_items' => array(
                        array(
                            'product_id' => 404,
                            'title' => 'Bossche Bol met koffie',
                            'participants' => 10,
                            'date' => '2026-07-01',
                            'starttime' => '09:00',
                            'endtime' => '10:00',
                            'bookingresolution' => array(
                                'pricing' => array(
                                    'per_person' => 9.5,
                                ),
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'name' => 'Planner Contact',
                'email' => 'planner@example.test',
                'phone' => '',
            )
        );

        $this->assertTrue($result['ok']);
        $this->assertNotSame('', $result['request_reference']);
        $this->assertNotSame('', $result['quote_reference']);
        $this->assertSame('Planner Contact', $result['requester']['name']);
        $this->assertSame('planner@example.test', $result['requester']['email']);
        $this->assertArrayNotHasKey('phone', $result['requester']);
    }

    public function testCompactValidationOnlyRequiresNameAndEmail(): void
    {
        $method = new ReflectionMethod(\BSP\Bookings\Shortcodes\OfferteForm::class, 'validateContactInput');
        $method->setAccessible(true);

        $valid = $method->invoke(null, array(
            'name' => 'Dagje Team',
            'email' => 'team@example.test',
            'phone' => '',
        ));

        $missingName = $method->invoke(null, array(
            'name' => '',
            'email' => 'team@example.test',
            'phone' => '',
        ));

        $missingEmail = $method->invoke(null, array(
            'name' => 'Dagje Team',
            'email' => '',
            'phone' => '',
        ));

        $this->assertNull($valid);
        $this->assertSame('Vul jullie naam in.', $missingName);
        $this->assertSame('Vul een geldig e-mailadres in.', $missingEmail);
    }
}

}
