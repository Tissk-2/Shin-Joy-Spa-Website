<?php

namespace Tests\Feature\Scheduling;

use App\Models\Booking;
use App\Models\Room;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceExtension;
use App\Models\ServiceVariant;
use App\Support\Scheduling\RoomAllocator;
use App\Support\Scheduling\ScheduleLock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RoomAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_booking_snapshot_and_smallest_room_are_used(): void
    {
        [$booking, $room] = $this->fixture();
        $booking->total_duration_minutes = 1;
        $reservation = (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
        $this->assertSame($room->id, $reservation->room_id);
        $this->assertSame('2026-10-25 03:00:00', $reservation->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-25 04:45:00', $reservation->ends_at->format('Y-m-d H:i:s'));
        DB::table('service_variants')->update(['is_active' => false]);
        $again = (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
        $this->assertSame($reservation->id, $again->id);
        $this->assertSame(265000, $booking->fresh()->total_price_idr);
        $this->assertSame(1, $booking->total_duration_minutes);
    }

    public function test_occupied_room_falls_back_and_no_room_persists_nothing(): void
    {
        [$booking, $room] = $this->fixture();
        $lock = new ScheduleLock;
        $lock->run(fn () => (new RoomAllocator)->allocateLocked($booking));
        $next = $booking->replicate(['reference', 'submission_key']);
        $next->reference = 'second';
        $next->submission_key = 'second';
        $next->save();
        try {
            $lock->run(fn () => (new RoomAllocator)->allocateLocked($next));
            $this->fail('Overlapping booking accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('room', $e->errors());
        }
        $this->assertDatabaseCount('room_reservations', 1);
        $larger = Room::create(['code' => 'large', 'name' => 'Large', 'capacity' => 4]);
        $larger->variants()->attach($booking->service_variant_id);
        $reservation = $lock->run(fn () => (new RoomAllocator)->allocateLocked($next));
        $this->assertSame($larger->id, $reservation->room_id);
    }

    public function test_smallest_compatible_capacity_wins_then_lowest_room_id_breaks_ties(): void
    {
        [$booking, $earlierLarge] = $this->fixture();
        $earlierLarge->update(['capacity' => 4]);
        DB::table('bookings')->where('id', $booking->id)->update(['guests' => 2]);
        $inactive = Room::create(['code' => 'inactive-first', 'name' => 'Inactive', 'capacity' => 2, 'is_active' => false]);
        $inactive->variants()->attach($booking->service_variant_id);
        Room::create(['code' => 'incompatible-first', 'name' => 'Incompatible', 'capacity' => 2]);
        $firstSmall = Room::create(['code' => 'first-small', 'name' => 'First small', 'capacity' => 2]);
        $firstSmall->variants()->attach($booking->service_variant_id);
        $secondSmall = Room::create(['code' => 'second-small', 'name' => 'Second small', 'capacity' => 2]);
        $secondSmall->variants()->attach($booking->service_variant_id);

        $reservation = (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));

        $this->assertLessThan($firstSmall->id, $earlierLarge->id);
        $this->assertLessThan($secondSmall->id, $firstSmall->id);
        $this->assertSame($firstSmall->id, $reservation->room_id);
    }

    public function test_release_is_conservative_and_preserves_first_audit(): void
    {
        [$booking] = $this->fixture();
        $allocator = new RoomAllocator;
        $lock = new ScheduleLock;
        $reservation = $lock->run(fn () => $allocator->allocateLocked($booking));
        try {
            $lock->run(fn () => $allocator->releaseLocked($booking, 'late', $reservation->starts_at));
            $this->fail('Ongoing occupancy released');
        } catch (ValidationException) {
        }
        $at = $reservation->starts_at->subMinute();
        $lock->run(fn () => $allocator->releaseLocked($booking, 'first', $at));
        $lock->run(fn () => $allocator->releaseLocked($booking, 'second', $at->addSecond()));
        $this->assertSame('first', $reservation->fresh()->release_reason);
        $this->expectException(ValidationException::class);
        $lock->run(fn () => $allocator->allocateLocked($booking));
    }

    public function test_outer_rollback_removes_allocation(): void
    {
        [$booking] = $this->fixture();
        DB::beginTransaction();
        (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
        DB::rollBack();
        $this->assertDatabaseCount('room_reservations', 0);
    }

    public function test_invalid_persisted_facts_fail_without_writing(): void
    {
        [$booking] = $this->fixture();
        $original = DB::table('bookings')->where('id', $booking->id)->first();
        foreach (['status' => 'cancelled', 'appointment_date' => '2026-02-30', 'appointment_time' => '20:30',
            'appointment_timezone' => 'UTC', 'guests' => 0, 'total_duration_minutes' => 721,
            'requested_room_type' => '', 'service_variant_id' => null, 'treatment_mode' => 'invalid'] as $field => $value) {
            DB::table('bookings')->where('id', $booking->id)->update([$field => $value]);
            try {
                (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
                $this->fail('Invalid saved '.$field.' accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('room_reservations', 0);
            }
            DB::table('bookings')->where('id', $booking->id)->update([$field => $original->$field]);
        }
    }

    public function test_block_cleaning_adjacency_type_and_whole_group(): void
    {
        [$booking, $room] = $this->fixture();
        $room->update(['type' => 'standard']);
        DB::table('bookings')->where('id', $booking->id)->update(['requested_room_type' => 'standard', 'guests' => 2]);
        DB::table('room_blocks')->insert(['room_id' => $room->id, 'starts_at' => '2026-10-25 04:45:00', 'ends_at' => '2026-10-25 05:00:00', 'reason' => 'maintenance']);
        $reservation = (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
        $this->assertSame($room->id, $reservation->room_id);
        $this->assertSame('2026-10-25 04:45:00', $reservation->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_allocator_rejects_unsaved_and_unguarded_models(): void
    {
        $this->expectException(\LogicException::class);
        (new RoomAllocator)->allocateLocked(new Booking);
    }

    public function test_current_catalog_lifecycle_and_extension_references_are_required(): void
    {
        [$booking] = $this->fixture();
        $extension = ServiceExtension::create(['service_id' => $booking->service_id, 'code' => 'extension', 'treatment_mode' => 'regular', 'duration_minutes' => 30, 'price_idr' => 1, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        DB::table('bookings')->where('id', $booking->id)->update(['service_extension_id' => $extension->id,
            'extension_choice_code' => 'extension', 'extension_duration_minutes' => 30, 'extension_price_idr' => 1]);
        foreach (['service_variants', 'services', 'service_categories', 'service_extensions'] as $table) {
            foreach (['is_active' => false, 'published_at' => null] as $field => $value) {
                $original = DB::table($table)->value($field);
                DB::table($table)->update([$field => $value]);
                try {
                    (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
                    $this->fail('Unavailable catalog accepted');
                } catch (ValidationException) {
                    $this->assertDatabaseCount('room_reservations', 0);
                }
                DB::table($table)->update([$field => $original]);
            }
        }
        DB::table('service_extensions')->where('id', $extension->id)->update(['treatment_mode' => 'invalid']);
        $this->expectException(ValidationException::class);
        (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
    }

    public function test_group_is_not_split_and_type_must_match(): void
    {
        [$booking, $room] = $this->fixture();
        DB::table('bookings')->where('id', $booking->id)->update(['guests' => 3]);
        foreach ([['guests' => 3], ['guests' => 1, 'requested_room_type' => 'vip']] as $attributes) {
            DB::table('bookings')->where('id', $booking->id)->update($attributes);
            try {
                (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
                $this->fail('Ineligible room accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('room_reservations', 0);
            }
        }
    }

    public function test_cancelled_unreleased_reservation_and_cleaning_overlap_block(): void
    {
        [$booking, $room] = $this->fixture();
        $first = (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
        DB::table('bookings')->where('id', $booking->id)->update(['status' => 'cancelled']);
        $other = Booking::factory()->create(['service_id' => $booking->service_id, 'service_variant_id' => $booking->service_variant_id, 'appointment_date' => '2026-10-25', 'appointment_time' => '11:30']);
        $this->expectException(ValidationException::class);
        (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($other));
    }

    public function test_release_validation_and_missing_reservation_no_op(): void
    {
        [$booking] = $this->fixture();
        $lock = new ScheduleLock;
        $allocator = new RoomAllocator;
        $at = CarbonImmutable::parse('2026-10-24 00:00:00', 'UTC');
        $lock->run(fn () => $allocator->releaseLocked($booking, 'missing', $at));
        $this->assertDatabaseCount('room_reservations', 0);
        $reservation = $lock->run(fn () => $allocator->allocateLocked($booking));
        foreach ([[' ', $at], [str_repeat('x', 256), $at], ['valid', $at->setMicrosecond(1)]] as [$reason, $time]) {
            try {
                $lock->run(fn () => $allocator->releaseLocked($booking, $reason, $time));
                $this->fail('Invalid release accepted');
            } catch (ValidationException) {
                $this->assertNull($reservation->fresh()->released_at);
            }
        }
        $lock->run(fn () => $allocator->releaseLocked($booking, 'first', $at));
        $lock->run(fn () => $allocator->releaseLocked($booking, 'second', $at->addHour()));
        $this->assertTrue($reservation->fresh()->released_at->equalTo($at));
    }

    public function test_saved_model_and_default_connection_are_required_under_guard(): void
    {
        [$booking] = $this->fixture();
        config(['database.connections.other' => config('database.connections.sqlite')]);
        foreach ([new Booking, (clone $booking)->setConnection('other')] as $invalid) {
            try {
                (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($invalid));
                $this->fail('Invalid booking identity accepted');
            } catch (\LogicException) {
                $this->assertDatabaseCount('room_reservations', 0);
            }
        }
    }

    public function test_guard_migration_rollback_preserves_existing_booking(): void
    {
        [$booking] = $this->fixture();
        $migration = require database_path('migrations/2026_10_22_010005_create_room_schedule_guard.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('bookings', 'requested_room_type'));
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'total_price_idr' => 265000]);
        $migration->up();
        $this->assertDatabaseCount('schedule_guards', 1);
        $this->assertDatabaseCount('rooms', 1);
    }

    public function test_deleted_selected_extension_fails_without_creating_a_reservation(): void
    {
        [$booking] = $this->fixture();
        $extension = $this->selectExtension($booking);
        $extension->delete();
        $saved = $booking->fresh();
        $this->assertNull($saved->service_extension_id);
        $this->assertSame('selected-extension', $saved->extension_choice_code);
        $this->assertSame(30, $saved->extension_duration_minutes);
        $this->assertSame(50000, $saved->extension_price_idr);
        try {
            (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
            $this->fail('Deleted selected extension accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('room', $exception->errors());
        }
        $this->assertDatabaseCount('room_reservations', 0);
    }

    public function test_existing_unreleased_reservation_replays_after_selected_extension_deletion(): void
    {
        [$booking] = $this->fixture();
        $extension = $this->selectExtension($booking);
        $lock = new ScheduleLock;
        $allocator = new RoomAllocator;
        $reservation = $lock->run(fn () => $allocator->allocateLocked($booking));
        $extension->delete();
        $replay = $lock->run(fn () => $allocator->allocateLocked($booking));
        $this->assertSame($reservation->id, $replay->id);
        $this->assertDatabaseCount('room_reservations', 1);
        $this->assertSame(120, $booking->fresh()->total_duration_minutes);
        $this->assertSame(315000, $booking->fresh()->total_price_idr);
    }

    public function test_incomplete_extension_selection_metadata_is_rejected(): void
    {
        [$booking] = $this->fixture();
        $extension = $this->selectExtension($booking);
        $valid = ['service_extension_id' => $extension->id, 'extension_choice_code' => 'selected-extension',
            'extension_duration_minutes' => 30, 'extension_price_idr' => 50000];
        foreach ([['service_extension_id' => null], ['extension_choice_code' => null],
            ['extension_duration_minutes' => null], ['extension_price_idr' => null],
            ['extension_choice_code' => ' ']] as $invalid) {
            DB::table('bookings')->where('id', $booking->id)->update([...$valid, ...$invalid]);
            try {
                (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
                $this->fail('Incomplete extension selection accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('room', $exception->errors());
            }
            $this->assertDatabaseCount('room_reservations', 0);
        }
    }

    private function selectExtension(Booking $booking): ServiceExtension
    {
        $extension = ServiceExtension::create(['service_id' => $booking->service_id, 'code' => 'selected-extension',
            'treatment_mode' => 'regular', 'duration_minutes' => 30, 'price_idr' => 50000,
            'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        DB::table('bookings')->where('id', $booking->id)->update(['service_extension_id' => $extension->id,
            'extension_choice_code' => 'selected-extension', 'extension_duration_minutes' => 30,
            'extension_price_idr' => 50000, 'total_duration_minutes' => 120, 'total_price_idr' => 315000]);

        return $extension;
    }

    public function fixture(): array
    {
        $category = ServiceCategory::forceCreate(['slug' => 'local', 'name_id' => 'Local', 'name_en' => 'Local', 'image_path' => '', 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        $service = Service::forceCreate(['category_id' => $category->id, 'slug' => 'local', 'name_id' => 'Local', 'name_en' => 'Local', 'description_id' => '', 'description_en' => '', 'long_description_id' => '', 'long_description_en' => '', 'duration_minutes' => 90, 'price_idr' => 1, 'image_path' => '', 'gallery_paths' => [], 'popularity' => 0, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        $variant = ServiceVariant::create(['service_id' => $service->id, 'code' => 'local', 'treatment_mode' => 'regular', 'duration_minutes' => 1, 'price_idr' => 1, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        $room = Room::create(['code' => 'small', 'name' => 'Small', 'capacity' => 2, 'cleaning_minutes' => 15]);
        $room->variants()->attach($variant);
        $booking = Booking::factory()->create(['service_id' => $service->id, 'service_variant_id' => $variant->id, 'appointment_date' => '2026-10-25', 'appointment_time' => '10:00']);

        return [$booking, $room];
    }
}
