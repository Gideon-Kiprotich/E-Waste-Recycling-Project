<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait ReportingSchema
{
    private string $reportingSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        $this->assertSame('ewaste_testing', config('database.connections.pgsql.database'));
        $this->reportingSchema = 'reporting_test_'.str_replace('-', '', (string) Str::uuid());
        $config = config('database.connections.pgsql');
        $config['search_path'] = $this->reportingSchema;
        config(['database.connections.reporting_test' => $config]);
        DB::setDefaultConnection('reporting_test');
        Schema::clearResolvedInstance('db.schema');
        DB::statement('CREATE SCHEMA "'.$this->reportingSchema.'"');
        foreach (glob(database_path('migrations/*.php')) as $file) {
            DB::transaction(fn () => (require $file)->up());
        }
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            DB::statement('DROP SCHEMA "'.$this->reportingSchema.'" CASCADE');
            DB::purge('reporting_test');
            DB::setDefaultConnection('pgsql');
            Schema::clearResolvedInstance('db.schema');
        } finally {
            parent::tearDown();
        }
    }
}
