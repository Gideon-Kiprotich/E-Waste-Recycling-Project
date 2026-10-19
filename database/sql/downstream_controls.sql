CREATE OR REPLACE FUNCTION downstream_profile_ready(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM downstream_shipments h JOIN output_profile_records pr ON pr.id=h.profile_record_id JOIN output_profiles p ON p.id=pr.profile_id AND p.record_id=pr.id
 JOIN dismantling_batches b ON b.subject_id=h.batch_id JOIN batch_outputs out ON out.subject_id=h.output_id
 JOIN memberships m ON m.id=h.receiver_membership_id JOIN users u ON u.id=h.receiver_actor_id AND m.user_id=u.id
 WHERE h.id=ref AND pr.approved AND pr.hazards=h.hazards AND pr.output_type=out.type AND pr.material_key=downstream_material(out.material_code)
 AND m.version=h.receiver_scope_version AND COALESCE((downstream_access(u.id,pr.facility_party_id)->>'eligible')::boolean,false)
 AND (NOT EXISTS(SELECT 1 FROM downstream_records WHERE id=h.created_record_id) OR EXISTS(SELECT 1 FROM downstream_records origin WHERE origin.id=h.created_record_id
 AND origin.context->'destination'->'access'=downstream_access(h.receiver_actor_id,pr.facility_party_id)
 AND origin.context->'source'->'facility_version'=batch_access(h.sender_actor_id,b.facility_party_id)->'facility_version'
 AND origin.context->'source'->'operator_version'=batch_access(h.sender_actor_id,b.facility_party_id)->'operator_version'
 AND origin.context->'source'->'capabilities'=batch_access(h.sender_actor_id,b.facility_party_id)->'capabilities'
 AND origin.context->'batch_record'=to_jsonb(b.record_id)
 AND origin.context->'measurements'=(SELECT jsonb_agg(jsonb_build_array(subject_id,measurement_record_id) ORDER BY subject_id) FROM batch_outputs WHERE batch_id=b.subject_id)))
 AND pr.facility_party_id<>b.facility_party_id AND (out.type<>'storage' OR EXISTS(SELECT 1 FROM place_categories pc JOIN parties pp ON pp.place_id=pc.place_id JOIN items i ON i.subject_id=out.subject_id JOIN categories c ON c.id=pc.category_id WHERE pp.id=pr.facility_party_id AND c.code='standalone_part' AND pc.subtype=i.subtype)))
$$;
CREATE OR REPLACE FUNCTION downstream_ready(ref uuid, actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT COALESCE(batch_control_ready(ref,actor),false) AND batch_balance(ref)->>'status'='reconciled_on_site' AND NOT downstream_blocked(ref) AND NOT downstream_closed(ref)
 AND NOT EXISTS(SELECT 1 FROM batch_members bm JOIN assessment_current ac ON ac.subject_id=bm.subject_id JOIN decisions d ON d.id=ac.decision_id WHERE bm.batch_id=ref AND (ac.decision_id<>bm.decision_id OR d.pathway<>'recycle'))
 AND NOT EXISTS(SELECT 1 FROM assessments a WHERE a.hard_hold AND a.subject_id IN (SELECT subject_id FROM batch_members WHERE batch_id=ref UNION SELECT subject_id FROM batch_detachments WHERE batch_id=ref))
 AND NOT EXISTS(SELECT 1 FROM handover_items h WHERE h.active AND h.subject_id IN (SELECT subject_id FROM batch_members WHERE batch_id=ref UNION SELECT subject_id FROM batch_detachments WHERE batch_id=ref))
$$;
CREATE FUNCTION output_profile_context(actor uuid, party uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('actor',(SELECT jsonb_build_array(u.status,u.session_version,m.id,m.version,m.role,m.active) FROM users u JOIN memberships m ON m.user_id=u.id WHERE u.id=actor),'facility',(SELECT jsonb_build_array(l.id,l.version,l.active,l.review_status,o.id,o.version,o.review_status) FROM parties p JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id WHERE p.id=party))
$$;
CREATE FUNCTION downstream_command_final() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='commands' THEN
 IF NEW.action LIKE 'downstream_%' AND NOT EXISTS(SELECT 1 FROM downstream_records WHERE command_id=NEW.id) THEN RAISE EXCEPTION 'Downstream command needs reciprocal ledger'; END IF;
 IF NEW.action LIKE 'output_profile:%' AND NOT EXISTS(SELECT 1 FROM output_profile_records WHERE command_id=NEW.id) THEN RAISE EXCEPTION 'Profile command needs reciprocal review'; END IF;
 ELSE
 IF NEW.aggregate_kind='downstream' AND NOT EXISTS(SELECT 1 FROM downstream_records WHERE event_id=NEW.id) THEN RAISE EXCEPTION 'Unbacked downstream event'; END IF;
 IF NEW.type='downstream_accepted' AND NOT EXISTS(SELECT 1 FROM downstream_records WHERE physical_event_id=NEW.id AND kind='accept' AND canonical) THEN RAISE EXCEPTION 'Unbacked downstream physical receipt'; END IF;
 IF NEW.aggregate_kind='output_profile' AND NOT EXISTS(SELECT 1 FROM output_profile_records WHERE event_id=NEW.id) THEN RAISE EXCEPTION 'Unbacked profile event'; END IF;
 END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER downstream_command_complete AFTER INSERT ON commands DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION downstream_command_final();
CREATE CONSTRAINT TRIGGER downstream_event_complete AFTER INSERT ON domain_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION downstream_command_final();
CREATE FUNCTION downstream_item_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid; cc custody_current; h downstream_shipments; r downstream_records; pr output_profile_records;
BEGIN
 ref:=COALESCE((to_jsonb(NEW)->>'subject_id')::uuid,(to_jsonb(NEW)->>'id')::uuid);
 SELECT * INTO r FROM downstream_records WHERE output_id=ref AND kind='accept' AND canonical;
 IF r.id IS NULL THEN RETURN NULL; END IF;
 SELECT * INTO h FROM downstream_shipments WHERE id=r.shipment_id;
 SELECT * INTO pr FROM output_profile_records WHERE id=h.profile_record_id;
 SELECT * INTO cc FROM custody_current WHERE subject_id=ref;
 IF cc.party_id IS DISTINCT FROM pr.facility_party_id OR cc.last_event_id IS DISTINCT FROM r.physical_event_id OR cc.version IS DISTINCT FROM h.source_custody_version+1 OR NOT downstream_receipt_matches(ref,cc.party_id,cc.last_event_id,cc.version) THEN RAISE EXCEPTION 'Canonical downstream receipt must retain exact acknowledged custody'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER downstream_item_custody AFTER INSERT OR UPDATE ON custody_current DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION downstream_item_final();
CREATE CONSTRAINT TRIGGER downstream_item_subject AFTER UPDATE ON subjects DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION downstream_item_final();
CREATE VIEW downstream_exceptions AS
 SELECT r.id,r.batch_id,r.actor_id,r.assignee_id,r.kind AS type,r.recorded_at FROM downstream_records r WHERE r.kind IN ('refuse','shortage','dispute','source_loss','downstream_loss','measurement_dispute') OR (r.kind='accept' AND r.condition='damaged');
