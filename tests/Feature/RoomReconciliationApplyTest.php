<?php

namespace Tests\Feature;

use App\Actions\Catalog\AttestPackagePricing;
use App\Enums\BookingStatus;
use App\Enums\PricingBasis;
use App\Models\Booking;
use App\Models\BookingSettings;
use App\Models\Room;
use App\Models\RoomReservation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Support\Bookings\BookingMutation;
use App\Support\Scheduling\RoomAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RoomReconciliationApplyTest extends TestCase
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

    public function test_preview_checks_without_writing(): void
    {
        $this->ready();
        $this->booking('SJ-NEED', BookingStatus::Confirmed, '2026-11-10 03:00:00', '2026-11-10', '10:00', ['guests' => 2]);

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $this->assertSame(0, Artisan::call('bookings:reconcile-rooms', [
            '--booking' => 'SJ-NEED',
            '--room' => 'big',
        ]));
        $this->assertStringContainsString(
            // Review fix: the allocator rechecks the saved schedule and catalog, so the preview must not promise more.
            'Room big passes the checks for booking SJ-NEED on 2026-11-10 10:00 WIB. Rerun with --apply to assign it; --apply checks everything again under the schedule lock.',
            Artisan::output(),
        );

        $this->assertSame(0, DB::table('room_reservations')->count());
        $this->assertNotSame([], $sql);
        foreach ($sql as $statement) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $statement);
        }
    }

    public function test_apply_assigns_the_chosen_room_and_keeps_the_booking_unchanged(): void
    {
        $this->ready();
        $this->booking('SJ-NEED', BookingStatus::Confirmed, '2026-11-10 03:00:00', '2026-11-10', '10:00', ['guests' => 2]);

        Log::spy();
        $before = (array) DB::table('bookings')->where('reference', 'SJ-NEED')->first();
        $options = ['--booking' => 'SJ-NEED', '--room' => 'big', '--apply' => true];

        $this->assertSame(0, Artisan::call('bookings:reconcile-rooms', $options));
        $this->assertStringContainsString(
            'Assigned room big to booking SJ-NEED on 2026-11-10 10:00 WIB. Status and price are unchanged.',
            Artisan::output(),
        );

        $reservation = RoomReservation::query()->sole();
        $this->assertSame('big', $reservation->room->code);
        $this->assertSame('2026-11-10 03:00:00', $reservation->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10 04:30:00', $reservation->treatment_ends_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10 04:45:00', $reservation->ends_at->format('Y-m-d H:i:s'));
        $this->assertNull($reservation->released_at);

        $this->assertSame($before, (array) DB::table('bookings')->where('reference', 'SJ-NEED')->first());
        $this->assertSame(0, DB::table('booking_status_changes')->count());
        $this->assertSame(0, DB::table('outbox_messages')->count());
        Log::shouldHaveReceived('info')->once()->with('Room assigned by reconciliation.', [
            'booking' => 'SJ-NEED',
            'room' => 'big',
            'reservation' => $reservation->id,
        ]);

        $this->assertSame(0, Artisan::call('bookings:reconcile-rooms', $options));
        $this->assertStringContainsString('Booking SJ-NEED already holds room big. Nothing was changed.', Artisan::output());
        $this->assertSame(1, RoomReservation::query()->count());
    }

    public function test_apply_refuses_a_room_that_is_taken_and_never_falls_back(): void
    {
        $this->ready();
        $small = Room::query()->where('code', 'small')->firstOrFail();
        $big = Room::query()->where('code', 'big')->firstOrFail();

        $hold = $this->booking('SJ-HOLD', BookingStatus::Confirmed, '2026-11-10 03:00:00', '2026-11-10');
        $this->reserve($big, $hold, '2026-11-10 03:00:00', '2026-11-10 04:45:00');
        $need = $this->booking('SJ-NEED', BookingStatus::Confirmed, '2026-11-10 03:00:00', '2026-11-10', '10:00', ['guests' => 2]);

        $this->assertSame(1, Artisan::call('bookings:reconcile-rooms', [
            '--booking' => 'SJ-NEED',
            '--room' => 'big',
            '--apply' => true,
        ]));
        $this->assertStringContainsString(
            'Not assigned: room big is not free for the whole treatment and cleaning time. Run bookings:reconcile-rooms to see free rooms.',
            Artisan::output(),
        );
        $this->assertSame(1, RoomReservation::query()->count());

        try {
            (new BookingMutation)->run($need->id,
                fn (Booking $locked) => (new RoomAllocator)->allocateLocked($locked, $big->id));
            $this->fail('The allocator assigned a taken room.');
        } catch (ValidationException $error) {
            $this->assertSame('No compatible room is available for the whole group.', collect($error->errors())->flatten()->first());
        }

        $this->assertSame(0, RoomReservation::query()->where('room_id', $small->id)->count());
        $this->assertSame(1, RoomReservation::query()->count());
    }

    public function test_every_refusal_names_its_cause_and_writes_nothing(): void
    {
        $this->ready();
        $variant = ServiceVariant::query()->where('code', 'base')->sole();
        $small = Room::query()->where('code', 'small')->firstOrFail();

        $off = Room::create([
            'code' => 'off', 'name' => 'Off', 'capacity' => 4,
            'is_active' => false, 'cleaning_minutes' => 15,
        ]);
        $off->variants()->attach($variant);
        $other = Room::create([
            'code' => 'other', 'name' => 'Other', 'capacity' => 4,
            'is_active' => true, 'cleaning_minutes' => 15,
        ]);

        $this->booking('SJ-NEED', BookingStatus::Confirmed, '2026-11-10 03:00:00', '2026-11-10', '10:00', ['guests' => 2]);
        $this->booking('SJ-PEND', BookingStatus::Pending, '2026-11-11 03:00:00', '2026-11-11');
        $this->booking('SJ-AWAIT', BookingStatus::AwaitingPayment, '2026-11-12 03:00:00', '2026-11-12');
        $smallBooking = $this->booking('SJ-SMALL', BookingStatus::Confirmed, '2026-11-13 03:00:00', '2026-11-13');
        $this->reserve($small, $smallBooking, '2026-11-13 03:00:00', '2026-11-13 04:45:00');
        $released = $this->booking('SJ-REL', BookingStatus::Confirmed, '2026-11-14 03:00:00', '2026-11-14');
        $this->reserve($small, $released, '2026-11-14 03:00:00', '2026-11-14 04:45:00', '2026-11-05 00:00:00');
        $this->booking('SJ-DONE', BookingStatus::Confirmed, '2026-11-06 00:00:00', '2026-11-06');
        $this->booking('SJ-LOST', BookingStatus::Confirmed, '2026-11-15 03:00:00', '2026-11-15', '10:00', ['service_variant_id' => null]);
        $this->booking('SJ-THREE', BookingStatus::Confirmed, '2026-11-16 03:00:00', '2026-11-16', '10:00', ['guests' => 3]);
        $this->booking('SJ-VIP', BookingStatus::Confirmed, '2026-11-17 03:00:00', '2026-11-17', '10:00', ['requested_room_type' => 'vip']);

        $cases = [
            'only booking' => [['--booking' => 'SJ-NEED'], 'Pass both --booking=REFERENCE and --room=CODE.'],
            'only apply' => [['--apply' => true], 'Pass both --booking=REFERENCE and --room=CODE.'],
            'unknown booking' => [['--booking' => 'SJ-NOPE', '--room' => 'big'], 'Booking SJ-NOPE was not found.'],
            'unknown room' => [['--booking' => 'SJ-NEED', '--room' => 'nope'], 'Room nope was not found.'],
            'pending' => [['--booking' => 'SJ-PEND', '--room' => 'big', '--apply' => true], 'Not assigned: booking SJ-PEND is pending; only confirmed bookings can be assigned a room here.'],
            'awaiting payment' => [['--booking' => 'SJ-AWAIT', '--room' => 'big', '--apply' => true], 'Not assigned: booking SJ-AWAIT is awaiting payment; online bookings get a room after payment, which is not available yet.'],
            'holds another room' => [['--booking' => 'SJ-SMALL', '--room' => 'big', '--apply' => true], 'Not assigned: booking SJ-SMALL already holds room small. Nothing was changed.'],
            'released' => [['--booking' => 'SJ-REL', '--room' => 'big', '--apply' => true], "Not assigned: booking SJ-REL's earlier room reservation was released and cannot be reopened. Cancel it and record a new booking."],
            'finished' => [['--booking' => 'SJ-DONE', '--room' => 'big', '--apply' => true], 'Not assigned: booking SJ-DONE has already finished.'],
            'no treatment' => [['--booking' => 'SJ-LOST', '--room' => 'big', '--apply' => true], 'Not assigned: booking SJ-LOST has no catalog treatment or an invalid group or duration. Cancel it and record a new booking.'],
            'too small' => [['--booking' => 'SJ-THREE', '--room' => 'small', '--apply' => true], 'Not assigned: room small holds 2, but the booking has 3 guests.'],
            'wrong type' => [['--booking' => 'SJ-VIP', '--room' => 'big', '--apply' => true], 'Not assigned: room big is not of the requested type vip.'],
            'inactive' => [['--booking' => 'SJ-NEED', '--room' => 'off', '--apply' => true], 'Not assigned: room off is inactive.'],
            'not set up' => [['--booking' => 'SJ-NEED', '--room' => 'other', '--apply' => true], 'Not assigned: room other is not set up for this treatment.'],
            // Review additions: released wins over "same room"; --report never writes; an empty value is not a reference.
            'released same room' => [['--booking' => 'SJ-REL', '--room' => 'small', '--apply' => true], "Not assigned: booking SJ-REL's earlier room reservation was released and cannot be reopened. Cancel it and record a new booking."],
            'report with apply' => [['--report' => true, '--booking' => 'SJ-NEED', '--room' => 'big', '--apply' => true], '--report cannot be combined with --booking, --room or --apply.'],
            'empty booking' => [['--booking' => '', '--room' => 'big'], 'Pass both --booking=REFERENCE and --room=CODE.'],
        ];

        foreach ($cases as $label => [$options, $message]) {
            $count = DB::table('room_reservations')->count();
            $this->assertSame(1, Artisan::call('bookings:reconcile-rooms', $options), $label);
            $this->assertStringContainsString($message, Artisan::output(), $label);
            $this->assertSame($count, DB::table('room_reservations')->count(), $label);
        }
    }

    // Review addition: another operator assigns between the pre-checks and the lock. The allocator returns
    // that reservation unchanged, so this run must report it, not claim (or log) an assignment.
    public function test_a_concurrent_assignment_is_reported_not_claimed(): void
    {
        $this->ready();
        $small = Room::query()->where('code', 'small')->firstOrFail();
        $big = Room::query()->where('code', 'big')->firstOrFail();
        $same = $this->booking('SJ-SAME', BookingStatus::Confirmed, '2026-11-10 03:00:00', '2026-11-10');
        $other = $this->booking('SJ-OTHER', BookingStatus::Confirmed, '2026-11-11 03:00:00', '2026-11-11');
        Log::spy();

        foreach ([[$same, $big, 0, 'Booking SJ-SAME already holds room big. Nothing was changed.'],
            [$other, $small, 1, 'Not assigned: booking SJ-OTHER already holds room small. Nothing was changed.']] as [$booking, $racer, $exit, $message]) {
            unset($fired); // each listener gets its own flag; reassigning would re-arm the earlier one
            $fired = false;
            DB::listen(function ($query) use (&$fired, $booking, $racer): void {
                if (! $fired && str_contains($query->sql, 'update "schedule_guards"')) {
                    $fired = true;
                    $start = $booking->getRawOriginal('starts_at');
                    DB::table('room_reservations')->insert(['booking_id' => $booking->id, 'room_id' => $racer->id,
                        'starts_at' => $start, 'treatment_ends_at' => $booking->getRawOriginal('treatment_ends_at'),
                        'ends_at' => CarbonImmutable::parse($start, 'UTC')->addMinutes(105)->format('Y-m-d H:i:s'),
                        'cleaning_minutes' => 15, 'created_at' => now(), 'updated_at' => now()]);
                }
            });

            $this->assertSame($exit, Artisan::call('bookings:reconcile-rooms',
                ['--booking' => $booking->reference, '--room' => 'big', '--apply' => true]));
            $this->assertTrue($fired);
            $this->assertStringContainsString($message, Artisan::output());
            $this->assertSame(1, RoomReservation::query()->where('booking_id', $booking->id)->count());
        }
        Log::shouldNotHaveReceived('info');
    }

    public function test_report_names_awaiting_payment_bookings_and_the_next_step(): void
    {
        $this->ready();
        $this->booking('SJ-AWAIT', BookingStatus::AwaitingPayment, '2026-11-10 03:00:00', '2026-11-10');

        $this->assertSame(1, Artisan::call('bookings:reconcile-rooms'));
        $output = Artisan::output();
        $this->assertStringContainsString(
            '- Booking SJ-AWAIT on 2026-11-10 10:00 WIB is awaiting payment; online bookings get a room after payment, which is not available yet.',
            $output,
        );
        $this->assertStringContainsString(
            'To assign one, rerun with --booking=REFERENCE --room=CODE to check it, then add --apply. This report changes nothing.',
            $output,
        );
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
        foreach ([['small', 2], ['big', 4]] as [$code, $capacity]) {
            $room = Room::create([
                'code' => $code, 'name' => ucfirst($code), 'capacity' => $capacity,
                'is_active' => true, 'cleaning_minutes' => 15,
            ]);
            $room->variants()->attach($variant);
        }

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
}
