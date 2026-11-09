<?php

namespace Tests\Feature;

use App\Actions\ApproveBooking;
use App\Actions\Catalog\AttestPackagePricing;
use App\Actions\CreateStaffBooking;
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
use App\Support\ActionContext;
use App\Support\CustomerIdentity;
use Carbon\CarbonImmutable;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StaffBookingTest extends TestCase
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

    // Production break caught: browser-supplied identity, status, pricing and channel fields must not control a staff booking.
    public function test_staff_creates_an_accountless_pending_walk_in_booking(): void
    {
        $staff = User::factory()->bookingStaff()->create();

        $response = $this->actingAs($staff)->post('/admin/bookings', $this->httpPayload([
            'user_id' => 999,
            'created_by_staff_id' => 999,
            'payment_preference' => 'online',
            'source_channel' => 'website',
            'status' => BookingStatus::Confirmed->value,
            'total_price_idr' => 1,
        ]));

        $booking = Booking::query()->firstOrFail();

        $response
            ->assertStatus(303)
            ->assertRedirect('/admin/bookings/'.$booking->reference);

        $this->assertNull($booking->user_id);
        $this->assertSame($staff->id, $booking->created_by_staff_id);
        $this->assertSame('walk_in', $booking->source_channel);
        $this->assertSame('at_spa', $booking->payment_preference);
        $this->assertNull($booking->customer_email);
        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame(1, $booking->booking_version);
        $this->assertSame(340000, $booking->total_price_idr);
        $this->assertNotNull($booking->starts_at);
        $this->assertSame(CustomerIdentity::phone('+62 812-1111-2222'), $booking->customer_phone);
        $this->assertDatabaseCount('booking_status_changes', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    // Production break caught: a staff-typed email must never attach an existing customer account.
    public function test_contact_email_is_normalized_and_never_links_an_account(): void
    {
        $customer = User::factory()->create(['email' => 'ayu@example.test']);
        $staff = User::factory()->bookingStaff()->create();

        $this->actingAs($staff)->post('/admin/bookings', $this->httpPayload([
            'email' => '  AYU@Example.TEST ',
        ]))->assertStatus(303);

        $booking = Booking::query()->firstOrFail();

        $this->assertSame('ayu@example.test', $booking->customer_email);
        $this->assertNull($booking->user_id);
        $this->assertSame(0, $customer->bookings()->count());
    }

    // Production break caught: a network retry must return the same booking without rerunning approval.
    public function test_manual_submission_replay_keeps_customer_identity(): void
    {
        $staff = User::factory()->bookingStaff()->create();
        $data = $this->data();

        $first = app(CreateStaffBooking::class)($staff, $data, false, $this->context());
        app(ApproveBooking::class)($staff, $first->id, BookingStatus::Pending, $this->context());

        $replayed = app(CreateStaffBooking::class)($staff, $data, false, $this->context());

        $this->assertSame($first->id, $replayed->id);
        $this->assertSame(BookingStatus::Confirmed, $replayed->status);
        $this->assertSame(2, $replayed->booking_version);
        $this->assertSame($first->customer_name, $replayed->customer_name);
        $this->assertSame($first->customer_phone, $replayed->customer_phone);
        $this->assertSame($first->customer_email, $replayed->customer_email);
        $this->assertNull($replayed->user_id);
        $this->assertDatabaseCount('booking_status_changes', 1);
        $this->assertSame(1, DB::table('outbox_messages')->where('type', 'booking.approved')->count());
        $this->assertDatabaseCount('bookings', 1);
    }

    // Production break caught: reusing a key with a different payload or approval flag must not silently return the old booking.
    public function test_same_key_with_changed_contact_or_approval_flag_is_rejected(): void
    {
        $staff = User::factory()->bookingStaff()->create();
        $data = $this->data();

        app(CreateStaffBooking::class)($staff, $data, false, $this->context());

        foreach ([
            [$this->data(['phone' => '+62 812-9999-8888']), false],
            [$data, true],
        ] as [$changed, $approve]) {
            try {
                app(CreateStaffBooking::class)($staff, $changed, $approve, $this->context());
                $this->fail('A changed replay was accepted: '.json_encode($changed));
            } catch (ValidationException $error) {
                $this->assertSame(['submission_key'], array_keys($error->errors()));
            }
        }

        $this->assertSame(BookingStatus::Pending, Booking::query()->sole()->status);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    // Production break caught: one staff member's submission key must not retrieve another staff member's booking.
    public function test_another_staff_member_can_reuse_the_key_without_retrieving_the_booking(): void
    {
        $firstStaff = User::factory()->bookingStaff()->create();
        $secondStaff = User::factory()->bookingStaff()->create();
        $data = $this->data();

        $first = app(CreateStaffBooking::class)($firstStaff, $data, false, $this->context());
        $second = app(CreateStaffBooking::class)($secondStaff, $data, false, $this->context());

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('bookings', 2);
    }

    // Production break caught: create-and-approve must record a staff-attributed history row and the approved event.
    public function test_create_and_approve_confirms_with_staff_audit(): void
    {
        $staff = User::factory()->bookingStaff()->create();

        $booking = app(CreateStaffBooking::class)($staff, $this->data(), true, $this->context());

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(2, $booking->booking_version);
        $this->assertNotNull($booking->approved_at);

        $change = DB::table('booking_status_changes')->where('booking_id', $booking->id)->sole();
        $this->assertSame($staff->id, $change->admin_user_id);
        $this->assertSame('staff', $change->actor_type);
        $this->assertSame('dashboard', $change->channel);
        $this->assertSame('203.0.113.20', $change->ip_address);
        $this->assertSame(1, DB::table('outbox_messages')
            ->where('deduplication_key', 'booking.approved:'.$booking->id.':2')->count());
    }

    // Production break caught: a failed allocation inside create-and-approve must roll back the whole creation.
    public function test_create_and_approve_with_scheduling_allocates_a_room_or_rolls_back_entirely(): void
    {
        $room = $this->enableScheduling();
        $staff = User::factory()->bookingStaff()->create();
        $data = $this->scheduledData();

        try {
            app(CreateStaffBooking::class)($staff, $data, true, $this->context());
            $this->fail('Approval without operational confirmation was accepted.');
        } catch (ValidationException $error) {
            $this->assertSame(['operationalConfirmed'], array_keys($error->errors()));
        }

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('room_reservations', 0);
        $this->assertDatabaseCount('booking_status_changes', 0);
        $this->assertDatabaseCount('outbox_messages', 0);

        $booking = app(CreateStaffBooking::class)($staff, $data, true, $this->context(), true);

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(1, RoomReservation::query()
            ->where('booking_id', $booking->id)->where('room_id', $room->id)->count());

        try {
            app(CreateStaffBooking::class)($staff, $this->scheduledData([
                'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a31',
            ]), false, $this->context());
            $this->fail('An occupied slot was accepted.');
        } catch (ValidationException $error) {
            $this->assertSame(['time'], array_keys($error->errors()));
        }

        $this->assertDatabaseCount('bookings', 1);
    }

    // Production break caught: staff without the booking capability and customers must not create bookings.
    public function test_staff_without_booking_permission_and_customers_cannot_create(): void
    {
        $this->post('/admin/bookings', $this->httpPayload())->assertRedirect();

        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/bookings', $this->httpPayload())
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post('/admin/bookings', $this->httpPayload())
            ->assertForbidden();

        // Authorization precedes validation: no field errors leak to an admin without booking permission.
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/admin/bookings', $this->httpPayload(['source' => 'website']))
            ->assertForbidden();

        $this->assertDatabaseCount('bookings', 0);
    }

    // Review fix (B5b): the entry page shows server messages, so they must be localized, not raw keys or English fallbacks.
    public function test_staff_validation_messages_are_localized(): void
    {
        $staff = User::factory()->bookingStaff()->create();
        $payload = $this->httpPayload(['phone' => null, 'email' => null, 'mode' => null, 'duration' => null]);

        $this->actingAs($staff)->withSession(['locale' => 'id'])
            ->postJson('/admin/bookings', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'Isi nomor telepon atau email agar tamu dapat dihubungi.')
            ->assertJsonPath('errors.mode.0', 'Jenis perawatan wajib diisi.')
            ->assertJsonPath('errors.duration.0', 'Durasi wajib diisi.');

        $this->actingAs($staff)->withSession(['locale' => 'en'])
            ->postJson('/admin/bookings', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'Enter a phone number or an email so the guest can be reached.')
            ->assertJsonPath('errors.mode.0', 'The treatment type field is required.')
            ->assertJsonPath('errors.duration.0', 'The duration field is required.');
    }

    // Production break caught: a phone or WhatsApp booking saved with no way to reach the customer.
    public function test_staff_booking_needs_a_phone_or_an_email(): void
    {
        $staff = User::factory()->bookingStaff()->create();

        $this->actingAs($staff)
            ->postJson('/admin/bookings', $this->httpPayload(['phone' => null, 'email' => null, 'source' => 'phone']))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('bookings', 0);

        $this->actingAs($staff)
            ->post('/admin/bookings', $this->httpPayload(['phone' => null, 'email' => 'guest@example.test']))
            ->assertStatus(303);
        $this->assertSame('', Booking::query()->sole()->customer_phone);
    }

    // Production break caught: the controller must forward the approval flag and the operational attestation.
    public function test_http_create_and_approve_forwards_the_operational_confirmation(): void
    {
        $room = $this->enableScheduling();
        $staff = User::factory()->bookingStaff()->create();
        $data = $this->scheduledData();
        unset($data['appointment_date'], $data['appointment_time']);
        $payload = [...$data, 'date' => '2026-10-26', 'time' => '09:00', 'approve' => true];

        $this->actingAs($staff)->from('/admin/bookings/create')
            ->post('/admin/bookings', $payload)
            ->assertRedirect('/admin/bookings/create')
            ->assertSessionHasErrors('operationalConfirmed');
        $this->assertDatabaseCount('bookings', 0);

        $this->actingAs($staff)
            ->post('/admin/bookings', [...$payload, 'operationalConfirmed' => true])
            ->assertStatus(303);

        $booking = Booking::query()->sole();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(1, RoomReservation::query()->where('booking_id', $booking->id)->where('room_id', $room->id)->count());
    }

    // Production break caught: staff-only fields must be validated before any booking is written.
    public function test_price_mismatch_source_email_and_approval_flag_are_validated(): void
    {
        $staff = User::factory()->bookingStaff()->create();

        $this->actingAs($staff)->postJson('/admin/bookings', $this->httpPayload([
            'expected_total_price_idr' => 1,
        ]))->assertUnprocessable()->assertJsonValidationErrors('expected_total_price_idr');

        $this->actingAs($staff)->postJson('/admin/bookings', $this->httpPayload([
            'source' => 'website',
        ]))->assertUnprocessable()->assertJsonValidationErrors('source');

        $this->actingAs($staff)->postJson('/admin/bookings', $this->httpPayload([
            'email' => 'not-an-email',
        ]))->assertUnprocessable()->assertJsonValidationErrors('email');

        $payload = $this->httpPayload();
        unset($payload['approve']);
        $this->actingAs($staff)->postJson('/admin/bookings', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('approve');

        $this->assertDatabaseCount('bookings', 0);
    }

    // Production break caught: the manual identity migration must keep the status CHECK and per-staff key uniqueness.
    public function test_manual_identity_migration_keeps_the_status_check_and_staff_key_uniqueness(): void
    {
        $booking = Booking::factory()->create(['customer_email' => null, 'user_id' => null]);
        $this->assertNull($booking->fresh()->customer_email);

        try {
            DB::table('bookings')->where('id', $booking->id)->update(['status' => 'bogus']);
            $this->fail('The status CHECK accepted a bogus value.');
        } catch (QueryException) {
            $this->assertSame('pending', DB::table('bookings')->where('id', $booking->id)->value('status'));
        }

        Booking::factory()->create(['status' => BookingStatus::NoShow]);

        $staff = User::factory()->bookingStaff()->create();
        $key = '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a40';
        Booking::factory()->create([
            'user_id' => null,
            'created_by_staff_id' => $staff->id,
            'submission_key' => $key,
        ]);

        try {
            Booking::factory()->create([
                'user_id' => null,
                'created_by_staff_id' => $staff->id,
                'submission_key' => $key,
            ]);
            $this->fail('The staff submission key was not unique.');
        } catch (QueryException) {
            // The second insert must be rejected by bookings_staff_submission_unique.
        }

        $this->assertDatabaseCount('bookings', 3);

        // MySQL: an index led by created_by_staff_id silently replaces the foreign key's own index,
        // after which 010011's down() cannot drop it (error 1553). submission_key must lead.
        $index = collect(Schema::getIndexes('bookings'))->firstWhere('name', 'bookings_staff_submission_unique');
        $this->assertSame(['submission_key', 'created_by_staff_id'], $index['columns']);
        $this->assertTrue($index['unique']);
    }

    // Production break caught: a retry that arrives after the slot time must return the original booking.
    public function test_http_replay_after_the_appointment_time_returns_the_original_booking(): void
    {
        $staff = User::factory()->bookingStaff()->create();
        $payload = $this->httpPayload();

        $this->actingAs($staff)->post('/admin/bookings', $payload)->assertStatus(303);
        $booking = Booking::query()->sole();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 09:00:00', 'Asia/Jakarta'));

        $this->actingAs($staff)->post('/admin/bookings', $payload)
            ->assertStatus(303)
            ->assertRedirect('/admin/bookings/'.$booking->reference);

        $this->assertDatabaseCount('bookings', 1);
    }

    private function data(array $overrides = []): array
    {
        return [...[
            'name' => 'Walk In Guest',
            'phone' => '+62 812-1111-2222',
            'email' => null,
            'source' => 'walk_in',
            'service' => 'nezuko',
            'mode' => 'regular',
            'duration' => 90,
            'extension' => 'nezuko-regular-extra-60',
            'appointment_date' => '2026-09-07',
            'appointment_time' => '10:30',
            'guests' => 1,
            'notes' => null,
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a20',
            'expected_total_price_idr' => 340000,
        ], ...$overrides];
    }

    private function httpPayload(array $overrides = []): array
    {
        $data = $this->data();
        unset($data['appointment_date'], $data['appointment_time']);

        return [...$data, 'date' => '2026-09-07', 'time' => '10:30', 'approve' => false, ...$overrides];
    }

    private function scheduledData(array $overrides = []): array
    {
        return $this->data([
            'service' => 'local',
            'duration' => 60,
            'extension' => null,
            'appointment_date' => '2026-10-26',
            'appointment_time' => '09:00',
            'expected_total_price_idr' => 100000,
            ...$overrides,
        ]);
    }

    private function context(): ActionContext
    {
        return new ActionContext('dashboard', '203.0.113.20', 'Staff booking test');
    }

    private function enableScheduling(): Room
    {
        CarbonImmutable::setTestNow('2026-10-25 23:00:00.123456 UTC');
        config(['booking.scheduling_enabled' => true, 'services.telegram.booking_notifications_enabled' => true]);
        Bus::fake();

        BookingSettings::create(['id' => 1, 'version' => 1,
            'review_intervals' => [['weekday' => 1, 'start_minute' => 540, 'end_minute' => 1260]],
            'review_target_minutes' => 30, 'booking_horizon_days' => 2,
            'minimum_lead_minutes' => 0, 'minimum_checkout_minutes' => 5]);
        $category = ServiceCategory::create(['slug' => 'local', 'name_id' => 'Local', 'name_en' => 'Local',
            'image_path' => '', 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        $service = Service::create(['category_id' => $category->id, 'slug' => 'local', 'name_id' => 'Local', 'name_en' => 'Local',
            'description_id' => '', 'description_en' => '', 'long_description_id' => '', 'long_description_en' => '',
            'duration_minutes' => 60, 'price_idr' => 100000, 'image_path' => '', 'gallery_paths' => [],
            'popularity' => 0, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        $variant = ServiceVariant::create(['service_id' => $service->id, 'code' => 'base', 'treatment_mode' => 'regular',
            'duration_minutes' => 60, 'price_idr' => 100000, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        app(AttestPackagePricing::class)(User::factory()->owner()->create(), $service,
            (int) $service->lock_version, PricingBasis::PerBooking, 1, 4);
        $room = Room::create(['code' => 'room', 'name' => 'Room', 'type' => 'standard', 'capacity' => 2,
            'is_active' => true, 'cleaning_minutes' => 15]);
        $room->variants()->attach($variant);

        return $room;
    }
}
