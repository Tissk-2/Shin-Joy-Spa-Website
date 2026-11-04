<?php

namespace Tests\Feature\Admin;

use App\Actions\SaveBookingSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Scheduling\IsolatedScheduleDatabase;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/scheduling/IsolatedScheduleDatabase.php';

class BookingSettingsContentionTest extends TestCase
{
    public static function races(): array
    {
        return ['first save' => ['create'], 'version update' => ['update'], 'demoted while waiting' => ['demote'],
            'unverified while waiting' => ['unverify'], 'deleted while waiting' => ['delete']];
    }

    #[DataProvider('races')]
    public function test_real_process_create_version_and_authority_races_preserve_final_facts(string $operation): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'scheduling-b3b-'.bin2hex(random_bytes(12));
        mkdir($directory);
        touch($directory.'/database.sqlite');
        $workers = [];
        try {
            IsolatedScheduleDatabase::connect($directory);
            Artisan::call('migrate', ['--force' => true]);
            $owner = User::factory()->owner()->create();
            if ($operation === 'update') {
                (new SaveBookingSettings)($owner, BookingSettingsTest::input());
            }
            foreach (['first', 'second'] as $role) {
                $worker = new Process([PHP_BINARY, base_path('tests/fixtures/scheduling/booking-settings-worker.php'),
                    $directory, $role, $operation, (string) $owner->id], base_path(), null, null, 30);
                $worker->start();
                $workers[$role] = $worker;
                IsolatedScheduleDatabase::wait($directory.'/'.$role.'-ready');
            }
            touch($directory.'/first-go');
            IsolatedScheduleDatabase::wait($directory.'/first-acquired');
            touch($directory.'/second-go');
            IsolatedScheduleDatabase::wait($directory.'/second-attempting');
            $this->assertFileDoesNotExist($directory.'/second-acquired');
            touch($directory.'/release-first');
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            }
            $saving = in_array($operation, ['create', 'update'], true);
            $this->assertSame($saving ? 'saved' : 'authority changed', $workers['first']->getOutput());
            $this->assertSame($saving ? 'stale' : 'denied', $workers['second']->getOutput());
            if ($saving) {
                $this->assertDatabaseCount('booking_settings', 1);
                $this->assertDatabaseHas('booking_settings', ['id' => 1, 'version' => $operation === 'create' ? 1 : 2,
                    'booking_horizon_days' => 31]);
            } else {
                $this->assertDatabaseCount('booking_settings', 0);
                match ($operation) {
                    'demote' => $this->assertDatabaseHas('users', ['id' => $owner->id, 'role' => 'admin']),
                    'unverify' => $this->assertDatabaseHas('users', ['id' => $owner->id, 'email_verified_at' => null]),
                    'delete' => $this->assertDatabaseMissing('users', ['id' => $owner->id]),
                };
            }
            $this->assertDatabaseCount('room_reservations', 0);
            $this->assertDatabaseCount('bookings', 0);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::disconnect('sqlite');
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            config(['database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
            RefreshDatabaseState::$migrated = false;
        }
    }
}
