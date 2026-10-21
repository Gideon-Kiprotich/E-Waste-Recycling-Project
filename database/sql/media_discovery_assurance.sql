CREATE FUNCTION discovery_authorization_applicable(a media_authorizations) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM batch_media_discoveries d JOIN batch_members bm ON bm.batch_id=d.batch_id AND bm.subject_id=d.parent_id
 JOIN dismantling_batches b ON b.subject_id=d.batch_id JOIN media_review_commits proof ON proof.record_id=d.review_id
 JOIN items i ON i.subject_id=d.medium_id JOIN subjects s ON s.id=i.subject_id JOIN subjects ps ON ps.id=d.parent_id
 JOIN custody_current cc ON cc.subject_id=i.subject_id JOIN custody_current pc ON pc.subject_id=d.parent_id
 JOIN parties p ON p.id=cc.party_id JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id
 JOIN assessment_current ac ON ac.subject_id=d.parent_id JOIN decisions dec ON dec.id=ac.decision_id JOIN media_inventory_current ic ON ic.parent_id=d.parent_id
 JOIN users u ON u.id=a.actor_id JOIN memberships m ON m.id=a.membership_id AND m.user_id=u.id
 WHERE d.medium_id=a.subject_id AND a.authorization_context_version=5 AND a.batch_id=d.batch_id AND a.parent_id=d.parent_id AND a.downstream_receipt_id IS NULL
 AND media_batch_onsite(b.subject_id) AND NOT media_batch_unresolved(b.subject_id) AND media_review_scope(a.actor_id,d.medium_id)
 AND media_recovery_allowed(d.medium_id,'authorization') AND media_recovery_allowed(d.batch_id,'authorization')
 AND s.version=a.source_subject_version AND ps.version=a.parent_subject_version AND i.subtype IN ('hdd','ssd') AND i.data_status='YES'
 AND cc.party_id=b.facility_party_id AND cc.party_id=pc.party_id AND cc.party_id=a.facility_party_id
 AND cc.last_event_id=a.custody_event_id AND cc.version=a.custody_version AND pc.last_event_id=a.parent_custody_event_id AND pc.version=a.parent_custody_version
 AND l.version=a.facility_version AND o.version=a.operator_version AND u.session_version=a.actor_session_version AND m.version=a.membership_version
 AND ac.assessment_id=a.assessment_id AND ac.version=a.assessment_version AND ac.decision_id=a.decision_id AND dec.id=bm.decision_id AND dec.pathway='recycle' AND ic.version=a.inventory_version
 AND NOT restriction_inspection_blocked(d.parent_id) AND NOT restriction_inspection_blocked(d.medium_id) AND NOT restriction_receipt_blocked(d.medium_id)
 AND NOT batch_work_restricted(d.parent_id) AND NOT batch_work_restricted(d.medium_id)
 AND NOT EXISTS(SELECT 1 FROM handover_items WHERE subject_id IN (d.parent_id,d.medium_id) AND active)
 AND NOT EXISTS(SELECT 1 FROM media_current_bindings WHERE medium_id=d.medium_id)
 AND a.observed_at>=date_trunc('minute',d.observed_at))
$$;
CREATE FUNCTION discovery_sanitization_dependencies(r sanitization_records) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM media_authorization_current cur JOIN media_authorizations a ON a.id=cur.record_id
 JOIN custody_current cc ON cc.subject_id=a.subject_id JOIN subjects s ON s.id=a.subject_id JOIN parties p ON p.id=cc.party_id JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id
 JOIN users u ON u.id=r.actor_id JOIN memberships m ON m.id=r.membership_id AND m.user_id=u.id
 WHERE cur.subject_id=r.subject_id AND a.id=r.authorization_id AND discovery_authorization_applicable(a) AND media_review_scope(r.actor_id,r.subject_id)
 AND s.version=r.source_subject_version AND cc.party_id=r.facility_party_id AND cc.last_event_id=r.custody_event_id AND cc.version=r.custody_version
 AND l.version=r.facility_version AND o.version=r.operator_version AND u.session_version=r.actor_session_version AND m.version=r.membership_version
 AND a.assessment_version=r.assessment_version AND a.assessment_id=r.assessment_id AND a.decision_id=r.decision_id)
$$;
CREATE FUNCTION discovery_authorization_final() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.authorization_context_version=5 AND NOT COALESCE(discovery_authorization_applicable(NEW),false) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Discovery authorization dependencies changed before COMMIT.'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER discovery_authorization_complete AFTER INSERT ON media_authorizations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION discovery_authorization_final();
