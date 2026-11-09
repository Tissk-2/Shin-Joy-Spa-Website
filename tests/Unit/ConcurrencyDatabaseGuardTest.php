<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Concurrency\ConcurrencyDatabase;

class ConcurrencyDatabaseGuardTest extends TestCase
{
    public function test_unsupported_drivers_are_refused(): void
    {
        try {
            ConcurrencyDatabase::settings(['CONCURRENCY_DB_DRIVER' => 'pgsql']);
            $this->fail('An unsupported driver was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unsupported concurrency database driver.', $exception->getMessage());
        }
    }

    public function test_mysql_requires_a_disposable_database_name_and_a_host(): void
    {
        foreach (['shinjoy', 'laravel', 'concurrency_test_prod', 'Shinjoy_concurrency_test'] as $database) {
            try {
                ConcurrencyDatabase::settings([
                    'CONCURRENCY_DB_DRIVER' => 'mysql',
                    'CONCURRENCY_DB_DATABASE' => $database,
                    'CONCURRENCY_DB_HOST' => '127.0.0.1',
                ]);
                $this->fail("The database name [{$database}] was accepted.");
            } catch (RuntimeException $exception) {
                $this->assertSame('Refusing a database whose name does not end in concurrency_test.', $exception->getMessage());
            }
        }

        try {
            ConcurrencyDatabase::settings([
                'CONCURRENCY_DB_DRIVER' => 'mysql',
                'CONCURRENCY_DB_DATABASE' => 'shinjoy_concurrency_test',
                'CONCURRENCY_DB_HOST' => '',
            ]);
            $this->fail('An empty host was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('CONCURRENCY_DB_HOST is required.', $exception->getMessage());
        }

        $settings = ConcurrencyDatabase::settings([
            'CONCURRENCY_DB_DRIVER' => 'mysql',
            'CONCURRENCY_DB_DATABASE' => 'shinjoy_concurrency_test',
            'CONCURRENCY_DB_HOST' => '127.0.0.1',
        ]);

        $this->assertSame('shinjoy_concurrency_test', $settings['database']);
        $this->assertSame('+00:00', $settings['timezone']);
        $this->assertSame('mysql', $settings['driver']);
        // Review additions: an unreachable host fails within seconds, and MariaDB gets the same rules.
        $this->assertSame(5, $settings['options'][\PDO::ATTR_TIMEOUT]);
        $this->assertSame('mariadb', ConcurrencyDatabase::settings([
            'CONCURRENCY_DB_DRIVER' => 'mariadb',
            'CONCURRENCY_DB_DATABASE' => 'shinjoy_concurrency_test',
            'CONCURRENCY_DB_HOST' => '127.0.0.1',
        ])['driver']);
    }

    public function test_sqlite_must_live_in_a_fresh_concurrency_directory(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'concurrency-'.bin2hex(random_bytes(12));
        $nestedParent = sys_get_temp_dir().DIRECTORY_SEPARATOR.'harness-'.bin2hex(random_bytes(12));
        $nested = $nestedParent.DIRECTORY_SEPARATOR.'concurrency-'.bin2hex(random_bytes(12));
        $scheduling = sys_get_temp_dir().DIRECTORY_SEPARATOR.'scheduling-'.bin2hex(random_bytes(12));
        mkdir($directory);
        mkdir($nestedParent);
        mkdir($nested);
        mkdir($scheduling);
        touch($directory.'/database.sqlite');
        touch($directory.'/other.sqlite');
        touch($nested.'/database.sqlite');
        touch($scheduling.'/database.sqlite');

        try {
            $settings = ConcurrencyDatabase::settings([
                'CONCURRENCY_DB_DRIVER' => 'sqlite',
                'CONCURRENCY_DB_DATABASE' => $directory.'/database.sqlite',
            ]);

            $this->assertSame(realpath($directory.'/database.sqlite'), $settings['database']);
            // Review addition: ".." segments are resolved before the checks, so they can't smuggle in another path.
            $this->assertSame(realpath($directory.'/database.sqlite'), ConcurrencyDatabase::settings([
                'CONCURRENCY_DB_DRIVER' => 'sqlite',
                'CONCURRENCY_DB_DATABASE' => $nested.'/../../'.basename($directory).'/database.sqlite',
            ])['database']);

            foreach ([
                $directory.'/other.sqlite',
                $nested.'/database.sqlite',
                $scheduling.'/database.sqlite',
                __DIR__.'/../../database/database.sqlite',
            ] as $path) {
                try {
                    ConcurrencyDatabase::settings(['CONCURRENCY_DB_DRIVER' => 'sqlite', 'CONCURRENCY_DB_DATABASE' => $path]);
                    $this->fail("The SQLite path [{$path}] was accepted.");
                } catch (RuntimeException $exception) {
                    $this->assertSame('Refusing a SQLite database outside a disposable concurrency directory.', $exception->getMessage());
                }
            }

            try {
                ConcurrencyDatabase::settings(['CONCURRENCY_DB_DRIVER' => 'sqlite']);
                $this->fail('A missing SQLite database path was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Refusing a SQLite database outside a disposable concurrency directory.', $exception->getMessage());
            }
        } finally {
            foreach ([
                $directory.'/database.sqlite',
                $directory.'/other.sqlite',
                $nested.'/database.sqlite',
                $scheduling.'/database.sqlite',
            ] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            foreach ([$directory, $nested, $nestedParent, $scheduling] as $path) {
                if (is_dir($path)) {
                    rmdir($path);
                }
            }
        }
    }

    public function test_the_barrier_directory_is_checked_the_same_way(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'concurrency-'.bin2hex(random_bytes(12));
        mkdir($directory);

        try {
            $this->assertSame(realpath($directory), ConcurrencyDatabase::barrier(['CONCURRENCY_BARRIER_DIR' => $directory]));

            foreach ([sys_get_temp_dir(), sys_get_temp_dir().DIRECTORY_SEPARATOR.'concurrency-xyz'] as $path) {
                try {
                    ConcurrencyDatabase::barrier(['CONCURRENCY_BARRIER_DIR' => $path]);
                    $this->fail("The barrier directory [{$path}] was accepted.");
                } catch (RuntimeException $exception) {
                    $this->assertSame('Refusing a barrier directory outside a disposable concurrency directory.', $exception->getMessage());
                }
            }
        } finally {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
