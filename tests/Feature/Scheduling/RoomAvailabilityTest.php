<?php

namespace Tests\Feature\Scheduling;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomReservation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceVariant;
use App\Queries\RoomAvailabilityQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class RoomAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private ServiceVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $category = ServiceCategory::forceCreate(['slug' => 'local', 'name_id' => 'Local', 'name_en' => 'Local', 'image_path' => '', 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        $service = Service::forceCreate(['category_id' => $category->id, 'slug' => 'local', 'name_id' => 'Local', 'name_en' => 'Local', 'description_id' => '', 'description_en' => '', 'long_description_id' => '', 'long_description_en' => '', 'duration_minutes' => 90, 'price_idr' => 1, 'image_path' => '', 'gallery_paths' => [], 'popularity' => 0, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
        $this->variant = ServiceVariant::create(['service_id' => $service->id, 'code' => 'local', 'treatment_mode' => 'regular', 'duration_minutes' => 90, 'price_idr' => 1, 'display_order' => 0, 'is_active' => true, 'published_at' => now()]);
    }

    public function test_filters_orders_and_returns_only_safe_room_fields_without_splitting_groups(): void
    {
        $this->room('large', 4);
        $this->room('small', 2);
        $this->room('second', 2);
        $this->room('solo', 1);
        $this->room('inactive', 4, ['is_active' => false]);
        $this->room('other', 4, ['type' => 'vip']);
        Room::create(['code' => 'incompatible', 'name' => 'Private', 'capacity' => 4]);
        $result = $this->availability(2, '10:00', 90, 'standard');
        $this->assertSame(['small', 'second', 'large'], array_column($result, 'code'));
        $this->assertSame(['code', 'name', 'type', 'capacity', 'available'], array_keys($result[0]));
        $this->assertSame([], $this->availability(4, '10:00', 90, 'missing'));
        Room::query()->where('capacity', '>', 2)->update(['is_active' => false]);
        $this->assertSame([], $this->availability(4));
    }

    public function test_reservation_cleaning_snapshot_adjacency_and_pending_booking(): void
    {
        $room = $this->room('room', 4);
        Booking::factory()->create();
        $this->assertTrue($this->availability()[0]['available']);
        $this->reservation($room, '10:00', '11:30', '11:45');
        $this->assertFalse($this->availability(1, '11:30')[0]['available']);
        $room->update(['cleaning_minutes' => 30]);
        $this->assertTrue($this->availability(1, '11:45')[0]['available']);
        $this->assertFalse($this->availability(1, '08:15', 90)[0]['available']);
        $this->assertTrue($this->availability(1, '08:00', 90)[0]['available']);
    }

    public function test_blocks_overlap_and_allow_adjacency_in_both_directions(): void
    {
        $room = $this->room('room', 4, ['cleaning_minutes' => 0]);
        RoomBlock::create(['room_id' => $room->id, 'starts_at' => $this->time('12:00'), 'ends_at' => $this->time('13:00'), 'reason' => 'Private maintenance']);
        $this->assertFalse($this->availability(1, '11:30', 60)[0]['available']);
        $this->assertTrue($this->availability(1, '11:00', 60)[0]['available']);
        $this->assertTrue($this->availability(1, '13:00', 60)[0]['available']);
    }

    public function test_cancelled_unreleased_reservation_blocks_until_explicit_release(): void
    {
        $room = $this->room('room', 4);
        $reservation = $this->reservation($room, '10:00', '11:30', '11:45', BookingStatus::Cancelled);
        $this->assertFalse($this->availability()[0]['available']);
        $reservation->update(['released_at' => $this->time('09:00'), 'release_reason' => 'Cancelled']);
        $this->assertTrue($this->availability()[0]['available']);
    }

    public function test_schedule_model_dates_store_utc_without_mutating_local_inputs(): void
    {
        $room = $this->room('room', 4);
        $start = $this->time('10:00')->setTimezone('Asia/Jakarta');
        RoomBlock::create(['room_id' => $room->id, 'starts_at' => $start, 'ends_at' => $start->addHour(), 'reason' => 'Private']);
        $reservation = RoomReservation::create(['room_id' => $room->id, 'booking_id' => Booking::factory()->create()->id, 'starts_at' => $start, 'treatment_ends_at' => $start->addMinutes(90), 'ends_at' => $start->addMinutes(105), 'cleaning_minutes' => 15]);
        $this->assertDatabaseHas('room_blocks', ['starts_at' => '2026-10-25 10:00:00', 'ends_at' => '2026-10-25 11:00:00']);
        $this->assertDatabaseHas('room_reservations', ['starts_at' => '2026-10-25 10:00:00', 'ends_at' => '2026-10-25 11:45:00']);
        $this->assertInstanceOf(CarbonImmutable::class, $reservation->fresh()->starts_at);
        $this->assertSame($start->timestamp, $reservation->fresh()->starts_at->timestamp);
        $this->assertSame('Asia/Jakarta', $start->timezoneName);
        $this->assertFalse($this->availability()[0]['available']);
    }

    public function test_each_room_uses_its_own_candidate_cleaning_time(): void
    {
        $short = $this->room('short', 2, ['cleaning_minutes' => 0]);
        $long = $this->room('long', 2, ['cleaning_minutes' => 30]);
        foreach ([$short, $long] as $room) {
            $this->reservation($room, '12:00', '13:30', '13:45');
        }
        $this->assertSame([true, false], array_column($this->availability(1, '10:15', 90), 'available'));
    }

    public function test_room_model_rejects_nonpositive_capacity_and_negative_cleaning(): void
    {
        foreach ([['capacity' => 0], ['capacity' => -1], ['cleaning_minutes' => -1]] as $attributes) {
            try {
                $this->room('invalid', 1, $attributes);
                $this->fail('Invalid room accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_timezone_equivalence_immutability_and_bounded_query_count(): void
    {
        foreach (range(1, 12) as $number) {
            $this->room('room-'.$number, 4);
        }
        $this->reservation(Room::first(), '10:00', '11:30', '11:45');
        $start = $this->time('10:00')->setTimezone('Asia/Jakarta');
        $original = $start->toIso8601String();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $actual = app(RoomAvailabilityQuery::class)->forInterval($this->variant, 1, $start, 90);
        $count = count(DB::getQueryLog());
        $this->assertLessThanOrEqual(11, $count); // Includes uncached rollout-schema checks and a batched terminal read.
        DB::disableQueryLog();
        $this->assertSame($this->availability(), $actual);
        foreach (range(13, 24) as $number) {
            $room = $this->room('room-'.$number, 4);
            RoomBlock::create(['room_id' => $room->id, 'starts_at' => $this->time('10:00'), 'ends_at' => $this->time('11:00'), 'reason' => 'Batch candidate']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(RoomAvailabilityQuery::class)->forInterval($this->variant, 1, $start, 90);
        // One terminal batch is added when the first block candidate appears, never per room.
        $this->assertSame($count + 1, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertFalse($actual[0]['available']);
        $this->assertSame($original, $start->toIso8601String());
    }

    public function test_catalog_lifecycle_is_rechecked_from_database(): void
    {
        $this->room('room', 4);
        foreach ([['service_variants', $this->variant->id], ['services', $this->variant->service_id], ['service_categories', $this->variant->service->category_id]] as [$table, $id]) {
            foreach (['is_active' => false, 'published_at' => null] as $field => $value) {
                DB::table($table)->where('id', $id)->update([$field => $value]);
                $this->assertSame([], $this->availability());
                DB::table($table)->where('id', $id)->update([$field => $field === 'is_active' ? true : now()]);
            }
        }
    }

    public function test_invalid_arguments_and_subsecond_precision_are_rejected(): void
    {
        foreach ([[0, 90, null], [5, 90, null], [1, 0, null], [1, 721, null], [1, 90, ''], [1, 90, str_repeat('x', 65)], [1, 90, "bad\nvalue"]] as [$guests, $duration, $type]) {
            try {
                $this->availability($guests, '10:00', $duration, $type);
                $this->fail('Invalid argument accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([[new ServiceVariant, $this->time('10:00')], [$this->variant, $this->time('10:00')->setMicrosecond(1)]] as [$variant, $start]) {
            try {
                app(RoomAvailabilityQuery::class)->forInterval($variant, 1, $start, 90);
                $this->fail('Invalid variant or precision accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unique_reservation_booking_constraint(): void
    {
        $room = $this->room('room', 4);
        $reservation = $this->reservation($room, '10:00', '11:30', '11:45');
        $otherRoom = $this->room('other', 4);
        $this->expectException(QueryException::class);
        RoomReservation::create([...$reservation->only(['booking_id', 'starts_at', 'treatment_ends_at', 'ends_at', 'cleaning_minutes']), 'room_id' => $otherRoom->id]);
    }

    public function test_pivot_pair_is_unique(): void
    {
        $room = $this->room('room', 4);
        $this->expectException(QueryException::class);
        $room->variants()->attach($this->variant);
    }

    public function test_reservation_history_prevents_room_or_booking_deletion(): void
    {
        $room = $this->room('room', 4);
        $reservation = $this->reservation($room, '10:00', '11:30', '11:45');
        foreach ([['rooms', $room->id], ['bookings', $reservation->booking_id]] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail('Reservation history deleted');
            } catch (QueryException) {
                $this->assertDatabaseHas($table, ['id' => $id]);
            }
        }
    }

    public function test_migration_rollback_only_removes_new_tables(): void
    {
        $migration = require database_path('migrations/2026_10_22_010002_create_room_schedule.php');
        $migration->down();
        foreach (['rooms', 'room_service_variant', 'room_reservations', 'room_blocks'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasTable('bookings'));
        $migration->up();
    }

    private function room(string $code, int $capacity, array $attributes = []): Room
    {
        $room = Room::create([...['code' => $code, 'name' => $code, 'type' => 'standard', 'capacity' => $capacity, 'is_active' => true, 'cleaning_minutes' => 15], ...$attributes]);
        $room->variants()->attach($this->variant);

        return $room;
    }

    private function reservation(Room $room, string $start, string $treatmentEnd, string $end, BookingStatus $status = BookingStatus::Confirmed): RoomReservation
    {
        return RoomReservation::create(['room_id' => $room->id, 'booking_id' => Booking::factory()->create(['status' => $status])->id, 'starts_at' => $this->time($start), 'treatment_ends_at' => $this->time($treatmentEnd), 'ends_at' => $this->time($end), 'cleaning_minutes' => 15]);
    }

    private function availability(int $guests = 1, string $time = '10:00', int $duration = 90, ?string $type = null): array
    {
        return app(RoomAvailabilityQuery::class)->forInterval($this->variant, $guests, $this->time($time), $duration, $type);
    }

    private function time(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-25 '.$time, 'UTC');
    }
}
