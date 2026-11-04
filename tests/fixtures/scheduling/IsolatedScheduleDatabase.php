<?php

namespace Tests\Fixtures\Scheduling;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class IsolatedScheduleDatabase
{
    public static function connect(string $directory): void
    {
        $real = realpath($directory);
        $temp = realpath(sys_get_temp_dir());
        if ($real === false || $temp === false || dirname($real) !== $temp
            || ! preg_match('/\Ascheduling-b3b-[a-f0-9]{24}\z/', basename($real))
            || ! is_file($real.DIRECTORY_SEPARATOR.'database.sqlite')) {
            throw new RuntimeException('Refusing a database outside the disposable scheduling fixture directory.');
        }
        if (! app()->environment('testing') || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('TestCase isolation must run before attaching the fixture database.');
        }
        DB::disconnect('sqlite');
        config(['database.connections.sqlite.database' => $real.DIRECTORY_SEPARATOR.'database.sqlite',
            'database.connections.sqlite.busy_timeout' => 5000]);
        DB::purge('sqlite');
        DB::connection()->statement('PRAGMA busy_timeout = 5000');
    }

    public static function wait(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for fixture barrier '.basename($path));
            }
            usleep(10000);
        }
    }
}
