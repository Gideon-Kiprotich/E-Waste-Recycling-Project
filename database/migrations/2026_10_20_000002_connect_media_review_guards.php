<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/media_correction_guards.sql')));
        $view = rtrim(DB::selectOne("SELECT pg_get_viewdef('restriction_sources'::regclass,true) AS definition")->definition, ";\n ");
        DB::unprepared('CREATE OR REPLACE VIEW restriction_sources AS '.$view.' UNION ALL SELECT * FROM media_correction_sources()');
        $this->prefix('restriction_case_guard()', <<<'SQL'
IF COALESCE(to_jsonb(NEW)->>'contract',to_jsonb(OLD)->>'contract')='P15' THEN PERFORM media_validate_case(NEW,OLD,TG_OP); RETURN NEW; END IF;
IF TG_OP='INSERT' AND EXISTS(SELECT 1 FROM restriction_cases c WHERE c.active AND c.contract='P15' AND media_source_scope(c.source_kind,c.source_id)=media_source_scope(NEW.source_kind,NEW.source_id)) THEN RAISE EXCEPTION 'An equivalent typed case already claims this source'; END IF;
SQL);
        $this->prefix('restriction_record_guard()', "IF (SELECT contract FROM restriction_cases WHERE id=NEW.case_id)='P15' THEN PERFORM media_validate_record(NEW); RETURN NEW; END IF;\nIF NEW.kind IN ('owner_clarify','fulfill') THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Typed owner clarification and fulfillment require a P15 contract.'; END IF;\nIF NEW.details<>'{}'::jsonb OR NEW.private_details_ciphertext IS NOT NULL THEN RAISE EXCEPTION 'Legacy investigations do not accept typed effects'; END IF;");
        $this->prefix('restriction_record_effects()', "IF (SELECT contract FROM restriction_cases WHERE id=NEW.case_id)='P15' THEN PERFORM media_begin_review(NEW); RETURN NULL; END IF;");
        $this->prefix('restriction_supersession_guard()', <<<'SQL'
IF (SELECT contract FROM restriction_cases WHERE id=NEW.case_id)='P15' THEN
IF pg_trigger_depth()<2 OR NOT EXISTS(SELECT 1 FROM restriction_records review_row JOIN restriction_records proposal_row ON proposal_row.id=review_row.proposal_id JOIN restriction_cases case_row ON case_row.id=review_row.case_id
WHERE review_row.id=NEW.record_id AND review_row.kind='approve' AND case_row.id=NEW.case_id AND proposal_row.id=NEW.proposal_id AND case_row.subject_id=NEW.subject_id AND case_row.source_kind=NEW.source_kind AND case_row.source_id=NEW.source_id AND case_row.fact_key=NEW.fact_key AND case_row.original_value=NEW.original_value AND proposal_row.proposed_value=NEW.proposed_value AND review_row.actor_id<>proposal_row.actor_id) THEN RAISE EXCEPTION 'Only exact independent typed approval may create effective facts'; END IF;
RETURN NEW; END IF;
SQL);
        foreach (['restriction_final()', 'restriction_dependency_final()'] as $signature) {
            $this->prefix($signature, <<<'SQL'
IF TG_TABLE_NAME IN ('restriction_cases','restriction_records') AND EXISTS(SELECT 1 FROM restriction_cases typed_case WHERE typed_case.id=CASE WHEN TG_TABLE_NAME='restriction_cases' THEN (to_jsonb(NEW)->>'id')::uuid ELSE (to_jsonb(NEW)->>'case_id')::uuid END AND typed_case.contract='P15') THEN
PERFORM media_review_final(CASE WHEN TG_TABLE_NAME='restriction_cases' THEN (to_jsonb(NEW)->>'id')::uuid ELSE (to_jsonb(NEW)->>'case_id')::uuid END);
IF TG_TABLE_NAME='restriction_records' THEN PERFORM media_review_boundary((to_jsonb(NEW)->>'id')::uuid); END IF; RETURN NULL; END IF;
SQL);
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION media_review_boundary(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE r restriction_records;p restriction_records;c restriction_cases;
BEGIN
 SELECT * INTO r FROM restriction_records WHERE id=ref;SELECT * INTO c FROM restriction_cases WHERE id=r.case_id;
 IF r.kind='proposal' AND (r.actor_context<>restriction_actor_context(r.actor_id) OR NOT media_review_scope(r.actor_id,c.subject_id) OR r.grant_record_id IS DISTINCT FROM c.grant_record_id) THEN RAISE EXCEPTION 'Technical scope changed before review commit'; END IF;
 IF r.kind IN ('approve','fulfill') THEN
 SELECT * INTO p FROM restriction_records WHERE id=r.proposal_id;
 IF EXISTS(SELECT 1 FROM restriction_records cited WHERE cited.case_id=c.id AND cited.kind='proposal' AND (cited.actor_id=r.actor_id OR cited.actor_context<>restriction_actor_context(cited.actor_id) OR NOT media_review_scope(cited.actor_id,c.subject_id))) THEN RAISE EXCEPTION 'Cited technical authors changed scope before COMMIT'; END IF;
 IF c.fact_key='return_report' AND EXISTS(SELECT 1 FROM restriction_records statement WHERE statement.id=(SELECT id FROM restriction_records WHERE case_id=c.id AND kind='owner_clarify' ORDER BY version DESC LIMIT 1) AND statement.actor_context<>restriction_actor_context(statement.actor_id)) THEN RAISE EXCEPTION 'Original owner clarification changed before COMMIT'; END IF;
 IF NOT restriction_admin(r.actor_id) OR r.actor_context<>restriction_actor_context(r.actor_id) OR p.actor_context<>restriction_actor_context(p.actor_id) OR NOT media_review_scope(p.actor_id,c.subject_id)
 OR (SELECT context FROM media_review_commits WHERE record_id=r.id) IS DISTINCT FROM restriction_dependency_context(c.subject_id) THEN RAISE EXCEPTION 'Typed correction effects, reviewer or investigator changed before COMMIT'; END IF;
 END IF;
END; $$;
CREATE FUNCTION media_review_effect_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' OR NOT EXISTS(SELECT 1 FROM restriction_records r JOIN restriction_records p ON p.id=r.proposal_id JOIN restriction_cases c ON c.id=r.case_id WHERE r.id=NEW.record_id AND r.case_id=NEW.case_id AND p.id=NEW.proposal_id AND p.actor_id=NEW.observer_id AND r.kind IN ('approve','fulfill') AND c.contract='P15' AND c.fact_key=NEW.action) THEN RAISE EXCEPTION 'Domain effect requires its exact independent typed review'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER media_review_effect_valid BEFORE INSERT OR UPDATE OR DELETE ON media_review_effects FOR EACH ROW EXECUTE FUNCTION media_review_effect_guard();
CREATE FUNCTION media_impact_review_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF pg_trigger_depth()<2 OR NOT EXISTS(SELECT 1 FROM restriction_records review_row JOIN restriction_cases case_row ON case_row.id=review_row.case_id WHERE review_row.id=NEW.record_id AND case_row.contract='P15' AND case_row.fact_key='impact_continuity' AND case_row.source_id=NEW.impact_id AND review_row.kind IN ('approve','fulfill') AND NEW.stage=(CASE review_row.kind WHEN 'fulfill' THEN 'fulfilled' ELSE 'continuity' END) AND NEW.epoch=restriction_epoch(case_row.subject_id) AND NEW.context=media_obligation_context(case_row.subject_id,NEW.impact_id)) THEN RAISE EXCEPTION 'Obligation proof can only project its exact newly recorded typed review'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER media_impact_review_valid BEFORE INSERT ON restriction_impact_reviews FOR EACH ROW EXECUTE FUNCTION media_impact_review_guard();
CREATE TRIGGER media_impact_reviews_retained BEFORE UPDATE OR DELETE ON restriction_impact_reviews FOR EACH ROW EXECUTE FUNCTION restriction_append_only();
CREATE TRIGGER batch_discoveries_retained BEFORE UPDATE OR DELETE ON batch_media_discoveries FOR EACH ROW EXECUTE FUNCTION restriction_append_only();
SQL);
    }

    private function prefix(string $signature, string $body): void
    {
        $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS definition', [$signature])->definition;
        $definition = preg_replace('/\bBEGIN\s*\n/', "BEGIN\n".$body."\n", $definition, 1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Typed correction migration blocked: unexpected preceding trigger.');
        }
        DB::unprepared($definition);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward media correction migration.');
    }
};
