<?php

namespace Tests\Feature\Admin;

use App\Actions\SaveRoomBlock;
use App\Actions\TerminateRoomBlock;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TerminateRoomBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function fixture(string $start = '2026-11-01 03:00:00'): array
    {
        CarbonImmutable::setTestNow('2026-11-01 02:59:59.123456 UTC');
        $actor = User::factory()->owner()->create(['name' => '<script>Staff</script>']);
        $room = Room::create(['code' => 'R1', 'name' => 'Room one', 'capacity' => 2]);
        $block = RoomBlock::create(['room_id' => $room->id, 'starts_at' => $start, 'ends_at' => '2026-11-01 04:00:00', 'reason' => "Original\nreason", 'created_by' => $actor->id]);

        return [$actor, $block];
    }

    private function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Invalid termination accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }

    public function test_terminal_feedback_is_localized_and_explains_explicit_intent(): void
    {
        foreach (['en', 'id'] as $locale) {
            foreach (['termination_saved', 'termination_unavailable', 'invalid_terminal', 'terminated', 'lifecycle_changed'] as $message) {
                $key = 'admin.blocks.'.$message;
                $this->assertNotSame($key, __($key, [], $locale));
            }
        }
        $this->assertStringContainsString('explicitly', __('admin.blocks.lifecycle_changed', [], 'en'));
    }

    public function test_cancellation_preserves_originals_and_exact_immutable_staff_history(): void
    {
        [$actor, $block] = $this->fixture();
        $before = $block->getRawOriginal();
        $saved = (new TerminateRoomBlock)($actor, $block->id, ['intent' => 'cancel_future', 'version' => '1', 'reason' => "  Plan changed\n safely  "]);
        $this->assertSame(2, $saved->version);
        foreach (['starts_at', 'ends_at', 'reason', 'created_by', 'room_id'] as $key) {
            $this->assertSame($before[$key], $saved->getRawOriginal($key));
        }
        $audit = DB::table('room_block_terminations')->first();
        $this->assertSame('2026-11-01 02:59:59.123456', $audit->occurred_at);
        $this->assertSame("Plan changed\n safely", $audit->reason);
        $this->assertSame($actor->name, $audit->actor_name);
        $this->assertSame('owner', $audit->actor_role);
        $this->assertSame(1, $audit->previous_version);
        $this->assertSame(2, $audit->resulting_version);
        $this->invalid(fn () => (new TerminateRoomBlock)($actor, $block->id, ['intent' => 'cancel_future', 'version' => 2, 'reason' => 'Replay']), 'block');
        $this->invalid(fn () => (new SaveRoomBlock)($actor, $block->id, ['roomId' => $block->room_id, 'startsAt' => '2026-11-01T10:00', 'endsAt' => '2026-11-01T11:00', 'version' => 2, 'reason' => 'Revive']), 'block');
        $this->assertDatabaseCount('room_block_terminations', 1);
        $this->assertSame(2, $block->fresh()->version);
    }

    public function test_lifecycle_is_explicit_at_start_end_and_fractional_instants(): void
    {
        [$actor, $block] = $this->fixture();
        $input = ['version' => 1, 'reason' => 'Reopen'];
        $this->invalid(fn () => (new TerminateRoomBlock)($actor, $block->id, [...$input, 'intent' => 'end_now']), 'intent');
        CarbonImmutable::setTestNow('2026-11-01 03:00:00 UTC');
        $this->invalid(fn () => (new TerminateRoomBlock)($actor, $block->id, [...$input, 'intent' => 'cancel_future']), 'intent');
        DB::beginTransaction();
        (new TerminateRoomBlock)($actor, $block->id, [...$input, 'intent' => 'end_now']);
        DB::rollBack();
        $this->assertDatabaseCount('room_block_terminations', 0);
        CarbonImmutable::setTestNow('2026-11-01 04:00:00 UTC');
        foreach (['end_now', 'cancel_future'] as $intent) {
            $this->invalid(fn () => (new TerminateRoomBlock)($actor, $block->id, [...$input, 'intent' => $intent]), 'intent');
        }
        CarbonImmutable::setTestNow('2026-11-01 03:00:00.500000 UTC');
        (new TerminateRoomBlock)($actor, $block->id, [...$input, 'intent' => 'end_now']);
        $this->assertDatabaseHas('room_block_terminations', ['occurred_at' => '2026-11-01 03:00:00.500000']);
    }

    public function test_strict_input_stale_versions_and_canonical_route_ids(): void
    {
        [$actor, $block] = $this->fixture();
        $input = ['intent' => 'cancel_future', 'version' => 1, 'reason' => 'Reason'];
        foreach ([['intent' => 'auto'], ['intent' => []], ['version' => '01'], ['version' => 4294967296], ['version' => 2], ['reason' => []], ['reason' => ' '], ['reason' => str_repeat('x', 1001)], ['occurred_at' => '2026-01-01'], ['actor_user_id' => 1], ['roomId' => 1]] as $bad) {
            $this->invalid(fn () => (new TerminateRoomBlock)($actor, $block->id, [...$input, ...$bad]), array_key_first($bad));
        }
        $this->actingAs($actor);
        foreach (['01', '0', '1e0', '99999999999999999999999'] as $id) {
            $this->post('/admin/room-blocks/'.$id.'/terminate', $input)->assertNotFound();
        }
        DB::table('room_blocks')->where('id', $block->id)->update(['version' => 4294967295]);
        $this->invalid(fn () => (new TerminateRoomBlock)($actor, $block->id, [...$input, 'version' => 4294967295]), 'version');
        $this->assertDatabaseCount('room_block_terminations', 0);
    }

    public function test_fresh_authority_is_required_and_http_redirect_retains_saved_detail(): void
    {
        [$actor, $block] = $this->fixture();
        $input = ['intent' => 'cancel_future', 'version' => 1, 'reason' => 'Reason'];
        foreach (['owner', 'admin'] as $role) {
            $allowed = User::factory()->create(['role' => $role, 'can_manage_bookings' => true]);
            DB::beginTransaction();
            (new TerminateRoomBlock)($allowed, $block->id, $input);
            DB::rollBack();
        }
        foreach ([['role' => 'customer'], ['can_manage_bookings' => false], ['email_verified_at' => null]] as $change) {
            $stale = User::factory()->admin()->create(['can_manage_bookings' => true]);
            DB::table('users')->where('id', $stale->id)->update($change);
            try {
                (new TerminateRoomBlock)($stale, $block->id, $input);
                $this->fail('Stale authority accepted');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('room_block_terminations', 0);
            }
        }
        $denied = User::factory()->admin()->create(['can_manage_bookings' => false]);
        $this->actingAs($denied)->post('/admin/room-blocks/'.$block->id.'/terminate', $input)->assertForbidden();
        $this->actingAs($actor)->post('/admin/room-blocks/'.$block->id.'/terminate', $input)->assertRedirect('/admin/room-blocks/'.$block->id.'/edit');
        $this->actingAs($denied)->get('/admin/room-blocks?view=history')->assertOk();
    }

    public function test_guard_time_and_atomic_insert_failure_leave_no_partial_fact(): void
    {
        [$actor, $block] = $this->fixture();
        $input = ['intent' => 'cancel_future', 'version' => 1, 'reason' => 'Reason'];
        DB::connection()->beforeExecuting(function ($sql): void {
            if (str_starts_with($sql, 'update "schedule_guards"')) {
                CarbonImmutable::setTestNow('2026-11-01 03:00:00.123456 UTC');
            }
        });
        $this->invalid(fn () => (new TerminateRoomBlock)($actor, $block->id, $input), 'intent');
        DB::statement("CREATE TRIGGER reject_terminal BEFORE INSERT ON room_block_terminations BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        try {
            (new TerminateRoomBlock)($actor, $block->id, [...$input, 'intent' => 'end_now']);
            $this->fail('Expected insert failure');
        } catch (QueryException) {
            $this->assertSame(1, $block->fresh()->version);
            $this->assertDatabaseCount('room_block_terminations', 0);
        }
    }
}
