<?php

namespace Tests\Feature;

use App\Actions\Catalog\AttestPackagePricing;
use App\Actions\CreateBooking;
use App\Enums\PricingBasis;
use App\Models\Booking;
use App\Models\BookingSettings;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomReservation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Queries\RoomAvailabilityQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingSubmissionAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Room $room;

    private ServiceVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-25 23:00:00.123456 UTC');
        config(['booking.scheduling_enabled' => true, 'services.telegram.booking_notifications_enabled' => true]);
        Bus::fake();
        $this->customer = User::factory()->create();
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
        $this->variant = ServiceVariant::create(['service_id' => $service->id, 'code' => 'base', 'treatment_mode' => 'regular',
            'duration_minutes' => 60, 'price_idr' => 100000, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        app(AttestPackagePricing::class)(User::factory()->owner()->create(), $service,
            (int) $service->lock_version, PricingBasis::PerBooking, 1, 4);
        $this->room = Room::create(['code' => 'room', 'name' => 'Room', 'type' => 'standard', 'capacity' => 2,
            'is_active' => true, 'cleaning_minutes' => 15]);
        $this->room->variants()->attach($this->variant);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // Removing the action availability call would allow an occupied requested instant.
    public function test_http_rejects_stale_exact_slot_even_when_another_slot_is_available_and_preserves_draft(): void
    {
        $this->actingAs($this->customer)->getJson('/booking/availability?'.http_build_query($this->selection()))
            ->assertOk()->assertJsonPath('slots.0.rooms.0.available', true);
        $this->block('02:00:00', '03:00:00');
        $slots = app(RoomAvailabilityQuery::class)->forSelection($this->selection())['slots'];
        $this->assertFalse($slots[0]['rooms'][0]['available']);
        $this->assertTrue($slots[2]['rooms'][0]['available']);
        $before = $this->scheduleSnapshot();
        $this->withSession(['locale' => 'en'])->from('/booking')->post('/bookings', $this->httpPayload())->assertRedirect('/booking')
            ->assertSessionHasErrors(['time' => 'The selected time is no longer available. Choose another time.'])
            ->assertSessionHasInput('notes', 'Keep this draft');
        $this->assertRejected($before);
    }

    public function test_direct_rejection_precedes_price_mismatch_and_http_json_uses_422(): void
    {
        $this->block('02:00:00', '03:00:00');
        $before = $this->scheduleSnapshot();
        $this->assertInvalid(['expected_total_price_idr' => 1], 'time');
        $this->actingAs($this->customer)->postJson('/bookings', $this->httpPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->assertRejected($before);
    }

    public function test_available_pending_duplicates_do_not_hold_and_replay_never_duplicates_notification(): void
    {
        $before = $this->scheduleSnapshot();
        $data = $this->data();
        $first = app(CreateBooking::class)($this->customer, $data);
        $second = app(CreateBooking::class)($this->customer, $this->data());
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('pending', $first->status->value);
        $this->assertSame(100000, $first->total_price_idr);
        $this->assertSame($before, $this->scheduleSnapshot());
        $this->assertDatabaseCount('room_reservations', 0);
        $this->assertSame($first->id, app(CreateBooking::class)($this->customer, $data)->id);
        $this->assertDatabaseCount('outbox_messages', 2);
    }

    public function test_flag_requires_literal_true_and_missing_schema_remains_compatible_without_new_reads(): void
    {
        foreach (['room_block_terminations', 'room_reservations', 'room_blocks', 'room_service_variant', 'rooms', 'booking_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach ([false, null, 0, 1, 'true', 'false'] as $flag) {
            config(['booking.scheduling_enabled' => $flag]);
            DB::enableQueryLog();
            DB::flushQueryLog();
            app(CreateBooking::class)($this->customer, $this->data());
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/booking_settings|\brooms\b|room_|schedule_guards|staff_access_guards/i', $query['query']);
            }
        }
        $this->assertDatabaseCount('bookings', 6);
    }

    public function test_selection_allowlist_ignores_untrusted_room_interval_and_contact_fields_without_writer_guards(): void
    {
        Schema::drop('schedule_guards');
        Schema::drop('staff_access_guards');
        $booking = app(CreateBooking::class)($this->customer, $this->data([
            'room_type' => 'missing', 'room_id' => 999999, 'price' => 1,
            'start_at' => 'invalid client instant', 'end_at' => 'invalid client instant',
            'generated_at' => 'invalid client timestamp',
        ]));
        $this->assertSame('pending', $booking->status->value);
        $this->assertSame(100000, $booking->total_price_idr);
        $this->assertDatabaseCount('room_reservations', 0);
    }

    public function test_enabled_unique_constraint_fallback_recovers_after_rollback_without_duplicate_side_effects(): void
    {
        $data = $this->data();
        $inserted = false;
        Booking::creating(function (Booking $booking) use (&$inserted): void {
            if ($inserted) {
                return;
            }
            $inserted = true;
            $attributes = [...$booking->getAttributes(), 'created_at' => now(), 'updated_at' => now()];
            DB::afterRollBack(fn () => DB::table('bookings')->insert([
                ...$attributes, 'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKB99',
            ]));
            DB::table('bookings')->insert([
                ...$attributes, 'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKA99',
            ]);
        });
        $booking = app(CreateBooking::class)($this->customer, $data);
        $this->assertSame('SJ-01K4DR9M7JQ2ABCD3EFGHJKB99', $booking->reference);
        $this->block('02:00:00', '03:00:00');
        $this->assertSame($booking->id, app(CreateBooking::class)($this->customer, $data)->id);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('room_reservations', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_occupied_slot_errors_are_fixed_in_both_supported_locales(): void
    {
        $this->block('02:00:00', '03:00:00');
        $before = $this->scheduleSnapshot();
        foreach (['en' => 'The selected time is no longer available. Choose another time.',
            'id' => 'Waktu yang dipilih tidak lagi tersedia. Pilih waktu lain.'] as $locale => $message) {
            $this->actingAs($this->customer)->withSession(['locale' => $locale])->postJson('/bookings', $this->httpPayload())
                ->assertUnprocessable()->assertJsonPath('errors.time.0', $message);
        }
        $this->assertRejected($before);
    }

    public function test_direct_noncanonical_time_and_selection_errors_are_field_validation_without_writes(): void
    {
        $before = $this->scheduleSnapshot();
        foreach ([null, [], true, '9:00', '09:00:00', '09:00Z', "09:00\n", '09:15', '08:30', '20:30', '24:00'] as $time) {
            $this->assertInvalid(['appointment_time' => $time], 'time');
        }
        foreach ([['appointment_date' => '2026-02-29', 'field' => 'date'], ['appointment_date' => '2026-10-026', 'field' => 'date'],
            ['service' => 'missing', 'field' => 'service'], ['service' => ' local', 'field' => 'service'],
            ['mode' => 'vip', 'field' => 'mode'], ['duration' => '060', 'field' => 'duration'],
            ['duration' => 61, 'field' => 'duration'], ['guests' => '1.0', 'field' => 'guests'],
            ['extension' => 'missing', 'field' => 'extension']] as $case) {
            $field = $case['field'];
            unset($case['field']);
            $this->assertInvalid($case, $field);
        }
        $this->assertRejected($before);
    }

    public function test_incompatible_capacity_inactive_and_detached_rooms_reject_without_writes(): void
    {
        $this->assertInvalid(['guests' => 3], 'time');
        $this->room->update(['is_active' => false]);
        $this->assertInvalid([], 'time');
        $this->room->update(['is_active' => true]);
        $this->room->variants()->detach();
        $before = $this->scheduleSnapshot();
        $this->assertInvalid([], 'time');
        $this->assertRejected($before);
    }

    public function test_half_open_cleaning_adjacency_and_cleaning_after_closing(): void
    {
        $this->block('03:15:00', '04:00:00');
        app(CreateBooking::class)($this->customer, $this->data());
        $this->assertInvalid(['appointment_time' => '09:30'], 'time');
        app(CreateBooking::class)($this->customer, $this->data(['appointment_time' => '11:00']));
        app(CreateBooking::class)($this->customer, $this->data(['appointment_time' => '20:00']));
        $this->assertDatabaseCount('bookings', 3);
        $this->assertDatabaseCount('room_reservations', 0);
    }

    public function test_inclusive_horizon_precise_lead_and_zero_lead_recheck_current_settings(): void
    {
        app(CreateBooking::class)($this->customer, $this->data(['appointment_date' => '2026-10-28']));
        foreach (['2026-10-25', '2026-10-29'] as $date) {
            $this->assertInvalid(['appointment_date' => $date], 'date');
        }
        BookingSettings::whereKey(1)->update(['minimum_lead_minutes' => 30]);
        CarbonImmutable::setTestNow('2026-10-26 01:30:00 UTC');
        app(CreateBooking::class)($this->customer, $this->data());
        CarbonImmutable::setTestNow('2026-10-26 01:30:00.000001 UTC');
        $this->assertInvalid([], 'time');
        BookingSettings::whereKey(1)->update(['minimum_lead_minutes' => 0]);
        CarbonImmutable::setTestNow('2026-10-26 01:59:59.999999 UTC');
        app(CreateBooking::class)($this->customer, $this->data());
        CarbonImmutable::setTestNow('2026-10-26 02:00:00 UTC');
        $this->assertInvalid([], 'time');
        $this->assertDatabaseCount('bookings', 3);
    }

    public function test_stale_settings_and_extension_closing_use_current_availability_before_price(): void
    {
        app(RoomAvailabilityQuery::class)->forSelection($this->selection());
        BookingSettings::whereKey(1)->update(['minimum_lead_minutes' => 240]);
        $this->assertInvalid([], 'time');
        BookingSettings::whereKey(1)->update(['minimum_lead_minutes' => 0, 'booking_horizon_days' => 1]);
        $this->assertInvalid(['appointment_date' => '2026-10-28'], 'date');
        ServiceExtension::create(['service_id' => $this->variant->service_id, 'code' => 'extra', 'treatment_mode' => 'regular',
            'duration_minutes' => 60, 'price_idr' => 50000, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        $this->assertInvalid(['extension' => 'extra', 'appointment_time' => '19:30'], 'time');
        $this->assertDatabaseCount('bookings', 0);
        app(CreateBooking::class)($this->customer, $this->data(['extension' => 'extra', 'appointment_time' => '19:00', 'expected_total_price_idr' => 150000]));
        $this->assertSame(120, Booking::query()->sole()->total_duration_minutes);
    }

    public function test_terminal_cancel_and_fractional_reopening_and_released_holds_keep_existing_semantics(): void
    {
        $block = $this->block('02:00:00', '03:00:00');
        DB::table('room_block_terminations')->insert(['room_block_id' => $block->id, 'kind' => 'cancel_future',
            'occurred_at' => '2026-10-26 01:00:00.000000', 'reason' => 'Synthetic', 'actor_user_id' => null,
            'actor_name' => 'Test', 'actor_role' => 'owner', 'room_id' => $this->room->id,
            'original_starts_at' => '2026-10-26 02:00:00', 'original_ends_at' => '2026-10-26 03:00:00',
            'original_reason' => 'Synthetic', 'previous_version' => 1, 'resulting_version' => 2]);
        $booking = app(CreateBooking::class)($this->customer, $this->data());
        DB::table('room_block_terminations')->update(['kind' => 'end_now', 'occurred_at' => '2026-10-26 02:00:00.500000']);
        $this->assertInvalid([], 'time');
        app(CreateBooking::class)($this->customer, $this->data(['appointment_time' => '09:30']));
        $hold = RoomReservation::create(['room_id' => $this->room->id, 'booking_id' => $booking->id,
            'starts_at' => '2026-10-26 03:00:00', 'treatment_ends_at' => '2026-10-26 04:00:00',
            'ends_at' => '2026-10-26 04:15:00', 'cleaning_minutes' => 15]);
        $booking->forceFill(['status' => 'cancelled'])->save();
        $this->assertInvalid(['appointment_time' => '10:00'], 'time');
        $hold->update(['released_at' => '2026-10-26 01:00:00']);
        $before = $this->scheduleSnapshot();
        app(CreateBooking::class)($this->customer, $this->data(['appointment_time' => '10:00']));
        $this->assertSame($before, $this->scheduleSnapshot());
        $this->assertDatabaseCount('room_reservations', 1);
    }

    public function test_exact_replay_survives_elapsed_date_occupancy_settings_schema_catalog_and_flag_changes(): void
    {
        $data = $this->data();
        $booking = app(CreateBooking::class)($this->customer, $data);
        $snapshot = $booking->fresh()->getAttributes();
        $this->block('02:00:00', '03:00:00');
        BookingSettings::whereKey(1)->update(['minimum_lead_minutes' => 999]);
        $this->variant->update(['is_active' => false, 'price_idr' => 999999]);
        CarbonImmutable::setTestNow('2026-11-30 02:00:00 UTC');
        Schema::drop('booking_settings');
        Schema::drop('room_blocks');
        Schema::rename('service_categories', 'missing_service_categories');
        foreach ([true, false, 'true'] as $flag) {
            config(['booking.scheduling_enabled' => $flag]);
            $this->assertSame($snapshot, app(CreateBooking::class)($this->customer, $data)->getAttributes());
        }
        $this->assertInvalid([...$data, 'notes' => 'Changed'], 'submission_key');
        $http = $this->httpPayload(['submission_key' => $data['submission_key']]);
        $this->actingAs($this->customer)->post('/bookings', $http)->assertStatus(303);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_readiness_errors_are_safe_localized_time_errors_and_do_not_persist(): void
    {
        BookingSettings::query()->delete();
        $before = $this->scheduleSnapshot();
        foreach (['en' => 'Room availability cannot be verified right now. Please try again.',
            'id' => 'Ketersediaan ruangan belum dapat diperiksa. Silakan coba lagi.'] as $locale => $message) {
            $this->actingAs($this->customer)->withSession(['locale' => $locale])->postJson('/bookings', $this->httpPayload())
                ->assertUnprocessable()->assertJsonPath('errors.time.0', $message);
        }
        $this->assertRejected($before);
        Schema::drop('booking_settings');
        $this->assertInvalid([], 'time');
    }

    public function test_unexpected_database_error_propagates_and_rolls_back_without_notification(): void
    {
        $before = $this->scheduleSnapshot();
        DB::connection()->beforeExecuting(function ($sql, $bindings): void {
            if (str_contains($sql, 'from "rooms"')) {
                throw new QueryException('sqlite', $sql, $bindings, new \PDOException('Synthetic unexpected error'));
            }
        });
        try {
            app(CreateBooking::class)($this->customer, $this->data());
            $this->fail('Unexpected database failure was swallowed');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Synthetic unexpected error', $error->getMessage());
        }
        $this->assertRejected($before);
    }

    private function data(array $changes = []): array
    {
        return [...['service' => 'local', 'mode' => 'regular', 'duration' => 60, 'extension' => null,
            'appointment_date' => '2026-10-26', 'appointment_time' => '09:00', 'guests' => 1,
            'name' => 'Test Customer', 'phone' => '', 'notes' => 'Keep this draft',
            'submission_key' => Str::uuid()->toString(), 'expected_total_price_idr' => 100000], ...$changes];
    }

    private function httpPayload(array $changes = []): array
    {
        $data = $this->data();
        unset($data['appointment_date'], $data['appointment_time']);

        return [...$data, 'date' => '2026-10-26', 'time' => '09:00', ...$changes];
    }

    private function selection(): array
    {
        return ['service' => 'local', 'mode' => 'regular', 'duration' => 60, 'date' => '2026-10-26', 'guests' => 1];
    }

    private function block(string $start, string $end): RoomBlock
    {
        return RoomBlock::create(['room_id' => $this->room->id, 'starts_at' => '2026-10-26 '.$start,
            'ends_at' => '2026-10-26 '.$end, 'reason' => 'Synthetic']);
    }

    private function assertInvalid(array $changes, string $field): void
    {
        try {
            app(CreateBooking::class)($this->customer, $this->data($changes));
            $this->fail('Invalid booking accepted: '.json_encode($changes));
        } catch (ValidationException $error) {
            $this->assertSame([$field], array_keys($error->errors()));
        }
    }

    private function scheduleSnapshot(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['booking_settings', 'room_blocks', 'room_reservations', 'room_block_terminations', 'schedule_guards', 'staff_access_guards']);
    }

    private function assertRejected(array $before): void
    {
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('room_reservations', 0);
        $this->assertSame($before, $this->scheduleSnapshot());
        $this->assertDatabaseCount('outbox_messages', 0);
    }
}
