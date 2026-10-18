<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        if (! Schema::hasTable('reuse_return_items') || DB::table('refurbishment_records')->exists() || DB::table('reuse_return_items')->exists()) {
            throw new RuntimeException('Reuse controls upgrade requires the inspected pre-release state; stop and inspect privately.');
        }
        // Reapply only Phase 11 functions; no old ledger, trigger or row is replaced.
        preg_match_all('/CREATE FUNCTION .*?\$\$;/s', file_get_contents(__DIR__.'/../sql/refurbishment.sql'), $matches);
        if (count($matches[0]) !== 14) {
            throw new RuntimeException('Unexpected Phase 11 control definitions.');
        }
        foreach ($matches[0] as $definition) {
            DB::unprepared(preg_replace('/^CREATE FUNCTION /', 'CREATE OR REPLACE FUNCTION ', $definition));
        }
        $body = DB::selectOne("SELECT pg_get_functiondef('receipt_integrity()'::regprocedure) AS body")->body;
        if (! str_contains($body, '-- Reuse derived provenance')) {
            $body = str_replace('IF NOT FOUND THEN', "IF NOT FOUND THEN\n -- Reuse derived provenance: an acknowledged return extends the evidenced origin, never invents first receipt.\n IF EXISTS(SELECT 1 FROM items subject_item JOIN reuse_return_items ri ON ri.subject_id=subject_item.subject_id WHERE subject_item.subject_id=ref AND subject_item.intake_class='processor_derived') THEN PERFORM media_origin_integrity(ref); RETURN NULL; END IF;", $body, $count);
            if ($count !== 1) {
                throw new RuntimeException('Unexpected derived receipt protection.');
            }
            DB::unprepared($body);
        }
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function down(): void
    {
        throw new RuntimeException('Reuse integrity protections must remain.');
    }
};
