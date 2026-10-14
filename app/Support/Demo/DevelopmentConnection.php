<?php

namespace App\Support\Demo;

use Illuminate\Support\Facades\DB;

class DevelopmentConnection
{
    public static function verify(): void
    {
        $c = config('database.connections.'.config('database.default'));
        if (! app()->environment('local') || config('database.default') !== 'pgsql' || ($c['driver'] ?? null) !== 'pgsql'
            || ($c['database'] ?? null) !== 'ewaste_development' || ($c['host'] ?? null) !== '127.0.0.1'
            || (string) ($c['port'] ?? '') !== '5432' || ($c['username'] ?? null) !== 'ewaste_app' || ! empty($c['url'])
            || ($c['search_path'] ?? 'public') !== 'public') {
            throw new DemoSetupException('Nairobi demo setup requires local, public-schema ewaste_development on 127.0.0.1:5432 as ewaste_app; testing/production/URL connections are refused.');
        }
        $i = DB::selectOne('SELECT current_database() AS database, current_user AS role, current_schema() AS schema, pg_get_userbyid(datdba) AS owner, rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls AS elevated FROM pg_database JOIN pg_roles ON rolname = current_user WHERE datname = current_database()');
        if ($i->database !== 'ewaste_development' || $i->role !== 'ewaste_app' || $i->schema !== 'public' || $i->owner !== 'ewaste_app' || $i->elevated) {
            throw new DemoSetupException('Unexpected live database identity, schema, ownership or privileges. No demo writes permitted.');
        }
    }
}
