<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/media_recovery_gates.sql')));
        // Effective eligibility excludes ended erroneous claims; history and origin proofs retain original entries.
        foreach (['media_coverage(uuid)', 'media_authorization_applicable_embedded(media_authorizations)', 'sanitization_ready_embedded(uuid,uuid)', 'sanitization_dependencies_embedded(sanitization_records)', 'sanitization_insert_guard()', 'refurbishment_ready_before_restriction(uuid,uuid)', 'refurbishment_context(uuid,uuid,uuid)', 'refurbishment_configuration_before_restriction(uuid)', 'reuse_manifest_snapshot(uuid)', 'batch_input_ready_before_restriction(uuid,uuid)'] as $signature) {
            $this->replace($signature, 'media_inventory_entries', 'media_current_bindings');
        }
        $this->replace('media_custody_guard()', 'media_inventory_entries', 'media_current_bindings');
        $this->replace('media_inventory_insert_guard()', "IF NEW.kind='review_binding'", "IF NEW.kind='correct' AND EXISTS(SELECT 1 FROM media_inventory_entries WHERE id=NEW.entry_id AND state='association_superseded') THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Ended erroneous associations remain history; record actual inventory through a new evidenced observation.'; END IF;\nIF NEW.kind='review_binding'");
        $this->replace('restriction_investigator(uuid,uuid)', 'restriction_staff(actor,c.subject_id)', "CASE WHEN c.contract='P15' THEN media_review_scope(actor,c.subject_id) ELSE restriction_staff(actor,c.subject_id) END");
        $this->replace('restriction_assignment_boundary()', 'restriction_staff(c.assigned_to,c.subject_id)', "CASE WHEN c.contract='P15' THEN media_review_scope(c.assigned_to,c.subject_id) ELSE restriction_staff(c.assigned_to,c.subject_id) END");
        $this->replace('restriction_inspection_epoch_guard()', 'restriction_fresh_inspection(NEW.subject_id)', "media_fresh_for(NEW.subject_id,'decision')");
        $this->replace('refurbishment_ready_before_restriction(uuid,uuid)', "AND NOT EXISTS(SELECT 1 FROM refurbishment_records WHERE subject_id=ref AND kind='test' AND (answers->>'safety' IN ('FAIL','UNKNOWN') OR answers->>'configuration' IN ('FAIL','UNKNOWN')))\n AND NOT EXISTS(SELECT 1 FROM refurbishment_records WHERE subject_id=ref AND (kind='exception' OR (kind='work' AND configuration<>'UNCHANGED')))", 'AND NOT media_work_blocked(ref)');
        $this->replace('batch_work_restricted(uuid)', "EXISTS(SELECT 1 FROM refurbishment_records WHERE subject_id=ref AND (kind='exception' OR configuration IN ('CHANGED','UNKNOWN') OR answers->>'safety' IN ('FAIL','UNKNOWN') OR answers->>'configuration' IN ('FAIL','UNKNOWN')))", 'media_work_blocked(ref)');
        foreach (['refurbishment_ready_before_restriction(uuid,uuid)', 'batch_work_restricted(uuid)'] as $signature) {
            $this->replace($signature, "r.result IN ('failed','incomplete')", "media_work_value(r.id,'work_outcome') IN ('FAILED','INCOMPLETE') AND NOT media_record_superseded('work',r.id,NULL)");
        }
        $this->replace('refurbishment_context(uuid,uuid,uuid)', "(SELECT id FROM refurbishment_records WHERE subject_id=ref AND kind='work' ORDER BY version DESC LIMIT 1)", 'media_work_basis(ref)');
        foreach (['batch_balance(uuid)', 'batch_record_insert_guard()', 'batch_authorization_applicable_on_site(media_authorizations)', 'downstream_blocked_before_restriction(uuid)'] as $signature) {
            $this->replace($signature, "EXISTS(SELECT 1 FROM batch_records WHERE batch_id=ref AND kind='discrepancy')", 'media_batch_unresolved(ref)', false);
            $this->replace($signature, "EXISTS(SELECT 1 FROM batch_records WHERE batch_id=b.subject_id AND kind='discrepancy')", 'media_batch_unresolved(b.subject_id)', false);
        }
        $this->replace('refurbishment_insert_guard()', "NEW.kind<>'withdraw' AND NOT refurbishment_ready(NEW.subject_id,NEW.actor_id)", "NEW.kind<>'withdraw' AND NOT media_refurbishment_ready(NEW.subject_id,NEW.actor_id,CASE WHEN NEW.kind IN ('test','work_applicability') THEN NEW.kind ELSE 'progress' END)");
        $this->replace('refurbishment_insert_guard()', "SELECT * INTO w FROM refurbishment_records WHERE subject_id=NEW.subject_id AND kind='work' ORDER BY version DESC LIMIT 1;", 'SELECT * INTO w FROM refurbishment_records WHERE id=media_work_basis(NEW.subject_id);');
        $this->replace('refurbishment_insert_guard()', "w.result<>'completed' OR w.configuration<>'UNCHANGED'", "w.kind NOT IN ('work','work_applicability')");
        $this->replace('refurbishment_insert_guard()', "IF NEW.kind='test' THEN", "IF NEW.kind='work_applicability' THEN PERFORM media_work_applicability_guard(NEW); ELSIF NEW.correction_review_id IS NOT NULL THEN RAISE EXCEPTION 'Only explicit retained-work applicability may cite correction authority'; END IF;\n IF NEW.kind='test' THEN");
        $this->replace('sanitization_insert_guard()', 'sanitization_ready(NEW.subject_id,NEW.actor_id)', 'media_sanitization_ready(NEW.subject_id,NEW.actor_id,NEW.kind)');
        foreach (DB::select("SELECT p.oid::regprocedure::text AS signature FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname=current_schema() AND p.proname LIKE 'sanitization_dependencies%' AND p.prosrc LIKE '%sanitization_ready(%'") as $function) {
            $this->replace($function->signature, 'sanitization_ready(', 'media_assurance_scope(');
        }
    }

    private function replace(string $signature, string $from, string $to, bool $required = true): void
    {
        $body = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$signature])->body;
        $body = str_replace($from, $to, $body, $count);
        if ($required && ! $count) {
            throw new RuntimeException('Media recovery migration blocked: unexpected preceding contract.');
        }
        if ($count) {
            DB::unprepared($body);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward correction migration.');
    }
};
