<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\PricingBasis;
use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminTodayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 12:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // Production break caught: the Today page must open on the work waiting for staff, oldest request first.
    public function test_queue_lists_waiting_requests_oldest_first_with_the_full_count(): void
    {
        $staff = User::factory()->bookingStaff()->create();
        for ($day = 1; $day <= 9; $day++) {
            $this->pending(sprintf('SJ-QUEUE-%02d', $day), sprintf('2026-09-%02d 01:00:00', 7 - min($day, 6)), $day);
        }
        Booking::factory()->create(['reference' => 'SJ-QUEUE-CONFIRMED', 'status' => BookingStatus::Confirmed]);

        $response = $this->actingAs($staff)->get('/admin');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/Dashboard', false)
            ->where('queueTotal', 9)
            ->has('queue', 8)
            ->where('queue.0.reference', 'SJ-QUEUE-06')
            ->where('queue.0.allowedTransitions', ['confirmed', 'cancelled'])
            ->where('roomSchedulingEnabled', false)
            ->where('canCreate', true));
        $this->assertSame(
            ['SJ-QUEUE-06', 'SJ-QUEUE-07', 'SJ-QUEUE-08', 'SJ-QUEUE-09', 'SJ-QUEUE-05', 'SJ-QUEUE-04', 'SJ-QUEUE-03', 'SJ-QUEUE-02'],
            array_column($response->inertiaProps('queue'), 'reference'),
        );
    }

    // Production break caught: today's list must show every appointment of the day in time order, but no cancelled one.
    public function test_today_lists_this_days_appointments_by_time_without_cancelled_ones(): void
    {
        $staff = User::factory()->bookingStaff()->create();
        $late = $this->on('SJ-TODAY-LATE', '2026-09-07', '17:00', BookingStatus::Confirmed);
        $early = $this->on('SJ-TODAY-EARLY', '2026-09-07', '09:00', BookingStatus::Completed);
        $noon = $this->on('SJ-TODAY-NOON', '2026-09-07', '12:00', BookingStatus::Pending);
        $this->on('SJ-TODAY-CANCELLED', '2026-09-07', '10:00', BookingStatus::Cancelled);
        $this->on('SJ-TOMORROW', '2026-09-08', '09:00', BookingStatus::Confirmed);
        $this->on('SJ-YESTERDAY', '2026-09-06', '09:00', BookingStatus::Confirmed);

        $response = $this->actingAs($staff)->get('/admin');

        $this->assertSame(
            [$early->reference, $noon->reference, $late->reference],
            array_column($response->inertiaProps('today'), 'reference'),
        );
    }

    // Production break caught: only the owner can verify pricing, so only the owner is told which packages still need it.
    public function test_only_the_owner_sees_how_many_public_packages_have_unverified_pricing(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $before = $this->actingAs($owner)->get('/admin')->inertiaProps('pricingUnverified');
        $this->assertIsInt($before);

        $service = $this->publicService('today-unverified');
        $this->assertSame($before + 1, $this->get('/admin')->inertiaProps('pricingUnverified'));

        $service->forceFill([
            'pricing_basis' => PricingBasis::PerPerson,
            'min_guests' => 1,
            'max_guests' => 2,
            'pricing_verified_at' => now(),
            'pricing_verified_by' => $owner->id,
        ])->save();
        $this->assertSame($before, $this->get('/admin')->inertiaProps('pricingUnverified'));

        $this->actingAs($staff)->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('pricingUnverified', null));
    }

    // Production break caught: the page carries only its own data, not the old dashboard totals.
    public function test_today_props_are_an_exact_allow_list(): void
    {
        $staff = User::factory()->bookingStaff()->create();

        $props = $this->actingAs($staff)->get('/admin')->assertOk()->inertiaProps();

        foreach (['queue', 'queueTotal', 'today', 'pricingUnverified', 'roomSchedulingEnabled', 'canCreate'] as $key) {
            $this->assertArrayHasKey($key, $props);
        }
        foreach (['stats', 'upcoming', 'recent', 'categoryStats'] as $key) {
            $this->assertArrayNotHasKey($key, $props);
        }
    }

    // Production break caught: every admin page must show how many requests wait, and nobody else may learn it.
    public function test_admin_pages_share_the_waiting_count_and_other_visitors_get_none(): void
    {
        $staff = User::factory()->bookingStaff()->create();
        $customer = User::factory()->create();
        $this->pending('SJ-BADGE-1', '2026-09-06 01:00:00', 1);
        $this->pending('SJ-BADGE-2', '2026-09-06 02:00:00', 2);
        Booking::factory()->create(['status' => BookingStatus::Confirmed]);

        $this->actingAs($staff)->get('/admin/bookings')->assertInertia(fn (Assert $page) => $page
            ->where('adminNav.pendingBookings', 2));
        $this->actingAs($customer)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('adminNav', null));
    }

    private function pending(string $reference, string $createdAt, int $dayOffset): Booking
    {
        return Booking::factory()->create([
            'reference' => $reference,
            'status' => BookingStatus::Pending,
            'created_at' => $createdAt,
            'appointment_date' => CarbonImmutable::parse('2026-09-08')->addDays($dayOffset)->toDateString(),
        ]);
    }

    private function on(string $reference, string $date, string $time, BookingStatus $status): Booking
    {
        return Booking::factory()->create([
            'reference' => $reference,
            'appointment_date' => $date,
            'appointment_time' => $time,
            'status' => $status,
        ]);
    }

    private function publicService(string $slug): Service
    {
        $category = ServiceCategory::query()->create([
            'slug' => "{$slug}-category",
            'name_id' => 'Kategori Hari Ini',
            'name_en' => 'Today Category',
            'image_path' => null,
            'published_at' => now(),
            'lock_version' => 0,
            'is_active' => true,
            'display_order' => 99,
        ]);
        $service = Service::query()->create([
            'category_id' => $category->id,
            'slug' => $slug,
            'name_id' => 'Paket Hari Ini',
            'name_en' => 'Today Package',
            'description_id' => null,
            'description_en' => null,
            'long_description_id' => null,
            'long_description_en' => null,
            'good_for_id' => null,
            'good_for_en' => null,
            'duration_minutes' => null,
            'price_idr' => null,
            'image_path' => null,
            'gallery_paths' => [],
            'popularity' => 0,
            'is_vip' => false,
            'is_active' => true,
            'published_at' => now(),
            'lock_version' => 0,
            'display_order' => 99,
        ]);
        ServiceVariant::query()->create([
            'service_id' => $service->id,
            'code' => "{$slug}-60",
            'treatment_mode' => 'regular',
            'duration_minutes' => 60,
            'price_idr' => 180000,
            'is_active' => true,
            'published_at' => now(),
            'display_order' => 0,
        ]);

        return $service;
    }
}
