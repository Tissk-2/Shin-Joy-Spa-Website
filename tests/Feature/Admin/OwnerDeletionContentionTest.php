<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Staff\IsolatedStaffDatabase;
use Tests\TestCase;

require_once __DIR__.'/../../fixtures/staff/IsolatedStaffDatabase.php';

class OwnerDeletionContentionTest extends TestCase
{
    public function test_two_real_profile_deletions_serialize_and_preserve_one_verified_owner(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'staff-b1a-'.bin2hex(random_bytes(12));
        mkdir($directory);
        touch($directory.'/database.sqlite');
        $workers = [];
        try {
            IsolatedStaffDatabase::connect($directory);
            Artisan::call('migrate', ['--force' => true]);
            $owners = User::factory()->owner()->count(2)->create();
            $this->assertSame(0, DB::transactionLevel());
            foreach (['first', 'second'] as $index => $role) {
                $worker = new Process([PHP_BINARY, base_path('tests/fixtures/staff/delete-owner-worker.php'), $directory, $role, (string) $owners[$index]->id], base_path(), null, null, 20);
                $worker->start();
                $workers[$role] = $worker;
                IsolatedStaffDatabase::wait($directory.'/'.$role.'-ready');
            }
            touch($directory.'/first-go');
            IsolatedStaffDatabase::wait($directory.'/first-acquired');
            touch($directory.'/second-go');
            IsolatedStaffDatabase::wait($directory.'/second-attempting');
            usleep(150000);
            $this->assertTrue($workers['second']->isRunning());
            $this->assertFileDoesNotExist($directory.'/second-acquired');
            touch($directory.'/release-first');
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput().$worker->getOutput());
            }
            $this->assertSame('deleted', $workers['first']->getOutput());
            $this->assertSame('refused', $workers['second']->getOutput());
            $this->assertFileExists($directory.'/second-acquired');
            $this->assertSame(1, User::where('role', 'owner')->whereNotNull('email_verified_at')->count());
            $this->assertSame($owners[1]->id, User::where('role', 'owner')->sole()->id);
            $this->assertSame(1, DB::table('staff_access_guards')->value('revision'));
        } catch (\Throwable $exception) {
            $diagnostics = array_map(fn ($worker) => $worker->getErrorOutput().$worker->getOutput(), $workers);
            throw new \RuntimeException($exception->getMessage().' '.implode(' ', $diagnostics), 0, $exception);
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
