<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        if (DB::table('dismantling_batches')->exists()) {
            throw new RuntimeException('Linked-media restriction upgrade requires inspected pre-use state; stop without changing history.');
        }
        preg_match_all('/CREATE FUNCTION .*?\$\$;/s', file_get_contents(__DIR__.'/../sql/dismantling_batches.sql'), $definitions);
        if (count($definitions[0]) !== 12) {
            throw new RuntimeException('Unexpected canonical batch restriction definitions.');
        }
        foreach ($definitions[0] as $definition) {
            DB::unprepared(preg_replace('/^CREATE FUNCTION /', 'CREATE OR REPLACE FUNCTION ', $definition));
        }
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function down(): void
    {
        throw new RuntimeException('Canonical media restrictions must remain.');
    }
};
