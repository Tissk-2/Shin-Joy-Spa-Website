<?php

namespace Tests\Feature\Admin;

use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\User;
use App\Support\Staff\StaffAccessLock;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OwnerAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_grant_normalizes_and_replays_without_password_changes(): void
    {
        $user = User::factory()->create(['name' => 'Exact Owner', 'email' => 'owner@example.com']);
        $password = $user->password;
        $this->artisan('owner:grant', ['email' => ' OWNER@EXAMPLE.COM ', '--yes' => true])
            ->expectsOutputToContain('Exact Owner <owner@example.com>')->assertSuccessful();
        $this->assertTrue($user->refresh()->isOwner());
        $this->assertTrue($user->isAdmin());
        $this->assertSame($password, $user->password);
        $this->artisan('owner:grant', ['email' => $user->email, '--yes' => true])->assertSuccessful();
        $this->assertSame($password, $user->refresh()->password);
    }

    public function test_owner_is_never_demoted_by_admin_commands(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->owner()->create();
        $this->artisan('admin:grant', ['email' => $owner->email, '--yes' => true])->assertSuccessful();
        $this->artisan('admin:revoke', ['email' => $owner->email, '--yes' => true])->assertFailed();
        $this->assertTrue($owner->refresh()->isOwner());
    }

    public function test_verified_owner_allows_final_ordinary_admin_revocation(): void
    {
        User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();
        $this->artisan('admin:revoke', ['email' => $admin->email, '--yes' => true])->assertSuccessful();
        $this->assertSame(UserRole::Customer, $admin->refresh()->role);
    }

    public function test_sole_verified_owner_cannot_delete_or_change_email_even_with_unverified_owner(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->owner()->unverified()->create();
        $this->actingAs($owner)->from('/settings/profile')->delete('/settings/profile', ['password' => 'password'])
            ->assertSessionHasErrors('password');
        $this->actingAs($owner)->patch('/settings/profile', ['name' => $owner->name, 'email' => 'new@example.com', 'current_password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertTrue($owner->refresh()->hasVerifiedEmail());
    }

    public function test_last_unverified_owner_cannot_delete_but_can_correct_email(): void
    {
        $owner = User::factory()->owner()->unverified()->create();
        $this->actingAs($owner)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->actingAs($owner)->patch('/settings/profile', ['name' => $owner->name, 'email' => 'fixed@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertSame('fixed@example.com', $owner->refresh()->email);
    }

    public function test_partial_staff_schema_and_missing_singleton_fail_closed(): void
    {
        DB::table('staff_access_guards')->delete();
        try {
            (new StaffAccessLock)->run(fn () => $this->fail('Missing guard allowed work.'));
            $this->fail('Expected failure.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('guard', $exception->getMessage());
        }
        Schema::drop('staff_access_guards');
        $this->expectException(\LogicException::class);
        (new StaffAccessLock)->run(fn () => null);
    }

    public function test_guard_nesting_writes_once_and_rollback_restores_revision(): void
    {
        $revision = DB::table('staff_access_guards')->value('revision');
        try {
            (new StaffAccessLock)->run(function (): void {
                (new StaffAccessLock)->run(fn () => $this->assertSame(1, DB::table('staff_access_guards')->value('revision')));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertSame($revision, DB::table('staff_access_guards')->value('revision'));
    }

    public function test_profile_delete_checks_the_fresh_password_under_the_guard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        User::whereKey($user->id)->update(['password' => Hash::make('replacement-password')]);
        $this->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertNotNull($user->fresh());
    }

    public function test_owner_command_decline_missing_and_unverified_targets(): void
    {
        $target = User::factory()->create(['name' => 'Review Owner']);
        $this->artisan('owner:grant', ['email' => $target->email])
            ->expectsOutputToContain('Review Owner <'.$target->email.'>')
            ->expectsConfirmation('Grant owner access to this account?', 'no')->assertSuccessful();
        $this->assertSame(UserRole::Customer, $target->refresh()->role);
        $this->artisan('owner:grant', ['email' => 'missing@example.com', '--yes' => true])->assertFailed();
        $target->forceFill(['email_verified_at' => null])->save();
        $this->artisan('owner:grant', ['email' => $target->email, '--yes' => true])->assertFailed();
    }

    public function test_grants_recheck_id_email_and_verification_after_confirmation(): void
    {
        foreach (['owner:grant', 'admin:grant'] as $command) {
            foreach (['email', 'verification', 'retarget'] as $change) {
                $target = User::factory()->create();
                $email = $target->email;
                $this->changeTargetBeforeGuard($target, $change);
                $this->artisan($command, ['email' => $email])
                    ->expectsConfirmation($command === 'owner:grant' ? 'Grant owner access to this account?' : 'Grant administrator access to this account?', 'yes')
                    ->expectsOutputToContain('The target account changed after confirmation.')->assertFailed();
                $this->assertSame(UserRole::Customer, $target->refresh()->role);
                $this->assertSame(0, User::whereIn('role', ['owner', 'admin'])->count());
            }
        }
    }

    public function test_owner_and_capability_are_protected_from_mass_assignment_and_profile_payloads(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->isFillable('role'));
        $this->assertFalse($user->isFillable('can_manage_bookings'));
        $this->actingAs($user)->patch('/settings/profile', ['name' => $user->name, 'email' => $user->email, 'role' => 'owner', 'can_manage_bookings' => true])->assertSessionHasNoErrors();
        $this->assertSame(UserRole::Customer, $user->refresh()->role);
        $this->assertFalse($user->can_manage_bookings);
    }

    public function test_owner_admin_and_customer_existing_routes_and_explicit_capability(): void
    {
        foreach ([UserRole::Owner, UserRole::Admin, UserRole::Customer] as $role) {
            $user = User::factory()->create(['role' => $role]);
            foreach (['/admin', '/admin/bookings', '/admin/content'] as $url) {
                $response = $this->actingAs($user)->get($url);
                $role === UserRole::Customer ? $response->assertForbidden() : $response->assertOk();
            }
            $this->assertFalse($user->refresh()->can_manage_bookings);
            if ($role === UserRole::Owner) {
                $booking = Booking::factory()->create();
                (new TransitionBookingStatus)($user, $booking, BookingStatus::Pending, BookingStatus::Confirmed, null, null, null);
                $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
            }
        }
    }

    public function test_owner_email_and_delete_protections_use_fresh_role_and_are_localized(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        User::whereKey($user->id)->update(['role' => 'owner']);
        foreach (['en', 'id'] as $locale) {
            $this->withSession(['locale' => $locale])->patch('/settings/profile', ['name' => $user->name, 'email' => 'new@example.com', 'current_password' => 'password'])
                ->assertSessionHasErrors(['email' => trans('auth.final_owner_email', [], $locale)]);
            $this->withSession(['locale' => $locale])->delete('/settings/profile', ['password' => 'password'])
                ->assertSessionHasErrors(['password' => trans('auth.final_owner', [], $locale)]);
            $this->assertAuthenticated();
        }
    }

    public function test_another_verified_owner_allows_owner_email_change_and_deletion(): void
    {
        $first = User::factory()->owner()->create();
        $second = User::factory()->owner()->create();
        $this->actingAs($first)->patch('/settings/profile', ['name' => $first->name, 'email' => 'changed@example.com', 'current_password' => 'password'])->assertSessionHasNoErrors();
        $this->assertNull($first->refresh()->email_verified_at);
        $this->actingAs($first)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasNoErrors()->assertRedirect('/');
        $this->assertGuest();
        $this->assertNull($first->fresh());
        $this->assertNotNull($second->fresh());
    }

    public function test_final_admin_fallback_requires_a_verified_owner(): void
    {
        $owner = User::factory()->owner()->unverified()->create();
        $admin = User::factory()->admin()->create();
        $this->artisan('admin:revoke', ['email' => $admin->email, '--yes' => true])->assertFailed();
        $this->actingAs($admin)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        $owner->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($admin)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasNoErrors();
        $this->assertNull($admin->fresh());
    }

    public function test_staff_write_precedes_fresh_actor_schedule_and_booking_reads(): void
    {
        $actor = User::factory()->owner()->create();
        $booking = Booking::factory()->create();
        DB::enableQueryLog();
        (new TransitionBookingStatus)($actor, $booking, BookingStatus::Pending, BookingStatus::Confirmed, null, null, null);
        $queries = array_column(DB::getQueryLog(), 'query');
        $staff = array_find_key($queries, fn ($query) => str_starts_with($query, 'update "staff_access_guards"'));
        $actorRead = array_find_key($queries, fn ($query) => str_starts_with($query, 'select * from "users"'));
        $schedule = array_find_key($queries, fn ($query) => str_starts_with($query, 'update "schedule_guards"'));
        $bookingRead = array_find_key($queries, fn ($query) => str_starts_with($query, 'select * from "bookings"'));
        foreach ([$staff, $actorRead, $schedule, $bookingRead] as $index) {
            $this->assertNotNull($index);
        }
        $this->assertLessThan($actorRead, $staff);
        $this->assertLessThan($schedule, $actorRead);
        $this->assertLessThan($bookingRead, $schedule);
    }

    private function changeTargetBeforeGuard(User $target, string $change): void
    {
        $armed = true;
        DB::connection()->beforeExecuting(function ($query) use ($target, $change, &$armed): void {
            if ($armed && str_starts_with($query, 'update "staff_access_guards"')) {
                $armed = false;
                if ($change === 'verification') {
                    User::whereKey($target->id)->update(['email_verified_at' => null]);
                } else {
                    User::whereKey($target->id)->update(['email' => 'changed-'.$target->id.'@example.com']);
                    if ($change === 'retarget') {
                        User::factory()->create(['email' => $target->email]);
                    }
                }
            }
        });
    }

    public function test_authority_set_uses_current_locking_reads_inside_existing_outer_transaction(): void
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();
        $this->assertGreaterThan(0, DB::transactionLevel());
        User::all(); // Establish a caller read before the staff operation.
        $connection = DB::connection();
        $original = $connection->getQueryGrammar();
        $grammar = new class($connection) extends SQLiteGrammar
        {
            public array $authorityReads = [];

            public function compileSelect(Builder $query)
            {
                if ($query->from === 'users' && $query->columns === ['id', 'role', 'email_verified_at']) {
                    $this->authorityReads[] = ['lock' => $query->lock, 'orders' => $query->orders];
                }

                return parent::compileSelect($query);
            }
        };
        $connection->setQueryGrammar($grammar);
        try {
            $this->artisan('admin:revoke', ['email' => $admin->email, '--yes' => true])->assertSuccessful();
            $this->actingAs($owner)->patch('/settings/profile', ['name' => $owner->name, 'email' => 'new@example.com', 'current_password' => 'password'])->assertSessionHasErrors('email');
            $this->actingAs($owner)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        } finally {
            $connection->setQueryGrammar($original);
        }
        $this->assertCount(3, $grammar->authorityReads);
        foreach ($grammar->authorityReads as $read) {
            $this->assertTrue($read['lock']);
            $this->assertSame([['column' => 'id', 'direction' => 'asc']], $read['orders']);
        }
    }

    public function test_fresh_schema_has_singleton_and_rejects_invalid_roles(): void
    {
        $this->assertSame(1, DB::table('staff_access_guards')->count());
        $this->assertSame(0, DB::table('staff_access_guards')->where('id', 1)->value('revision'));
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        User::whereKey($user->id)->update(['role' => 'superuser']);
    }
}
