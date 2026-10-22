CREATE FUNCTION downstream_discovery_on_site(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM batch_media_discoveries d JOIN dismantling_batches b ON b.subject_id=d.batch_id JOIN custody_current cc ON cc.subject_id=d.medium_id WHERE d.medium_id=ref AND b.state='processing' AND cc.party_id=b.facility_party_id AND NOT downstream_closed(b.subject_id) AND NOT downstream_media_uncontrolled(ref) AND NOT EXISTS(SELECT 1 FROM downstream_records WHERE output_id=ref AND kind='dispatch'))
$$;
CREATE FUNCTION downstream_discovery_movable(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM batch_media_discoveries d JOIN batch_outputs o ON o.subject_id=d.medium_id JOIN items medium ON medium.subject_id=d.medium_id JOIN items parent ON parent.subject_id=d.parent_id
 JOIN downstream_fact_revisions v ON v.anchor_kind='discovery' AND v.anchor_id=d.medium_id AND v.fact='movement' AND v.value='approved' JOIN downstream_review_effects review ON review.record_id=v.review_id
 WHERE d.medium_id=ref AND o.batch_id=d.batch_id AND o.type='storage' AND parent.owner_user_id=medium.owner_user_id AND medium.data_status='YES' AND medium.subtype IN ('hdd','ssd')
 AND downstream_discovery_on_site(ref) AND downstream_proof_current(review.record_id) AND media_review_scope(actor,ref) AND NOT media_batch_unresolved(d.batch_id) AND NOT restriction_dependent(d.batch_id)
 AND NOT restriction_receipt_blocked(ref) AND NOT restriction_inspection_blocked(ref) AND NOT restriction_inspection_blocked(d.parent_id) AND NOT batch_work_restricted(ref) AND NOT batch_work_restricted(d.parent_id)
 AND sanitization_state(ref)='satisfied' AND NOT EXISTS(SELECT 1 FROM sanitization_current WHERE subject_id=ref AND destruction_reported) AND NOT EXISTS(SELECT 1 FROM handover_items WHERE subject_id=ref AND active))
$$;
CREATE FUNCTION downstream_discovery_receipt(ref uuid,party uuid,ev uuid,cv bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM downstream_records r JOIN downstream_shipments h ON h.id=r.shipment_id JOIN batch_media_discoveries d ON d.id=h.discovery_id
 JOIN batch_outputs o ON o.subject_id=r.output_id JOIN downstream_effective_records effective ON effective.id=r.id JOIN output_profile_records pr ON pr.id=h.profile_record_id
 JOIN subjects s ON s.id=r.output_id JOIN items medium ON medium.subject_id=s.id JOIN items parent ON parent.subject_id=d.parent_id JOIN domain_events event ON event.id=r.physical_event_id
 WHERE r.output_id=ref AND r.kind='accept' AND r.canonical AND effective.kg=(downstream_stock(ref)->>'produced')::numeric AND r.physical_event_id=ev AND h.source_custody_version+1=cv AND pr.facility_party_id=party
 AND h.output_id=d.medium_id AND h.batch_id=d.batch_id AND o.batch_id=d.batch_id AND o.production_record_id=d.production_id AND h.movement_review_id IS NOT NULL
 AND EXISTS(SELECT 1 FROM downstream_fact_revisions fact WHERE fact.review_id=h.movement_review_id AND fact.anchor_kind='discovery' AND fact.anchor_id=ref AND fact.fact='movement' AND fact.value='approved')
 AND medium.owner_user_id=parent.owner_user_id AND medium.data_status='YES' AND s.version=h.source_subject_version+1
 AND event.subject_id=ref AND event.aggregate_id=ref AND event.aggregate_kind='item' AND event.aggregate_version=s.version AND event.actor_id=r.actor_id AND event.command_id=r.command_id AND event.type='downstream_accepted' AND event.occurred_at=r.observed_at)
$$;
CREATE FUNCTION downstream_discovery_received(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT EXISTS(SELECT 1 FROM custody_current cc WHERE cc.subject_id=ref AND downstream_discovery_receipt(ref,cc.party_id,cc.last_event_id,cc.version)) $$;
CREATE FUNCTION downstream_discovery_chain(ref uuid,party uuid,ev uuid,cv bigint,sv bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM downstream_shipments h JOIN batch_media_discoveries d ON d.id=h.discovery_id JOIN dismantling_batches b ON b.subject_id=h.batch_id
 WHERE h.output_id=ref AND b.facility_party_id=party AND h.source_event_id=ev AND h.source_custody_version=cv AND h.source_subject_version=sv AND downstream_discovery_received(ref))
$$;
CREATE FUNCTION downstream_discovery_authorization(a media_authorizations) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM downstream_effective_records receipt JOIN downstream_effective_shipments h ON h.id=receipt.shipment_id JOIN batch_media_discoveries d ON d.id=h.discovery_id
 JOIN dismantling_batches b ON b.subject_id=d.batch_id JOIN batch_members bm ON bm.batch_id=d.batch_id AND bm.subject_id=d.parent_id
 JOIN items i ON i.subject_id=d.medium_id JOIN subjects s ON s.id=i.subject_id JOIN subjects ps ON ps.id=d.parent_id
 JOIN custody_current cc ON cc.subject_id=d.medium_id JOIN custody_current pc ON pc.subject_id=d.parent_id JOIN parties party ON party.id=cc.party_id JOIN places l ON l.id=party.place_id JOIN organizations org ON org.id=l.organization_id
 JOIN assessment_current ac ON ac.subject_id=d.parent_id JOIN decisions dec ON dec.id=ac.decision_id JOIN media_inventory_current inventory ON inventory.parent_id=d.parent_id
 JOIN users u ON u.id=a.actor_id JOIN memberships m ON m.id=a.membership_id AND m.user_id=u.id
 WHERE a.authorization_context_version=6 AND a.discovery_id=d.id AND a.downstream_receipt_id=receipt.id AND receipt.kind='accept' AND receipt.canonical AND receipt.output_id=a.subject_id
 AND h.receiver_actor_id=a.actor_id AND a.batch_id=d.batch_id AND a.parent_id=d.parent_id AND downstream_profile_ready(h.id)
 AND downstream_discovery_receipt(d.medium_id,cc.party_id,cc.last_event_id,cc.version) AND cc.party_id=a.facility_party_id AND cc.last_event_id=a.custody_event_id AND cc.version=a.custody_version
 AND pc.party_id=b.facility_party_id AND pc.last_event_id=a.parent_custody_event_id AND pc.version=a.parent_custody_version AND s.version=a.source_subject_version AND ps.version=a.parent_subject_version
 AND i.data_status='YES' AND i.subtype IN ('hdd','ssd') AND ac.assessment_id=a.assessment_id AND ac.version=a.assessment_version AND ac.decision_id=a.decision_id AND dec.id=bm.decision_id AND dec.pathway='recycle' AND inventory.version=a.inventory_version
 AND l.version=a.facility_version AND org.version=a.operator_version AND u.session_version=a.actor_session_version AND m.version=a.membership_version
 AND COALESCE((batch_access(a.actor_id,a.facility_party_id)->>'eligible')::boolean,false) AND media_review_capability(a.actor_id,i.subtype)
 AND NOT downstream_media_uncontrolled(a.subject_id) AND NOT media_batch_unresolved(d.batch_id) AND NOT restriction_receipt_blocked(a.subject_id) AND NOT restriction_inspection_blocked(a.subject_id) AND NOT restriction_inspection_blocked(d.parent_id) AND NOT batch_work_restricted(a.subject_id) AND NOT batch_work_restricted(d.parent_id)
 AND media_recovery_allowed(a.subject_id,'authorization') AND NOT EXISTS(SELECT 1 FROM sanitization_current WHERE subject_id=a.subject_id AND destruction_reported)
 AND a.observed_at>=date_trunc('minute',receipt.observed_at))
$$;
CREATE FUNCTION downstream_discovery_dependencies(r sanitization_records) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM media_authorization_current current JOIN media_authorizations a ON a.id=current.record_id JOIN subjects s ON s.id=a.subject_id JOIN custody_current cc ON cc.subject_id=a.subject_id
 JOIN parties p ON p.id=cc.party_id JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id JOIN users u ON u.id=r.actor_id JOIN memberships m ON m.id=r.membership_id AND m.user_id=u.id
 WHERE current.subject_id=r.subject_id AND a.id=r.authorization_id AND downstream_discovery_authorization(a) AND r.authorization_context_version=6 AND s.version=r.source_subject_version AND cc.party_id=r.facility_party_id AND cc.last_event_id=r.custody_event_id AND cc.version=r.custody_version
 AND l.version=r.facility_version AND o.version=r.operator_version AND u.session_version=r.actor_session_version AND m.version=r.membership_version AND a.assessment_id=r.assessment_id AND a.assessment_version=r.assessment_version AND a.decision_id=r.decision_id AND media_recovery_allowed(r.subject_id,'assurance_review'))
$$;
CREATE FUNCTION downstream_discovery_header(n downstream_shipments) RETURNS void LANGUAGE plpgsql AS $$
DECLARE d batch_media_discoveries;cc custody_current;b dismantling_batches;
BEGIN
 SELECT * INTO d FROM batch_media_discoveries WHERE id=n.discovery_id;SELECT * INTO cc FROM custody_current WHERE subject_id=n.output_id;SELECT * INTO b FROM dismantling_batches WHERE subject_id=n.batch_id;
 IF d.medium_id IS DISTINCT FROM n.output_id OR d.batch_id IS DISTINCT FROM n.batch_id OR cc.party_id IS DISTINCT FROM b.facility_party_id OR n.source_custody_version IS DISTINCT FROM cc.version OR n.source_event_id IS DISTINCT FROM cc.last_event_id OR n.source_subject_version IS DISTINCT FROM (SELECT version FROM subjects WHERE id=n.output_id)
 OR NOT downstream_discovery_movable(n.output_id,n.sender_actor_id) OR NOT EXISTS(SELECT 1 FROM downstream_fact_revisions fact WHERE fact.review_id=n.movement_review_id AND fact.anchor_kind='discovery' AND fact.anchor_id=n.output_id AND fact.fact='movement' AND fact.value='approved') OR EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=n.output_id) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Discovery shipment requires its real approved provenance and current whole-unit source control, never a fabricated detachment.'; END IF;
END; $$;
CREATE FUNCTION downstream_discovery_final() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.authorization_context_version=6 AND NOT downstream_discovery_authorization(NEW) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Discovery recipient authorization dependencies changed before COMMIT.'; END IF;RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER downstream_discovery_authorization_complete AFTER INSERT ON media_authorizations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION downstream_discovery_final();
