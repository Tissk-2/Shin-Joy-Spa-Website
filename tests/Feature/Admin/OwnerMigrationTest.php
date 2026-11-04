<?php

namespace Tests\Feature\Admin;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OwnerMigrationTest extends TestCase
{
    public function test_mysql_and_mariadb_migration_sql_compiles_without_runtime_claim(): void
    {
        foreach (['mysql', 'mariadb'] as $driver) {
            DB::extend('owner_compile', fn ($config) => new MySqlConnection(new \PDO('sqlite::memory:'), 'testing', '', [...$config, 'driver' => $driver]));
            config(['database.connections.owner_compile' => ['driver' => 'owner_compile']]);
            DB::purge('owner_compile');
            DB::setDefaultConnection('owner_compile');
            Schema::clearResolvedInstance('db.schema');
            try {
                $migration = require database_path('migrations/2026_10_22_010000_add_owner_and_staff_permissions.php');
                $queries = DB::pretend(fn () => $migration->up());
                $this->assertSame("ALTER TABLE `users` MODIFY `role` ENUM('customer', 'admin', 'owner') NOT NULL DEFAULT 'customer'", $queries[0]['query']);
                $this->assertStringContainsString('`can_manage_bookings` tinyint(1) not null default', $queries[1]['query']);
                $this->assertSame([true, 'admin'], $queries[2]['bindings']);
                $this->assertStringContainsString('create table `staff_access_guards`', $queries[3]['query']);
                $this->assertSame([1, 0], $queries[4]['bindings']);
            } finally {
                DB::disconnect('owner_compile');
                DB::setDefaultConnection('sqlite');
                Schema::clearResolvedInstance('db.schema');
            }
        }
    }

    public function test_unsupported_driver_fails_before_any_mutating_query(): void
    {
        DB::extend('owner_unsupported', fn ($config) => new SQLiteConnection(new \PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'pgsql']));
        config(['database.connections.owner_unsupported' => ['driver' => 'owner_unsupported']]);
        DB::setDefaultConnection('owner_unsupported');
        try {
            DB::enableQueryLog();
            $migration = require database_path('migrations/2026_10_22_010000_add_owner_and_staff_permissions.php');
            try {
                $migration->up();
                $this->fail('Unsupported driver accepted.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('supports SQLite, MySQL and MariaDB only', $exception->getMessage());
                $this->assertSame([], DB::getQueryLog());
            }
        } finally {
            DB::disconnect('owner_unsupported');
            DB::setDefaultConnection('sqlite');
        }
    }

    public function test_committed_old_sqlite_upgrade_preserves_children_defaults_indexes_and_checks(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'owner-upgrade-');
        config(['database.connections.owner_upgrade' => [...config('database.connections.sqlite'), 'database' => $path]]);
        DB::setDefaultConnection('owner_upgrade');
        try {
            DB::statement("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name varchar NOT NULL, email varchar NOT NULL, role varchar CHECK (role in ('customer', 'admin')) NOT NULL DEFAULT 'customer')");
            DB::statement('CREATE UNIQUE INDEX users_email_unique ON users(email)');
            DB::statement('CREATE INDEX users_role_index ON users(role)');
            DB::statement('CREATE TABLE children (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE)');
            DB::table('users')->insert([['id' => 1, 'name' => 'Admin', 'email' => 'a@example.com', 'role' => 'admin'], ['id' => 2, 'name' => 'Customer', 'email' => 'c@example.com', 'role' => 'customer']]);
            DB::table('children')->insert(['id' => 1, 'user_id' => 1]);
            DB::table('users')->insert(['id' => 99, 'name' => 'Deleted', 'email' => 'deleted@example.com']);
            DB::table('users')->where('id', 99)->delete();
            DB::statement('CREATE TABLE user_names (name TEXT)');
            DB::statement('CREATE TRIGGER users_name_audit AFTER UPDATE OF name ON users BEGIN INSERT INTO user_names (name) VALUES (NEW.name); END');
            $this->assertSame(0, DB::transactionLevel());
            $migration = require database_path('migrations/2026_10_22_010000_add_owner_and_staff_permissions.php');
            $migration->up();
            $this->assertSame(['admin', 'customer'], DB::table('users')->orderBy('id')->pluck('role')->all());
            $this->assertSame([1, 0], DB::table('users')->orderBy('id')->pluck('can_manage_bookings')->all());
            $this->assertSame(1, DB::table('children')->count());
            $this->assertSame(100, DB::table('users')->insertGetId(['name' => 'Next', 'email' => 'next@example.com']));
            DB::table('users')->where('id', 1)->update(['name' => 'Audited']);
            $this->assertSame('Audited', DB::table('user_names')->value('name'));
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
            $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
            $this->assertCount(2, Schema::getIndexes('users') === [] ? [] : array_filter(Schema::getIndexes('users'), fn ($index) => ! $index['primary']));
            DB::table('users')->insert(['id' => 3, 'name' => 'Default', 'email' => 'd@example.com']);
            $this->assertSame('customer', DB::table('users')->where('id', 3)->value('role'));
            $this->assertSame(0, DB::table('users')->where('id', 3)->value('can_manage_bookings'));
            DB::table('users')->where('id', 2)->update(['role' => 'owner']);
            try {
                $migration->down();
                $this->fail('Owner rollback must refuse.');
            } catch (\LogicException) {
                $this->assertTrue(Schema::hasTable('staff_access_guards'));
            }
            DB::table('users')->where('id', 2)->update(['role' => 'customer']);
            $migration->down();
            $this->assertFalse(Schema::hasColumn('users', 'can_manage_bookings'));
            $this->assertFalse(Schema::hasTable('staff_access_guards'));
            $this->assertSame(1, DB::table('children')->count());
            $this->assertSame(101, DB::table('users')->insertGetId(['name' => 'After rollback', 'email' => 'rollback@example.com']));
            $this->expectException(QueryException::class);
            DB::table('users')->where('id', 1)->update(['role' => 'owner']);
        } finally {
            DB::disconnect('owner_upgrade');
            DB::setDefaultConnection('sqlite');
            unlink($path);
        }
    }

    public function test_sqlite_preflight_refuses_transaction_before_mutation(): void
    {
        DB::beginTransaction();
        DB::enableQueryLog();
        try {
            $migration = require database_path('migrations/2026_10_22_010000_add_owner_and_staff_permissions.php');
            try {
                $migration->up();
                $this->fail('Transactional SQLite rebuild accepted.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('outside a transaction', $exception->getMessage());
                $this->assertSame([], DB::getQueryLog());
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_failed_sqlite_rebuild_restores_foreign_keys_and_original_table(): void
    {
        DB::statement("CREATE TABLE users (id INTEGER PRIMARY KEY, role varchar CHECK (role in ('customer', 'admin')) NOT NULL DEFAULT 'customer')");
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('CREATE TABLE children (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id))');
        DB::table('children')->insert(['id' => 1, 'user_id' => 123]);
        DB::statement('PRAGMA foreign_keys = ON');
        $original = DB::scalar("SELECT sql FROM sqlite_master WHERE name = 'users'");
        $migration = require database_path('migrations/2026_10_22_010000_add_owner_and_staff_permissions.php');
        try {
            $migration->up();
            $this->fail('Invalid existing foreign key accepted.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('foreign key validation failed', $exception->getMessage());
            $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
            $this->assertSame($original, DB::scalar("SELECT sql FROM sqlite_master WHERE name = 'users'"));
            $this->assertFalse(Schema::hasColumn('users', 'can_manage_bookings'));
            $this->assertFalse(Schema::hasTable('staff_access_guards'));
        }
    }

    public function test_sqlite_preserves_foreign_keys_disabled_before_upgrade(): void
    {
        DB::statement("CREATE TABLE users (id INTEGER PRIMARY KEY, role varchar CHECK (role in ('customer', 'admin')) NOT NULL DEFAULT 'customer')");
        DB::statement('PRAGMA foreign_keys = OFF');
        $migration = require database_path('migrations/2026_10_22_010000_add_owner_and_staff_permissions.php');
        $migration->up();
        $this->assertSame(0, (int) DB::scalar('PRAGMA foreign_keys'));
    }
}
