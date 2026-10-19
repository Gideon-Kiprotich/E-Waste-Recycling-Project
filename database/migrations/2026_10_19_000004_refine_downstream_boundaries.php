<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE downstream_records ADD COLUMN identity_code text');
        $this->patch('downstream_record_guard()', "IF NEW.kind='complete' AND (", "IF (NEW.kind='accept')<>(NEW.identity_code IS NOT NULL) OR (NEW.kind='accept' AND NEW.identity_code IS DISTINCT FROM (SELECT public_code FROM subjects WHERE id=NEW.output_id)) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='The actual QR/manual code must match this shipment. Recheck the label; other records are not disclosed.'; END IF; IF NEW.kind='complete' AND (");
        $this->patch('downstream_record_guard()', "IF NEW.kind='reserve' AND (", "IF NEW.assignee_id IS DISTINCT FROM COALESCE(h.receiver_actor_id,NEW.actor_id) OR (NEW.attachment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attachments a WHERE a.id=NEW.attachment_id AND a.uploaded_by=NEW.actor_id AND a.privacy_class='private')) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Private movement evidence and accountable assignee must match this action.'; END IF; IF NEW.kind='reserve' AND (");
        DB::statement('ALTER TABLE output_profile_records ALTER CONSTRAINT output_profile_records_profile_id_fkey DEFERRABLE INITIALLY DEFERRED');
        $this->patch('receipt_integrity()', "JOIN items i ON i.subject_id=d.subject_id WHERE d.subject_id=ref AND i.intake_class='processor_derived'", "JOIN items derived_item ON derived_item.subject_id=d.subject_id WHERE d.subject_id=ref AND derived_item.intake_class='processor_derived'");
        $this->patch('downstream_record_guard()', "IF NEW.kind IN ('accept','refuse','shortage','dispute','complete','downstream_loss') THEN", "IF NEW.kind IN ('accept','refuse','shortage','dispute','complete','downstream_loss') OR (NEW.kind='measurement_dispute' AND NEW.shipment_id IS NOT NULL) THEN");
        $this->patch('downstream_record_guard()', "IF (NEW.kind IN ('draft','reserve','withdraw','dispatch','accept','refuse','shortage','dispute','complete','downstream_loss'))<>(NEW.shipment_id IS NOT NULL)", "IF (NEW.kind<>'measurement_dispute' AND (NEW.kind IN ('draft','reserve','withdraw','dispatch','accept','refuse','shortage','dispute','complete','downstream_loss'))<>(NEW.shipment_id IS NOT NULL))");
        $this->patch('downstream_record_guard()', 'h.batch_id<>NEW.batch_id OR h.output_id IS DISTINCT FROM NEW.output_id', "h.batch_id<>NEW.batch_id OR (NEW.kind<>'measurement_dispute' AND h.output_id IS DISTINCT FROM NEW.output_id)");
        $this->patch('downstream_record_guard()', "('draft','reserve','dispatch','source_loss','close')", "('draft','reserve','dispatch','close')");
        $this->patch('downstream_record_guard()', "IF NEW.kind='source_loss' AND (out.type='storage' OR", "IF NEW.kind='source_loss' AND (NOT batch_control_ready(NEW.batch_id,NEW.actor_id) OR (downstream_stock(out.subject_id)->>'produced') IS NULL OR out.type='storage' OR");
        $this->patch('downstream_integrity(uuid)', 'FROM batch_outputs WHERE batch_id=ref LOOP', 'FROM batch_outputs WHERE batch_id=ref AND (measurement_record_id IS NOT NULL OR EXISTS(SELECT 1 FROM downstream_records WHERE output_id=batch_outputs.subject_id AND kg IS NOT NULL)) LOOP');
        DB::statement("ALTER TABLE output_profiles ADD COLUMN facility_party_id uuid NOT NULL REFERENCES parties(id), ADD COLUMN output_type text NOT NULL, ADD COLUMN material_key text NOT NULL, ADD COLUMN hazards text NOT NULL CHECK(hazards='NONE')");
        DB::statement('CREATE UNIQUE INDEX output_profile_namespace ON output_profiles(facility_party_id,output_type,material_key,hazards)');
        $this->patch('downstream_profile_guard()', "OR NOT EXISTS(SELECT 1 FROM parties p JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id WHERE p.id=NEW.facility_party_id AND l.kind='facility' AND l.active AND l.review_status='approved' AND o.review_status='approved' AND o.kind IN ('processor','both'))", "OR (NEW.approved AND NOT EXISTS(SELECT 1 FROM parties p JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id WHERE p.id=NEW.facility_party_id AND l.kind='facility' AND l.active AND l.review_status='approved' AND o.review_status='approved' AND o.kind IN ('processor','both')))");
        $this->patch('downstream_profile_guard()', 'RETURN NEW;', "IF EXISTS(SELECT 1 FROM output_profiles p WHERE p.id=NEW.profile_id AND (p.facility_party_id<>NEW.facility_party_id OR p.output_type<>NEW.output_type OR p.material_key<>NEW.material_key OR p.hazards<>NEW.hazards)) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Acceptance scope is permanent. Create a separate profile for a different facility or stream.'; END IF; RETURN NEW;");
        $this->patch('downstream_profile_final()', 'r.profile_id=p.id AND r.version=p.version', 'r.profile_id=p.id AND r.version=p.version AND r.facility_party_id=p.facility_party_id AND r.output_type=p.output_type AND r.material_key=p.material_key AND r.hazards=p.hazards');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION downstream_status(ref uuid) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN downstream_closed(ref) THEN CASE WHEN downstream_blocked(ref) THEN 'closed_later_evidence_exception' ELSE 'closed_recorded_outcomes' END WHEN downstream_blocked(ref) THEN 'blocked_downstream_exception' WHEN EXISTS(SELECT 1 FROM downstream_records WHERE batch_id=ref AND kind='dispatch') THEN 'awaiting_downstream_outcomes' ELSE 'processing_on_site' END
$$;
CREATE FUNCTION downstream_shipment_quantities(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('reserved',downstream_reserved(ref),'dispatched',COALESCE(sum(kg) FILTER(WHERE kind='dispatch'),0),'accepted',COALESCE(sum(kg) FILTER(WHERE kind='accept'),0),'completed',COALESCE(sum(kg) FILTER(WHERE kind='complete'),0),'downstream_loss',COALESCE(sum(kg) FILTER(WHERE kind='downstream_loss'),0),'refused',COALESCE(sum(kg) FILTER(WHERE kind='refuse'),0),'shortage',COALESCE(sum(kg) FILTER(WHERE kind='shortage'),0),'unacknowledged',COALESCE(sum(downstream_remaining(id)) FILTER(WHERE kind='dispatch'),0),'completion_pending',COALESCE(sum(downstream_remaining(id)) FILTER(WHERE kind='accept'),0)) FROM downstream_records WHERE shipment_id=ref
$$;
CREATE OR REPLACE FUNCTION downstream_context(ref uuid, actor uuid, shipment uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('actor',(SELECT jsonb_build_array(u.status,u.session_version,m.id,m.version,m.role,m.active,m.organization_id,m.place_id) FROM users u JOIN memberships m ON m.user_id=u.id WHERE u.id=actor),
 'source',downstream_access(actor,b.facility_party_id),'batch_record',b.record_id,'input_measurement',b.input_measurement_id,
 'measurements',(SELECT jsonb_agg(jsonb_build_array(subject_id,measurement_record_id) ORDER BY subject_id) FROM batch_outputs WHERE batch_id=ref),
 'media_assurance',(SELECT COALESCE(jsonb_agg(jsonb_build_object('id',out.subject_id,'record',cur.record_id,'version',cur.version) ORDER BY out.subject_id),'[]'::jsonb) FROM batch_outputs out LEFT JOIN sanitization_current cur ON cur.subject_id=out.subject_id WHERE out.batch_id=ref AND out.type='storage'),
 'destination',(SELECT jsonb_build_object('profile',p.record_id,'access',downstream_access(h.receiver_actor_id,pr.facility_party_id)) FROM downstream_shipments h JOIN output_profile_records pr ON pr.id=h.profile_record_id JOIN output_profiles p ON p.id=pr.profile_id WHERE h.id=shipment)) FROM dismantling_batches b WHERE b.subject_id=ref
$$;
CREATE UNIQUE INDEX output_profile_consecutive_version ON output_profile_records(profile_id,version);
CREATE OR REPLACE VIEW downstream_exceptions AS
 SELECT r.id,r.batch_id,r.actor_id,r.assignee_id,r.kind AS type,r.recorded_at FROM downstream_records r WHERE r.kind IN ('refuse','shortage','dispute','source_loss','downstream_loss','measurement_dispute') OR (r.kind='accept' AND r.condition='damaged')
 UNION ALL
 SELECT r.id,closed.batch_id,r.actor_id,r.actor_id,'post_closure_assurance_contradiction'::text,r.recorded_at FROM downstream_records closed
 JOIN LATERAL jsonb_array_elements(closed.context->'media_assurance') frozen ON true
 JOIN sanitization_records r ON r.subject_id=(frozen->>'id')::uuid AND r.version>(frozen->>'version')::bigint
 WHERE closed.kind='close' AND (r.kind='attempt' OR r.outcome IN ('failed','incomplete','unverifiable','unsatisfactory','rejected'));
CREATE OR REPLACE FUNCTION downstream_blocked(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM downstream_exceptions WHERE batch_id=ref) OR EXISTS(SELECT 1 FROM batch_records WHERE batch_id=ref AND kind='discrepancy')
$$;

SQL);
    }

    private function patch(string $function, string $search, string $replace): void
    {
        $body = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$function])->body;
        $body = str_replace($search, $replace, $body, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected downstream boundary: '.$function);
        }
        DB::unprepared($body);
    }

    public function down(): void
    {
        throw new RuntimeException('Keep movement boundaries and historical provenance.');
    }
};
