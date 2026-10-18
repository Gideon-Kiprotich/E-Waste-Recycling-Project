<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        if (DB::table('dismantling_batches')->exists()) {
            throw new RuntimeException('Measurement control upgrade requires inspected unused batch state; stop without rewriting history.');
        }
        DB::statement("ALTER TABLE batch_records ADD CONSTRAINT batch_complete_gross CHECK(measure_mode IS DISTINCT FROM 'gross_tare' OR (gross_kg IS NOT NULL AND tare_kg IS NOT NULL))");
        preg_match_all('/CREATE FUNCTION .*?\$\$;/s', file_get_contents(__DIR__.'/../sql/dismantling_batches.sql'), $definitions);
        if (count($definitions[0]) !== 12) {
            throw new RuntimeException('Unexpected batch integrity definitions.');
        }
        foreach ($definitions[0] as $definition) {
            DB::unprepared(preg_replace('/^CREATE FUNCTION /', 'CREATE OR REPLACE FUNCTION ', $definition));
        }
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function down(): void
    {
        throw new RuntimeException('Complete measurement and immutable-origin controls must remain.');
    }
};
