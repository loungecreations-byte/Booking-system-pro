<?php
declare(strict_types=1);

namespace {
    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            public function __construct(private array $params = [], private array $headers = [])
            {
            }

            public function get_json_params(): array
            {
                return $this->params;
            }

            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }

            public function get_header(string $key)
            {
                $lookup = strtoupper($key);
                foreach ($this->headers as $header => $value) {
                    if (strtoupper($header) === $lookup) {
                        return $value;
                    }
                }

                return null;
            }
        }
    }

    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(
                private string $code = '',
                private string $message = '',
                private array $data = []
            ) {
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }

            public function get_error_data(): array
            {
                return $this->data;
            }
        }
    }
}

namespace BSP\Tests\Integration\BookingBoard {

use BSP\BookingBoard\Rest\BookingsController;
use BSP\Bookings\Service\BookingManager;
use PHPUnit\Framework\TestCase;

final class BookingBoardRestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BookingsController::resetService();
        BookingManager::createDefault()->reset();
    }

    public function testManualFlow(): void
    {
        $createRequest = new class extends \WP_REST_Request {
            public function get_json_params(): array
            {
                return [
                    'product'        => 501,
                    'product_label'  => 'Test Arrangement',
                    'date_start'     => '2030-01-02',
                    'time_start'     => '09:30',
                    'date_end'       => '2030-01-02',
                    'time_end'       => '11:00',
                    'persons'        => 3,
                    'customer_name'  => 'Board Tester',
                    'customer_email' => 'board.tester@example.com',
                    'price'          => 120.0,
                    'status'         => 'pending',
                ];
            }
        };

        $createResponse = BookingsController::createManual($createRequest);
        $this->assertIsArray($createResponse);
        $this->assertArrayHasKey('booking', $createResponse);

        $booking = $createResponse['booking'];
        $this->assertSame('Board Tester', $booking['customer']);
        $this->assertSame('pending', $booking['status']);
        $this->assertArrayHasKey('customer_details', $booking);
        $this->assertIsArray($booking['customer_details']);
        $this->assertSame('board.tester@example.com', $booking['customer_email']);

        $listRequest = new class extends \WP_REST_Request {
            public function get_param($key)
            {
                unset($key);
                return null;
            }
        };

        $listResponse = BookingsController::list($listRequest);
        $this->assertIsArray($listResponse);
        $this->assertArrayHasKey('items', $listResponse);
        $this->assertCount(1, $listResponse['items']);

        $bookingId = $booking['booking_id'];

        $rescheduleRequest = new class($bookingId) extends \WP_REST_Request {
            public function __construct(private int $bookingId)
            {
            }

            public function get_json_params(): array
            {
                return [
                    'booking_id' => $this->bookingId,
                    'date_start' => '2030-01-03',
                    'time_start' => '10:00',
                ];
            }
        };

        $rescheduleResponse = BookingsController::reschedule($rescheduleRequest);
        $this->assertIsArray($rescheduleResponse);
        $this->assertArrayHasKey('booking', $rescheduleResponse);
        $this->assertStringContainsString('2030-01-03', $rescheduleResponse['booking']['from']);

        $updateRequest = new class($bookingId) extends \WP_REST_Request {
            public function __construct(private int $bookingId)
            {
            }

            public function get_json_params(): array
            {
                return [
                    'booking_id' => $this->bookingId,
                    'status'     => 'completed',
                    'notes'      => 'Customer confirmed arrival.',
                ];
            }
        };

        $updateResponse = BookingsController::update($updateRequest);
        $this->assertIsArray($updateResponse);
        $this->assertSame('completed', $updateResponse['booking']['status']);

        $statsRequest = new class extends \WP_REST_Request {
            public function get_param($key)
            {
                unset($key);
                return null;
            }
        };

        $statsResponse = BookingsController::stats($statsRequest);
        $this->assertIsArray($statsResponse);
        $this->assertArrayHasKey('total', $statsResponse);
        $this->assertSame(1, $statsResponse['total']);

        $exportRequest = new class extends \WP_REST_Request {
            public function get_json_params(): array
            {
                return [
                    'filters' => [],
                    'format'  => 'csv',
                ];
            }
        };

        $exportResponse = BookingsController::export($exportRequest);
        $this->assertIsArray($exportResponse);
        $this->assertArrayHasKey('rows', $exportResponse);
        $this->assertCount(1, $exportResponse['rows']);
    }

    public function testInvoiceEndpointWithoutWooCommerce(): void
    {
        $createRequest = new class extends \WP_REST_Request {
            public function get_json_params(): array
            {
                return [
                    'product'        => 700,
                    'product_label'  => 'Invoice Test',
                    'date_start'     => '2031-02-01',
                    'time_start'     => '08:00',
                    'date_end'       => '2031-02-01',
                    'time_end'       => '09:00',
                    'persons'        => 2,
                    'customer_name'  => 'Invoice Runner',
                    'customer_email' => 'invoice.runner@example.com',
                    'send_invoice'   => true,
                ];
            }
        };

        $created = BookingsController::createManual($createRequest);
        $this->assertIsArray($created);
        $bookingId = $created['booking']['booking_id'];

        $invoiceRequest = new class($bookingId) extends \WP_REST_Request {
            public function __construct(private int $bookingId)
            {
            }

            public function get_json_params(): array
            {
                return [
                    'booking_id' => $this->bookingId,
                    'force'      => true,
                ];
            }
        };

        $invoiceResponse = BookingsController::invoice($invoiceRequest);
        $this->assertIsArray($invoiceResponse);
        $this->assertArrayHasKey('booking', $invoiceResponse);
        $this->assertSame($bookingId, $invoiceResponse['booking']['booking_id']);
    }

    public function testInvoicePdfFailsWithoutPlugin(): void
    {
        $createRequest = new class extends \WP_REST_Request {
            public function get_json_params(): array
            {
                return [
                    'product'        => 710,
                    'product_label'  => 'PDF Invoice Test',
                    'date_start'     => '2031-03-01',
                    'time_start'     => '10:00',
                    'date_end'       => '2031-03-01',
                    'time_end'       => '11:00',
                    'persons'        => 2,
                    'customer_name'  => 'Pdf Runner',
                    'customer_email' => 'pdf.runner@example.com',
                ];
            }
        };

        $created = BookingsController::createManual($createRequest);
        $this->assertIsArray($created);
        $bookingId = $created['booking']['booking_id'];

        $pdfRequest = new class($bookingId) extends \WP_REST_Request {
            public function __construct(private int $bookingId)
            {
            }

            public function get_json_params(): array
            {
                return [
                    'booking_id' => $this->bookingId,
                ];
            }
        };

        $pdfResponse = BookingsController::invoicePdf($pdfRequest);
        $this->assertInstanceOf(\WP_Error::class, $pdfResponse);
    }

    public function testCustomerLookupReturnsEmptyWhenUnavailable(): void
    {
        $customerRequest = new class extends \WP_REST_Request {
            public function get_param($key)
            {
                unset($key);
                return 'Test';
            }
        };

        $response = BookingsController::customers($customerRequest);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('items', $response);
        $this->assertSame([], $response['items']);
    }
}

}
