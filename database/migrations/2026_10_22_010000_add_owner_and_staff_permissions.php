<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->preflight();
        $this->changeRoles(true);
        Schema::table('users', fn (Blueprint $table) => $table->boolean('can_manage_bookings')->default(false));
        // One-time compatibility backfill. Later grants intentionally retain false.
        DB::table('users')->where('role', 'admin')->update(['can_manage_bookings' => true]);
        Schema::create('staff_access_guards', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
        });
        DB::table('staff_access_guards')->insert(['id' => 1, 'revision' => 0]);
    }

    public function down(): void
    {
        $this->preflight();
        if (DB::table('users')->where('role', 'owner')->exists()) {
            throw new LogicException('Cannot roll back owner authority while owner accounts exist.');
        }
        $this->changeRoles(false);
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('can_manage_bookings'));
        Schema::drop('staff_access_guards');
    }

    private function preflight(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new LogicException('Owner authority migration supports SQLite, MySQL and MariaDB only.');
        }
        if (DB::getDriverName() === 'sqlite' && DB::transactionLevel() !== 0) {
            throw new LogicException('SQLite owner migration must run outside a transaction to preserve foreign keys.');
        }
    }

    private function changeRoles(bool $expand): void
    {
        $values = $expand ? "'customer', 'admin', 'owner'" : "'customer', 'admin'";
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `users` MODIFY `role` ENUM({$values}) NOT NULL DEFAULT 'customer'");

            return;
        }
        $original = DB::scalar("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'");
        $pattern = '/(CHECK\s*\(\s*["`\[]?role["`\]]?\s+in\s*\()[^)]*(\)\s*\))/i';
        $sql = preg_replace_callback($pattern, fn ($match) => $match[1].$values.$match[2], $original, -1, $count);
        if ($count !== 1) {
            throw new LogicException('Expected exactly one users.role CHECK constraint before owner migration.');
        }
        $sql = preg_replace('/\ACREATE TABLE\s+["`\[]?users["`\]]?/i', 'CREATE TABLE "users_owner_rebuild"', $sql, 1, $tableCount);
        if ($tableCount !== 1 || Schema::hasTable('users_owner_rebuild')) {
            throw new LogicException('Cannot safely prepare users owner rebuild.');
        }
        $indexes = DB::select("SELECT sql FROM sqlite_master WHERE tbl_name = 'users' AND type IN ('index', 'trigger') AND sql IS NOT NULL");
        $sequence = str_contains(strtoupper($original), 'AUTOINCREMENT')
            ? DB::table('sqlite_sequence')->where('name', 'users')->value('seq') : null;
        $columns = array_map(fn ($column) => '"'.str_replace('"', '""', $column->name).'"', DB::select('PRAGMA table_info("users")'));
        $foreignKeys = (int) DB::scalar('PRAGMA foreign_keys');
        try {
            DB::statement('PRAGMA foreign_keys = OFF');
            if ((int) DB::scalar('PRAGMA foreign_keys') !== 0) {
                throw new LogicException('Could not disable SQLite foreign keys before rebuild.');
            }
            DB::transaction(function () use ($sql, $columns, $indexes, $sequence): void {
                DB::statement($sql);
                $list = implode(', ', $columns);
                DB::statement("INSERT INTO users_owner_rebuild ({$list}) SELECT {$list} FROM users");
                DB::statement('DROP TABLE users');
                DB::statement('ALTER TABLE users_owner_rebuild RENAME TO users');
                if ($sequence !== null) {
                    DB::table('sqlite_sequence')->where('name', 'users')->update(['seq' => $sequence]);
                }
                foreach ($indexes as $index) {
                    DB::statement($index->sql);
                }
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new LogicException('SQLite foreign key validation failed after owner rebuild.');
                }
            });
        } finally {
            DB::statement('PRAGMA foreign_keys = '.($foreignKeys ? 'ON' : 'OFF'));
        }
    }
};
