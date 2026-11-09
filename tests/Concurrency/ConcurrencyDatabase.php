<?php

namespace Tests\Concurrency;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

final class ConcurrencyDatabase
{
    public const CONNECTION = 'concurrency';

    public const KEYS = ['CONCURRENCY_DB_DRIVER', 'CONCURRENCY_DB_HOST', 'CONCURRENCY_DB_PORT',
        'CONCURRENCY_DB_DATABASE', 'CONCURRENCY_DB_USERNAME', 'CONCURRENCY_DB_PASSWORD', 'CONCURRENCY_BARRIER_DIR'];

    /** @return array<string, string> */
    public static function environment(): array
    {
        $environment = [];

        foreach (self::KEYS as $key) {
            $value = getenv($key);

            if ($value !== false) {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }

    /** @return array<string, mixed> */
    public static function settings(array $env): array
    {
        $driver = $env['CONCURRENCY_DB_DRIVER'] ?? 'sqlite';

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Unsupported concurrency database driver.');
        }

        if ($driver === 'sqlite') {
            $path = $env['CONCURRENCY_DB_DATABASE'] ?? '';
            $database = realpath($path);
            $directory = $database === false ? false : realpath(dirname($database));

            if ($database === false || basename($database) !== 'database.sqlite'
                || $directory === false || ! preg_match('/\Aconcurrency-[a-f0-9]{24}\z/', basename($directory))) {
                throw new RuntimeException('Refusing a SQLite database outside a disposable concurrency directory.');
            }

            if (realpath(dirname($directory)) !== realpath(sys_get_temp_dir())) {
                throw new RuntimeException('Refusing a SQLite database outside a disposable concurrency directory.');
            }

            return ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000];
        }

        $database = $env['CONCURRENCY_DB_DATABASE'] ?? '';

        if (! preg_match('/\A[a-z0-9_]{0,48}concurrency_test\z/', $database)) {
            throw new RuntimeException('Refusing a database whose name does not end in concurrency_test.');
        }

        $host = $env['CONCURRENCY_DB_HOST'] ?? '';

        if ($host === '') {
            throw new RuntimeException('CONCURRENCY_DB_HOST is required.');
        }

        return [
            'driver' => $driver,
            'host' => $host,
            'port' => $env['CONCURRENCY_DB_PORT'] ?? '3306',
            'database' => $database,
            'username' => $env['CONCURRENCY_DB_USERNAME'] ?? '',
            'password' => $env['CONCURRENCY_DB_PASSWORD'] ?? '',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'timezone' => '+00:00',
            // An unreachable host (dropped packets) fails in seconds instead of stalling each attempt.
            'options' => [PDO::ATTR_TIMEOUT => 5],
        ];
    }

    public static function barrier(array $env): string
    {
        $directory = realpath($env['CONCURRENCY_BARRIER_DIR'] ?? '');

        if ($directory === false || ! is_dir($directory)
            || ! preg_match('/\Aconcurrency-[a-f0-9]{24}\z/', basename($directory))
            || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())) {
            throw new RuntimeException('Refusing a barrier directory outside a disposable concurrency directory.');
        }

        return $directory;
    }

    public static function connect(array $env): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('The concurrency database may only be used in the testing environment.');
        }

        $settings = self::settings($env);

        config(['database.connections.'.self::CONNECTION => $settings, 'database.default' => self::CONNECTION]);

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();
    }

    /** Pass the worker being waited on, so a crash fails at once with its own output instead of a bare timeout. */
    public static function wait(string $path, ?Process $process = null): void
    {
        $deadline = microtime(true) + 15;

        while (! file_exists($path)) {
            if ($process !== null && ! $process->isRunning()) {
                throw new RuntimeException('A concurrency worker exited (code '.$process->getExitCode().') before ['.basename($path).']: '
                    .trim($process->getErrorOutput().' '.$process->getOutput()));
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Timed out waiting for the concurrency barrier [{$path}].");
            }

            usleep(10000);
        }
    }
}
