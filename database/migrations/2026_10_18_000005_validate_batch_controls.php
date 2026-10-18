<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        if (DB::table('dismantling_batches')->exists()) {
            throw new RuntimeException('Batch controls upgrade requires inspected pre-use state; preserve records and stop for review.');
        }
        foreach (['dismantling_batches.sql', 'batch_assurance.sql'] as $source) {
            preg_match_all('/CREATE FUNCTION .*?\$\$;/s', file_get_contents(__DIR__.'/../sql/'.$source), $definitions);
            if (count($definitions[0]) !== ($source === 'dismantling_batches.sql' ? 12 : 5)) {
                throw new RuntimeException('Unexpected batch control definitions.');
            }
            foreach ($definitions[0] as $definition) {
                DB::unprepared(preg_replace('/^CREATE FUNCTION /', 'CREATE OR REPLACE FUNCTION ', $definition));
            }
        }
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function down(): void
    {
        throw new RuntimeException('Batch integrity controls must remain.');
    }
};
