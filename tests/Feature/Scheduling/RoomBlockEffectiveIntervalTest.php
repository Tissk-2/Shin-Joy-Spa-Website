<?php

namespace Tests\Feature\Scheduling;

use App\Actions\SaveRoomBlock;
use App\Actions\TerminateRoomBlock;
use App\Models\RoomBlock;
use App\Models\RoomReservation;
use App\Models\User;
use App\Queries\AdminRoomIndexQuery;
use App\Queries\RoomAvailabilityQuery;
use App\Queries\RoomBlockManagementQuery;
use App\Support\Rooms\RoomBlockInterval;
use App\Support\Scheduling\RoomAllocator;
use App\Support\Scheduling\ScheduleLock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RoomBlockEffectiveIntervalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function fixture(): array
    {
        [$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
        CarbonImmutable::setTestNow('2026-10-25 02:00:00 UTC');
        $actor = User::factory()->owner()->create();
        $block = RoomBlock::create(['room_id' => $room->id, 'starts_at' => '2026-10-25 03:00:00', 'ends_at' => '2026-10-25 05:00:00', 'reason' => 'Maintenance']);

        return [$booking, $room, $actor, $block];
    }

    public function test_cancelled_row_agrees_across_all_readers_and_replacement_conflicts(): void
    {
        [$booking, $room, $actor, $block] = $this->fixture();
        $before = [DB::table('bookings')->get()->toJson(), DB::table('booking_status_changes')->get()->toJson(), DB::table('room_reservations')->get()->toJson()];
        (new TerminateRoomBlock)($actor, $block->id, ['intent' => 'cancel_future', 'version' => 1, 'reason' => 'Cancelled']);
        $this->assertSame($before, [DB::table('bookings')->get()->toJson(), DB::table('booking_status_changes')->get()->toJson(), DB::table('room_reservations')->get()->toJson()]);
        $this->assertTrue((new RoomAvailabilityQuery)->forInterval($booking->serviceVariant, 1, CarbonImmutable::parse('2026-10-25 03:00:00 UTC'), 90)[0]['available']);
        $index = (new AdminRoomIndexQuery)->get()['rooms'][0];
        $this->assertSame(0, $index['upcomingBlockCount']);
        $this->assertSame([], $index['blocks']);
        $this->assertSame([], (new RoomBlockManagementQuery)->index($actor, [])['blocks']);
        $history = (new RoomBlockManagementQuery)->index(User::factory()->admin()->create(), ['view' => 'history']);
        $this->assertSame('cancelled', $history['blocks'][0]['state']);
        $this->assertSame('2026-10-25T02:00:00.000000Z', $history['blocks'][0]['termination']['occurredAt']);
        $replacement = (new SaveRoomBlock)($actor, null, ['roomId' => $room->id, 'startsAt' => '2026-10-25T10:00', 'endsAt' => '2026-10-25T11:00', 'reason' => 'Replacement']);
        $this->assertTrue($replacement->exists);
        $this->assertFalse((new RoomAvailabilityQuery)->forInterval($booking->serviceVariant, 1, CarbonImmutable::parse('2026-10-25 03:00:00 UTC'), 90)[0]['available']);
        DB::table('room_blocks')->where('id', $replacement->id)->delete();
        $this->assertSame($room->id, (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking))->room_id);
    }

    public function test_fractional_end_preserves_elapsed_overlap_and_exact_adjacency(): void
    {
        [$booking, $room, $actor, $block] = $this->fixture();
        DB::table('room_blocks')->where('id', $block->id)->update(['starts_at' => '2026-10-25 02:59:59.123456']);
        CarbonImmutable::setTestNow('2026-10-25 03:00:00.500000 UTC');
        (new TerminateRoomBlock)($actor, $block->id, ['intent' => 'end_now', 'version' => 1, 'reason' => 'Finished']);
        $interval = new RoomBlockInterval;
        $row = DB::table('room_blocks')->first();
        $terminal = $interval->loadTerminations([$block->id])[$block->id];
        foreach (['03:00:00' => true, '03:00:00.500000' => false, '03:00:01' => false] as $time => $expected) {
            $this->assertSame($expected, $interval->overlaps($row, $terminal, CarbonImmutable::parse('2026-10-25 '.$time, 'UTC'), CarbonImmutable::parse('2026-10-25 04:00:00', 'UTC')));
        }
        $this->assertFalse($interval->overlaps($row, $terminal, CarbonImmutable::parse('2026-10-25 02:00:00 UTC'), CarbonImmutable::parse('2026-10-25 02:59:59.123456 UTC')));
        $query = new RoomAvailabilityQuery;
        $this->assertFalse($query->forInterval($booking->serviceVariant, 1, CarbonImmutable::parse('2026-10-25 03:00:00 UTC'), 90)[0]['available']);
        $this->assertTrue($query->forInterval($booking->serviceVariant, 1, CarbonImmutable::parse('2026-10-25 03:00:01 UTC'), 90)[0]['available']);
        $this->assertSame(0, (new AdminRoomIndexQuery)->get()['rooms'][0]['currentBlockCount']);
        $this->assertSame('2026-10-25T03:00:00.500000Z', (new RoomBlockManagementQuery)->form($actor, $block->id)['block']['effectiveEndsAt']);
        $this->expectException(ValidationException::class);
        (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
    }

    public function test_termination_does_not_override_other_blocks_inactive_rooms_or_unreleased_cleaning(): void
    {
        [$booking, $room, $actor, $block] = $this->fixture();
        $hold = RoomReservation::create(['room_id' => $room->id, 'booking_id' => $booking->id,
            'starts_at' => '2026-10-25 03:00:00', 'treatment_ends_at' => '2026-10-25 04:30:00',
            'ends_at' => '2026-10-25 04:45:00', 'cleaning_minutes' => 15]);
        DB::table('bookings')->where('id', $booking->id)->update(['status' => 'completed']);
        $before = [DB::table('bookings')->get()->toJson(), DB::table('room_reservations')->get()->toJson(), DB::table('booking_status_changes')->get()->toJson()];
        (new TerminateRoomBlock)($actor, $block->id, ['intent' => 'cancel_future', 'version' => 1, 'reason' => 'Maintenance cancelled only']);
        $this->assertSame($before, [DB::table('bookings')->get()->toJson(), DB::table('room_reservations')->get()->toJson(), DB::table('booking_status_changes')->get()->toJson()]);
        $available = fn (string $time) => (new RoomAvailabilityQuery)->forInterval($booking->serviceVariant, 1, CarbonImmutable::parse('2026-10-25 '.$time, 'UTC'), 30);
        $this->assertFalse($available('04:30:00')[0]['available']);
        $this->assertTrue($available('04:45:00')[0]['available']);
        DB::table('bookings')->where('id', $booking->id)->update(['status' => 'cancelled']);
        $this->assertFalse($available('04:30:00')[0]['available']);
        $other = RoomBlock::create(['room_id' => $room->id, 'starts_at' => '2026-10-25 04:45:00', 'ends_at' => '2026-10-25 05:45:00', 'reason' => 'Independent block']);
        $this->assertFalse($available('04:45:00')[0]['available']);
        $room->update(['is_active' => false]);
        $this->assertSame([], $available('06:00:00'));
        $this->assertSame($hold->getRawOriginal('ends_at'), $hold->fresh()->getRawOriginal('ends_at'));
        $this->assertSame(1, $other->fresh()->version);
    }

    public function test_absent_retains_old_behavior_but_partial_fails_closed_even_without_candidates(): void
    {
        [$booking, $room, $actor, $block] = $this->fixture();
        $migration = require database_path('migrations/2026_10_25_010007_add_room_block_terminations.php');
        $migration->down();
        $access = (new RoomBlockManagementQuery)->access($actor);
        $this->assertTrue($access['ready']);
        $this->assertFalse($access['terminationReady']);
        $this->assertFalse((new RoomAvailabilityQuery)->forInterval($booking->serviceVariant, 1, CarbonImmutable::parse('2026-10-25 03:00:00 UTC'), 90)[0]['available']);
        $migration->up();
        Schema::table('room_block_terminations', fn ($t) => $t->renameColumn('kind', 'missing'));
        $this->assertSame('unavailable', (new AdminRoomIndexQuery)->get()['state']);
        $this->assertSame('unavailable', (new RoomBlockManagementQuery)->index($actor, [])['state']);
        foreach ([fn () => (new RoomAvailabilityQuery)->forInterval($booking->serviceVariant, 1, CarbonImmutable::parse('2030-01-01 UTC'), 90), fn () => (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking)), fn () => (new SaveRoomBlock)($actor, null, ['roomId' => $room->id, 'startsAt' => '2030-01-01T10:00', 'endsAt' => '2030-01-01T11:00', 'reason' => 'Unsafe'])] as $read) {
            try {
                $read();
                $this->fail('Partial schema accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        Schema::table('room_block_terminations', fn ($t) => $t->renameColumn('missing', 'kind'));
    }

    public function test_malformed_terminal_never_looks_available_and_lock_loader_requires_guard(): void
    {
        [$booking, $room, $actor, $block] = $this->fixture();
        (new TerminateRoomBlock)($actor, $block->id, ['intent' => 'cancel_future', 'version' => 1, 'reason' => 'Cancelled']);
        foreach ([['kind' => 'corrupt'], ['kind' => 'cancel_future', 'occurred_at' => 'nonsense'], ['kind' => 'cancel_future', 'occurred_at' => '2026-10-25 03:01:00.000000']] as $invalid) {
            DB::table('room_block_terminations')->update($invalid);
            foreach ([fn () => (new AdminRoomIndexQuery)->get(), fn () => (new RoomBlockManagementQuery)->index($actor, []), fn () => (new RoomAvailabilityQuery)->forInterval($booking->serviceVariant, 1, CarbonImmutable::parse('2026-10-25 03:00:00 UTC'), 90)] as $read) {
                try {
                    $read();
                    $this->fail('Corrupt termination advertised');
                } catch (ValidationException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $this->expectException(\LogicException::class);
        (new RoomBlockInterval)->loadTerminations([], true);
    }
}
