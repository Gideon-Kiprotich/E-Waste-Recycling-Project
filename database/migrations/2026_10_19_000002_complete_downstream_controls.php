<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->patch('downstream_profile_final()', "ref:=CASE WHEN TG_TABLE_NAME='output_profiles' THEN NEW.id ELSE NEW.profile_id END;", "ref:=COALESCE((to_jsonb(NEW)->>'profile_id')::uuid,(to_jsonb(NEW)->>'id')::uuid);");
        $this->patch('sanitization_insert_guard()', 'IF NEW.authorization_context_version NOT IN (3,4)', 'IF NEW.authorization_context_version IS DISTINCT FROM (SELECT authorization_context_version FROM media_authorizations WHERE id=NEW.authorization_id)', false);
        // The preceding migration retains the original context-3 branch until this extension is installed.
        $this->patch('sanitization_insert_guard()', 'IF NEW.authorization_context_version<>3', 'IF NEW.authorization_context_version IS DISTINCT FROM (SELECT authorization_context_version FROM media_authorizations WHERE id=NEW.authorization_id)', false);
        DB::unprepared(<<<'SQL'
CREATE FUNCTION downstream_projection_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Downstream projections cannot be deleted'; END IF;
 IF (to_jsonb(NEW)-ARRAY['version','record_id','closure_record_id']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['version','record_id','closure_record_id']) OR NEW.version<>OLD.version+1 OR ((to_jsonb(OLD)->>'closure_record_id') IS NOT NULL AND (to_jsonb(NEW)->>'closure_record_id') IS DISTINCT FROM (to_jsonb(OLD)->>'closure_record_id')) THEN RAISE EXCEPTION 'Downstream projection identity, version and closure must retain ledger'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER downstream_projection BEFORE UPDATE OR DELETE ON downstream_batches FOR EACH ROW EXECUTE FUNCTION downstream_projection_guard();
CREATE TRIGGER output_profile_projection BEFORE UPDATE OR DELETE ON output_profiles FOR EACH ROW EXECUTE FUNCTION downstream_projection_guard();
CREATE FUNCTION downstream_measurement_allowed(ref uuid, target uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT NOT downstream_closed(ref) AND NOT EXISTS(SELECT 1 FROM downstream_records WHERE batch_id=ref AND kind IN ('dispatch','source_loss') AND (target=ref OR output_id=target)) AND NOT EXISTS(SELECT 1 FROM downstream_shipments h WHERE h.batch_id=ref AND (target=ref OR output_id=target) AND downstream_reserved(h.id)>0)
$$;
CREATE OR REPLACE FUNCTION downstream_measurement_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF downstream_closed(NEW.batch_id) AND NEW.kind<>'discrepancy' THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Closed production history cannot be changed; record a later evidence dispute.'; END IF;
 IF NEW.kind='measurement' AND NOT downstream_measurement_allowed(NEW.batch_id,NEW.target_id) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Withdraw affected reservations before correction; after dispatch/loss record a blocking measurement dispute.'; END IF;
 RETURN NEW;
END; $$;
CREATE FUNCTION downstream_successor_final() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.authorization_context_version=4 AND NOT downstream_authorization_applicable(NEW) THEN RAISE EXCEPTION 'Successor authorization dependencies changed before commit'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER downstream_successor_complete AFTER INSERT ON media_authorizations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION downstream_successor_final();
SQL);
        $this->patch('downstream_integrity(uuid)', 'AND NOT downstream_receipt_matches(r.output_id,', 'AND NOT downstream_receipt_matches(r.output_id,', false);
    }

    private function patch(string $function, string $search, string $replace, bool $required = true): void
    {
        $body = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$function])->body;
        $body = str_replace($search, $replace, $body, $count);
        if ($required && $count !== 1) {
            throw new RuntimeException('Unexpected downstream control: '.$function);
        }
        if ($count) {
            DB::unprepared($body);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retained movement and assurance controls require forward correction.');
    }
};
