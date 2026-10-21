<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT count(*) AS n FROM restriction_impacts i JOIN domain_events e ON e.id=i.target_id WHERE e.subject_id IS DISTINCT FROM i.subject_id')->n) {
            throw new RuntimeException('Correction integrity migration blocked by an inconsistent dependent target; no history was rewritten.');
        }
        // Retain independent standalone identity without granting a second stock movement path.
        $this->patch('refurbishment_ready_before_restriction(uuid,uuid)', 'SELECT inspection_scope(ref,actor)', 'SELECT NOT EXISTS(SELECT 1 FROM batch_media_discoveries WHERE medium_id=ref) AND inspection_scope(ref,actor)');
        $this->patch('media_custody_guard()', 'ref:=NEW.subject_id;', <<<'SQL'
ref:=NEW.subject_id;
 IF EXISTS(SELECT 1 FROM batch_media_discoveries WHERE medium_id=ref) THEN
 IF TG_TABLE_NAME='custody_current' AND TG_OP='UPDATE' THEN IF NEW IS NOT DISTINCT FROM OLD THEN RETURN NEW; END IF; END IF;
 RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='On-site discovery output movement and owner return require a separately authorized physical-movement contract. Retain facility control.';
 END IF;
SQL);
        $this->patch('media_units_valid(restriction_cases,restriction_records)', 'OR NOT media_no_terminal(medium.subject_id)', 'OR NOT media_no_terminal(medium.subject_id) OR batch_work_restricted(medium.subject_id) OR restriction_receipt_blocked(medium.subject_id) OR restriction_inspection_blocked(medium.subject_id) OR EXISTS(SELECT 1 FROM handover_items WHERE subject_id=medium.subject_id AND active)');
        $this->patch('media_validate_record(restriction_records)', "q.value NOT IN ('YES','NO','UNKNOWN')", "COALESCE(q.value,'') NOT IN ('YES','NO','UNKNOWN')");
        $this->patch('media_review_eligible(restriction_cases,restriction_records)', "x.value<>'YES'", "x.value IS DISTINCT FROM 'YES'");
        $this->patch('media_batch_record_guard(batch_records)', "OR n.kind NOT IN ('output','measurement')", "OR n.unit IS DISTINCT FROM 'kg' OR n.kind NOT IN ('output','measurement')");
        $this->patch('restriction_dependency_final()', 'e.id=NEW.target_id WHERE', 'e.id=NEW.target_id AND e.subject_id=NEW.subject_id WHERE');
        $this->patch('media_validate_record(restriction_records)', "MESSAGE='Source, scope or dependencies changed; reload the typed impact preview.'", <<<'SQL'
MESSAGE=CASE
 WHEN n.context IS DISTINCT FROM restriction_dependency_context(c.subject_id) THEN 'Dependency proof changed. Reload the typed impact preview before recording.'
 WHEN n.actor_context IS DISTINCT FROM restriction_actor_context(n.actor_id) THEN 'Account or facility scope changed. Reload the investigation.'
 WHEN n.observed_at>clock_timestamp() OR n.observed_at<date_trunc('minute',GREATEST(c.created_at,source.source_time)) OR n.recorded_at<n.observed_at THEN 'Observation time is outside the investigation/source period. Recheck the UTC time and reload the form.'
 ELSE 'Case or source version changed. Reload the typed impact preview.'
 END
SQL);
        $this->patch('media_review_eligible(restriction_cases,restriction_records)', 'OR p.actor_context IS DISTINCT FROM restriction_actor_context(p.actor_id)', "OR (p.attachment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attachments WHERE id=p.attachment_id AND uploaded_by=p.actor_id AND privacy_class='private' AND redacted_at IS NULL)) OR p.actor_context IS DISTINCT FROM restriction_actor_context(p.actor_id)");
        $this->patch('media_review_eligible(restriction_cases,restriction_records)', 'AND statement.id IS NOT NULL', "AND statement.id IS NOT NULL AND (statement.attachment_id IS NULL OR EXISTS(SELECT 1 FROM attachments WHERE id=statement.attachment_id AND uploaded_by=statement.actor_id AND privacy_class='private' AND redacted_at IS NULL))");
        $this->patch('media_recovery_allowed(uuid,text)', "'work_applicability','test'", "'work_applicability','test','fan_na'");
        $this->patch('refurbishment_insert_guard()', "NEW.kind IN ('test','work_applicability')", "NEW.kind IN ('test','work_applicability','fan_na')");
        // Correcting an old fact cannot skip explicit current applicability of retained work.
        $this->patch('refurbishment_insert_guard()', "IF NEW.kind='test' THEN", <<<'SQL'
IF NEW.kind='test' AND EXISTS(
 SELECT 1 FROM restriction_records corrected_review JOIN restriction_cases corrected_case ON corrected_case.id=corrected_review.case_id JOIN media_review_commits proof ON proof.record_id=corrected_review.id
 JOIN refurbishment_records retained_work ON retained_work.id=(SELECT id FROM refurbishment_records WHERE subject_id=NEW.subject_id AND kind='work' ORDER BY version DESC LIMIT 1)
 JOIN restriction_epoch_steps effect_step ON effect_step.record_id=corrected_review.id AND effect_step.subject_id=NEW.subject_id
 WHERE corrected_case.subject_id=NEW.subject_id AND corrected_case.contract='P15' AND corrected_review.kind='approve' AND effect_step.version>COALESCE((SELECT version FROM restriction_stamps WHERE kind='refurbishment_records' AND record_id=retained_work.id AND subject_id=NEW.subject_id),0)
 AND (corrected_case.fact_key IN ('association_confirm','association_end','work_configuration','work_outcome','return_report') OR corrected_case.fact_key LIKE 'test\_%'))
 AND (SELECT kind FROM refurbishment_records WHERE id=media_work_basis(NEW.subject_id)) IS DISTINCT FROM 'work_applicability'
 THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Review retained work applicability after the approved correction before recording a fresh complete test. Do not invent another repair.'; END IF;
 IF NEW.kind='test' THEN
SQL);
        $this->patch('downstream_record_guard()', "ERRCODE='P1500',MESSAGE='Discovery-origin downstream movement", "ERRCODE='P1300',MESSAGE='Discovery-origin downstream movement");
    }

    private function patch(string $signature, string $from, string $to): void
    {
        $body = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$signature])->body;
        $body = str_replace($from, $to, $body, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected correction control contract; inspect before migrating.');
        }
        DB::unprepared($body);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward correction migration.');
    }
};
