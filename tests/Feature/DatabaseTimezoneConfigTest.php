<?php

namespace Tests\Feature;

use Pdo\Mysql;
use Tests\TestCase;

class DatabaseTimezoneConfigTest extends TestCase
{
    public function test_mysql_connections_store_instants_in_utc(): void
    {
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
        $this->assertSame('+00:00', config('database.connections.mariadb.timezone'));
    }

    public function test_mysql_connections_count_the_rows_an_update_matched(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The pdo_mysql extension is not loaded.');
        }

        // Every affected-row check in the code counts matched rows, as SQLite does; MySQL counts changed rows without this.
        $foundRows = PHP_VERSION_ID >= 80500 ? Mysql::ATTR_FOUND_ROWS : \PDO::MYSQL_ATTR_FOUND_ROWS;
        $this->assertTrue(config('database.connections.mysql.options')[$foundRows] ?? null);
        $this->assertTrue(config('database.connections.mariadb.options')[$foundRows] ?? null);
    }
}
