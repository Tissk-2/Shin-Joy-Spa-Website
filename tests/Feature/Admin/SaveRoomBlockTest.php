<?php

namespace Tests\Feature\Admin;

use App\Actions\SaveRoomBlock;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomReservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Scheduling\RoomAllocationTest;
use Tests\TestCase;

class SaveRoomBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-01 02:59:30 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function input(int $roomId, array $overrides = []): array
    {
        return [...['roomId' => $roomId, 'startsAt' => '2026-11-01T10:00', 'endsAt' => '2026-11-01T11:00', 'reason' => " Repair\n room "], ...$overrides];
    }

    private function room(): Room
    {
        return Room::create(['code' => 'R'.Room::count(), 'name' => 'Room', 'capacity' => 2]);
    }

    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Invalid write accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }

    public function test_only_fresh_verified_owner_or_capable_admin_can_write(): void
    {
        $room = $this->room();
        foreach (['owner', 'admin'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'can_manage_bookings' => $role === 'admin']);
            $block = (new SaveRoomBlock)($actor, null, $this->input($room->id));
            $this->assertSame($actor->id, $block->created_by);
            $block->delete();
        }
        foreach (['capability', 'unverified', 'demoted', 'deleted', 'unsaved', 'connection'] as $case) {
            $actor = User::factory()->admin()->create(['can_manage_bookings' => true]);
            match ($case) {
                'capability' => DB::table('users')->where('id', $actor->id)->update(['can_manage_bookings' => false]),
                'unverified' => DB::table('users')->where('id', $actor->id)->update(['email_verified_at' => null]),
                'demoted' => DB::table('users')->where('id', $actor->id)->update(['role' => 'customer']),
                'deleted' => DB::table('users')->where('id', $actor->id)->delete(),
                'unsaved' => $actor = User::factory()->owner()->make(),
                'connection' => $actor->setConnection('mysql'),
            };
            try {
                (new SaveRoomBlock)($actor, null, $this->input($room->id));
                $this->fail($case);
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('room_blocks', 0);
            }
        }
        $this->actingAs(User::factory()->admin()->create(['can_manage_bookings' => false]))->post('/admin/room-blocks', ['scope' => 'room', ...$this->input($room->id)])->assertForbidden();
        $this->get('/admin/room-blocks/create')->assertForbidden();
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->post('/admin/room-blocks', ['scope' => 'room', ...$this->input($room->id)])->assertRedirect();
        $saved = RoomBlock::firstOrFail();
        DB::table('users')->where('id', $owner->id)->update(['email_verified_at' => null]);
        $this->patch('/admin/room-blocks/'.$saved->id, $this->input($room->id, ['version' => 1]))->assertForbidden();
        $this->assertSame(1, $saved->fresh()->version);
    }

    public function test_room_block_input_is_strict_and_stored_in_utc(): void
    {
        $room = $this->room();
        $owner = User::factory()->owner()->create();
        $save = fn ($data) => (new SaveRoomBlock)($owner, null, $data);
        foreach ([['startsAt' => '2026-02-30T10:00'], ['startsAt' => '2026-11-01T10:00Z'], ['startsAt' => '1000-01-01T00:00'], ['endsAt' => '2026-11-01T10:00'], ['startsAt' => []], ['reason' => ' '], ['reason' => str_repeat('a', 1001)], ['roomId' => '01'], ['created_by' => 4], ['version' => 1], ['roomIds' => [$room->id]]] as $bad) {
            $this->invalid(fn () => $save($this->input($room->id, $bad)), array_key_first($bad));
            $this->assertDatabaseCount('room_blocks', 0);
        }
        $block = $save($this->input($room->id));
        $this->assertSame('2026-11-01 03:00:00', $block->getRawOriginal('starts_at'));
        $this->assertSame("Repair\n room", $block->reason);
    }

    public function test_block_overlap_respects_cleaning_and_adjacency(): void
    {
        [$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
        $owner = User::factory()->owner()->create();
        $hold = RoomReservation::create(['room_id' => $room->id, 'booking_id' => $booking->id, 'starts_at' => '2026-11-01 03:00:00', 'treatment_ends_at' => '2026-11-01 04:30:00', 'ends_at' => '2026-11-01 04:45:00', 'cleaning_minutes' => 15]);
        foreach (['cancelled', 'completed'] as $status) {
            DB::table('bookings')->where('id', $booking->id)->update(['status' => $status]);
            $this->invalid(fn () => (new SaveRoomBlock)($owner, null, $this->input($room->id, ['startsAt' => '2026-11-01T11:30', 'endsAt' => '2026-11-01T12:00'])), 'room');
        }
        $block = (new SaveRoomBlock)($owner, null, $this->input($room->id, ['startsAt' => '2026-11-01T11:45', 'endsAt' => '2026-11-01T12:00']));
        $this->invalid(fn () => (new SaveRoomBlock)($owner, null, $this->input($room->id, ['startsAt' => '2026-11-01T11:59', 'endsAt' => '2026-11-01T12:30'])), 'room');
        $hold->update(['released_at' => CarbonImmutable::now('UTC')]);
        $this->assertSame(1, (new SaveRoomBlock)($owner, null, $this->input($room->id))->version);
        $this->assertSame(1, $block->fresh()->version);
    }

    public function test_block_edits_preserve_elapsed_occupancy(): void
    {
        $room = $this->room();
        $other = $this->room();
        $owner = User::factory()->owner()->create();
        $block = RoomBlock::create(['room_id' => $room->id, 'starts_at' => '2026-11-01 02:00:23', 'ends_at' => '2026-11-01 04:00:00', 'reason' => 'Old', 'created_by' => $owner->id]);
        DB::table('room_blocks')->where('id', $block->id)->update(['starts_at' => '2026-11-01 02:00:23.123456']);
        $input = $this->input($room->id, ['startsAt' => '2026-11-01T09:00', 'version' => 1]);
        foreach ([['roomId' => $other->id], ['startsAt' => '2026-11-01T09:01'], ['endsAt' => '2026-11-01T09:59']] as $bad) {
            $this->invalid(fn () => (new SaveRoomBlock)($owner, $block->id, [...$input, ...$bad]), array_key_first($bad));
        }
        $saved = (new SaveRoomBlock)($owner, $block->id, $input);
        $this->assertSame('2026-11-01 02:00:23.123456', $saved->getRawOriginal('starts_at'));
        $this->assertSame(2, $saved->version);
        CarbonImmutable::setTestNow('2026-11-01 05:00:00 UTC');
        $this->invalid(fn () => (new SaveRoomBlock)($owner, $block->id, [...$input, 'version' => 2]), 'block');
    }

    public function test_stale_block_edit_preserves_saved_facts(): void
    {
        $room = $this->room();
        $owner = User::factory()->owner()->create();
        $block = (new SaveRoomBlock)($owner, null, $this->input($room->id));
        $this->invalid(fn () => (new SaveRoomBlock)($owner, $block->id, $this->input($room->id, ['version' => 2])), 'version');
        $this->assertSame($owner->id, $block->fresh()->created_by);
        DB::table('room_blocks')->where('id', $block->id)->update(['version' => 4294967295]);
        $this->invalid(fn () => (new SaveRoomBlock)($owner, $block->id, $this->input($room->id, ['version' => 4294967295])), 'version');
    }

    public function test_all_rooms_closure_is_atomic(): void
    {
        $owner = User::factory()->owner()->create();
        $data = $this->input(1);
        unset($data['roomId']);
        $this->invalid(fn () => (new SaveRoomBlock)->forAllRooms($owner, $data), 'room');
        $first = $this->room();
        $second = $this->room();
        $second->update(['is_active' => false]);
        $block = (new SaveRoomBlock)($owner, null, $this->input($second->id));
        $this->invalid(fn () => (new SaveRoomBlock)->forAllRooms($owner, $data), 'room');
        $this->assertDatabaseCount('room_blocks', 1);
        $block->delete();
        $this->invalid(fn () => (new SaveRoomBlock)->forAllRooms($owner, [...$data, 'roomId' => $first->id]), 'roomId');
        $blocks = (new SaveRoomBlock)->forAllRooms($owner, $data);
        $this->assertSame([$first->id, $second->id], array_map(fn ($b) => $b->room_id, $blocks));
    }

    public function test_block_only_conflicts_do_not_show_an_empty_booking_reference_clause(): void
    {
        $room = $this->room();
        $owner = User::factory()->owner()->create();
        (new SaveRoomBlock)($owner, null, $this->input($room->id));
        try {
            (new SaveRoomBlock)($owner, null, $this->input($room->id));
            $this->fail('Overlap accepted');
        } catch (ValidationException $error) {
            $this->assertStringNotContainsString('Booking references:', $error->errors()['room'][0]);
            $this->assertStringContainsString($room->code, $error->errors()['room'][0]);
        }
    }

    public function test_http_block_identifiers_must_be_canonical_and_bounded(): void
    {
        $owner = User::factory()->owner()->create();
        $room = $this->room();
        $block = (new SaveRoomBlock)($owner, null, $this->input($room->id));
        $this->actingAs($owner);
        foreach (['0'.$block->id, '0', '999999999999999999999999', '1e0'] as $id) {
            $this->get('/admin/room-blocks/'.$id.'/edit')->assertNotFound();
        }
    }

    public function test_adjacency_on_either_side_and_outer_rollback_preserve_exact_schedule(): void
    {
        $room = $this->room();
        $owner = User::factory()->owner()->create();
        $block = (new SaveRoomBlock)($owner, null, $this->input($room->id, ['startsAt' => '2026-11-01T11:00', 'endsAt' => '2026-11-01T12:00']));
        $adjacent = (new SaveRoomBlock)($owner, null, $this->input($room->id));
        $this->assertSame(1, $adjacent->version);
        $revision = DB::table('schedule_guards')->value('revision');
        DB::beginTransaction();
        (new SaveRoomBlock)($owner, $block->id, $this->input($room->id, ['startsAt' => '2026-11-01T12:00', 'endsAt' => '2026-11-01T13:00', 'version' => 1]));
        DB::rollBack();
        $this->assertSame(1, $block->fresh()->version);
        $this->assertSame($revision, DB::table('schedule_guards')->value('revision'));
    }

    public function test_missing_schema_and_singletons_fail_closed_when_disabled(): void
    {
        config(['booking.scheduling_enabled' => false]);
        $owner = User::factory()->owner()->create();
        $room = $this->room();
        foreach (['room_blocks' => 'created_by', 'users' => 'can_manage_bookings'] as $table => $column) {
            Schema::table($table, fn ($b) => $b->renameColumn($column, 'missing_b3f'));
            try {
                $this->invalid(fn () => (new SaveRoomBlock)($owner, null, $this->input($room->id)), 'room');
            } finally {
                Schema::table($table, fn ($b) => $b->renameColumn('missing_b3f', $column));
            }
        }
        foreach (['staff_access_guards', 'schedule_guards'] as $table) {
            $row = (array) DB::table($table)->first();
            DB::table($table)->delete();
            $this->invalid(fn () => (new SaveRoomBlock)($owner, null, $this->input($room->id)), 'room');
            DB::table($table)->insert($row);
        }
        $this->assertDatabaseCount('room_blocks', 0);
    }

    public function test_now_is_captured_after_guards_and_throttle_precedes_guard(): void
    {
        $owner = User::factory()->owner()->create();
        $room = $this->room();
        $block = (new SaveRoomBlock)($owner, null, $this->input($room->id));
        DB::connection()->beforeExecuting(function ($sql): void {
            if (str_starts_with($sql, 'update "schedule_guards"')) {
                CarbonImmutable::setTestNow('2026-11-01 03:00:30 UTC');
            }
        });
        $this->invalid(fn () => (new SaveRoomBlock)($owner, $block->id, $this->input($room->id, ['version' => 1, 'startsAt' => '2026-11-01T10:01'])), 'startsAt');
        $this->actingAs($owner);
        foreach (range(1, 20) as $i) {
            $this->post('/admin/room-blocks', ['scope' => 'room', ...$this->input($room->id)])->assertRedirect();
        }
        $revision = DB::table('staff_access_guards')->value('revision');
        $this->post('/admin/room-blocks', ['scope' => 'room', ...$this->input($room->id)])->assertTooManyRequests();
        $this->assertSame($revision, DB::table('staff_access_guards')->value('revision'));
    }
}
