<?php

namespace Tests\Feature\Admin;

use App\Actions\SaveBookingSettings;
use App\Models\BookingSettings;
use App\Models\RoomReservation;
use App\Models\User;
use App\Queries\BookingSettingsQuery;
use App\Support\Scheduling\BookingWindow;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Scheduling\RoomAllocationTest;
use Tests\TestCase;

class BookingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public static function input(array $overrides = []): array
    {
        return [...['version' => 0, 'review_intervals' => [
            ['weekday' => 2, 'start' => '09:00', 'end' => '17:00'],
            ['weekday' => 1, 'start' => '13:00', 'end' => '17:00'],
            ['weekday' => 1, 'start' => '09:00', 'end' => '13:00'],
        ], 'review_target_minutes' => 60, 'booking_horizon_days' => 30,
            'minimum_lead_minutes' => 0, 'minimum_checkout_minutes' => 15], ...$overrides];
    }

    public function test_unconfigured_owner_create_reload_update_and_canonical_multiple_intervals(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->get('/admin/settings/booking')->assertOk()->assertInertia(fn ($p) => $p
            ->component('admin/settings/Booking')->where('settings', null)->where('state', 'unconfigured')->where('version', 0));
        $input = self::input();
        $original = $input;
        $this->patch('/admin/settings/booking', $input)->assertRedirect('/admin/settings/booking')->assertSessionHas('status');
        $this->assertSame($original, $input);
        $settings = BookingSettings::findOrFail(1);
        $this->assertSame(1, $settings->version);
        $this->assertSame([
            ['weekday' => 1, 'start_minute' => 540, 'end_minute' => 780],
            ['weekday' => 1, 'start_minute' => 780, 'end_minute' => 1020],
            ['weekday' => 2, 'start_minute' => 540, 'end_minute' => 1020],
        ], $settings->review_intervals);
        $this->get('/admin/settings/booking')->assertOk()->assertInertia(fn ($p) => $p->where('state', 'configured')->where('settings.booking_horizon_days', 30)->where('version', 1));
        $this->patch('/admin/settings/booking', self::input(['version' => '1', 'booking_horizon_days' => '365']))->assertRedirect();
        $this->assertDatabaseHas('booking_settings', ['id' => 1, 'version' => 2, 'booking_horizon_days' => 365]);
        $this->assertFalse(config('booking.scheduling_enabled'));
        $this->assertDatabaseCount('rooms', 0);
        $this->assertDatabaseCount('room_reservations', 0);
    }

    public function test_http_requires_current_verified_owner_and_named_limiter(): void
    {
        $this->get('/admin/settings/booking')->assertRedirect('/login');
        $this->patch('/admin/settings/booking', self::input())->assertRedirect('/login');
        foreach ([User::factory()->create(), User::factory()->admin()->create(), User::factory()->admin()->create(['can_manage_bookings' => true])] as $actor) {
            $this->actingAs($actor)->get('/admin/settings/booking')->assertForbidden();
            $this->patch('/admin/settings/booking', self::input())->assertForbidden();
        }
        $this->actingAs(User::factory()->owner()->unverified()->create())->get('/admin/settings/booking')->assertRedirect(route('verification.notice', absolute: false));
        $this->patch('/admin/settings/booking', self::input())->assertRedirect(route('verification.notice', absolute: false));
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);
        DB::table('users')->where('id', $owner->id)->update(['role' => 'admin']);
        $this->get('/admin/settings/booking')->assertForbidden();
        $this->patch('/admin/settings/booking', self::input())->assertForbidden();
        $route = app('router')->getRoutes()->getByName('admin.settings.booking.update');
        $this->assertContains('throttle:room-write', $route->gatherMiddleware());
        $this->assertDatabaseCount('booking_settings', 0);
    }

    public function test_action_rejects_stale_forged_deleted_unverified_unsaved_and_foreign_actors(): void
    {
        foreach (['admin', 'capable', 'customer', 'demoted', 'forged', 'deleted', 'unverified', 'unsaved', 'foreign'] as $case) {
            $actor = User::factory()->owner()->create();
            match ($case) {
                'admin', 'demoted' => DB::table('users')->where('id', $actor->id)->update(['role' => 'admin']),
                'capable' => DB::table('users')->where('id', $actor->id)->update(['role' => 'admin', 'can_manage_bookings' => true]),
                'customer', 'forged' => DB::table('users')->where('id', $actor->id)->update(['role' => 'customer']),
                'deleted' => DB::table('users')->where('id', $actor->id)->delete(),
                'unverified' => DB::table('users')->where('id', $actor->id)->update(['email_verified_at' => null]),
                'unsaved' => $actor = User::factory()->owner()->make(),
                'foreign' => $actor->setConnection('mysql'),
            };
            try {
                (new SaveBookingSettings)($actor, self::input());
                $this->fail('Unauthorized '.$case);
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('booking_settings', 0);
            }
        }
    }

    public function test_strict_boundary_rejects_missing_unknown_scalar_nested_and_out_of_bounds_input_without_partial_save(): void
    {
        $owner = User::factory()->owner()->create();
        $invalids = [['unknown' => 1], ['version' => true], ['version' => '01'], ['version' => 4294967296],
            ['booking_horizon_days' => 0], ['booking_horizon_days' => 366], ['booking_horizon_days' => 1.0],
            ['minimum_lead_minutes' => -1], ['minimum_lead_minutes' => 525601], ['minimum_lead_minutes' => '1e2'],
            ['review_target_minutes' => 0], ['review_target_minutes' => 10081], ['minimum_checkout_minutes' => 0], ['minimum_checkout_minutes' => 61],
            ['review_intervals' => null], ['review_intervals' => '09:00'], ['review_intervals' => []], ['review_intervals' => [true]],
            ['review_intervals' => ['a' => ['weekday' => 1, 'start' => '09:00', 'end' => '10:00']]],
            ['review_intervals' => array_fill(0, 169, ['weekday' => 1, 'start' => '09:00', 'end' => '10:00'])]];
        foreach ([['weekday' => 0], ['weekday' => 8], ['weekday' => true], ['weekday' => 1.0], ['weekday' => '01'],
            ['start' => '9:00'], ['start' => '24:00'], ['start' => []], ['end' => '24:01'], ['end' => '08:00'], ['end' => '09:00'],
            ['extra' => 1], ['start_minute' => 540]] as $row) {
            $invalids[] = ['review_intervals' => [[...['weekday' => 1, 'start' => '09:00', 'end' => '17:00'], ...$row]]];
        }
        $invalids[] = ['review_intervals' => [['weekday' => 1, 'start' => '09:00', 'end' => '13:00'], ['weekday' => 1, 'start' => '12:59', 'end' => '17:00']]];
        foreach ($invalids as $invalid) {
            try {
                (new SaveBookingSettings)($owner, self::input($invalid));
                $this->fail('Invalid accepted '.json_encode($invalid));
            } catch (ValidationException) {
                $this->assertDatabaseCount('booking_settings', 0);
            }
        }
        foreach (array_keys(self::input()) as $field) {
            $input = self::input();
            unset($input[$field]);
            try {
                (new SaveBookingSettings)($owner, $input);
                $this->fail('Missing '.$field);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $saved = (new SaveBookingSettings)($owner, self::input(['review_intervals' => [['weekday' => '7', 'start' => '00:00', 'end' => '24:00']]]));
        $this->assertSame(1440, $saved->review_intervals[0]['end_minute']);
    }

    public function test_version_stale_exhaustion_and_corrupt_row_correction_preserve_explicit_version(): void
    {
        $owner = User::factory()->owner()->create();
        (new SaveBookingSettings)($owner, self::input());
        foreach ([0, 2] as $version) {
            try {
                (new SaveBookingSettings)($owner, self::input(['version' => $version]));
                $this->fail('Stale accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('version', $e->errors());
                $this->assertDatabaseHas('booking_settings', ['id' => 1, 'version' => 1]);
            }
        }
        DB::table('booking_settings')->where('id', 1)->update(['review_intervals' => '[]']);
        $this->expectCorrupt($owner);
        (new SaveBookingSettings)($owner, self::input(['version' => 1]));
        $this->assertSame('configured', (new BookingSettingsQuery)->get($owner)['state']);
        DB::table('booking_settings')->where('id', 1)->update(['version' => 4294967295]);
        try {
            (new SaveBookingSettings)($owner, self::input(['version' => 4294967295]));
            $this->fail('Exhaustion accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('version', $e->errors());
        }
    }

    private function expectCorrupt(User $owner): void
    {
        $result = (new BookingSettingsQuery)->get($owner);
        $this->assertSame('invalid', $result['state']);
        $this->assertNull($result['settings']);
        $this->assertSame(1, $result['version']);
    }

    public function test_missing_partial_schema_and_guard_singletons_are_unavailable_without_room_schema_dependency(): void
    {
        $owner = User::factory()->owner()->create();
        foreach (['booking_settings', 'staff_access_guards', 'schedule_guards'] as $table) {
            Schema::rename($table, $table.'_missing');
            try {
                $this->actingAs($owner)->get('/admin/settings/booking')->assertOk()->assertInertia(fn ($p) => $p->where('state', 'unavailable')->where('settings', null));
                $this->patch('/admin/settings/booking', self::input())->assertSessionHasErrors('settings');
            } finally {
                Schema::rename($table.'_missing', $table);
            }
        }
        foreach (['booking_settings' => 'review_target_minutes', 'staff_access_guards' => 'revision', 'schedule_guards' => 'revision', 'users' => 'can_manage_bookings'] as $table => $column) {
            Schema::table($table, fn ($t) => $t->renameColumn($column, 'b3h_missing'));
            try {
                $this->assertSame('unavailable', (new BookingSettingsQuery)->get($owner)['state']);
                $this->patch('/admin/settings/booking', self::input())->assertSessionHasErrors('settings');
            } finally {
                Schema::table($table, fn ($t) => $t->renameColumn('b3h_missing', $column));
            }
        }
        foreach (['staff_access_guards', 'schedule_guards'] as $guard) {
            $row = (array) DB::table($guard)->first();
            DB::table($guard)->delete();
            $this->assertSame('unavailable', (new BookingSettingsQuery)->get($owner)['state']);
            $this->patch('/admin/settings/booking', self::input())->assertSessionHasErrors('settings');
            DB::table($guard)->insert($row);
        }
        Schema::rename('room_blocks', 'room_blocks_missing');
        try {
            (new SaveBookingSettings)($owner, self::input());
            $this->assertSame('configured', (new BookingSettingsQuery)->get($owner)['state']);
        } finally {
            Schema::rename('room_blocks_missing', 'room_blocks');
        }
    }

    public function test_database_failure_and_outer_transaction_rollback_do_not_commit_settings_or_guards(): void
    {
        $owner = User::factory()->owner()->create();
        $before = DB::table('staff_access_guards')->value('revision');
        DB::beginTransaction();
        try {
            (new SaveBookingSettings)($owner, self::input());
            $this->assertSame(2, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('booking_settings', 0);
        $this->assertSame($before, DB::table('staff_access_guards')->value('revision'));
        DB::statement("CREATE TRIGGER fail_booking_settings BEFORE INSERT ON booking_settings BEGIN SELECT RAISE(ABORT, 'settings write failed'); END");
        try {
            (new SaveBookingSettings)($owner, self::input());
            $this->fail('Database failure swallowed');
        } catch (QueryException $e) {
            $this->assertStringContainsString('settings write failed', $e->getMessage());
            $this->assertDatabaseCount('booking_settings', 0);
            $this->assertSame($before, DB::table('staff_access_guards')->value('revision'));
        }
    }

    public function test_provider_minimum_is_explicit_canonical_and_only_enforced_when_enabled(): void
    {
        $this->assertFalse(config('booking.online_payments_enabled'));
        $this->assertNull(config('booking.minimum_provider_checkout_minutes'));
        $owner = User::factory()->owner()->create();
        config(['booking.online_payments_enabled' => true]);
        foreach ([null, '', true, 1.0, '01', 0, 61, 16] as $minimum) {
            config(['booking.minimum_provider_checkout_minutes' => $minimum]);
            try {
                (new SaveBookingSettings)($owner, self::input());
                $this->fail('Invalid provider config accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('minimum_checkout_minutes', $e->errors());
                $this->assertDatabaseCount('booking_settings', 0);
            }
        }
        config(['booking.minimum_provider_checkout_minutes' => '15']);
        (new SaveBookingSettings)($owner, self::input());
        $this->assertDatabaseHas('booking_settings', ['version' => 1]);
    }

    public function test_limiter_precedes_guards_and_transport_only_fields_are_stripped(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);
        foreach (range(0, 19) as $version) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($version + 1)])
                ->patch('/admin/settings/booking', self::input(['version' => $version, '_token' => 'transport', '_method' => 'PATCH']))->assertRedirect();
        }
        $revisions = [DB::table('staff_access_guards')->value('revision'), DB::table('schedule_guards')->value('revision')];
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])->patch('/admin/settings/booking', self::input(['version' => 20]))->assertTooManyRequests();
        $this->assertSame($revisions, [DB::table('staff_access_guards')->value('revision'), DB::table('schedule_guards')->value('revision')]);
        $this->assertDatabaseHas('booking_settings', ['id' => 1, 'version' => 20]);
    }

    public function test_owner_navigation_ability_uses_current_verified_role(): void
    {
        foreach (['owner', 'admin', 'customer', 'unverified', 'demoted'] as $case) {
            $actor = User::factory()->owner()->create();
            if (in_array($case, ['admin', 'customer', 'demoted'], true)) {
                DB::table('users')->where('id', $actor->id)->update(['role' => $case === 'customer' ? 'customer' : 'admin']);
            }
            if ($case === 'unverified') {
                DB::table('users')->where('id', $actor->id)->update(['email_verified_at' => null]);
            }
            $this->actingAs($actor)->get('/')->assertOk()->assertInertia(fn ($p) => $p->where('auth.isOwner', $case === 'owner'));
        }
    }

    public function test_settings_save_preserves_existing_quotes_reservations_and_disabled_scheduling(): void
    {
        [$booking, $room] = (new RoomAllocationTest('fixture'))->fixture();
        $reservation = RoomReservation::create(['room_id' => $room->id, 'booking_id' => $booking->id,
            'starts_at' => '2026-10-25 03:00:00', 'treatment_ends_at' => '2026-10-25 04:30:00',
            'ends_at' => '2026-10-25 04:45:00', 'cleaning_minutes' => 15]);
        $facts = [(array) DB::table('bookings')->where('id', $booking->id)->first(),
            (array) DB::table('room_reservations')->where('id', $reservation->id)->first()];
        (new SaveBookingSettings)(User::factory()->owner()->create(), self::input());
        $this->assertSame($facts, [(array) DB::table('bookings')->where('id', $booking->id)->first(),
            (array) DB::table('room_reservations')->where('id', $reservation->id)->first()]);
        $this->assertFalse(config('booking.scheduling_enabled'));
    }

    public function test_persisted_json_object_key_order_does_not_change_settings_read_or_review_deadline(): void
    {
        $owner = User::factory()->owner()->create();
        (new SaveBookingSettings)($owner, self::input());
        $json = '[{"end_minute":780,"start_minute":540,"weekday":1},{"weekday":1,"end_minute":1020,"start_minute":780},{"start_minute":540,"weekday":2,"end_minute":1020}]';
        DB::table('booking_settings')->where('id', 1)->update(['review_intervals' => $json]);
        $settings = BookingSettings::findOrFail(1);
        $original = $settings->getAttributes();
        $this->assertSame(['end_minute', 'start_minute', 'weekday'], array_keys($settings->review_intervals[0]));
        $this->actingAs($owner)->get('/admin/settings/booking')->assertOk()->assertInertia(fn ($p) => $p
            ->where('state', 'configured')->where('version', 1)->where('settings.review_intervals.0.start', '09:00'));
        $deadline = BookingWindow::reviewDeadline(CarbonImmutable::parse('2026-10-26 16:30 Asia/Jakarta'), $settings);
        $this->assertTrue($deadline->equalTo(CarbonImmutable::parse('2026-10-27 09:30 Asia/Jakarta')));
        $this->assertSame($original, $settings->getAttributes());
        foreach ([
            '[{"end_minute":1020,"start_minute":540,"weekday":2},{"end_minute":1020,"start_minute":540,"weekday":1}]',
            '[{"end_minute":1020,"start_minute":780,"weekday":1},{"end_minute":780,"start_minute":540,"weekday":1}]',
            '[{"end_minute":1020,"start_minute":540,"weekday":"1"}]',
            '[{"end_minute":1020,"start_minute":540,"weekday":1.0}]',
            '[{"end_minute":1020,"start_minute":true,"weekday":1}]',
        ] as $invalid) {
            DB::table('booking_settings')->where('id', 1)->update(['review_intervals' => $invalid]);
            $this->expectCorrupt($owner);
        }
    }
}
