<?php

namespace Tests\Feature\Admin;

use App\Actions\SaveRoom;
use App\Models\Room;
use App\Models\RoomReservation;
use App\Models\User;
use App\Support\Scheduling\RoomAllocator;
use App\Support\Scheduling\ScheduleLock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Scheduling\RoomAllocationTest;
use Tests\TestCase;

class SaveRoomTest extends TestCase
{
    use RefreshDatabase;

    public function input(array $overrides = []): array
    {
        return [...['code' => ' NEW ', 'name' => ' New room ', 'type' => ' Suite 1 ', 'capacity' => 8,
            'cleaningMinutes' => 20, 'isActive' => false, 'compatibleVariantIds' => []], ...$overrides];
    }

    private function save(?Room $room = null, array $overrides = []): Room
    {
        return (new SaveRoom)(User::factory()->owner()->create(), $room?->id,
            $this->input([...($room ? ['version' => $room->fresh()->version] : []), ...$overrides]));
    }

    public function test_owner_can_create_and_edit_with_real_pivot_and_server_version(): void
    {
        [$booking] = (new RoomAllocationTest('fixture'))->fixture();
        DB::table('service_variants')->update(['is_active' => false, 'published_at' => null]);
        $room = $this->save(null, ['compatibleVariantIds' => [$booking->service_variant_id]]);
        $this->assertSame(['NEW', 'New room', 'Suite 1', 8, 1, false], [$room->code, $room->name, $room->type, $room->capacity, $room->version, $room->is_active]);
        $this->assertSame([$booking->service_variant_id], $room->variants()->pluck('service_variants.id')->all());
        $changed = $this->save($room, ['code' => 'CHANGED', 'type' => ' ', 'capacity' => 4294967295]);
        $this->assertSame(2, $changed->version);
        $this->assertNull($changed->type);
        $this->assertSame(4294967295, $changed->capacity);
        $this->assertSame([], $changed->variants()->pluck('service_variants.id')->all());
    }

    public function test_direct_action_denies_stale_forged_unsaved_unverified_deleted_and_other_connection_actors(): void
    {
        foreach (['staff', 'customer', 'demoted', 'unverified', 'deleted', 'unsaved', 'connection', 'forged'] as $case) {
            $actor = User::factory()->owner()->create();
            match ($case) {
                'staff', 'demoted' => DB::table('users')->where('id', $actor->id)->update(['role' => 'admin']),
                'customer', 'forged' => DB::table('users')->where('id', $actor->id)->update(['role' => 'customer']),
                'unverified' => DB::table('users')->where('id', $actor->id)->update(['email_verified_at' => null]),
                'deleted' => DB::table('users')->where('id', $actor->id)->delete(),
                'unsaved' => $actor = User::factory()->owner()->make(),
                'connection' => $actor->setConnection('mysql'),
            };
            try {
                (new SaveRoom)($actor, null, $this->input());
                $this->fail('Unauthorized actor accepted '.$case);
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('rooms', 0);
            }
        }
    }

    public function test_http_forms_and_mutations_require_current_verified_owner(): void
    {
        $room = Room::create(['code' => 'R', 'name' => 'Room', 'capacity' => 1]);
        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $actor) {
            $this->actingAs($actor)->get('/admin/rooms/create')->assertForbidden();
            $this->get('/admin/rooms/'.$room->id.'/edit')->assertForbidden();
            $this->post('/admin/rooms', $this->input())->assertForbidden();
            $this->patch('/admin/rooms/'.$room->id, $this->input(['version' => 1]))->assertForbidden();
        }
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->get('/admin/rooms/create')->assertOk()->assertInertia(fn ($page) => $page
            ->component('admin/rooms/Form')->where('management.ready', true)->where('room', null));
        $this->post('/admin/rooms', $this->input())->assertRedirect()->assertSessionHas('status');
        $created = Room::where('code', 'NEW')->firstOrFail();
        $this->get('/admin/rooms/'.$created->id.'/edit')->assertOk()->assertInertia(fn ($page) => $page->where('room.version', 1));
        $this->patch('/admin/rooms/'.$created->id, $this->input(['name' => 'Updated', 'version' => 1]))->assertRedirect();
        $this->assertSame('Updated', $created->fresh()->name);
        DB::table('users')->where('id', $owner->id)->update(['role' => 'admin']);
        $this->get('/admin/rooms/create')->assertForbidden();
        $this->post('/admin/rooms', $this->input())->assertForbidden();
        $this->assertDatabaseCount('rooms', 2);
    }

    public function test_scalar_bounds_unknown_attributes_duplicates_and_missing_catalog_are_rejected(): void
    {
        foreach ([['code' => ' '], ['code' => str_repeat('x', 65)], ['name' => ' '], ['name' => str_repeat('x', 256)],
            ['type' => '_bad'], ['type' => str_repeat('x', 65)], ['capacity' => 0], ['capacity' => 4294967296],
            ['capacity' => 1.2], ['capacity' => true], ['cleaningMinutes' => -1], ['cleaningMinutes' => 721],
            ['isActive' => 'true'], ['compatibleVariantIds' => [1, 1]], ['compatibleVariantIds' => [999]],
            ['compatibleVariantIds' => array_fill(0, 501, 1)], ['id' => 5], ['version' => 50], ['role' => 'owner']] as $invalid) {
            try {
                $this->save(null, $invalid);
                $this->fail('Invalid input accepted '.json_encode($invalid));
            } catch (ValidationException) {
                $this->assertDatabaseCount('rooms', 0);
            }
        }
        foreach (['capacity', 'cleaningMinutes', 'isActive', 'compatibleVariantIds'] as $field) {
            $input = $this->input();
            unset($input[$field]);
            try {
                (new SaveRoom)(User::factory()->owner()->create(), null, $input);
                $this->fail('Missing input accepted '.$field);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }

    public function test_code_uniqueness_stale_version_and_overflow_preserve_saved_room_and_pivot(): void
    {
        $first = $this->save();
        foreach ([['code' => 'NEW'], ['code' => 'A', 'version' => 0]] as $invalid) {
            try {
                $invalid['version'] ??= 1;
                $this->save($first, $invalid);
                if ($invalid['code'] === 'NEW') {
                    $this->save(null);
                }
                $this->fail('Conflict accepted');
            } catch (ValidationException) {
                $this->assertSame('NEW', $first->fresh()->code);
            }
        }
        DB::table('rooms')->where('id', $first->id)->update(['version' => 4294967295]);
        try {
            $this->save($first->fresh());
            $this->fail('Overflow accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('version', $e->errors());
        }
    }

    public function test_missing_write_tables_columns_and_singletons_fail_closed_without_validation_probes(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);
        foreach (['room_service_variant', 'room_reservations', 'rooms', 'schedule_guards', 'staff_access_guards', 'service_variants'] as $table) {
            Schema::rename($table, $table.'_absent');
            try {
                $this->post('/admin/rooms', $this->input(['compatibleVariantIds' => [999]]))->assertSessionHasErrors('room');
                $this->get('/admin/rooms/create')->assertOk()->assertInertia(fn ($page) => $page->where('management.ready', false)->where('variants', []));
            } finally {
                Schema::rename($table.'_absent', $table);
            }
        }
        foreach (['staff_access_guards', 'schedule_guards'] as $guard) {
            $row = (array) DB::table($guard)->first();
            DB::table($guard)->delete();
            $this->post('/admin/rooms', $this->input())->assertSessionHasErrors('room');
            DB::table($guard)->insert($row);
        }
        Schema::table('rooms', fn ($table) => $table->renameColumn('version', 'missing_version'));
        try {
            $this->post('/admin/rooms', $this->input())->assertSessionHasErrors('room');
        } finally {
            Schema::table('rooms', fn ($table) => $table->renameColumn('missing_version', 'version'));
        }
    }

    public function test_protected_reservations_selectively_reject_invalidations_and_allow_benign_edits(): void
    {
        [$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
        DB::table('bookings')->where('id', $booking->id)->update(['guests' => 2, 'requested_room_type' => 'Suite 1', 'status' => 'completed']);
        $room->update(['type' => 'Suite 1']);
        $reservation = RoomReservation::create(['room_id' => $room->id, 'booking_id' => $booking->id,
            'starts_at' => '2026-10-25 03:00:00', 'treatment_ends_at' => '2026-10-25 04:30:00', 'ends_at' => '2026-10-25 04:45:00', 'cleaning_minutes' => 15]);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 04:35:00 UTC'));
        config(['app.timezone' => 'Asia/Jakarta']);
        $original = $reservation->fresh()->getRawOriginal();
        $input = ['isActive' => true, 'capacity' => 2, 'compatibleVariantIds' => [$booking->service_variant_id]];
        foreach ([['isActive' => false], ['capacity' => 1], ['type' => null], ['compatibleVariantIds' => []]] as $invalid) {
            try {
                $this->save($room, [...$input, ...$invalid]);
                $this->fail('Protected edit accepted');
            } catch (ValidationException $error) {
                $this->assertStringContainsString($booking->reference, $error->errors()['room'][0]);
                $this->assertSame(1, $room->fresh()->version);
                $this->assertSame($original, $reservation->fresh()->getRawOriginal());
            }
        }
        DB::table('service_variants')->update(['is_active' => false, 'published_at' => null]);
        $updated = $this->save($room, [...$input, 'name' => 'Benign', 'capacity' => 3, 'cleaningMinutes' => 0]);
        $this->assertSame(2, $updated->version);
        $this->assertSame($original, $reservation->fresh()->getRawOriginal());
        $this->travelBack();
    }

    public function test_released_expired_and_exact_end_reservations_do_not_block(): void
    {
        [$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
        $hold = (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
        foreach (['released', 'exact', 'expired'] as $case) {
            $room = $room->fresh();
            DB::table('room_reservations')->where('id', $hold->id)->update(['released_at' => $case === 'released' ? '2026-10-24 00:00:00' : null]);
            $this->travelTo($case === 'released' ? $hold->starts_at->subDay() : $hold->ends_at->addSeconds($case === 'exact' ? 0 : 1));
            $this->assertFalse($this->save($room, ['isActive' => false])->is_active);
        }
        $this->travelBack();
    }

    public function test_missing_raw_booking_facts_fail_closed_only_for_potentially_invalidating_changes(): void
    {
        [$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
        (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
        $this->travelTo(CarbonImmutable::parse('2026-10-24 UTC'));
        DB::table('bookings')->where('id', $booking->id)->update(['service_variant_id' => null]);
        try {
            $this->save($room, ['isActive' => true, 'capacity' => 2]);
            $this->fail('Missing variant accepted for removal');
        } catch (ValidationException) {
            $this->assertSame(1, $room->fresh()->version);
        }
        $this->save($room, ['isActive' => true, 'capacity' => 3, 'compatibleVariantIds' => $room->variants()->pluck('service_variants.id')->all()]);
        $this->travelBack();
    }

    public function test_failed_pivot_insert_rolls_back_room_and_version(): void
    {
        [$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
        $room->variants()->detach();
        DB::statement("CREATE TRIGGER fail_room_pivot BEFORE INSERT ON room_service_variant BEGIN SELECT RAISE(ABORT, 'pivot unavailable'); END");
        try {
            $this->save($room, ['compatibleVariantIds' => [$booking->service_variant_id]]);
            $this->fail('Pivot failure ignored');
        } catch (QueryException $error) {
            $this->assertStringContainsString('pivot unavailable', $error->getMessage());
            $this->assertSame('small', $room->fresh()->code);
            $this->assertSame(1, $room->fresh()->version);
        }
    }

    public function test_unverified_owner_http_and_missing_catalog_parents_cannot_write(): void
    {
        $this->actingAs(User::factory()->owner()->unverified()->create())->get('/admin/rooms/create')->assertRedirect(route('verification.notice', absolute: false));
        $this->post('/admin/rooms', $this->input())->assertRedirect(route('verification.notice', absolute: false));
        [$booking] = (new RoomAllocationTest('fixture'))->fixture();
        // Deliberately corrupt disposable SQLite references, without using a relationship that hides the orphan.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('service_variants')->where('id', $booking->service_variant_id)->update(['service_id' => 99999]);
        try {
            $this->save(null, ['compatibleVariantIds' => [$booking->service_variant_id]]);
            $this->fail('Orphan variant accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('compatibleVariantIds', $e->errors());
        }
    }

    public function test_now_is_captured_after_guards_and_expired_hold_is_not_protected_after_wait(): void
    {
        [$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
        $hold = (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
        $this->travelTo($hold->ends_at->subSecond());
        DB::connection()->beforeExecuting(function ($sql) use ($hold): void {
            if (str_starts_with($sql, 'update "schedule_guards"')) {
                CarbonImmutable::setTestNow($hold->ends_at->toImmutable());
            }
        });
        $updated = $this->save($room, ['isActive' => false]);
        $this->assertFalse($updated->is_active);
        $this->travelBack();
    }

    public function test_existing_inactive_room_allows_metadata_but_invalid_saved_guest_facts_block_reduction(): void
    {
        [$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
        $hold = (new ScheduleLock)->run(fn () => (new RoomAllocator)->allocateLocked($booking));
        $this->travelTo(CarbonImmutable::parse('2026-10-24 UTC'));
        $room->update(['is_active' => false]);
        $original = $hold->fresh()->getRawOriginal();
        DB::table('bookings')->where('id', $booking->id)->update(['guests' => 'unknown']);
        $input = ['isActive' => false, 'type' => null, 'capacity' => 2, 'compatibleVariantIds' => [$booking->service_variant_id]];
        $updated = $this->save($room, $input);
        $this->assertFalse($updated->is_active);
        $this->assertSame($original, $hold->fresh()->getRawOriginal());
        try {
            $this->save($updated, [...$input, 'capacity' => 1]);
            $this->fail('Invalid saved guests accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('room', $e->errors());
            $this->assertSame(2, $updated->fresh()->capacity);
        }
        $this->travelBack();
    }

    public function test_user_throttle_is_independent_of_ip_and_runs_before_staff_guard(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);
        foreach (range(1, 20) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->post('/admin/rooms', $this->input(['code' => 'T'.$i]))->assertRedirect();
        }
        $revision = DB::table('staff_access_guards')->value('revision');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])->post('/admin/rooms', $this->input(['code' => 'DENIED']))->assertTooManyRequests();
        $this->assertSame($revision, DB::table('staff_access_guards')->value('revision'));
        $this->actingAs(User::factory()->owner()->create())->post('/admin/rooms', $this->input(['code' => 'OTHER']))->assertRedirect();
    }

    public function test_incomplete_schedule_audit_and_reservation_columns_disable_writes_even_when_scheduling_is_off(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        foreach (['room_blocks' => 'created_by', 'room_reservations' => 'updated_at', 'booking_status_changes' => 'operational_confirmed_at'] as $table => $column) {
            Schema::table($table, fn ($blueprint) => $blueprint->renameColumn($column, 'b3e_missing'));
            try {
                $this->post('/admin/rooms', $this->input())->assertSessionHasErrors('room');
            } finally {
                Schema::table($table, fn ($blueprint) => $blueprint->renameColumn('b3e_missing', $column));
            }
        }
        $this->assertDatabaseCount('rooms', 0);
    }
}
