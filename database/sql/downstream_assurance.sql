CREATE FUNCTION downstream_receipt_matches(ref uuid, party uuid, ev uuid, cv bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM downstream_records r JOIN downstream_shipments h ON h.id=r.shipment_id JOIN output_profile_records p ON p.id=h.profile_record_id JOIN batch_detachments d ON d.subject_id=r.output_id JOIN subjects s ON s.id=d.subject_id JOIN domain_events e ON e.id=r.physical_event_id
 WHERE r.output_id=ref AND r.kind='accept' AND r.canonical AND r.kg=h.requested_kg AND p.facility_party_id=party AND r.physical_event_id=ev AND h.source_custody_version+1=cv
 AND h.source_subject_version=d.source_subject_version+1 AND h.source_custody_version=d.source_custody_version AND h.source_event_id=d.source_custody_event_id
 AND s.version=h.source_subject_version+1
 AND e.subject_id=ref AND e.aggregate_kind='item' AND e.aggregate_id=ref AND e.aggregate_version=s.version AND e.actor_id=r.actor_id AND e.command_id=r.command_id AND e.type='downstream_accepted' AND e.occurred_at=r.observed_at)
$$;
ALTER FUNCTION batch_source_matches(uuid,uuid,uuid,bigint,bigint) RENAME TO batch_source_matches_on_site;
CREATE FUNCTION batch_source_matches(ref uuid, party uuid, ev uuid, cv bigint, sv bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT batch_source_matches_on_site(ref,party,ev,cv,sv) OR EXISTS(SELECT 1 FROM batch_detachments d JOIN custody_current cc ON cc.subject_id=d.subject_id WHERE d.subject_id=ref AND d.facility_party_id=party AND d.source_custody_event_id=ev AND d.source_custody_version=cv AND d.source_subject_version=sv AND downstream_receipt_matches(ref,cc.party_id,cc.last_event_id,cc.version))
$$;
ALTER FUNCTION batch_authorization_applicable(media_authorizations) RENAME TO batch_authorization_applicable_on_site;
CREATE FUNCTION batch_authorization_applicable(a media_authorizations) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT batch_authorization_applicable_on_site(a) AND NOT EXISTS(SELECT 1 FROM downstream_records WHERE output_id=a.subject_id AND kind='dispatch') $$;
CREATE FUNCTION downstream_authorization_applicable(a media_authorizations) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM downstream_records receipt JOIN downstream_shipments h ON h.id=receipt.shipment_id JOIN output_profile_records pr ON pr.id=h.profile_record_id
 JOIN batch_detachments d ON d.subject_id=receipt.output_id JOIN batch_members bm ON bm.subject_id=d.parent_id JOIN media_inventory_entries e ON e.id=d.placement_id JOIN media_inventory_current ic ON ic.parent_id=d.parent_id
 JOIN subjects s ON s.id=d.subject_id JOIN subjects ps ON ps.id=d.parent_id JOIN custody_current cc ON cc.subject_id=d.subject_id JOIN custody_current pc ON pc.subject_id=d.parent_id
 JOIN parties p ON p.id=cc.party_id JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id JOIN assessment_current ac ON ac.subject_id=d.parent_id JOIN decisions dec ON dec.id=ac.decision_id
 JOIN users u ON u.id=a.actor_id JOIN memberships m ON m.id=a.membership_id AND m.user_id=u.id
 WHERE receipt.id=a.downstream_receipt_id AND receipt.kind='accept' AND receipt.canonical AND receipt.output_id=a.subject_id AND receipt.actor_id=a.actor_id AND a.authorization_context_version=4 AND a.batch_id=d.batch_id AND a.parent_id=d.parent_id
 AND downstream_receipt_matches(d.subject_id,cc.party_id,cc.last_event_id,cc.version) AND cc.party_id=pr.facility_party_id AND cc.party_id=a.facility_party_id AND cc.last_event_id=a.custody_event_id AND cc.version=a.custody_version
 AND pc.party_id=d.facility_party_id AND pc.last_event_id=a.parent_custody_event_id AND pc.version=a.parent_custody_version AND s.version=a.source_subject_version AND ps.version=a.parent_subject_version
 AND e.medium_id=d.subject_id AND e.record_id=d.placement_record_id AND e.state='confirmed' AND ic.version=a.inventory_version
 AND ac.assessment_id=a.assessment_id AND ac.version=a.assessment_version AND ac.decision_id=a.decision_id AND dec.id=bm.decision_id AND dec.pathway='recycle'
 AND l.version=a.facility_version AND o.version=a.operator_version AND u.session_version=a.actor_session_version AND m.version=a.membership_version AND downstream_profile_ready(h.id)
 AND COALESCE((batch_access(a.actor_id,a.facility_party_id)->>'eligible')::boolean,false) AND NOT downstream_blocked(d.batch_id)
 AND NOT batch_work_restricted(d.subject_id) AND NOT batch_work_restricted(d.parent_id) AND NOT EXISTS(SELECT 1 FROM assessments WHERE subject_id IN (d.subject_id,d.parent_id) AND hard_hold)
 AND a.observed_at>=date_trunc('minute',receipt.observed_at))
$$;
ALTER FUNCTION media_authorization_applicable(media_authorizations) RENAME TO media_authorization_applicable_before_downstream;
CREATE FUNCTION media_authorization_applicable(a media_authorizations) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT CASE WHEN a.authorization_context_version=4 THEN downstream_authorization_applicable(a) ELSE media_authorization_applicable_before_downstream(a) END $$;
ALTER FUNCTION sanitization_ready(uuid,uuid) RENAME TO sanitization_ready_before_downstream;
CREATE FUNCTION sanitization_ready(ref uuid, actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN EXISTS(SELECT 1 FROM downstream_records WHERE output_id=ref AND kind='dispatch') THEN EXISTS(SELECT 1 FROM media_authorization_current cur JOIN media_authorizations a ON a.id=cur.record_id WHERE cur.subject_id=ref AND a.authorization_context_version=4 AND a.actor_id=actor AND downstream_authorization_applicable(a)) ELSE sanitization_ready_before_downstream(ref,actor) END
$$;
ALTER FUNCTION sanitization_dependencies(sanitization_records) RENAME TO sanitization_dependencies_before_downstream;
CREATE FUNCTION sanitization_dependencies(r sanitization_records) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN r.authorization_context_version=4 THEN EXISTS(SELECT 1 FROM media_authorization_current cur JOIN media_authorizations a ON a.id=cur.record_id JOIN custody_current cc ON cc.subject_id=a.subject_id JOIN subjects s ON s.id=a.subject_id JOIN parties p ON p.id=cc.party_id JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id JOIN memberships m ON m.id=r.membership_id JOIN users u ON u.id=r.actor_id
 WHERE cur.subject_id=r.subject_id AND a.id=r.authorization_id AND downstream_authorization_applicable(a) AND s.version=r.source_subject_version AND cc.party_id=r.facility_party_id AND cc.version=r.custody_version AND cc.last_event_id=r.custody_event_id AND l.version=r.facility_version AND o.version=r.operator_version AND m.user_id=u.id AND m.version=r.membership_version AND u.session_version=r.actor_session_version AND a.assessment_version=r.assessment_version AND a.assessment_id=r.assessment_id AND a.decision_id=r.decision_id AND sanitization_ready(r.subject_id,r.actor_id)) ELSE sanitization_dependencies_before_downstream(r) END
$$;
