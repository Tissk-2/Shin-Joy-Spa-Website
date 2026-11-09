<?php

namespace Tests\Feature;

use Tests\TestCase;

class DatabaseTimezoneConfigTest extends TestCase
{
    public function test_mysql_connections_store_instants_in_utc(): void
    {
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
        $this->assertSame('+00:00', config('database.connections.mariadb.timezone'));
    }
}
