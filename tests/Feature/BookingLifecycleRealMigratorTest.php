<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

// Production break caught: the lifecycle migration rebuilds the parent bookings table.
// RefreshDatabase hides how `php artisan migrate` really runs it (outside a transaction,
// with SQLite foreign keys on), so this runs the real migrator on a disposable file.
class BookingLifecycleRealMigratorTest extends TestCase
{
    public function test_the_real_migrator_upgrades_existing_rows_without_losing_or_orphaning_data(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'booking-lifecycle-'.bin2hex(random_bytes(12));
        mkdir($directory);
        $database = $directory.DIRECTORY_SEPARATOR.'database.sqlite';
        touch($database);

        try {
            $this->assertSame(':memory:', config('database.connections.sqlite.database'));
            DB::disconnect('sqlite');
            config(['database.connections.sqlite.database' => realpath($database)]);
            DB::purge('sqlite');
            if (realpath(dirname((string) config('database.connections.sqlite.database'))) !== realpath($directory)) {
                throw new RuntimeException('Refusing to migrate outside the disposable directory.');
            }

            // One batch per migration, then roll back to just before 2026_10_22_010003.
            Artisan::call('migrate', ['--force' => true, '--step' => true]);
            // Count the migrations from 010003 onward, so later tasks' migrations don't break this rollback.
            $steps = DB::table('migrations')->where('migration', '>=', '2026_10_22_010003_expand_booking_lifecycle')->count();
            $this->assertGreaterThanOrEqual(7, $steps);
            Artisan::call('migrate:rollback', ['--force' => true, '--step' => $steps]);
            $this->assertFalse(DB::table('migrations')->where('migration', '2026_10_22_010003_expand_booking_lifecycle')->exists());

            $now = '2026-09-10 08:00:00';
            $bookingId = DB::table('bookings')->insertGetId([
                'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL77',
                'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a77',
                'request_fingerprint' => hash('sha256', 'real-migrator'),
                'status' => 'confirmed',
                'customer_name' => 'Dewi Lestari',
                'customer_email' => 'dewi@example.test',
                'customer_phone' => '+6281234567890',
                'appointment_date' => '2026-09-15',
                'appointment_time' => '10:30',
                'appointment_timezone' => 'Asia/Jakarta',
                'guests' => 1,
                'package_slug' => 'nezuko',
                'package_name_id' => 'Nezuko',
                'package_name_en' => 'Nezuko',
                'package_is_vip' => 0,
                'treatment_mode' => 'regular',
                'base_choice_code' => 'nezuko-regular-90',
                'base_duration_minutes' => 90,
                'base_price_idr' => 265000,
                'total_duration_minutes' => 90,
                'total_price_idr' => 265000,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('booking_status_changes')->insert([
                'booking_id' => $bookingId,
                'actor_name' => 'Legacy Admin',
                'actor_email' => 'admin@example.test',
                'from_status' => 'pending',
                'to_status' => 'confirmed',
                'created_at' => $now,
            ]);
            $invalidStartId = DB::table('bookings')->insertGetId([
                'reference' => 'SJ-01K4DR9M7JQ2ABCD3EFGHJKL78',
                'submission_key' => '018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a78',
                'request_fingerprint' => hash('sha256', 'real-migrator-invalid-start'),
                'status' => 'confirmed',
                'customer_name' => 'Sari Wulandari',
                'customer_email' => 'sari@example.test',
                'customer_phone' => '+6281234567891',
                'appointment_date' => '2026-09-16',
                'appointment_time' => '25:99',
                'appointment_timezone' => 'Asia/Jakarta',
                'guests' => 1,
                'package_slug' => 'nezuko',
                'package_name_id' => 'Nezuko',
                'package_name_en' => 'Nezuko',
                'package_is_vip' => 0,
                'treatment_mode' => 'regular',
                'base_choice_code' => 'nezuko-regular-90',
                'base_duration_minutes' => 90,
                'base_price_idr' => 265000,
                'total_duration_minutes' => 90,
                'total_price_idr' => 265000,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $roomId = DB::table('rooms')->insertGetId([
                'code' => 'R1', 'name' => 'Room 1', 'capacity' => 2, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('room_reservations')->insert([
                'booking_id' => $bookingId,
                'room_id' => $roomId,
                'starts_at' => '2026-09-15 03:30:00',
                'treatment_ends_at' => '2026-09-15 05:00:00',
                'ends_at' => '2026-09-15 05:15:00',
                'cleaning_minutes' => 15,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Artisan::call('migrate', ['--force' => true]);

            $this->assertTrue(DB::table('migrations')->where('migration', '2026_10_22_010003_expand_booking_lifecycle')->exists());
            $this->assertSame(2, DB::table('bookings')->count());
            $this->assertSame(1, DB::table('booking_status_changes')->count());
            $this->assertSame(1, DB::table('room_reservations')->count());
            $this->assertSame('confirmed', DB::table('bookings')->where('id', $bookingId)->value('status'));
            $this->assertSame(1, (int) DB::table('bookings')->where('id', $bookingId)->value('booking_version'));
            $this->assertSame('2026-09-15 03:30:00', DB::table('bookings')->where('id', $bookingId)->value('starts_at'));
            $this->assertSame('2026-09-15 05:00:00', DB::table('bookings')->where('id', $bookingId)->value('treatment_ends_at'));
            $this->assertNull(DB::table('bookings')->where('id', $invalidStartId)->value('starts_at'));
            $this->assertNull(DB::table('bookings')->where('id', $invalidStartId)->value('treatment_ends_at'));
            $this->assertSame('staff', DB::table('booking_status_changes')->value('actor_type'));
            $this->assertSame([], DB::select('pragma foreign_key_check'));
            $this->assertSame(1, (int) DB::scalar('pragma foreign_keys'));

            DB::table('bookings')->where('id', $bookingId)->update(['status' => 'no_show']);
            $rejected = null;
            try {
                DB::table('bookings')->where('id', $bookingId)->update(['status' => 'refunded']);
            } catch (QueryException $exception) {
                $rejected = $exception;
            }
            $this->assertInstanceOf(QueryException::class, $rejected);

            // The status-history CHECKs must also survive 010003 and the later migrations.
            foreach (['from_status', 'to_status'] as $column) {
                $historyRejected = null;
                try {
                    DB::table('booking_status_changes')->update([$column => 'refunded']);
                } catch (QueryException $exception) {
                    $historyRejected = $exception;
                }
                $this->assertInstanceOf(QueryException::class, $historyRejected, $column);
            }
        } finally {
            DB::disconnect('sqlite');
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
            config(['database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
            // This test migrated a separate file, so the next RefreshDatabase class must rebuild memory.
            RefreshDatabaseState::$migrated = false;
        }
    }
}
