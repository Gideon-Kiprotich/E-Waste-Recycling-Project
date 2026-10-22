<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::select("SELECT conname FROM pg_constraint WHERE conrelid='restriction_cases'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%contract%'") as $constraint) {
            DB::statement('ALTER TABLE restriction_cases DROP CONSTRAINT "'.$constraint->conname.'"');
        }
        DB::statement("ALTER TABLE restriction_cases ADD CHECK(contract IN ('C14','P15','P16'))");
        $factCheck = DB::select("SELECT conname FROM pg_constraint WHERE conrelid='restriction_records'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%original_value IS NOT NULL%'");
        if (count($factCheck) !== 1) {
            throw new RuntimeException('Unexpected typed finding constraint; inspect before migrating.');
        }
        DB::statement('ALTER TABLE restriction_records DROP CONSTRAINT "'.$factCheck[0]->conname.'"');
        DB::statement("ALTER TABLE restriction_records ADD CHECK((kind='proposal')=(original_value IS NOT NULL AND proposed_value IS NOT NULL AND identity_code IS NOT NULL AND basis_ciphertext IS NOT NULL) OR kind='clarify')");
        DB::unprepared(file_get_contents(database_path('sql/downstream_review.sql')));
        $view = rtrim(DB::selectOne("SELECT pg_get_viewdef('restriction_sources'::regclass,true) AS body")->body, ";\n ");
        DB::unprepared('CREATE OR REPLACE VIEW restriction_sources AS '.$view.' UNION ALL SELECT * FROM downstream_correction_sources()');
        $this->prefix('restriction_case_guard()', "IF COALESCE(to_jsonb(NEW)->>'contract',to_jsonb(OLD)->>'contract')='P16' THEN PERFORM downstream_validate_case(NEW,OLD,TG_OP); RETURN NEW; END IF;");
        $this->prefix('restriction_record_guard()', "IF (SELECT contract FROM restriction_cases WHERE id=NEW.case_id)='P16' THEN PERFORM downstream_validate_record(NEW); RETURN NEW; END IF;");
        $this->prefix('restriction_record_guard()', "IF NEW.kind='clarify' AND (SELECT contract FROM restriction_cases WHERE id=NEW.case_id)<>'P16' AND (NEW.original_value IS NOT NULL OR NEW.proposed_value IS NOT NULL OR NEW.identity_code IS NOT NULL OR NEW.basis_ciphertext IS NOT NULL) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Linked technical fact signatures require a P16 contract.'; END IF;");
        $this->prefix('restriction_assignment_boundary()', <<<'SQL'
IF EXISTS(SELECT 1 FROM restriction_cases p16_case WHERE p16_case.id=NEW.case_id AND p16_case.contract='P16') THEN
 IF NEW.kind='assign' AND EXISTS(SELECT 1 FROM restriction_cases p16_case WHERE p16_case.id=NEW.case_id AND p16_case.grant_record_id=NEW.id AND (p16_case.assignment_context IS DISTINCT FROM restriction_actor_context(p16_case.assigned_to) OR NOT downstream_review_scope(p16_case.assigned_to,p16_case.subject_id) OR (NEW.details ? 'party_actor_id' AND NEW.details->'party_context' IS DISTINCT FROM restriction_actor_context((NEW.details->>'party_actor_id')::uuid)))) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Assigned downstream party scope changed before COMMIT.'; END IF;RETURN NULL;END IF;
SQL);
        $this->prefix('restriction_record_effects()', "IF (SELECT contract FROM restriction_cases WHERE id=NEW.case_id)='P16' THEN PERFORM downstream_review_begin(NEW); RETURN NULL; END IF;");
        foreach (['restriction_final()', 'restriction_dependency_final()'] as $signature) {
            $this->prefix($signature, <<<'SQL'
IF TG_TABLE_NAME IN ('restriction_cases','restriction_records','commands','domain_events') AND (TG_TABLE_NAME<>'commands' OR to_jsonb(NEW)->>'action' LIKE 'restriction_%') AND (TG_TABLE_NAME<>'domain_events' OR to_jsonb(NEW)->>'aggregate_kind'='restriction') AND EXISTS(SELECT 1 FROM restriction_cases p16_case WHERE p16_case.id=CASE WHEN TG_TABLE_NAME='restriction_cases' THEN (to_jsonb(NEW)->>'id')::uuid WHEN TG_TABLE_NAME='restriction_records' THEN (to_jsonb(NEW)->>'case_id')::uuid WHEN TG_TABLE_NAME='commands' THEN (SELECT p16_r.case_id FROM restriction_records p16_r WHERE p16_r.command_id=(to_jsonb(NEW)->>'id')::uuid) ELSE (SELECT p16_r.case_id FROM restriction_records p16_r WHERE p16_r.event_id=(to_jsonb(NEW)->>'id')::uuid) END AND p16_case.contract='P16') THEN
 PERFORM downstream_review_final(CASE WHEN TG_TABLE_NAME='restriction_cases' THEN (to_jsonb(NEW)->>'id')::uuid WHEN TG_TABLE_NAME='restriction_records' THEN (to_jsonb(NEW)->>'case_id')::uuid WHEN TG_TABLE_NAME='commands' THEN (SELECT p16_r.case_id FROM restriction_records p16_r WHERE p16_r.command_id=(to_jsonb(NEW)->>'id')::uuid) ELSE (SELECT p16_r.case_id FROM restriction_records p16_r WHERE p16_r.event_id=(to_jsonb(NEW)->>'id')::uuid) END);
 IF TG_TABLE_NAME='restriction_records' THEN PERFORM downstream_review_boundary((to_jsonb(NEW)->>'id')::uuid); END IF; RETURN NULL; END IF;
SQL);
        }
        $this->prefix('restriction_dependency_final()', <<<'SQL'
IF TG_TABLE_NAME='restriction_impacts' AND EXISTS(SELECT 1 FROM restriction_records p16_record JOIN restriction_cases p16_case ON p16_case.id=p16_record.case_id WHERE p16_record.id=(to_jsonb(NEW)->>'record_id')::uuid AND p16_case.contract='P16') THEN
 IF NOT EXISTS(SELECT 1 FROM downstream_review_effects p16_effect JOIN restriction_cases p16_case ON p16_case.id=p16_effect.case_id JOIN downstream_records p16_target ON p16_target.batch_id=p16_case.subject_id WHERE p16_effect.record_id=(to_jsonb(NEW)->>'record_id')::uuid AND (p16_target.event_id=(to_jsonb(NEW)->>'target_id')::uuid OR p16_target.physical_event_id=(to_jsonb(NEW)->>'target_id')::uuid) AND (to_jsonb(NEW)->>'subject_id')::uuid IN (p16_case.subject_id,p16_target.output_id)) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Downstream impact must reference the exact reviewed actual obligation.'; END IF;RETURN NULL;END IF;
SQL);
        // New review choices are not new physical facts. Keep legacy proof fingerprints stable
        // until a real approved P16 effect adds the explicit effective-accounting dependency.
        $this->patch('restriction_source_generation(uuid,uuid)', 'WHERE subject_id=ref AND', "WHERE source_kind<>'downstream_correction' AND subject_id=ref AND");
        $this->patch('restriction_dependency_context(uuid)', 'FROM restriction_sources WHERE subject_id=ref', "FROM restriction_sources WHERE source_kind<>'downstream_correction' AND subject_id=ref");
        DB::unprepared(<<<'SQL'
ALTER FUNCTION restriction_investigator(uuid,uuid) RENAME TO restriction_investigator_before_downstream;
CREATE FUNCTION restriction_investigator(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT CASE WHEN EXISTS(SELECT 1 FROM restriction_cases WHERE id=ref AND contract='P16') THEN downstream_case_access(ref,actor) ELSE restriction_investigator_before_downstream(ref,actor) END $$;
ALTER FUNCTION restriction_dependency_context(uuid) RENAME TO restriction_dependency_context_before_downstream;
CREATE FUNCTION restriction_dependency_context(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$ SELECT restriction_dependency_context_before_downstream(ref)||CASE WHEN EXISTS(SELECT 1 FROM downstream_fact_revisions f JOIN restriction_records r ON r.id=f.review_id JOIN restriction_cases c ON c.id=r.case_id WHERE c.subject_id IN(SELECT subject_id FROM restriction_family(ref))) OR EXISTS(SELECT 1 FROM downstream_recipient_records r JOIN downstream_shipments h ON h.id=r.shipment_id WHERE h.batch_id IN(SELECT subject_id FROM restriction_family(ref))) THEN jsonb_build_object('downstream_effective',(SELECT jsonb_agg(jsonb_build_array(b.subject_id,downstream_accounting_context(b.subject_id)) ORDER BY b.subject_id) FROM dismantling_batches b WHERE b.subject_id IN(SELECT subject_id FROM restriction_family(ref)))) ELSE '{}'::jsonb END $$;
ALTER FUNCTION downstream_context(uuid,uuid,uuid) RENAME TO downstream_context_before_corrections;
CREATE FUNCTION downstream_context(ref uuid,actor uuid,shipment uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$ SELECT downstream_context_before_corrections(ref,actor,shipment)||jsonb_build_object('effective_accounting',downstream_accounting_context(ref)) $$;
SQL);
        foreach (['downstream_reserved(uuid)', 'downstream_remaining(uuid)', 'downstream_shipment_quantities(uuid)', 'downstream_profile_ready(uuid)', 'downstream_preparation_ready(uuid)', 'downstream_context_before_corrections(uuid,uuid,uuid)', 'downstream_record_guard()', 'downstream_integrity(uuid)', 'downstream_receipt_matches(uuid,uuid,uuid,bigint)', 'downstream_authorization_applicable(media_authorizations)'] as $signature) {
            $body = $this->body($signature);
            $body = preg_replace('/\b(FROM|JOIN) downstream_records\b/', '$1 downstream_effective_records', $body);
            $body = preg_replace('/\b(FROM|JOIN) downstream_shipments\b/', '$1 downstream_effective_shipments', $body);
            DB::unprepared($body);
        }
        $this->patch('downstream_preparation_ready(uuid)', 'SELECT downstream_profile_ready(ref)', "SELECT COALESCE((SELECT origin.context->'effective_accounting' FROM downstream_records origin JOIN downstream_shipments h ON h.created_record_id=origin.id WHERE h.id=ref),' {\"facts\":[],\"recipients\":[]}'::jsonb)=downstream_accounting_context((SELECT batch_id FROM downstream_shipments WHERE id=ref)) AND downstream_profile_ready(ref)");
        $this->patch('downstream_record_guard()', "IF NEW.kind='complete' AND related.condition='damaged' THEN", "IF NEW.kind='complete' AND related.condition='damaged' AND downstream_damage_outcome(related.id) IS DISTINCT FROM NEW.outcome THEN");
        $this->patch('downstream_record_guard()', 'NOT batch_control_ready(NEW.batch_id,NEW.actor_id) OR', 'NOT batch_control_ready(NEW.batch_id,NEW.actor_id) OR');
        $this->patch('downstream_record_guard()', "OR out.type='storage' OR", 'OR');
        $this->patch('downstream_record_guard()', "CASE WHEN NEW.kind='complete' THEN 'accept' ELSE 'dispatch' END", "CASE WHEN NEW.kind='complete' OR (NEW.kind='downstream_loss' AND related.kind='accept') THEN 'accept' ELSE 'dispatch' END");
        $this->patch('downstream_record_guard()', 'IF NEW.canonical AND NEW.kg IS NOT NULL AND NEW.kg<>h.requested_kg', "IF NEW.canonical AND NEW.kg IS NOT NULL AND NEW.kg<>COALESCE(h.requested_kg,(downstream_stock(NEW.output_id)->>'produced')::numeric)");
        $this->prefix('downstream_record_guard()', "IF NEW.kind='source_loss' AND NEW.canonical AND (NEW.kg IS DISTINCT FROM (downstream_stock(NEW.output_id)->>'produced')::numeric OR EXISTS(SELECT 1 FROM downstream_records WHERE output_id=NEW.output_id AND kind='dispatch')) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Media source incident must identify the whole available unit; no movement or data clearance follows.'; END IF;");
        // Preparation and current facts share the same measurement resolver.
        $body = preg_replace('/\b(FROM|JOIN) batch_records\b/', '$1 downstream_effective_measurements', $this->body('batch_balance(uuid)'));
        DB::unprepared($body);
    }

    private function body(string $signature): string
    {
        return DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$signature])->body;
    }

    private function patch(string $signature, string $from, string $to): void
    {
        $body = str_replace($from, $to, $this->body($signature), $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected existing downstream contract: '.$signature);
        }
        DB::unprepared($body);
    }

    private function prefix(string $signature, string $body): void
    {
        $definition = preg_replace('/\bBEGIN\s*\n/', "BEGIN\n".$body."\n", $this->body($signature), 1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected existing downstream guard: '.$signature);
        }
        DB::unprepared($definition);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain downstream corrections; use forward migrations.');
    }
};
