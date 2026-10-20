<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION restriction_source_generation(ref uuid,except_review uuid DEFAULT NULL) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT COALESCE(jsonb_agg(jsonb_build_array(source_kind,source_id,fact_key,original_value,source_version) ORDER BY source_kind,source_id,fact_key),'[]') FROM restriction_sources
 WHERE subject_id=ref AND (except_review IS NULL OR source_kind<>'dependent' OR NOT EXISTS(SELECT 1 FROM restriction_impacts i WHERE i.id=source_id AND i.record_id=except_review))
$$;
SQL);
        $this->patch('restriction_final()', "NEW.context->'sources' IS DISTINCT FROM restriction_dependency_context(c.subject_id)->'sources'", "NEW.context->'sources' IS DISTINCT FROM restriction_source_generation(c.subject_id,NEW.id)");
        $this->patch('restriction_dependency_final()', 'restriction_supersessions s JOIN restriction_cases c ON c.id=s.case_id JOIN domain_events e ON e.id=NEW.target_id WHERE s.record_id=NEW.record_id AND NEW.subject_id IN(SELECT subject_id FROM restriction_family(c.subject_id))', 'restriction_supersessions s JOIN restriction_cases source_case ON source_case.id=s.case_id JOIN domain_events e ON e.id=NEW.target_id WHERE s.record_id=NEW.record_id AND NEW.subject_id IN(SELECT subject_id FROM restriction_family(source_case.subject_id))');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION restriction_assignment_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c restriction_cases;
BEGIN
 IF NEW.kind='assign' THEN
 SELECT * INTO c FROM restriction_cases WHERE id=NEW.case_id;
 IF c.grant_record_id=NEW.id AND (c.assignment_context IS DISTINCT FROM restriction_actor_context(c.assigned_to) OR NOT (restriction_staff(c.assigned_to,c.subject_id) OR (NOT c.supported AND restriction_admin(c.assigned_to)))) THEN RAISE EXCEPTION 'Assigned scope changed before commit'; END IF;
 END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER restriction_assignment_complete AFTER INSERT ON restriction_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restriction_assignment_boundary();
SQL);
    }

    private function patch(string $signature, string $from, string $to): void
    {
        $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS definition', [$signature])->definition;
        $definition = str_replace($from, $to, $definition, $count);
        if ($count !== 1) {
            throw new RuntimeException('Restriction impact upgrade blocked: unexpected dependency guard.');
        }
        DB::unprepared($definition);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward restriction migration.');
    }
};
