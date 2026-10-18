ALTER TABLE media_authorizations ADD COLUMN authorization_context_version integer NOT NULL DEFAULT 2 CHECK(authorization_context_version IN (2,3)), ADD COLUMN batch_id uuid REFERENCES dismantling_batches(subject_id);
ALTER TABLE media_authorizations ADD CHECK((authorization_context_version=2 AND batch_id IS NULL) OR (authorization_context_version=3 AND batch_id IS NOT NULL));
CREATE FUNCTION batch_authorization_applicable(a media_authorizations) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT COALESCE(EXISTS(SELECT 1 FROM batch_detachments d JOIN batch_members bm ON bm.subject_id=d.parent_id JOIN dismantling_batches b ON b.subject_id=d.batch_id
 JOIN media_inventory_entries e ON e.id=d.placement_id JOIN media_inventory_current ic ON ic.parent_id=d.parent_id JOIN subjects s ON s.id=d.subject_id JOIN subjects ps ON ps.id=d.parent_id
 JOIN items i ON i.subject_id=d.subject_id JOIN custody_current cc ON cc.subject_id=d.subject_id JOIN custody_current pc ON pc.subject_id=d.parent_id
 JOIN parties p ON p.id=cc.party_id JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id
 JOIN assessment_current ac ON ac.subject_id=d.parent_id JOIN decisions dec ON dec.id=ac.decision_id
 JOIN users u ON u.id=a.actor_id JOIN memberships m ON m.id=a.membership_id AND m.user_id=u.id
 WHERE d.subject_id=a.subject_id AND a.authorization_context_version=3 AND a.batch_id=d.batch_id AND a.parent_id=d.parent_id AND b.state='processing'
 AND batch_control_ready(b.subject_id,a.actor_id) AND NOT EXISTS(SELECT 1 FROM batch_records WHERE batch_id=b.subject_id AND kind='discrepancy')
 AND e.medium_id=d.subject_id AND e.record_id=d.placement_record_id AND e.state='confirmed' AND ic.version=a.inventory_version
 AND s.version=a.source_subject_version AND ps.version=a.parent_subject_version AND i.data_status='YES' AND i.subtype IN ('hdd','ssd')
 AND cc.party_id=d.facility_party_id AND cc.last_event_id=d.source_custody_event_id AND cc.version=d.source_custody_version
 AND cc.party_id=pc.party_id AND pc.party_id=a.facility_party_id AND cc.last_event_id=a.custody_event_id AND cc.version=a.custody_version AND pc.last_event_id=a.parent_custody_event_id AND pc.version=a.parent_custody_version
 AND l.version=a.facility_version AND o.version=a.operator_version AND ac.assessment_id=a.assessment_id AND ac.version=a.assessment_version AND ac.decision_id=a.decision_id AND dec.pathway='recycle' AND bm.decision_id=dec.id
 AND u.session_version=a.actor_session_version AND m.version=a.membership_version AND COALESCE((batch_access(a.actor_id,a.facility_party_id)->>'eligible')::boolean,false)
 AND EXISTS(SELECT 1 FROM place_categories cap JOIN categories c ON c.id=cap.category_id WHERE cap.place_id=l.id AND c.code='standalone_part' AND cap.subtype=i.subtype)
 AND NOT EXISTS(SELECT 1 FROM assessments WHERE subject_id IN (d.subject_id,d.parent_id) AND hard_hold)
 AND NOT EXISTS(SELECT 1 FROM handover_items WHERE subject_id IN (d.subject_id,d.parent_id) AND active)
 AND a.observed_at>=(SELECT date_trunc('minute',observed_at) FROM batch_records WHERE id=d.record_id)),false)
$$;
ALTER FUNCTION media_authorization_applicable(media_authorizations) RENAME TO media_authorization_applicable_embedded;
CREATE FUNCTION media_authorization_applicable(a media_authorizations) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN a.authorization_context_version=3 THEN batch_authorization_applicable(a) ELSE NOT EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=a.subject_id) AND media_authorization_applicable_embedded(a) END
$$;
ALTER FUNCTION sanitization_ready(uuid,uuid) RENAME TO sanitization_ready_embedded;
CREATE FUNCTION sanitization_ready(ref uuid, actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=ref) THEN EXISTS(SELECT 1 FROM media_authorization_current cur JOIN media_authorizations a ON a.id=cur.record_id
 WHERE cur.subject_id=ref AND a.authorization_context_version=3 AND batch_authorization_applicable(a) AND COALESCE((batch_access(actor,a.facility_party_id)->>'eligible')::boolean,false)) ELSE sanitization_ready_embedded(ref,actor) END
$$;
ALTER FUNCTION sanitization_dependencies(sanitization_records) RENAME TO sanitization_dependencies_embedded;
CREATE FUNCTION sanitization_dependencies(r sanitization_records) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN r.authorization_context_version=3 THEN EXISTS(SELECT 1 FROM media_authorization_current cur JOIN media_authorizations a ON a.id=cur.record_id
 JOIN subjects s ON s.id=a.subject_id JOIN custody_current cc ON cc.subject_id=s.id JOIN parties p ON p.id=cc.party_id JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id
 JOIN users u ON u.id=r.actor_id JOIN memberships m ON m.id=r.membership_id AND m.user_id=u.id
 WHERE cur.subject_id=r.subject_id AND a.id=r.authorization_id AND batch_authorization_applicable(a) AND s.version=r.source_subject_version
 AND cc.party_id=r.facility_party_id AND cc.last_event_id=r.custody_event_id AND cc.version=r.custody_version AND l.version=r.facility_version AND o.version=r.operator_version
 AND a.assessment_version=r.assessment_version AND a.assessment_id=r.assessment_id AND a.decision_id=r.decision_id
 AND u.session_version=r.actor_session_version AND m.version=r.membership_version AND sanitization_ready(r.subject_id,r.actor_id))
 ELSE NOT EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=r.subject_id) AND sanitization_dependencies_embedded(r) END
$$;
CREATE FUNCTION batch_authorization_final() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.authorization_context_version=3 AND NOT batch_authorization_applicable(NEW) THEN RAISE EXCEPTION 'Detached authorization dependencies changed before commit'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER batch_authorization_complete AFTER INSERT ON media_authorizations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION batch_authorization_final();
