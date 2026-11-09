<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomReservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 18:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_any_staff_member_sees_each_room_for_a_jakarta_day(): void
    {
        $inactive = $this->room('B', false);
        $active = $this->room('A');
        $booking = $this->booking('SJ-SCHEDULE-1', BookingStatus::Confirmed, '2026-12-01', '10:00', [
            'customer_name' => 'Sari',
            'guests' => 2,
        ]);
        $this->reserve($active, $booking, '2026-12-01 03:00:00', '2026-12-01 04:30:00', '2026-12-01 04:45:00');

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->get('/admin/schedule?date=2026-12-01');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/schedule/Index', false));
        $schedule = $response->inertiaProps()['schedule'];
        $this->assertSame('2026-12-01', $schedule['date']);
        $this->assertSame('2026-11-30', $schedule['previousDate']);
        $this->assertSame('2026-12-02', $schedule['nextDate']);
        $this->assertSame('2026-11-06', $schedule['today']);
        $this->assertFalse($schedule['schedulingEnabled']);
        $this->assertSame([
            ['id' => $active->id, 'code' => 'A', 'name' => 'Room A', 'capacity' => 2, 'isActive' => true, 'items' => [[
                'kind' => 'booking', 'reference' => 'SJ-SCHEDULE-1', 'status' => 'confirmed',
                'customerName' => 'Sari', 'guests' => 2, 'packageNameId' => 'Nezuko', 'packageNameEn' => 'Nezuko',
                'startsAt' => '2026-12-01T03:00:00Z', 'treatmentEndsAt' => '2026-12-01T04:30:00Z', 'endsAt' => '2026-12-01T04:45:00Z',
            ]]],
            ['id' => $inactive->id, 'code' => 'B', 'name' => 'Room B', 'capacity' => 2, 'isActive' => false, 'items' => []],
        ], $schedule['rooms']);

        $noDate = $this->actingAs($admin)->get('/admin/schedule');
        $noDate->assertOk();
        $this->assertSame('2026-11-06', $noDate->inertiaProps()['schedule']['date']);

        $emptyDate = $this->actingAs($admin)->get('/admin/schedule?date=');
        $emptyDate->assertOk();
        $this->assertSame('2026-11-06', $emptyDate->inertiaProps()['schedule']['date']);

        // Review addition: the flag is read, not assumed off.
        config(['booking.scheduling_enabled' => true]);
        $this->assertTrue($this->actingAs($admin)->get('/admin/schedule')->inertiaProps()['schedule']['schedulingEnabled']);
    }

    public function test_the_day_is_a_half_open_jakarta_window(): void
    {
        $room = $this->room('A');
        $cross = $this->booking('SJ-CROSS', BookingStatus::Confirmed);
        $before = $this->booking('SJ-BEFORE', BookingStatus::Confirmed);
        $after = $this->booking('SJ-AFTER', BookingStatus::Confirmed);
        $this->reserve($room, $cross, '2026-11-30 16:30:00', '2026-11-30 17:15:00', '2026-11-30 17:30:00');
        $this->reserve($room, $before, '2026-11-30 15:00:00', '2026-11-30 16:45:00', '2026-11-30 17:00:00');
        $this->reserve($room, $after, '2026-12-01 17:00:00', '2026-12-01 17:45:00', '2026-12-01 18:00:00');

        $admin = User::factory()->admin()->create();
        foreach ([
            '2026-12-01' => ['SJ-CROSS'],
            '2026-11-30' => ['SJ-BEFORE', 'SJ-CROSS'],
            '2026-12-02' => ['SJ-AFTER'],
        ] as $date => $expected) {
            $schedule = $this->actingAs($admin)->get('/admin/schedule?date='.$date)->inertiaProps()['schedule'];
            $this->assertSame($expected, array_column($schedule['rooms'][0]['items'], 'reference'), $date);
        }
    }

    public function test_released_reservations_and_terminated_blocks_free_the_room(): void
    {
        $room = $this->room('A');
        $released = $this->booking('SJ-RELEASED', BookingStatus::Cancelled);
        $this->reserve($room, $released, '2026-12-01 02:00:00', '2026-12-01 03:00:00', '2026-12-01 03:15:00', '2026-11-20 00:00:00');
        $retained = $this->booking('SJ-RETAINED', BookingStatus::Cancelled, '2026-12-01', '11:00', ['customer_name' => 'Retained Guest']);
        $this->reserve($room, $retained, '2026-12-01 04:00:00', '2026-12-01 05:00:00', '2026-12-01 05:15:00');
        $plain = $this->block($room, '2026-12-01 06:00:00', '2026-12-01 07:00:00', 'Deep clean');
        $cancelled = $this->block($room, '2026-12-01 08:00:00', '2026-12-01 09:00:00');
        $this->terminate($cancelled, 'cancel_future', '2026-11-20 00:00:00.000000', '2026-12-01 08:00:00', '2026-12-01 09:00:00');
        $ended = $this->block($room, '2026-12-01 10:00:00', '2026-12-01 12:00:00');
        $this->terminate($ended, 'end_now', '2026-12-01 10:30:00.000000', '2026-12-01 10:00:00', '2026-12-01 12:00:00');

        $schedule = $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/schedule?date=2026-12-01')->inertiaProps()['schedule'];
        $items = $schedule['rooms'][0]['items'];

        $this->assertCount(3, $items);
        $this->assertSame([
            'kind' => 'booking', 'reference' => 'SJ-RETAINED', 'status' => 'cancelled',
            'customerName' => 'Retained Guest', 'guests' => 1, 'packageNameId' => 'Nezuko', 'packageNameEn' => 'Nezuko',
            'startsAt' => '2026-12-01T04:00:00Z', 'treatmentEndsAt' => '2026-12-01T05:00:00Z', 'endsAt' => '2026-12-01T05:15:00Z',
        ], $items[0]);
        $this->assertSame([
            'kind' => 'block', 'id' => $plain->id, 'reason' => 'Deep clean',
            'startsAt' => '2026-12-01T06:00:00Z', 'endsAt' => '2026-12-01T07:00:00Z',
        ], $items[1]);
        $this->assertSame($ended->id, $items[2]['id']);
        $this->assertSame('2026-12-01T10:30:00Z', $items[2]['endsAt']);
        $this->assertNotContains('SJ-RELEASED', array_column($items, 'reference'));
        $this->assertNotContains($cancelled->id, array_column($items, 'id'));
    }

    public function test_bookings_without_a_room_list_the_days_active_requests(): void
    {
        $room = $this->room('A');
        $this->booking('SJ-PEND-B', BookingStatus::Pending, '2026-12-01', '14:00');
        $this->booking('SJ-PEND-A', BookingStatus::Pending, '2026-12-01', '09:30', ['customer_name' => 'Budi']);
        $this->booking('SJ-CONF-NOROOM', BookingStatus::Confirmed, '2026-12-01', '11:00');
        $held = $this->booking('SJ-CONF-ROOM', BookingStatus::Confirmed, '2026-12-01', '12:00');
        $this->reserve($room, $held, '2026-12-01 05:00:00', '2026-12-01 06:30:00', '2026-12-01 06:45:00');
        $this->booking('SJ-CANCELLED', BookingStatus::Cancelled, '2026-12-01', '10:00');
        $this->booking('SJ-DONE', BookingStatus::Completed, '2026-12-01', '10:00');
        $this->booking('SJ-OTHER-DAY', BookingStatus::Pending, '2026-12-02', '10:00');
        // Review additions: awaiting payment counts as active, and a released room no longer holds the booking.
        $this->booking('SJ-AWAIT', BookingStatus::AwaitingPayment, '2026-12-01', '08:00');
        $freed = $this->booking('SJ-CONF-RELEASED', BookingStatus::Confirmed, '2026-12-01', '13:00');
        $this->reserve($room, $freed, '2026-12-01 06:00:00', '2026-12-01 07:00:00', '2026-12-01 07:15:00', '2026-11-20 00:00:00');

        $schedule = $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/schedule?date=2026-12-01')->inertiaProps()['schedule'];

        $this->assertSame(
            ['SJ-AWAIT', 'SJ-PEND-A', 'SJ-CONF-NOROOM', 'SJ-CONF-RELEASED', 'SJ-PEND-B'],
            array_column($schedule['unassigned'], 'reference'),
        );
        $this->assertSame([
            'reference' => 'SJ-PEND-A', 'status' => 'pending', 'customerName' => 'Budi', 'guests' => 1,
            'packageNameId' => 'Nezuko', 'packageNameEn' => 'Nezuko', 'time' => '09:30', 'durationMinutes' => 90,
        ], $schedule['unassigned'][1]);
    }

    public function test_schedule_access_and_malformed_dates(): void
    {
        $this->get('/admin/schedule')->assertRedirect();
        $this->actingAs(User::factory()->create())->get('/admin/schedule')->assertForbidden();

        $admin = User::factory()->admin()->create();
        foreach (['?date=2026-02-30', '?date=tomorrow', '?date=2026-12-01T00:00', '?date=01-12-2026', '?date[]=2026-12-01'] as $query) {
            $this->actingAs($admin)->get('/admin/schedule'.$query)->assertSessionHasErrors('date');
        }
    }

    private function room(string $code, bool $active = true): Room
    {
        return Room::create(['code' => $code, 'name' => 'Room '.$code, 'capacity' => 2, 'is_active' => $active]);
    }

    private function booking(string $reference, BookingStatus $status, string $date = '2026-12-01', string $time = '10:00', array $extra = []): Booking
    {
        return Booking::factory()->create([
            'reference' => $reference,
            'status' => $status,
            'appointment_date' => $date,
            'appointment_time' => $time,
            ...$extra,
        ]);
    }

    private function reserve(Room $room, Booking $booking, string $start, string $treatmentEnd, string $end, ?string $releasedAt = null): void
    {
        RoomReservation::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'starts_at' => $start,
            'treatment_ends_at' => $treatmentEnd,
            'ends_at' => $end,
            'cleaning_minutes' => 15,
            'released_at' => $releasedAt,
            'release_reason' => $releasedAt ? 'cancelled_before_start' : null,
        ]);
    }

    private function block(Room $room, string $start, string $end, string $reason = 'Deep clean'): RoomBlock
    {
        return RoomBlock::create(['room_id' => $room->id, 'starts_at' => $start, 'ends_at' => $end, 'reason' => $reason]);
    }

    private function terminate(RoomBlock $block, string $kind, string $at, string $start, string $end): void
    {
        DB::table('room_block_terminations')->insert([
            'room_block_id' => $block->id,
            'kind' => $kind,
            'occurred_at' => $at,
            'reason' => 'Synthetic',
            'actor_user_id' => null,
            'actor_name' => 'Test',
            'actor_role' => 'owner',
            'room_id' => $block->room_id,
            'original_starts_at' => $start,
            'original_ends_at' => $end,
            'original_reason' => $block->reason,
            'previous_version' => 1,
            'resulting_version' => 2,
        ]);
    }
}
