<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER FUNCTION batch_balance(uuid) RENAME TO batch_balance_before_downstream_corrections');
        DB::unprepared(file_get_contents(database_path('sql/downstream_correction_controls.sql')));
        DB::unprepared(<<<'SQL'
CREATE FUNCTION batch_balance(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$ SELECT batch_balance_before_downstream_corrections(ref)||CASE WHEN batch_balance_before_downstream_corrections(ref)->>'status'='reconciled_on_site' AND NOT downstream_reconciliation_current(ref) THEN '{"status":"pending_accounting"}'::jsonb ELSE '{}'::jsonb END $$;
ALTER FUNCTION downstream_integrity(uuid) RENAME TO downstream_integrity_before_corrections;
CREATE FUNCTION downstream_integrity(ref uuid) RETURNS void LANGUAGE plpgsql AS $$ BEGIN PERFORM downstream_integrity_before_corrections(ref);PERFORM downstream_revision_integrity(ref);END; $$;
ALTER FUNCTION media_impact_fulfilled(uuid) RENAME TO media_impact_fulfilled_before_downstream;
CREATE FUNCTION media_impact_fulfilled(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT media_impact_fulfilled_before_downstream(ref) OR downstream_impact_fulfilled(ref) $$;
ALTER FUNCTION media_recovery_allowed(uuid,text) RENAME TO media_recovery_allowed_before_downstream;
CREATE FUNCTION media_recovery_allowed(ref uuid,operation text) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT media_recovery_allowed_before_downstream(ref,operation) OR (operation IN ('authorization','assurance_review','accounting') AND NOT EXISTS(SELECT 1 FROM restriction_impacts i WHERE i.subject_id IN(SELECT subject_id FROM restriction_family(ref)) AND NOT media_impact_fulfilled(i.id) AND NOT downstream_continuity(i.id))) $$;
SQL);
        $this->prefix('downstream_command_final()', <<<'SQL'
IF TG_TABLE_NAME='commands' AND to_jsonb(NEW)->>'action' LIKE 'downstream_recipient_%' THEN
 IF NOT EXISTS(SELECT 1 FROM downstream_recipient_records generation WHERE generation.command_id=(to_jsonb(NEW)->>'id')::uuid AND generation.kind IN ('accepted','cancelled')) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Responsibility command requires its exact reciprocal generation.'; END IF;RETURN NULL;END IF;
IF TG_TABLE_NAME='domain_events' AND to_jsonb(NEW)->>'aggregate_kind'='downstream_recipient' THEN
 IF NOT EXISTS(SELECT 1 FROM downstream_recipient_records generation WHERE generation.event_id=(to_jsonb(NEW)->>'id')::uuid) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Responsibility event requires its exact reciprocal generation.'; END IF;RETURN NULL;END IF;
SQL);
        $this->prefix('media_impact_review_guard()', <<<'SQL'
IF EXISTS(SELECT 1 FROM restriction_records p16_record JOIN restriction_cases p16_case ON p16_case.id=p16_record.case_id WHERE p16_record.id=NEW.record_id AND p16_case.contract='P16') THEN
 IF pg_trigger_depth()<2 OR NOT EXISTS(SELECT 1 FROM restriction_records p16_record JOIN restriction_cases p16_case ON p16_case.id=p16_record.case_id WHERE p16_record.id=NEW.record_id AND p16_case.source_id=NEW.impact_id AND p16_case.fact_key='downstream_downstream_continuity' AND NEW.stage=CASE p16_record.kind WHEN 'approve' THEN 'continuity' WHEN 'fulfill' THEN 'fulfilled' END AND NEW.epoch=restriction_epoch(p16_case.subject_id) AND NEW.context=downstream_obligation_context(p16_case.subject_id,p16_case.source_id)) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='The exact downstream obligation proof is required without another epoch bump.'; END IF;RETURN NEW;END IF;
SQL);
        $this->prefix('downstream_record_guard()', <<<'SQL'
IF NEW.kind='accept' AND NOT downstream_ack_allowed(NEW.batch_id,NEW.related_record_id,NEW.actor_id) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Review exact downstream continuity and outstanding claim permission before actual acknowledgment.'; END IF;
IF NEW.kind IN ('complete','dispatch') AND restriction_dependent(NEW.batch_id) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Current downstream obligations require independent fulfillment; no completion or dispatch authority follows from continuity alone.'; END IF;
SQL);
        $this->patch('downstream_profile_ready(uuid)', "origin.context->'destination'->'access'=downstream_access(h.receiver_actor_id,pr.facility_party_id)", "(origin.context->'destination'->'access'=downstream_access(h.receiver_actor_id,pr.facility_party_id) OR downstream_recipient_authority(h.id))");
        $this->patch('batch_record_insert_guard()', 'NOT batch_control_ready(b.subject_id,NEW.actor_id)', "NOT (batch_control_ready(b.subject_id,NEW.actor_id) OR (NEW.kind='accounted' AND downstream_accounting_allowed(b.subject_id,NEW.actor_id)))");
        $this->prefix('restriction_case_guard()', <<<'SQL'
IF TG_OP='INSERT' AND NEW.source_kind IN ('downstream','downstream_correction') THEN
 PERFORM 1 FROM subjects WHERE id=NEW.subject_id FOR UPDATE;
 IF EXISTS(SELECT 1 FROM restriction_cases existing_case WHERE existing_case.active AND existing_case.source_id=NEW.source_id AND existing_case.source_kind IN ('downstream','downstream_correction')) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='An equivalent investigation already claims this downstream source.'; END IF;END IF;
SQL);
    }

    private function patch(string $signature, string $from, string $to): void
    {
        $body = str_replace($from, $to, DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$signature])->body, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected downstream accounting boundary: '.$signature);
        }
        DB::unprepared($body);
    }

    private function prefix(string $signature, string $body): void
    {
        $definition = preg_replace('/\bBEGIN\s*\n/', "BEGIN\n".$body."\n", DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$signature])->body, 1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected downstream accounting guard: '.$signature);
        }
        DB::unprepared($definition);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain accounting history; use forward migrations.');
    }
};
