<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE restriction_cases IN SHARE ROW EXCLUSIVE MODE');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION media_case_claim(kind text,ref uuid,fact text) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN kind IN ('media_correction','inventory','work','batch','dependent') THEN media_source_scope(kind,ref) ELSE kind||':'||ref::text||':'||fact END
$$;
SQL);
        if (DB::selectOne('SELECT count(*) AS n FROM (SELECT media_case_claim(source_kind,source_id,fact_key) FROM restriction_cases WHERE active GROUP BY 1 HAVING count(*)>1) duplicates')->n) {
            throw new RuntimeException('Correction claim migration blocked by existing equivalent active investigations; no survivor or history was changed.');
        }
        // Index existing claim metadata separately; do not rewrite retained case records.
        DB::unprepared(<<<'SQL'
CREATE TABLE restriction_source_claims (case_id uuid PRIMARY KEY REFERENCES restriction_cases(id),scope text NOT NULL,active boolean NOT NULL);
INSERT INTO restriction_source_claims SELECT id,media_case_claim(source_kind,source_id,fact_key),active FROM restriction_cases;
CREATE UNIQUE INDEX restriction_one_active_source_claim ON restriction_source_claims(scope) WHERE active;
CREATE FUNCTION media_case_claim_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR pg_trigger_depth()<2 OR NOT EXISTS(SELECT 1 FROM restriction_cases c WHERE c.id=NEW.case_id AND c.active=NEW.active AND media_case_claim(c.source_kind,c.source_id,c.fact_key)=NEW.scope)
 OR (TG_OP='UPDATE' AND (NEW.case_id<>OLD.case_id OR NEW.scope<>OLD.scope)) THEN RAISE EXCEPTION 'Source claim must project its retained case without rewriting identity'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER media_case_claim_valid BEFORE INSERT OR UPDATE OR DELETE ON restriction_source_claims FOR EACH ROW EXECUTE FUNCTION media_case_claim_guard();
CREATE FUNCTION media_case_claim_capture() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 INSERT INTO restriction_source_claims VALUES(NEW.id,media_case_claim(NEW.source_kind,NEW.source_id,NEW.fact_key),NEW.active) ON CONFLICT(case_id) DO UPDATE SET active=EXCLUDED.active;
 RETURN NULL;
END; $$;
CREATE TRIGGER media_case_claim_capture AFTER INSERT OR UPDATE ON restriction_cases FOR EACH ROW EXECUTE FUNCTION media_case_claim_capture();
CREATE FUNCTION media_case_claim_final() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM restriction_source_claims claim JOIN restriction_cases c ON c.id=claim.case_id WHERE c.id=NEW.id AND claim.active=c.active AND claim.scope=media_case_claim(c.source_kind,c.source_id,c.fact_key)) THEN RAISE EXCEPTION 'A retained case needs its exact effective source claim'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER media_case_claim_complete AFTER INSERT OR UPDATE ON restriction_cases DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_case_claim_final();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward correction migration.');
    }
};
