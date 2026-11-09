<?php

namespace Tests\Feature\Admin;

use App\Actions\CreateStaffBooking;
use App\Models\Booking;
use App\Models\User;
use App\Support\ActionContext;
use Carbon\CarbonImmutable;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffBookingPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 09:00:00', 'Asia/Jakarta'));
        $this->seed(ServiceCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // Production break caught: the entry page must receive the same catalog the customer form uses and a fresh key per load.
    public function test_booking_staff_gets_the_entry_page_with_catalog_and_a_fresh_key(): void
    {
        $staff = User::factory()->bookingStaff()->create();

        $first = $this->actingAs($staff)->get('/admin/bookings/create');

        $first->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/bookings/Create', false)
            ->has('packages')
            ->where('availabilityEnabled', false)
            ->where('sources', ['walk_in', 'phone', 'whatsapp'])
            ->where('submissionKey', fn ($key): bool => is_string($key) && Str::isUuid($key)));

        $this->assertIsString($first->inertiaProps('packages.0.slug'));

        $second = $this->get('/admin/bookings/create')->assertOk();

        $this->assertNotSame(
            $first->inertiaProps('submissionKey'),
            $second->inertiaProps('submissionKey'),
        );
    }

    // Production break caught: staff without the booking capability, customers and guests must not reach the entry page.
    public function test_entry_page_requires_booking_permission(): void
    {
        $this->get('/admin/bookings/create')->assertRedirect();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/bookings/create')
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->get('/admin/bookings/create')
            ->assertForbidden();
    }

    // Production break caught: the index must only offer the entry link to actors who may actually create bookings.
    public function test_index_offers_new_booking_only_to_booking_staff(): void
    {
        $this->actingAs(User::factory()->bookingStaff()->create())
            ->get('/admin/bookings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCreate', true));

        $this->actingAs(User::factory()->owner()->create())
            ->get('/admin/bookings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCreate', true));

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/bookings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCreate', false));
    }

    // Production break caught: the detail page must show where a booking came from, even after the staff account is deleted.
    public function test_detail_shows_the_origin_of_staff_and_website_bookings(): void
    {
        $rani = User::factory()->bookingStaff()->create(['name' => 'Rani']);
        $viewer = User::factory()->bookingStaff()->create();

        $staffBooking = app(CreateStaffBooking::class)($rani, [
            'name' => 'Phone Guest',
            'phone' => '+62 812-1111-2222',
            'email' => null,
            'source' => 'phone',
            'service' => 'nezuko',
            'mode' => 'regular',
            'duration' => 90,
            'extension' => null,
            'appointment_date' => '2026-09-07',
            'appointment_time' => '10:30',
            'guests' => 1,
            'notes' => null,
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a50',
            'expected_total_price_idr' => 265000,
        ], false, new ActionContext('dashboard', '203.0.113.20', 'Staff booking pages test'));

        $this->actingAs($viewer)
            ->get(route('admin.bookings.show', $staffBooking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('booking.origin', [
                'source' => 'phone',
                'staffName' => 'Rani',
            ]));

        $websiteBooking = Booking::factory()->create([
            'source_channel' => 'website',
            'created_by_staff_id' => null,
        ]);

        $this->get(route('admin.bookings.show', $websiteBooking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('booking.origin', [
                'source' => 'website',
                'staffName' => null,
            ]));

        $rani->delete();

        $this->get(route('admin.bookings.show', $staffBooking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('booking.origin', [
                'source' => 'phone',
                'staffName' => null,
            ]));
    }

    // Production break caught: a booking reference that is literally "create" must not capture the entry route.
    public function test_entry_page_route_is_not_captured_by_the_reference_route(): void
    {
        Booking::factory()->create(['reference' => 'create']);

        $this->actingAs(User::factory()->bookingStaff()->create())
            ->get('/admin/bookings/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/bookings/Create', false));
    }
}
