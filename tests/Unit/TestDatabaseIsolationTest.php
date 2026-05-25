<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Safety net: the test suite must NEVER default to the production MySQL connection.
 * phpunit.xml previously had the sqlite overrides commented out, so a test using
 * RefreshDatabase would have run migrate:fresh against production. This guards that.
 */
class TestDatabaseIsolationTest extends TestCase
{
    public function test_default_connection_is_isolated_sqlite_not_production_mysql(): void
    {
        $this->assertSame(
            'sqlite',
            config('database.default'),
            'Tests must default to sqlite. If this fails, phpunit.xml DB_CONNECTION override is missing or a cached config is overriding it — DO NOT run DB tests until fixed.'
        );

        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
