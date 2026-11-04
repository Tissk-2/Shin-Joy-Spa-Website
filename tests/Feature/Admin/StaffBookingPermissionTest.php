<?php

namespace Tests\Feature\Admin;

use App\Actions\SaveRoomBlock;
use App\Actions\Staff\SetBookingPermission;
use App\Actions\TerminateRoomBlock;
use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use App\Policies\BookingPolicy;
use App\Support\Staff\StaffSchemaException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffBookingPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_grants_revokes_and_noops_with_atomic_identity_history(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->admin()->create();
        $result = (new SetBookingPermission)($owner, $staff->id, true);
        $this->assertTrue($result->can_manage_bookings);
        $this->assertFalse($staff->can_manage_bookings ?? false);
        (new SetBookingPermission)($owner, $staff->id, true);
        $this->assertDatabaseCount('staff_permission_changes', 1);
        $this->assertDatabaseHas('staff_permission_changes', [
            'target_user_id' => $staff->id, 'target_name' => $staff->name, 'target_email' => $staff->email,
            'actor_user_id' => $owner->id, 'actor_name' => $owner->name, 'actor_email' => $owner->email,
            'was_allowed' => false, 'is_allowed' => true, 'channel' => 'dashboard',
        ]);
        (new SetBookingPermission)($owner, $staff->id, false);
        $this->assertFalse($staff->fresh()->can_manage_bookings);
        $this->assertDatabaseCount('staff_permission_changes', 2);
        $staff->delete();
        $owner->delete();
        $this->assertDatabaseCount('staff_permission_changes', 2);
        $this->assertDatabaseHas('staff_permission_changes', ['target_user_id' => null, 'actor_user_id' => null, 'target_email' => $staff->email, 'actor_email' => $owner->email]);
    }

    public function test_permission_action_rejects_untrusted_and_currently_ineligible_actors(): void
    {
        $target = User::factory()->admin()->create();
        $actors = [User::factory()->create(), User::factory()->admin()->create(), User::factory()->owner()->unverified()->create(), User::factory()->owner()->make()];
        $stale = User::factory()->owner()->create();
        User::whereKey($stale->id)->update(['role' => 'admin']);
        $actors[] = $stale;
        $deleted = User::factory()->owner()->create();
        User::whereKey($deleted->id)->delete();
        $actors[] = $deleted;
        $wrongConnection = User::factory()->owner()->create();
        $wrongConnection->setConnection('mysql');
        $actors[] = $wrongConnection;
        foreach ($actors as $actor) {
            try {
                (new SetBookingPermission)($actor, $target->id, true);
                $this->fail('Ineligible actor granted permission.');
            } catch (AuthorizationException) {
                $this->assertFalse($target->fresh()->can_manage_bookings);
            }
        }
        $this->assertDatabaseCount('staff_permission_changes', 0);
    }

    public function test_targets_must_be_admin_and_verified_for_grants_but_not_revokes(): void
    {
        $owner = User::factory()->owner()->create();
        foreach ([99999, User::factory()->create()->id, $owner->id, User::factory()->admin()->unverified()->create()->id] as $id) {
            try {
                (new SetBookingPermission)($owner, $id, true);
                $this->fail('Ineligible target accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('allowed', $exception->errors());
            }
        }
        $unverified = User::factory()->admin()->unverified()->create(['can_manage_bookings' => true]);
        (new SetBookingPermission)($owner, $unverified->id, false);
        $this->assertFalse($unverified->fresh()->can_manage_bookings);
    }

    public function test_http_is_owner_only_and_lists_only_safe_staff_with_bounded_pagination(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->admin()->create();
        User::factory()->create();
        $this->get('/admin/staff')->assertRedirect('/login');
        foreach ([User::factory()->create(), $staff] as $actor) {
            $this->actingAs($actor)->get('/admin/staff')->assertForbidden();
            $this->patch('/admin/staff/'.$staff->id.'/booking-permission', ['allowed' => true])->assertForbidden();
        }
        $this->actingAs(User::factory()->owner()->unverified()->create())->get('/admin/staff')->assertRedirect('/verify-email');
        $this->actingAs($owner)->get('/admin/staff?per_page=100000')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/staff/Index')->has('staff.data', 3)
            ->where('staff.data.0.id', $owner->id)->where('staff.data.0.role', 'owner')
            ->missing('staff.data.0.password')->missing('staff.data.0.remember_token')->missing('staff.data.0.phone'));
    }

    public function test_explicit_boolean_is_required_and_payload_cannot_select_authority(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->admin()->create(['can_manage_bookings' => true]);
        $this->actingAs($owner);
        foreach ([[], ['allowed' => null], ['allowed' => 'false'], ['allowed' => []], ['allowed' => 2]] as $payload) {
            $this->from('/admin/staff')->patch('/admin/staff/'.$staff->id.'/booking-permission', $payload)->assertSessionHasErrors('allowed');
            $this->assertTrue($staff->fresh()->can_manage_bookings);
        }
        $this->patch('/admin/staff/'.$staff->id.'/booking-permission', ['allowed' => false, 'role' => 'owner', 'actor_user_id' => $staff->id, 'channel' => 'cli'])->assertRedirect('/admin/staff');
        $this->assertFalse($staff->fresh()->can_manage_bookings);
        $this->assertDatabaseHas('staff_permission_changes', ['actor_user_id' => $owner->id, 'channel' => 'dashboard']);
        $this->assertFalse($staff->fresh()->isOwner());
    }

    public function test_audit_failure_rolls_back_and_missing_or_partial_audit_schema_fails_closed(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->admin()->create();
        DB::unprepared("CREATE TRIGGER reject_permission_audit BEFORE INSERT ON staff_permission_changes BEGIN SELECT RAISE(ABORT, 'audit refused'); END");
        try {
            (new SetBookingPermission)($owner, $staff->id, true);
            $this->fail('Audit failure was ignored.');
        } catch (QueryException) {
            $this->assertFalse($staff->fresh()->can_manage_bookings);
        }
        DB::unprepared('DROP TRIGGER reject_permission_audit');
        Schema::table('staff_permission_changes', fn ($table) => $table->dropColumn('channel'));
        try {
            (new SetBookingPermission)($owner, $staff->id, true);
            $this->fail('Partial schema accepted.');
        } catch (StaffSchemaException) {
            $this->assertFalse($staff->fresh()->can_manage_bookings);
        }
        Schema::drop('staff_permission_changes');
        $this->expectException(StaffSchemaException::class);
        (new SetBookingPermission)($owner, $staff->id, true);
    }

    public function test_revocation_keeps_reads_but_denies_stale_policy_transition_and_presented_actions(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->admin()->create(['can_manage_bookings' => true]);
        $booking = Booking::factory()->create();
        $policy = new BookingPolicy;
        $this->assertTrue($policy->updateStatus($staff, $booking));
        (new SetBookingPermission)($owner, $staff->id, false);
        foreach (['updateStatus', 'approve', 'cancel', 'complete', 'noShow'] as $ability) {
            $this->assertFalse($policy->$ability($staff, $booking));
            $this->assertTrue($policy->$ability($owner, $booking));
        }
        $this->actingAs($staff)->get('/admin/bookings/'.$booking->reference)->assertOk()->assertInertia(fn (Assert $page) => $page->where('booking.allowedTransitions', []));
        $this->patch('/admin/bookings/'.$booking->reference.'/status', ['currentStatus' => 'pending', 'nextStatus' => 'confirmed'])->assertForbidden();
        try {
            (new TransitionBookingStatus)($staff, $booking, BookingStatus::Pending, BookingStatus::Confirmed, null, null, null);
            $this->fail('Revoked actor mutated booking.');
        } catch (AuthorizationException) {
            $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
            $this->assertDatabaseCount('booking_status_changes', 0);
            $this->assertDatabaseCount('room_reservations', 0);
        }
        (new TransitionBookingStatus)($owner, $booking, BookingStatus::Pending, BookingStatus::Confirmed, null, null, null);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_provisioning_never_resurrects_permission_and_audits_role_clearing(): void
    {
        User::factory()->owner()->create();
        $staff = User::factory()->admin()->create(['can_manage_bookings' => true]);
        $this->artisan('admin:grant', ['email' => $staff->email, '--yes' => true])->assertSuccessful();
        $this->assertTrue($staff->fresh()->can_manage_bookings);
        $this->artisan('admin:revoke', ['email' => $staff->email, '--yes' => true])->assertSuccessful();
        $this->assertFalse($staff->fresh()->can_manage_bookings);
        $this->assertDatabaseHas('staff_permission_changes', ['target_user_id' => $staff->id, 'actor_user_id' => null, 'channel' => 'cli', 'was_allowed' => true, 'is_allowed' => false]);
        $this->artisan('admin:grant', ['email' => $staff->email, '--yes' => true])->assertSuccessful();
        $this->assertFalse($staff->fresh()->can_manage_bookings);
        $this->assertDatabaseCount('staff_permission_changes', 1);
    }

    public function test_revoked_stale_actor_cannot_create_update_or_terminate_maintenance(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        $room = Room::create(['code' => 'B1B', 'name' => 'Maintenance room', 'capacity' => 2]);
        $input = ['roomId' => $room->id, 'startsAt' => now('Asia/Jakarta')->addDays(2)->format('Y-m-d').'T10:00',
            'endsAt' => now('Asia/Jakarta')->addDays(2)->format('Y-m-d').'T11:00', 'reason' => 'Repair'];
        $block = (new SaveRoomBlock)($staff, null, $input);
        (new SetBookingPermission)($owner, $staff->id, false);
        foreach ([fn () => (new SaveRoomBlock)($staff, null, $input),
            fn () => (new SaveRoomBlock)($staff, $block->id, [...$input, 'version' => 1]),
            fn () => (new TerminateRoomBlock)($staff, $block->id, ['version' => 1, 'reason' => 'Finished'])] as $operation) {
            try {
                $operation();
                $this->fail('Revoked stale staff changed maintenance.');
            } catch (AuthorizationException) {
                $this->assertSame(1, $block->fresh()->version);
                $this->assertNull($block->fresh()->terminated_at);
            }
        }
        $this->assertDatabaseCount('room_blocks', 1);
    }

    public function test_new_admin_defaults_false_and_upgrade_does_not_backfill_existing_false_admin(): void
    {
        $plain = User::factory()->admin()->create();
        $capable = User::factory()->bookingStaff()->create();
        $this->assertFalse($plain->fresh()->can_manage_bookings);
        Schema::drop('staff_permission_changes');
        $migration = require database_path('migrations/2026_10_31_010009_create_staff_permission_changes.php');
        $migration->up();
        $this->assertFalse($plain->fresh()->can_manage_bookings);
        $this->assertTrue($capable->fresh()->can_manage_bookings);
        $this->assertDatabaseCount('staff_permission_changes', 0);
    }

    public function test_provisioning_preserves_b1a_compatibility_but_partial_audit_or_failure_rolls_back(): void
    {
        User::factory()->owner()->create();
        $staff = User::factory()->bookingStaff()->create();
        DB::unprepared("CREATE TRIGGER reject_permission_audit BEFORE INSERT ON staff_permission_changes BEGIN SELECT RAISE(ABORT, 'audit refused'); END");
        try {
            $this->artisan('admin:revoke', ['email' => $staff->email, '--yes' => true])->run();
            $this->fail('CLI audit failure ignored.');
        } catch (QueryException) {
            $this->assertTrue($staff->fresh()->isAdmin());
            $this->assertTrue($staff->fresh()->can_manage_bookings);
        }
        DB::unprepared('DROP TRIGGER reject_permission_audit');
        Schema::table('staff_permission_changes', fn ($table) => $table->dropColumn('channel'));
        $this->artisan('admin:revoke', ['email' => $staff->email, '--yes' => true])->assertFailed();
        $this->assertTrue($staff->fresh()->can_manage_bookings);
        Schema::drop('staff_permission_changes');
        $this->artisan('admin:revoke', ['email' => $staff->email, '--yes' => true])->assertSuccessful();
        $this->artisan('admin:grant', ['email' => $staff->email, '--yes' => true])->assertSuccessful();
        $this->assertFalse($staff->fresh()->can_manage_bookings);
        $customer = User::factory()->create(['can_manage_bookings' => true]);
        $this->artisan('admin:grant', ['email' => $customer->email, '--yes' => true])->assertSuccessful();
        $this->assertFalse($customer->fresh()->can_manage_bookings);
    }

    public function test_current_role_verification_deleted_and_forged_policy_actors_fail_closed(): void
    {
        $booking = Booking::factory()->create();
        $policy = new BookingPolicy;
        foreach (['role', 'verification', 'deleted', 'forged'] as $case) {
            $actor = User::factory()->bookingStaff()->create();
            match ($case) {
                'role' => User::whereKey($actor->id)->update(['role' => 'customer']),
                'verification' => User::whereKey($actor->id)->update(['email_verified_at' => null]),
                'deleted' => User::whereKey($actor->id)->delete(),
                'forged' => $actor = User::factory()->owner()->make(),
            };
            $this->assertFalse($policy->updateStatus($actor, $booking));
        }
        $staff = User::factory()->bookingStaff()->create();
        DB::table('staff_access_guards')->delete();
        $this->assertFalse($policy->updateStatus($staff, $booking));
    }

    public function test_guarded_transition_policy_keeps_current_locking_actor_reads(): void
    {
        $owner = User::factory()->owner()->create();
        $booking = Booking::factory()->create();
        User::all(); // A caller can already have a repeatable-read snapshot.
        $connection = DB::connection();
        $original = $connection->getQueryGrammar();
        $grammar = new class($connection) extends SQLiteGrammar
        {
            public array $reads = [];

            public function compileSelect(Builder $query)
            {
                if ($query->from === 'users' && $query->columns === ['*']) {
                    $this->reads[] = $query->lock;
                }

                return parent::compileSelect($query);
            }
        };
        $connection->setQueryGrammar($grammar);
        try {
            (new TransitionBookingStatus)($owner, $booking, BookingStatus::Pending, BookingStatus::Confirmed, null, null, null);
        } finally {
            $connection->setQueryGrammar($original);
        }
        $this->assertGreaterThanOrEqual(2, count($grammar->reads));
        foreach ($grammar->reads as $lock) {
            $this->assertTrue($lock);
        }
    }

    public function test_booking_page_observes_authority_once_for_its_presented_rows(): void
    {
        $staff = User::factory()->admin()->create();
        Booking::factory()->count(25)->create();
        DB::enableQueryLog();
        $response = $this->actingAs($staff)->get('/admin/bookings?scope=all');
        $reads = array_filter(DB::getQueryLog(), fn ($entry) => str_starts_with($entry['query'], 'select * from "users"'));
        $response->assertOk();
        $this->assertLessThan(10, count($reads), 'Presenter repeated authority queries for each booking.');
        foreach ($response->inertiaProps('bookings.data') as $row) {
            $this->assertSame([], $row['allowedTransitions']);
        }
        DB::disableQueryLog();
    }

    public function test_staff_write_uses_web_csrf_and_named_throttle(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.staff.booking-permission.update');
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('verified', $route->gatherMiddleware());
        $this->assertContains('admin', $route->gatherMiddleware());
        $this->assertContains('throttle:room-write', $route->gatherMiddleware());
        $this->assertContains(PreventRequestForgery::class,
            app(Kernel::class)->getMiddlewareGroups()['web']);
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->admin()->create();
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->actingAs($owner)->patch('/admin/staff/'.$staff->id.'/booking-permission', ['allowed' => false])->assertRedirect('/admin/staff');
        }
        $this->patch('/admin/staff/'.$staff->id.'/booking-permission', ['allowed' => false])->assertTooManyRequests();
        $this->assertDatabaseCount('staff_permission_changes', 0);
    }

    public function test_staff_listing_is_deterministic_paged_and_current_owner_is_required(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->admin()->count(22)->create();
        $first = $this->actingAs($owner)->get('/admin/staff?per_page=100000')->assertOk();
        $this->assertCount(20, $first->inertiaProps('staff.data'));
        $this->assertSame($owner->id, $first->inertiaProps('staff.data.0.id'));
        $second = $this->get('/admin/staff?page=2')->assertOk();
        $this->assertSame($staff->slice(19)->pluck('id')->all(), array_column($second->inertiaProps('staff.data'), 'id'));
        User::whereKey($owner->id)->update(['email_verified_at' => null]);
        $this->get('/admin/staff')->assertForbidden();
        $this->patch('/admin/staff/'.$staff[0]->id.'/booking-permission', ['allowed' => true])->assertForbidden();
    }

    public function test_owner_promotion_clears_admin_permission_with_cli_audit_and_existing_owner_is_noop(): void
    {
        $staff = User::factory()->bookingStaff()->create();
        $password = $staff->password;
        $this->artisan('owner:grant', ['email' => $staff->email, '--yes' => true])->assertSuccessful();
        $this->assertTrue($staff->fresh()->isOwner());
        $this->assertFalse($staff->fresh()->can_manage_bookings);
        $this->assertSame($password, $staff->fresh()->password);
        $this->assertDatabaseHas('staff_permission_changes', ['target_user_id' => $staff->id, 'target_role' => 'admin',
            'actor_user_id' => null, 'actor_name' => 'CLI operator (owner:grant)', 'channel' => 'cli', 'was_allowed' => true, 'is_allowed' => false]);
        $this->assertTrue((new BookingPolicy)->updateStatus($staff->fresh(), Booking::factory()->create()));
        $this->artisan('owner:grant', ['email' => $staff->email, '--yes' => true])->assertSuccessful();
        $this->assertDatabaseCount('staff_permission_changes', 1);
        $existingOwner = User::factory()->owner()->create(['can_manage_bookings' => true]);
        $this->artisan('owner:grant', ['email' => $existingOwner->email, '--yes' => true])->assertSuccessful();
        $this->assertTrue($existingOwner->fresh()->can_manage_bookings);
        $this->assertDatabaseCount('staff_permission_changes', 1);
    }

    public function test_partial_audit_does_not_disable_role_changes_without_a_capability_change(): void
    {
        User::factory()->owner()->create();
        $customer = User::factory()->create();
        Schema::table('staff_permission_changes', fn ($table) => $table->dropColumn('channel'));
        $this->artisan('admin:grant', ['email' => $customer->email, '--yes' => true])->assertSuccessful();
        $this->assertFalse($customer->fresh()->can_manage_bookings);
        $this->artisan('admin:revoke', ['email' => $customer->email, '--yes' => true])->assertSuccessful();
        $this->assertSame('customer', $customer->fresh()->role->value);
        $this->artisan('owner:grant', ['email' => $customer->email, '--yes' => true])->assertSuccessful();
        $this->assertTrue($customer->fresh()->isOwner());
        $this->assertFalse($customer->fresh()->can_manage_bookings);
        $this->assertDatabaseCount('staff_permission_changes', 0);
    }

    public function test_same_state_returns_current_locked_target_without_an_unlocked_reread(): void
    {
        $owner = User::factory()->owner()->create();
        $this->assertGreaterThan(0, DB::transactionLevel());
        foreach ([true, false] as $allowed) {
            $staff = User::factory()->admin()->create(['can_manage_bookings' => ! $allowed]);
            User::whereKey($staff->id)->update(['can_manage_bookings' => $allowed]);
            User::all(); // Exercise an already-open caller transaction with earlier reads.
            $connection = DB::connection();
            $original = $connection->getQueryGrammar();
            $grammar = new class($connection) extends SQLiteGrammar
            {
                public int $targetId;

                public array $targetReads = [];

                public function compileSelect(Builder $query)
                {
                    if ($query->from === 'users' && $query->columns === ['*']
                        && array_any($query->wheres, fn (array $where) => in_array($where['column'] ?? null, ['id', 'users.id'], true)
                            && ($where['value'] ?? null) === $this->targetId)) {
                        $this->targetReads[] = $query->lock;
                    }

                    return parent::compileSelect($query);
                }
            };
            $grammar->targetId = $staff->id;
            $connection->setQueryGrammar($grammar);
            try {
                $result = (new SetBookingPermission)($owner, $staff->id, $allowed);
            } finally {
                $connection->setQueryGrammar($original);
            }
            $this->assertSame($staff->id, $result->id);
            $this->assertSame($allowed, $result->can_manage_bookings);
            $this->assertSame(! $allowed, $staff->can_manage_bookings);
            $this->assertDatabaseCount('staff_permission_changes', 0);
            $this->assertNotEmpty($grammar->targetReads);
            foreach ($grammar->targetReads as $lock) {
                $this->assertTrue($lock, 'The action must not replace the current locked target with an unlocked reread.');
            }
        }
    }
}
