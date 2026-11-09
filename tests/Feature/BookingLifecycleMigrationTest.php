<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingLifecycleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const LIFECYCLE_MIGRATION = 'migrations/2026_10_22_010003_expand_booking_lifecycle.php';

    // Production break caught: a fresh install would miss the lifecycle columns and their defaults.
    public function test_fresh_schema_contains_lifecycle_columns_and_defaults(): void
    {
        $bookings = collect(Schema::getColumns('bookings'))->keyBy('name');
        $statusChanges = collect(Schema::getColumns('booking_status_changes'))->keyBy('name');

        foreach ($this->newBookingColumns() as $column) {
            $this->assertTrue($bookings->has($column), "Missing bookings.{$column}");
        }

        foreach (['actor_type', 'channel', 'external_actor_id'] as $column) {
            $this->assertTrue($statusChanges->has($column), "Missing booking_status_changes.{$column}");
        }

        $this->assertSame('1', $this->normalizedDefault($bookings['booking_version']));
        $this->assertFalse($bookings['booking_version']['nullable']);
        $this->assertSame('staff', $this->normalizedDefault($statusChanges['actor_type']));
        $this->assertFalse($statusChanges['actor_type']['nullable']);

        foreach (['payment_preference', 'source_channel', 'created_by_staff_id', 'starts_at', 'treatment_ends_at', 'approved_at', 'payment_deadline_at', 'review_due_at', 'refund_eligible_at', 'refund_reason'] as $column) {
            $this->assertTrue($bookings[$column]['nullable'], "bookings.{$column} must be nullable.");
        }

        $this->assertTrue($bookings['created_by_staff_id']['nullable']);
        $this->assertTrue($statusChanges['actor_name']['nullable'], 'booking_status_changes.actor_name must accept system actors.');
        $this->assertTrue($statusChanges['actor_email']['nullable'], 'booking_status_changes.actor_email must accept system actors.');
        $this->assertTrue($statusChanges['channel']['nullable']);
        $this->assertTrue($statusChanges['external_actor_id']['nullable']);
    }

    // Production break caught: the new lifecycle statuses must persist in all three enum columns on a fresh install.
    public function test_fresh_schema_accepts_lifecycle_statuses_in_every_status_column(): void
    {
        $awaitingPaymentId = DB::table('bookings')->insertGetId($this->legacyBookingRow([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL02',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a12',
            'status' => 'awaiting_payment',
        ]));

        DB::table('booking_status_changes')->insert($this->legacyStatusChangeRow([
            'booking_id' => $awaitingPaymentId,
            'actor_name' => null,
            'actor_email' => null,
            'actor_type' => 'system',
            'from_status' => 'awaiting_payment',
            'to_status' => 'no_show',
        ]));

        DB::table('bookings')->insert($this->legacyBookingRow([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL03',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a13',
            'status' => 'no_show',
        ]));

        $this->assertSame('awaiting_payment', DB::table('bookings')->where('id', $awaitingPaymentId)->value('status'));
        $this->assertSame(1, DB::table('bookings')->where('status', 'no_show')->count());

        $change = DB::table('booking_status_changes')->where('booking_id', $awaitingPaymentId)->sole();

        $this->assertSame('awaiting_payment', $change->from_status);
        $this->assertSame('no_show', $change->to_status);
        $this->assertNull($change->actor_name);
        $this->assertNull($change->actor_email);
        $this->assertSame('system', $change->actor_type);
    }

    // Production break caught: widening the enum must not let unknown statuses slip into the schema.
    public function test_fresh_schema_still_rejects_unknown_statuses(): void
    {
        $rejectedBooking = $this->insertExpectingFailure('bookings', $this->legacyBookingRow([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL04',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a14',
            'status' => 'refunded',
        ]));

        $this->assertInstanceOf(QueryException::class, $rejectedBooking);
        $this->assertDatabaseCount('bookings', 0);

        $bookingId = DB::table('bookings')->insertGetId($this->legacyBookingRow([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL05',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a15',
        ]));

        $rejectedChange = $this->insertExpectingFailure('booking_status_changes', $this->legacyStatusChangeRow([
            'booking_id' => $bookingId,
            'from_status' => 'refunded',
        ]));

        $this->assertInstanceOf(QueryException::class, $rejectedChange);
        $this->assertDatabaseCount('booking_status_changes', 0);
    }

    // Production break caught: an upgrade over a live database must widen the enums without rewriting historical rows or dropping constraints.
    public function test_upgrade_over_existing_schema_preserves_rows_and_widens_status_constraints(): void
    {
        $migration = $this->lifecycleMigration();
        $this->runMigration($migration, 'down');

        $bookingRow = $this->legacyBookingRow();
        $bookingId = (int) DB::table('bookings')->insertGetId($bookingRow);
        $changeRow = $this->legacyStatusChangeRow(['booking_id' => $bookingId]);
        DB::table('booking_status_changes')->insert($changeRow);

        $rejectedBeforeUpgrade = $this->insertExpectingFailure('bookings', $this->legacyBookingRow([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL06',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a16',
            'status' => 'no_show',
        ]));

        $this->assertInstanceOf(QueryException::class, $rejectedBeforeUpgrade, 'The pre-upgrade schema must still reject no_show.');
        $this->assertDatabaseCount('bookings', 1);

        $this->runMigration($migration, 'up');

        $this->assertOriginalColumnsIntact('bookings', ['id' => $bookingId], $bookingRow);
        $this->assertOriginalColumnsIntact('booking_status_changes', ['booking_id' => $bookingId], $changeRow);

        $upgradedBooking = DB::table('bookings')->where('id', $bookingId)->sole();
        $this->assertSame('1', (string) $upgradedBooking->booking_version);

        foreach (['payment_preference', 'source_channel', 'created_by_staff_id', 'starts_at', 'treatment_ends_at', 'approved_at', 'payment_deadline_at', 'review_due_at', 'refund_eligible_at', 'refund_reason'] as $column) {
            $this->assertNull($upgradedBooking->{$column}, "bookings.{$column} must start null for existing rows.");
        }

        $upgradedChange = DB::table('booking_status_changes')->where('booking_id', $bookingId)->sole();
        $this->assertSame('staff', $upgradedChange->actor_type);
        $this->assertNull($upgradedChange->channel);
        $this->assertNull($upgradedChange->external_actor_id);

        $noShowId = (int) DB::table('bookings')->insertGetId($this->legacyBookingRow([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL07',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a17',
            'status' => 'no_show',
        ]));

        DB::table('booking_status_changes')->insert($this->legacyStatusChangeRow([
            'booking_id' => $noShowId,
            'from_status' => 'awaiting_payment',
            'to_status' => 'no_show',
        ]));

        $this->assertSame('no_show', DB::table('bookings')->where('id', $noShowId)->value('status'));

        $rejectedAfterUpgrade = $this->insertExpectingFailure('bookings', $this->legacyBookingRow([
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL08',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a18',
            'status' => 'refunded',
        ]));

        $this->assertInstanceOf(QueryException::class, $rejectedAfterUpgrade, 'The upgraded schema must only accept lifecycle statuses.');

        $rejectedChangeAfterUpgrade = $this->insertExpectingFailure('booking_status_changes', $this->legacyStatusChangeRow([
            'booking_id' => $noShowId,
            'from_status' => 'refunded',
        ]));

        $this->assertInstanceOf(QueryException::class, $rejectedChangeAfterUpgrade, 'The upgraded history table must only accept lifecycle statuses.');

        $indexNames = collect(Schema::getIndexes('bookings'))->pluck('name')->all();
        foreach (['bookings_status_appointment_index', 'bookings_user_appointment_index', 'bookings_user_submission_unique'] as $index) {
            $this->assertContains($index, $indexNames, "Index {$index} must survive the upgrade.");
        }

        $rejectedDeletion = $this->deleteExpectingFailure($bookingId);
        $this->assertInstanceOf(QueryException::class, $rejectedDeletion, 'Deleting a booking with history must remain restricted.');
        $this->assertSame(1, DB::table('bookings')->where('id', $bookingId)->count());
    }

    // Production break caught: a reversible upgrade must not corrupt rows when rolled back and replayed.
    public function test_round_trip_between_down_and_up_preserves_legacy_rows(): void
    {
        $migration = $this->lifecycleMigration();
        $this->runMigration($migration, 'down');

        $bookingRow = $this->legacyBookingRow();
        $bookingId = (int) DB::table('bookings')->insertGetId($bookingRow);
        $changeRow = $this->legacyStatusChangeRow(['booking_id' => $bookingId]);
        DB::table('booking_status_changes')->insert($changeRow);

        $this->runMigration($migration, 'up');
        $this->assertOriginalColumnsIntact('bookings', ['id' => $bookingId], $bookingRow);
        $this->assertOriginalColumnsIntact('booking_status_changes', ['booking_id' => $bookingId], $changeRow);

        $this->runMigration($migration, 'down');
        $this->runMigration($migration, 'up');

        $this->assertOriginalColumnsIntact('bookings', ['id' => $bookingId], $bookingRow);
        $this->assertOriginalColumnsIntact('booking_status_changes', ['booking_id' => $bookingId], $changeRow);
        $this->assertSame('1', (string) DB::table('bookings')->where('id', $bookingId)->value('booking_version'));
        $this->assertSame('staff', DB::table('booking_status_changes')->where('booking_id', $bookingId)->value('actor_type'));
    }

    // Production break caught: a fresh install and an upgraded install must expose the same schema to application code.
    public function test_fresh_and_upgraded_schemas_expose_the_same_columns(): void
    {
        $freshBookings = $this->columnNames('bookings');
        $freshStatusChanges = $this->columnNames('booking_status_changes');

        $migration = $this->lifecycleMigration();
        $this->runMigration($migration, 'down');
        $this->runMigration($migration, 'up');

        $this->assertSame($freshBookings, $this->columnNames('bookings'));
        $this->assertSame($freshStatusChanges, $this->columnNames('booking_status_changes'));
    }

    // Production break caught: adding lifecycle cases must not silently enable transitions before the state writer can stamp refunds.
    public function test_original_transitions_are_unchanged_and_new_statuses_stay_closed(): void
    {
        $this->assertSame([BookingStatus::Confirmed, BookingStatus::Cancelled], BookingStatus::Pending->allowedTransitions());
        $this->assertSame([BookingStatus::Completed, BookingStatus::Cancelled, BookingStatus::NoShow], BookingStatus::Confirmed->allowedTransitions());
        $this->assertSame([], BookingStatus::Completed->allowedTransitions());
        $this->assertSame([], BookingStatus::Cancelled->allowedTransitions());

        $this->assertSame([BookingStatus::Confirmed, BookingStatus::Cancelled], BookingStatus::AwaitingPayment->allowedTransitions());
        $this->assertSame([], BookingStatus::NoShow->allowedTransitions());
    }

    // Production break caught: converting every date attribute to UTC would move a
    // Jakarta-midnight appointment_date to the previous day.
    public function test_only_lifecycle_instants_are_normalised_to_utc(): void
    {
        $jakartaMidnight = CarbonImmutable::parse('2026-09-15 00:00:00', 'Asia/Jakarta');
        $booking = Booking::factory()->create([
            'appointment_date' => $jakartaMidnight,
            'starts_at' => $jakartaMidnight->setTime(10, 30),
        ]);

        $row = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertStringStartsWith('2026-09-15', (string) $row->appointment_date);
        $this->assertSame('2026-09-15 03:30:00', (string) $row->starts_at);
    }

    /**
     * @return list<string>
     */
    private function newBookingColumns(): array
    {
        return [
            'booking_version',
            'payment_preference',
            'source_channel',
            'created_by_staff_id',
            'starts_at',
            'treatment_ends_at',
            'approved_at',
            'payment_deadline_at',
            'review_due_at',
            'refund_eligible_at',
            'refund_reason',
        ];
    }

    /**
     * @return list<string>
     */
    private function columnNames(string $table): array
    {
        $names = array_map(
            fn (array $column): string => $column['name'],
            Schema::getColumns($table),
        );

        sort($names);

        return $names;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function normalizedDefault(array $definition): ?string
    {
        $default = $definition['default'] ?? null;

        return $default === null ? null : trim((string) $default, "'\"");
    }

    private function lifecycleMigration(): object
    {
        $path = database_path(self::LIFECYCLE_MIGRATION);

        $this->assertFileExists($path, 'The lifecycle upgrade migration does not exist yet.');

        return require $path;
    }

    private function runMigration(object $migration, string $method): void
    {
        // RefreshDatabase wraps each test in a transaction, where SQLite ignores the
        // foreign_keys pragma that table rebuilds rely on; defer enforcement instead.
        DB::statement('pragma defer_foreign_keys = on');

        try {
            $migration->{$method}();
        } finally {
            DB::statement('pragma defer_foreign_keys = off');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertExpectingFailure(string $table, array $row): ?QueryException
    {
        try {
            DB::table($table)->insert($row);
        } catch (QueryException $exception) {
            return $exception;
        }

        return null;
    }

    private function deleteExpectingFailure(int $bookingId): ?QueryException
    {
        try {
            DB::table('bookings')->where('id', $bookingId)->delete();
        } catch (QueryException $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * @param  array<string, int>  $key
     * @param  array<string, mixed>  $expected
     */
    private function assertOriginalColumnsIntact(string $table, array $key, array $expected): void
    {
        $row = (array) DB::table($table)->where($key)->sole();

        foreach ($expected as $column => $value) {
            $this->assertArrayHasKey($column, $row, "{$table}.{$column} disappeared during the upgrade.");
            $this->assertSame(
                $value === null ? null : (string) $value,
                $row[$column] === null ? null : (string) $row[$column],
                "{$table}.{$column} changed during the upgrade.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function legacyBookingRow(array $overrides = []): array
    {
        return [
            'user_id' => null,
            'service_id' => null,
            'service_variant_id' => null,
            'service_extension_id' => null,
            'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL01',
            'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a11',
            'request_fingerprint' => hash('sha256', 'booking-lifecycle-migration-test'),
            'status' => 'pending',
            'customer_name' => 'Dewi Lestari',
            'customer_email' => 'dewi@example.test',
            'customer_phone' => '+6281234567890',
            'appointment_date' => '2026-09-15',
            'appointment_time' => '10:30',
            'appointment_timezone' => 'Asia/Jakarta',
            'guests' => 1,
            'notes' => 'Legacy note',
            'package_slug' => 'nezuko',
            'package_name_id' => 'Nezuko',
            'package_name_en' => 'Nezuko',
            'package_is_vip' => 0,
            'treatment_mode' => 'regular',
            'base_choice_code' => 'nezuko-regular-90',
            'base_duration_minutes' => 90,
            'base_price_idr' => 265000,
            'extension_choice_code' => 'nezuko-regular-extra-60',
            'extension_duration_minutes' => 60,
            'extension_price_idr' => 75000,
            'total_duration_minutes' => 150,
            'total_price_idr' => 340000,
            'requested_room_type' => 'couple',
            'pricing_basis' => 'per_person',
            'min_guests' => 1,
            'max_guests' => 2,
            'created_at' => '2026-09-10 08:00:00',
            'updated_at' => '2026-09-11 09:30:00',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function legacyStatusChangeRow(array $overrides = []): array
    {
        return [
            'admin_user_id' => null,
            'actor_name' => 'Legacy Admin',
            'actor_email' => 'admin@example.test',
            'from_status' => 'pending',
            'to_status' => 'confirmed',
            'reason' => 'Legacy approval',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit legacy',
            'created_at' => '2026-09-10 08:05:00',
            ...$overrides,
        ];
    }
}
