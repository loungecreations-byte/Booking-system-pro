<?php
declare(strict_types=1);

namespace BSP\Tests\Unit\Planner;

use BSP\Planner\Module;
use PHPUnit\Framework\TestCase;

final class ModuleTest extends TestCase
{
    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = new Module();
    }

    public function testGenerateScheduleSortsByTime(): void
    {
        $schedule = $this->module->generateSchedule([
            ['time' => '11:00', 'name' => 'C', 'resource' => 'room-2'],
            ['time' => '09:00', 'name' => 'A', 'resource' => 'room-1'],
            ['time' => '10:00', 'name' => 'B', 'resource' => 'room-1'],
        ]);

        $this->assertSame([
            ['slot' => '09:00', 'label' => 'A', 'resource' => 'room-1'],
            ['slot' => '10:00', 'label' => 'B', 'resource' => 'room-1'],
            ['slot' => '11:00', 'label' => 'C', 'resource' => 'room-2'],
        ], $schedule);
    }

    public function testHasOverlapDetectsConflicts(): void
    {
        $hasOverlap = $this->module->hasOverlap([
            ['resource' => 'room-1', 'time' => '10:00'],
            ['resource' => 'room-1', 'time' => '10:00'],
        ]);

        $this->assertTrue($hasOverlap);
        $this->assertFalse($this->module->hasOverlap([
            ['resource' => 'room-1', 'time' => '10:00'],
            ['resource' => 'room-1', 'time' => '11:00'],
        ]));
    }

    public function testAvailableSlotsFiltersBookedAndReturnsSortedList(): void
    {
        $available = $this->module->availableSlots(
            ['10:00', '09:00', '11:00'],
            ['10:00']
        );

        $this->assertSame(['09:00', '11:00'], $available);
    }

    public function testAssignResourceTakesFirstResourceId(): void
    {
        $booking = ['name' => 'Tour'];
        $result = $this->module->assignResource($booking, [
            ['id' => 'resource-1'],
            ['id' => 'resource-2'],
        ]);

        $this->assertSame('resource-1', $result['resource']);
    }

    public function testMoveBookingRequiresIdAndTime(): void
    {
        $this->assertFalse($this->module->moveBooking(0, '10:00'));
        $this->assertFalse($this->module->moveBooking(1, ''));
        $this->assertTrue($this->module->moveBooking(1, '10:00'));
    }

    public function testValidateBookingReturnsErrorsForMissingFields(): void
    {
        $errors = $this->module->validateBooking([]);
        $this->assertSame(['time_required', 'name_required'], $errors);

        $this->assertSame([], $this->module->validateBooking([
            'time' => '10:00',
            'name' => 'Tour',
        ]));
    }
}
