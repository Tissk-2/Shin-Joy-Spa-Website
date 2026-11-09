<?php

namespace Tests\Feature;

use App\Actions\Catalog\AttestPackagePricing;
use App\Enums\BookingStatus;
use App\Enums\PricingBasis;
use App\Models\Booking;
use App\Models\BookingSettings;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomReservation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Queries\BookingReadinessQuery;
use App\Queries\RoomAssignmentAudit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoomAssignmentAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-06 03:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_overlapping_upcoming_occupancy_is_reported_per_room(): void
    {
        $this->ready();
        $room = Room::query()->where('code', 'room')->firstOrFail();
        $suite = Room::create([
            'code' => 'suite', 'name' => 'Suite', 'capacity' => 4,
            'is_active' => true, 'cleaning_minutes' => 15,
        ]);
        $suite->variants()->attach(ServiceVariant::query()->where('code', 'base')->sole());

        $a = $this->booking('SJ-A', BookingStatus::Confirmed, '2026-11-10 03:00:00', '2026-11-10');
        $b = $this->booking('SJ-B', BookingStatus::Confirmed, '2026-11-10 04:00:00', '2026-11-10');
        $c = $this->booking('SJ-C', BookingStatus::Confirmed, '2026-11-10 05:00:00', '2026-11-10');
        $released = $this->booking('SJ-REL', BookingStatus::Confirmed, '2026-11-10 05:15:00', '2026-11-10');
        $sameTime = $this->booking('SJ-S', BookingStatus::Confirmed, '2026-11-10 03:00:00', '2026-11-10');
        $old1 = $this->booking('SJ-OLD1', BookingStatus::Confirmed, '2026-11-01 03:00:00', '2026-11-01');
        $old2 = $this->booking('SJ-OLD2', BookingStatus::Confirmed, '2026-11-01 03:30:00', '2026-11-01');

        $this->reserve($room, $a, '2026-11-10 03:00:00', '2026-11-10 04:45:00');
        $this->reserve($room, $b, '2026-11-10 04:00:00', '2026-11-10 05:00:00');
        $this->reserve($room, $c, '2026-11-10 05:00:00', '2026-11-10 06:00:00');
        $this->reserve($room, $released, '2026-11-10 05:15:00', '2026-11-10 05:45:00', '2026-11-05 00:00:00');
        $this->reserve($suite, $sameTime, '2026-11-10 03:00:00', '2026-11-10 04:45:00');
        $this->reserve($room, $old1, '2026-11-01 03:00:00', '2026-11-01 04:00:00');
        $this->reserve($room, $old2, '2026-11-01 03:30:00', '2026-11-01 04:30:00');

        $x = $this->block($room, '2026-11-10 05:30:00', '2026-11-10 06:30:00');
        $y = $this->block($room, '2026-11-10 05:45:00', '2026-11-10 06:15:00');
        $this->terminate($y, 'cancel_future', '2026-11-05 00:00:00.000000', '2026-11-10 05:45:00', '2026-11-10 06:15:00');

        $this->assertSame([
            '[conflicts] Room room: booking SJ-A overlaps booking SJ-B at 2026-11-10 11:00 WIB.',
            "[conflicts] Room room: booking SJ-C overlaps maintenance block #{$x->id} at 2026-11-10 12:30 WIB.",
        ], (new RoomAssignmentAudit)->conflicts(CarbonImmutable::now()));
    }

    public function test_pending_bookings_that_approval_would_refuse_are_reported(): void
    {
        $service = $this->ready();
        $room = Room::query()->where('code', 'room')->firstOrFail();

        $this->booking('SJ-OK', BookingStatus::Pending, '2026-11-10 05:00:00', '2026-11-10', '09:00', ['guests' => 2]);
        $this->booking('SJ-TYPE', BookingStatus::Pending, '2026-11-10 05:00:00', '2026-11-10', '10:00', [
            'guests' => 2, 'requested_room_type' => 'vip',
        ]);
        $this->booking('SJ-ORPHAN', BookingStatus::Pending, '2026-11-10 05:00:00', '2026-11-10', '11:00', [
            'service_variant_id' => null,
        ]);
        $old = ServiceVariant::create([
            'service_id' => $service->id, 'code' => 'old', 'treatment_mode' => 'regular',
            'duration_minutes' => 120, 'price_idr' => 100000, 'display_order' => 1,
            'is_active' => false, 'published_at' => now(),
        ]);
        $room->variants()->attach($old);
        $this->booking('SJ-RETIRED', BookingStatus::Pending, '2026-11-10 05:00:00', '2026-11-10', '12:00', [
            'service_variant_id' => $old->id,
        ]);
        $holder = $this->booking('SJ-HOLD', BookingStatus::Confirmed, '2026-11-10 05:00:00', '2026-11-10', '13:00');
        $this->reserve($room, $holder, '2026-11-10 05:00:00', '2026-11-10 06:30:00');
        $this->booking('SJ-BUSY', BookingStatus::Pending, '2026-11-10 05:00:00', '2026-11-10', '13:00');
        $this->booking('SJ-ENDED', BookingStatus::Pending, '2026-11-06 00:00:00', '2026-11-06', '14:00');

        $this->assertSame([
            '[pending] Booking SJ-TYPE on 2026-11-10 10:00 WIB has no offered treatment or active compatible room for 2 guests; approval will be refused.',
            '[pending] Booking SJ-ORPHAN on 2026-11-10 11:00 WIB has no catalog treatment or an invalid group or duration; approval will be refused. Decline it and record a new booking.',
            '[pending] Booking SJ-RETIRED on 2026-11-10 12:00 WIB has no offered treatment or active compatible room for 1 guest; approval will be refused.',
        ], (new RoomAssignmentAudit)->pendingIssues(CarbonImmutable::now()));
    }

    public function test_readiness_reports_pending_and_conflicts_after_assignments(): void
    {
        $this->ready();
        $room = Room::query()->where('code', 'room')->firstOrFail();
        $a = $this->booking('SJ-A', BookingStatus::Confirmed, '2026-11-10 03:00:00', '2026-11-10');
        $b = $this->booking('SJ-B', BookingStatus::Confirmed, '2026-11-10 04:00:00', '2026-11-10');
        $this->reserve($room, $a, '2026-11-10 03:00:00', '2026-11-10 04:45:00');
        $this->reserve($room, $b, '2026-11-10 04:00:00', '2026-11-10 05:00:00');
        $this->booking('SJ-ORPHAN', BookingStatus::Pending, '2026-11-10 05:00:00', '2026-11-10', '11:00', [
            'service_variant_id' => null,
        ]);

        $this->assertSame([
            '[pending] Booking SJ-ORPHAN on 2026-11-10 11:00 WIB has no catalog treatment or an invalid group or duration; approval will be refused. Decline it and record a new booking.',
            '[conflicts] Room room: booking SJ-A overlaps booking SJ-B at 2026-11-10 11:00 WIB.',
        ], (new BookingReadinessQuery)->issues(CarbonImmutable::now()));

        $this->assertSame(1, Artisan::call('bookings:readiness'));
    }

    public function test_reconcile_report_suggests_free_rooms_and_changes_nothing(): void
    {
        $this->ready();
        $suite = Room::create([
            'code' => 'suite', 'name' => 'Suite', 'capacity' => 4,
            'is_active' => true, 'cleaning_minutes' => 15,
        ]);
        $suite->variants()->attach(ServiceVariant::query()->where('code', 'base')->sole());

        $this->booking('SJ-NEED', BookingStatus::Confirmed, '2026-11-10 05:00:00', '2026-11-10', '12:00', ['guests' => 2]);
        $blocker = $this->booking('SJ-BLOCKER', BookingStatus::Confirmed, '2026-11-10 04:30:00', '2026-11-10', '11:00');
        $this->reserve($suite, $blocker, '2026-11-10 04:30:00', '2026-11-10 07:00:00');
        $this->booking('SJ-LOST', BookingStatus::Confirmed, '2026-11-11 03:00:00', '2026-11-11', '10:00', [
            'service_variant_id' => null,
        ]);
        // Review additions: a real catalog booking that forInterval() rejects as input, and one no room can hold.
        $this->booking('SJ-BADTYPE', BookingStatus::Confirmed, '2026-11-12 03:00:00', '2026-11-12', '10:00', [
            'requested_room_type' => '!!',
        ]);
        $this->booking('SJ-NOFIT', BookingStatus::Confirmed, '2026-11-13 03:00:00', '2026-11-13', '10:00', [
            'guests' => 2, 'requested_room_type' => 'vip',
        ]);

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $this->assertSame(1, Artisan::call('bookings:reconcile-rooms'));
        $output = Artisan::output();
        $this->assertStringContainsString('Bookings that need a room: 4', $output);
        $this->assertStringContainsString('- Booking SJ-NEED on 2026-11-10 12:00 WIB, 2 guests: free rooms room; busy rooms suite.', $output);
        $this->assertStringContainsString('- Booking SJ-LOST on 2026-11-11 10:00 WIB has no catalog treatment or an invalid group or duration. Cancel it and record a new booking.', $output);
        $this->assertStringContainsString('- Booking SJ-BADTYPE on 2026-11-12 10:00 WIB has no catalog treatment or an invalid group or duration. Cancel it and record a new booking.', $output);
        $this->assertStringContainsString('- Booking SJ-NOFIT on 2026-11-13 10:00 WIB, 2 guests: no offered treatment or active compatible room. Cancel it and record a new booking.', $output);
        $this->assertStringContainsString('Conflicts: 0', $output);
        // Review fix: no internal task codes in operator output.
        $this->assertStringContainsString('To assign one, rerun with --booking=REFERENCE --room=CODE to check it, then add --apply. This report changes nothing.', $output);
        $this->assertStringNotContainsString('B6c', $output);

        $this->assertNotSame([], $sql);
        foreach ($sql as $statement) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $statement);
        }
    }

    public function test_reconcile_report_is_clean_when_nothing_needs_a_room(): void
    {
        $this->ready();

        $this->assertSame(0, Artisan::call('bookings:reconcile-rooms'));
        $output = Artisan::output();
        $this->assertStringContainsString('Bookings that need a room: 0', $output);
        $this->assertStringContainsString('Conflicts: 0', $output);
    }

    private function ready(): Service
    {
        BookingSettings::create([
            'id' => 1, 'version' => 1,
            'review_intervals' => [['weekday' => 1, 'start_minute' => 540, 'end_minute' => 1260]],
            'review_target_minutes' => 30, 'booking_horizon_days' => 2,
            'minimum_lead_minutes' => 0, 'minimum_checkout_minutes' => 5,
        ]);
        $category = ServiceCategory::create([
            'slug' => 'local', 'name_id' => 'Local', 'name_en' => 'Local', 'image_path' => '',
            'display_order' => 0, 'is_active' => true, 'published_at' => now(),
        ]);
        $service = Service::create([
            'category_id' => $category->id, 'slug' => 'local', 'name_id' => 'Local', 'name_en' => 'Local',
            'description_id' => '', 'description_en' => '', 'long_description_id' => '', 'long_description_en' => '',
            'duration_minutes' => 60, 'price_idr' => 100000, 'image_path' => '', 'gallery_paths' => [],
            'popularity' => 0, 'display_order' => 0, 'is_active' => true, 'published_at' => now(),
        ]);
        $variant = ServiceVariant::create([
            'service_id' => $service->id, 'code' => 'base', 'treatment_mode' => 'regular',
            'duration_minutes' => 60, 'price_idr' => 100000, 'display_order' => 0,
            'is_active' => true, 'published_at' => now(),
        ]);
        app(AttestPackagePricing::class)(User::factory()->owner()->create(), $service,
            (int) $service->lock_version, PricingBasis::PerBooking, 1, 4);
        $room = Room::create([
            'code' => 'room', 'name' => 'Room', 'capacity' => 4,
            'is_active' => true, 'cleaning_minutes' => 15,
        ]);
        $room->variants()->attach($variant);

        return $service->refresh();
    }

    private function booking(string $reference, BookingStatus $status, ?string $startsAt, string $date, string $time = '10:00', array $extra = []): Booking
    {
        return Booking::factory()->create([
            'reference' => $reference,
            'status' => $status,
            'appointment_date' => $date,
            'appointment_time' => $time,
            'service_id' => Service::query()->where('slug', 'local')->sole()->id,
            'service_variant_id' => ServiceVariant::query()->where('code', 'base')->sole()->id,
            // Every writer sets both instants together; the factory's 90-minute treatment.
            ...($startsAt === null ? [] : ['starts_at' => $startsAt,
                'treatment_ends_at' => CarbonImmutable::parse($startsAt, 'UTC')->addMinutes(90)->format('Y-m-d H:i:s')]),
            ...$extra,
        ]);
    }

    private function reserve(Room $room, Booking $booking, string $start, string $end, ?string $releasedAt = null): void
    {
        RoomReservation::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'starts_at' => $start,
            'treatment_ends_at' => CarbonImmutable::parse($end, 'UTC')->subMinutes(15)->format('Y-m-d H:i:s'),
            'ends_at' => $end,
            'cleaning_minutes' => 15,
            'released_at' => $releasedAt,
            'release_reason' => $releasedAt ? 'cancelled_before_start' : null,
        ]);
    }

    private function block(Room $room, string $start, string $end): RoomBlock
    {
        return RoomBlock::create(['room_id' => $room->id, 'starts_at' => $start, 'ends_at' => $end, 'reason' => 'Deep clean']);
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
