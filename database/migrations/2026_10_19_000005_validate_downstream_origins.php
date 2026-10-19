<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION downstream_header_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE out batch_outputs; b dismantling_batches; cc custody_current; sv bigint; d batch_detachments;
BEGIN
 SELECT * INTO out FROM batch_outputs WHERE subject_id=NEW.output_id;
 SELECT * INTO b FROM dismantling_batches WHERE subject_id=NEW.batch_id;
 IF out.batch_id IS DISTINCT FROM NEW.batch_id THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Shipment output must belong to its original batch.'; END IF;
 IF out.type='storage' THEN
 SELECT * INTO cc FROM custody_current WHERE subject_id=out.subject_id;
 SELECT version INTO sv FROM subjects WHERE id=out.subject_id;
 SELECT * INTO d FROM batch_detachments WHERE subject_id=out.subject_id;
 IF d.batch_id IS DISTINCT FROM b.subject_id OR cc.party_id IS DISTINCT FROM b.facility_party_id OR NEW.source_subject_version IS DISTINCT FROM sv OR NEW.source_custody_version IS DISTINCT FROM cc.version OR NEW.source_event_id IS DISTINCT FROM cc.last_event_id OR NEW.source_subject_version IS DISTINCT FROM d.source_subject_version+1 OR NEW.source_custody_version IS DISTINCT FROM d.source_custody_version OR NEW.source_event_id IS DISTINCT FROM d.source_custody_event_id THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Canonical shipment origin must freeze the actual detached identity and confirmed source custody.'; END IF;
 ELSIF NEW.source_subject_version IS NOT NULL OR NEW.source_custody_version IS NOT NULL OR NEW.source_event_id IS NOT NULL THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Aggregate shipment cannot invent item custody or physical identity history.'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER downstream_header BEFORE INSERT ON downstream_shipments FOR EACH ROW EXECUTE FUNCTION downstream_header_guard();
SQL);
        $this->patch('downstream_profile_ready(uuid)', "AND origin.context->'source'->'facility_version'=batch_access(h.sender_actor_id,b.facility_party_id)->'facility_version'
 AND origin.context->'source'->'operator_version'=batch_access(h.sender_actor_id,b.facility_party_id)->'operator_version'
 AND origin.context->'source'->'capabilities'=batch_access(h.sender_actor_id,b.facility_party_id)->'capabilities'
 AND origin.context->'batch_record'=to_jsonb(b.record_id)
 AND origin.context->'measurements'=(SELECT jsonb_agg(jsonb_build_array(subject_id,measurement_record_id) ORDER BY subject_id) FROM batch_outputs WHERE batch_id=b.subject_id)", '');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION downstream_preparation_ready(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT downstream_profile_ready(ref) AND EXISTS(SELECT 1 FROM downstream_shipments h JOIN dismantling_batches b ON b.subject_id=h.batch_id JOIN downstream_records origin ON origin.id=h.created_record_id WHERE h.id=ref
 AND origin.context->'source'->'facility_version'=batch_access(h.sender_actor_id,b.facility_party_id)->'facility_version'
 AND origin.context->'source'->'operator_version'=batch_access(h.sender_actor_id,b.facility_party_id)->'operator_version'
 AND origin.context->'source'->'capabilities'=batch_access(h.sender_actor_id,b.facility_party_id)->'capabilities'
 AND origin.context->'batch_record'=to_jsonb(b.record_id)
 AND origin.context->'measurements'=(SELECT jsonb_agg(jsonb_build_array(subject_id,measurement_record_id) ORDER BY subject_id) FROM batch_outputs WHERE batch_id=b.subject_id))
$$;
SQL);
        $this->patch('downstream_record_guard()', "IF NEW.kind='reserve' AND (", "IF NEW.kind IN ('accept','complete') AND batch_balance(NEW.batch_id)->>'status'<>'reconciled_on_site' THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Current source output accounting needs fresh complete reconciliation before acceptance/completion. Ask source staff to review accounting; actual dispatch history is retained.'; END IF; IF NEW.kind IN ('reserve','dispatch') AND NOT downstream_preparation_ready(h.id) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Source preparation or accounting changed. Withdraw undispatched reservations and prepare a fresh shipment; actual dispatched history remains.'; END IF; IF NEW.kind='reserve' AND (");
        $this->patch('downstream_profile_guard()', 'RETURN NEW;', "IF NOT EXISTS(SELECT 1 FROM parties p JOIN places l ON l.id=p.place_id WHERE p.id=NEW.facility_party_id AND l.kind='facility') OR (NEW.attachment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attachments a WHERE a.id=NEW.attachment_id AND a.uploaded_by=NEW.actor_id AND a.privacy_class='private')) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Acceptance reviews require a receiving facility and private evidence belonging to the reviewing actor.'; END IF; RETURN NEW;");
        $this->patch('downstream_record_guard()', "IF NEW.kind='complete' AND (", "IF NEW.kind='complete' AND related.condition='damaged' THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Damaged acceptance needs reviewed resolution before completion; that resolution workflow is unavailable.'; END IF; IF NEW.kind='complete' AND (");
    }

    private function patch(string $function, string $search, string $replace): void
    {
        $body = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$function])->body;
        $body = str_replace($search, $replace, $body, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected downstream final guard: '.$function);
        }
        DB::unprepared($body);
    }

    public function down(): void
    {
        throw new RuntimeException('Keep reciprocal shipment and safety protections.');
    }
};
