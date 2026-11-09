<?php

namespace Tests\Concurrency;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

abstract class ConcurrencyTestCase extends TestCase
{
    /** @var array<string, string> */
    protected array $env = [];

    protected string $directory = '';

    /** @var array<string, Process> */
    protected array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'concurrency-'.bin2hex(random_bytes(12));
            mkdir($this->directory);

            $this->env = ConcurrencyDatabase::environment();
            $this->env['CONCURRENCY_BARRIER_DIR'] = $this->directory;

            if (($this->env['CONCURRENCY_DB_DRIVER'] ?? 'sqlite') === 'sqlite') {
                touch($this->directory.DIRECTORY_SEPARATOR.'database.sqlite');
                $this->env['CONCURRENCY_DB_DATABASE'] = $this->directory.DIRECTORY_SEPARATOR.'database.sqlite';
            }

            ConcurrencyDatabase::connect($this->env);

            $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--database' => ConcurrencyDatabase::CONNECTION]));
        } catch (Throwable $exception) {
            $this->cleanup();

            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanup();
        } finally {
            parent::tearDown();
        }
    }

    protected function worker(string $scenario, string $role, int $bookingId): Process
    {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Concurrency/fixtures/worker.php'), $scenario, $role, (string) $bookingId],
            base_path(),
            $this->env,
            null,
            30,
        );

        $process->start();
        $this->workers[$role] = $process;
        ConcurrencyDatabase::wait($this->directory.DIRECTORY_SEPARATOR.$role.'-ready', $process);

        return $process;
    }

    protected function driver(): string
    {
        return $this->env['CONCURRENCY_DB_DRIVER'] ?? 'sqlite';
    }

    private function cleanup(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }

        try {
            DB::disconnect(ConcurrencyDatabase::CONNECTION);

            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
        } finally {
            // Temp files go even when the connection reset fails (for example after a failed parent::setUp()).
            $this->deleteDirectory($this->directory);

            RefreshDatabaseState::$migrated = false;
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if ($directory === '' || ! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_dir($file)) {
                $this->deleteDirectory($file);

                continue;
            }

            unlink($file);
        }

        rmdir($directory);
    }
}
