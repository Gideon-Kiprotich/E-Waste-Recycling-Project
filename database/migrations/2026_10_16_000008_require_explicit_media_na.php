<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('ALTER TABLE media_inventory_records ADD COLUMN na_reason_ciphertext text');
        $guard = DB::selectOne("SELECT pg_get_functiondef('media_inventory_insert_guard()'::regprocedure) AS body")->body;
        $guard = str_replace(" IF NEW.kind='attest' THEN", " IF NEW.kind='attest' THEN\n IF NEW.completeness='YES' AND NEW.na_reason_ciphertext IS NULL AND EXISTS(SELECT 1 FROM assessment_current ac JOIN assessments a ON a.id=ac.assessment_id WHERE ac.subject_id=NEW.parent_id AND a.data_status='NO') THEN RAISE EXCEPTION 'N/A needs explicit evidenced justification'; END IF;", $guard, $count);
        if ($count !== 1) {
            throw new RuntimeException('Media N/A upgrade blocked: unexpected inventory guard.');
        }
        DB::unprepared($guard);
        $coverage = DB::selectOne("SELECT pg_get_functiondef('media_coverage(uuid)'::regprocedure) AS body")->body;
        $coverage = str_replace(" IF data IS NULL OR data='UNKNOWN'", " IF data='NO' AND r.na_reason_ciphertext IS NULL THEN reasons:=reasons||'\"Explicit evidenced N/A justification required\"'::jsonb; END IF;\n IF data IS NULL OR data='UNKNOWN'", $coverage, $count);
        if ($count !== 1) {
            throw new RuntimeException('Media N/A upgrade blocked: unexpected coverage calculation.');
        }
        DB::unprepared($coverage);
        $final = DB::selectOne("SELECT pg_get_functiondef('media_final()'::regprocedure) AS body")->body;
        $final = str_replace("ELSIF NEW.type='media_identified' THEN", "ELSIF NEW.type='media_identified' THEN IF NOT EXISTS(SELECT 1 FROM items WHERE subject_id=NEW.subject_id AND intake_class='processor_derived') THEN RAISE EXCEPTION 'Identification event requires derived origin'; END IF;", $final, $count);
        if ($count !== 1) {
            throw new RuntimeException('Media provenance upgrade blocked: unexpected reciprocal guard.');
        }
        DB::unprepared($final);
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function down(): void
    {
        throw new RuntimeException('Evidenced N/A and provenance protections must remain.');
    }
};
