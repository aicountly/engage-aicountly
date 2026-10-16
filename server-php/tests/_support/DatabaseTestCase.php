<?php

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Base class for tests that touch the isolated `tests` database group.
 *
 * Runs every Engage migration and refreshes the schema between tests. The
 * whole class self-skips when no test database is configured
 * (database.tests.database or TEST_DB_NAME), so the production database can
 * never be reached from a test run.
 */
abstract class DatabaseTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $DBGroup   = 'tests';

    protected function setUp(): void
    {
        $name = getenv('database.tests.database');
        if ($name === false || $name === '') {
            $name = getenv('TEST_DB_NAME') ?: '';
        }
        if ($name === '') {
            $this->markTestSkipped('Isolated PostgreSQL test database unavailable: set database.tests.database (and database.tests.username/password).');
        }

        parent::setUp();
    }
}
