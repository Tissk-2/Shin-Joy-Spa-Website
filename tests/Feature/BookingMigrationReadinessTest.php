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
use App\Queries\BookingReadinessQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingMigrationReadinessTest extends TestCase
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

    public function test_a_fully_configured_site_is_ready(): void
    {
        $this->ready();
        $room = Room::query()->where('code', 'room')->firstOrFail();
        $booking = $this->booking('SJ-HELD', BookingStatus::Confirmed, '2026-11-10 03:00:00', '2026-11-10');
        RoomReservation::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'starts_at' => '2026-11-10 03:00:00',
            'treatment_ends_at' => '2026-11-10 04:30:00',
            'ends_at' => '2026-11-10 04:45:00',
            'cleaning_minutes' => 15,
            'released_at' => null,
            'release_reason' => null,
        ]);

        $this->assertSame([], (new BookingReadinessQuery)->issues(CarbonImmutable::now()));

        $this->artisan('bookings:readiness')
            ->expectsOutputToContain('Booking readiness: ready.')
            ->expectsOutputToContain('Room scheduling is currently off. This command never changes it.')
            ->assertExitCode(0);

        // Review addition: the flag line reports the real setting.
        config(['booking.scheduling_enabled' => true]);
        $this->artisan('bookings:readiness')
            ->expectsOutputToContain('Room scheduling is currently on. This command never changes it.')
            ->assertExitCode(0);
    }

    public function test_missing_settings_pricing_and_rooms_are_each_reported(): void
    {
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
        $room = Room::create([
            'code' => 'room', 'name' => 'Room', 'capacity' => 2,
            'is_active' => false, 'cleaning_minutes' => 15,
        ]);
        $room->variants()->attach($variant);

        $this->assertSame([
            '[settings] Booking settings have not been saved. The owner saves them at /admin/settings/booking.',
            '[pricing] Package local has no verified pricing.',
            '[rooms] No room is active.',
            '[rooms] Variant base of package local has no active compatible room.',
        ], (new BookingReadinessQuery)->issues(CarbonImmutable::now()));
    }

    public function test_invalid_settings_and_small_rooms_are_reported(): void
    {
        $this->ready();
        DB::table('booking_settings')->update(['review_intervals' => '[]']);
        // Review fix: the package sells up to 4 guests, so a 3-person room is too small even though it fits the minimum.
        DB::table('rooms')->where('code', 'room')->update(['capacity' => 3]);

        $this->assertSame([
            '[settings] Saved booking settings are invalid. The owner re-saves them at /admin/settings/booking.',
            '[rooms] Variant base of package local has no active compatible room for 4 guests.',
        ], (new BookingReadinessQuery)->issues(CarbonImmutable::now()));
    }

    public function test_future_confirmed_bookings_without_a_room_are_listed_without_contact_data(): void
    {
        $this->ready();
        $room = Room::query()->where('code', 'room')->firstOrFail();

        $held = $this->booking('SJ-HELD', BookingStatus::Confirmed, '2026-11-10 03:00:00', '2026-11-10');
        RoomReservation::create([
            'booking_id' => $held->id,
            'room_id' => $room->id,
            'starts_at' => '2026-11-10 03:00:00',
            'treatment_ends_at' => '2026-11-10 04:30:00',
            'ends_at' => '2026-11-10 04:45:00',
            'cleaning_minutes' => 15,
            'released_at' => null,
            'release_reason' => null,
        ]);

        $this->booking('SJ-NOROOM', BookingStatus::Confirmed, '2026-11-10 05:00:00', '2026-11-10', '12:00', [
            'customer_name' => 'Secret Guest',
            'customer_email' => 'secret@example.test',
            'customer_phone' => '+6289999999999',
        ]);

        $released = $this->booking('SJ-RELEASED', BookingStatus::Confirmed, '2026-11-11 03:00:00', '2026-11-11');
        RoomReservation::create([
            'booking_id' => $released->id,
            'room_id' => $room->id,
            'starts_at' => '2026-11-11 03:00:00',
            'treatment_ends_at' => '2026-11-11 04:30:00',
            'ends_at' => '2026-11-11 04:45:00',
            'cleaning_minutes' => 15,
            'released_at' => '2026-11-05 00:00:00',
            'release_reason' => 'cancelled_before_start',
        ]);

        $this->booking('SJ-PENDING', BookingStatus::Pending, '2026-11-10 07:00:00', '2026-11-10', '10:00', ['service_variant_id' => ServiceVariant::query()->where('code', 'base')->value('id')]);
        $this->booking('SJ-PAST', BookingStatus::Confirmed, '2026-11-01 03:00:00', '2026-11-01');
        $this->booking('SJ-CANCELLED', BookingStatus::Cancelled, '2026-11-10 08:00:00', '2026-11-10');
        $this->booking('SJ-NOSTART', BookingStatus::Confirmed, null, '2026-11-12', '09:30');
        $this->booking('SJ-OLDNOSTART', BookingStatus::Pending, null, '2026-11-01');
        // Review additions: awaiting payment also needs a room and is named by its own status;
        // a treatment already under way at 09:00 WIB still needs its room until it ends.
        $this->booking('SJ-AWAIT', BookingStatus::AwaitingPayment, '2026-11-13 03:00:00', '2026-11-13');
        $this->booking('SJ-INPROGRESS', BookingStatus::Confirmed, '2026-11-06 02:00:00', '2026-11-06', '09:00');

        $this->assertSame([
            '[assignments] Booking SJ-NOSTART on 2026-11-12 09:30 WIB has no valid start time.',
            '[assignments] Booking SJ-INPROGRESS on 2026-11-06 09:00 WIB is confirmed but holds no room.',
            '[assignments] Booking SJ-NOROOM on 2026-11-10 12:00 WIB is confirmed but holds no room.',
            '[assignments] Booking SJ-RELEASED on 2026-11-11 10:00 WIB is confirmed but holds no room.',
            '[assignments] Booking SJ-AWAIT on 2026-11-13 10:00 WIB is awaiting payment but holds no room.',
        ], (new BookingReadinessQuery)->issues(CarbonImmutable::now()));

        $code = Artisan::call('bookings:readiness');
        $output = Artisan::output();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Booking readiness: not ready (5 issues).', $output);
        $this->assertStringNotContainsString('Secret Guest', $output);
        $this->assertStringNotContainsString('secret@example.test', $output);
        $this->assertStringNotContainsString('+6289999999999', $output);
    }

    public function test_the_check_only_reads_and_reports_a_missing_schema(): void
    {
        $this->ready();
        $this->booking('SJ-NOROOM', BookingStatus::Confirmed, '2026-11-10 05:00:00', '2026-11-10', '12:00');

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $this->assertSame(1, Artisan::call('bookings:readiness'));

        $this->assertNotSame([], $sql);
        foreach ($sql as $statement) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $statement);
        }

        Schema::drop('booking_settings');

        $this->assertSame([
            '[schema] Room scheduling tables are missing or incomplete. Run the pending migrations first.',
        ], (new BookingReadinessQuery)->issues(CarbonImmutable::now()));
        $this->assertSame(1, Artisan::call('bookings:readiness'));
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
            // Every writer sets both instants together; the factory's 90-minute treatment.
            ...($startsAt === null ? [] : ['starts_at' => $startsAt,
                'treatment_ends_at' => CarbonImmutable::parse($startsAt, 'UTC')->addMinutes(90)->format('Y-m-d H:i:s')]),
            ...$extra,
        ]);
    }
}
