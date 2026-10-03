<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresConnectionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_postgresql_is_isolated_and_supports_transactional_reads_and_writes(): void
    {
        $connection = DB::connection();
        $this->assertSame('pgsql', $connection->getDriverName());

        $server = $connection->selectOne("SELECT current_database() AS database_name,
            current_user AS role_name, current_setting('server_version_num')::int AS version_number");

        $this->assertSame('ewaste_testing', $server->database_name);
        $this->assertNotSame($this->developmentDatabase(), $server->database_name);
        $this->assertSame('ewaste_app', $server->role_name);
        $this->assertSame(16, intdiv((int) $server->version_number, 10000));

        $role = $connection->selectOne('SELECT rolsuper::int AS superuser,
            rolcreatedb::int AS create_database, rolcreaterole::int AS create_role,
            rolreplication::int AS replication, rolbypassrls::int AS bypass_rls
            FROM pg_roles WHERE rolname = current_user');

        foreach ((array) $role as $privilege) {
            $this->assertSame(0, (int) $privilege, 'The project role must not have administrative privileges.');
        }

        // Temporary data is rolled back; no persistent fixtures or domain records.
        $connection->statement('CREATE TEMPORARY TABLE phase0_connection_probe (value text NOT NULL) ON COMMIT DROP');
        $connection->insert('INSERT INTO phase0_connection_probe (value) VALUES (?)', ['connection verified']);
        $this->assertSame('connection verified', $connection->selectOne('SELECT value FROM phase0_connection_probe')->value);
    }
}
