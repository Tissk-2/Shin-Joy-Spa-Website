<?php

namespace Tests\Feature\Admin;

use App\Actions\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\User;
use App\Support\Staff\StaffAccessLock;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class StaffLegacyCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private function legacySchema(): void
    {
        Schema::drop('staff_access_guards');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('can_manage_bookings'));
    }

    public function test_default_off_legacy_admin_profile_and_booking_operations_keep_working(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        $booking = Booking::factory()->create();
        $this->legacySchema();
        $this->artisan('admin:grant', ['email' => $target->email, '--yes' => true])->assertSuccessful();
        $this->actingAs($target)->patch('/settings/profile', ['name' => 'Legacy Name', 'email' => $target->email])->assertSessionHasNoErrors();
        $this->assertSame('Legacy Name', $target->refresh()->name);
        (new TransitionBookingStatus)($admin, $booking, BookingStatus::Pending, BookingStatus::Confirmed, null, null, null);
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->artisan('admin:revoke', ['email' => $target->email, '--yes' => true])->assertSuccessful();
        $this->assertSame(UserRole::Customer, $target->refresh()->role);
        $this->actingAs($admin)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->actingAs($target)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasNoErrors();
        $this->assertNull($target->fresh());
    }

    public function test_legacy_sqlite_serializes_before_reading_any_authority(): void
    {
        $admin = User::factory()->admin()->create();
        $this->legacySchema();
        DB::enableQueryLog();
        $this->artisan('admin:revoke', ['email' => $admin->email, '--yes' => true])->assertFailed();
        $queries = array_column(DB::getQueryLog(), 'query');
        $write = array_find_key($queries, fn ($query) => str_starts_with($query, 'update "users" set "id" = id'));
        $fresh = array_find_key($queries, fn ($query) => str_starts_with($query, 'select * from "users" where "users"."id"'));
        $this->assertNotNull($write);
        $this->assertNotNull($fresh);
        $this->assertLessThan($fresh, $write);
    }

    public function test_owner_grant_requires_full_schema_even_when_legacy_admin_grant_is_allowed(): void
    {
        $target = User::factory()->create();
        $this->legacySchema();
        $this->artisan('owner:grant', ['email' => $target->email, '--yes' => true])
            ->expectsOutputToContain('Staff authority schema is incomplete.')->assertFailed();
        $this->assertSame(UserRole::Customer, $target->refresh()->role);
    }

    public function test_legacy_schema_with_owner_rows_fails_closed(): void
    {
        User::factory()->owner()->create();
        $this->legacySchema();
        $this->expectException(LogicException::class);
        (new StaffAccessLock)->run(fn () => $this->fail('Legacy owner operation accepted.'));
    }

    public function test_scheduling_enabled_without_staff_schema_fails_closed(): void
    {
        $this->legacySchema();
        config(['booking.scheduling_enabled' => true]);
        $this->expectException(LogicException::class);
        (new StaffAccessLock)->run(fn () => $this->fail('Missing staff schema accepted.'));
    }

    public function test_guard_only_partial_schema_fails_closed(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('can_manage_bookings'));
        $this->expectException(LogicException::class);
        (new StaffAccessLock)->run(fn () => $this->fail('Partial schema accepted.'));
    }

    public function test_arbitrary_guard_query_errors_are_never_legacy_fallback(): void
    {
        Schema::table('staff_access_guards', fn (Blueprint $table) => $table->renameColumn('revision', 'broken_revision'));
        $this->expectException(QueryException::class);
        (new StaffAccessLock)->run(fn () => $this->fail('Invalid guard accepted.'));
    }
}
