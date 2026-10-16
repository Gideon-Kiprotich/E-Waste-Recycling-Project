CREATE FUNCTION sanitization_ready(ref uuid, actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT inspection_scope(ref,actor)
 AND EXISTS (SELECT 1 FROM storage_assets sa JOIN items i ON i.subject_id=sa.subject_id JOIN categories c ON c.id=i.category_id
 JOIN assessment_current ac ON ac.subject_id=sa.subject_id JOIN decisions d ON d.id=ac.decision_id
 WHERE sa.subject_id=ref AND c.code='standalone_part' AND sa.media_kind=i.subtype AND i.data_status='YES' AND d.pathway<>'hold' AND d.assessment_id=ac.assessment_id)
 AND NOT EXISTS (SELECT 1 FROM assessments WHERE subject_id=ref AND hard_hold)
$$;

CREATE FUNCTION sanitization_dependencies(r sanitization_records) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS (SELECT 1 FROM subjects s JOIN custody_current cc ON cc.subject_id=s.id JOIN parties p ON p.id=cc.party_id
 JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id JOIN assessment_current ac ON ac.subject_id=s.id
 JOIN users u ON u.id=r.actor_id JOIN memberships m ON m.id=r.membership_id AND m.user_id=u.id
 WHERE s.id=r.subject_id AND s.version=r.source_subject_version AND cc.party_id=r.facility_party_id AND cc.last_event_id=r.custody_event_id AND cc.version=r.custody_version
 AND l.version=r.facility_version AND o.version=r.operator_version AND ac.version=r.assessment_version AND ac.assessment_id=r.assessment_id AND ac.decision_id=r.decision_id
 AND u.session_version=r.actor_session_version AND m.version=r.membership_version AND sanitization_ready(r.subject_id,r.actor_id))
$$;

CREATE FUNCTION sanitization_supported(a sanitization_records) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS (SELECT 1 FROM sanitization_policies p JOIN storage_assets s ON s.subject_id=a.subject_id
 WHERE p.id=a.policy_id AND p.definition->'techniques'->a.technique->>'supported'='true'
 AND p.definition->'techniques'->a.technique->'media' ? s.media_kind
 AND a.outcome='success' AND a.executor_ciphertext IS NOT NULL AND a.tool_ciphertext IS NOT NULL AND a.scope_ciphertext IS NOT NULL AND a.procedure_ciphertext IS NOT NULL
 AND (p.definition->'techniques'->a.technique->>'software_version'='false' OR a.tool_version_ciphertext IS NOT NULL)
 AND a.evidence_kind IS NOT NULL
 AND (p.definition->'techniques'->a.technique->>'method'='destroy' OR NOT EXISTS (
 SELECT 1 FROM sanitization_records old JOIN sanitization_policies op ON op.id=old.policy_id WHERE old.subject_id=a.subject_id AND old.kind='attempt' AND op.definition->'techniques'->old.technique->>'method'='destroy')))
$$;

CREATE FUNCTION sanitization_state(ref uuid) RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE r sanitization_records;
BEGIN
 SELECT sr.* INTO r FROM sanitization_current sc JOIN sanitization_records sr ON sr.id=sc.record_id WHERE sc.subject_id=ref;
 IF NOT FOUND THEN RETURN 'required'; END IF;
 IF NOT sanitization_dependencies(r) THEN RETURN 'review_required'; END IF;
 IF r.kind='attempt' THEN RETURN CASE WHEN r.outcome='success' THEN 'pending_verification' ELSE r.outcome END; END IF;
 IF r.kind='verification' THEN RETURN CASE WHEN r.outcome='satisfactory' THEN 'pending_validation' ELSE r.outcome END; END IF;
 RETURN CASE WHEN r.outcome='accepted' THEN 'satisfied' ELSE 'rejected' END;
END; $$;

CREATE FUNCTION sanitization_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cur sanitization_current; prior sanitization_records; a sanitization_records; pol sanitization_policies; tech jsonb; keys jsonb; entry record; cc custody_current; ac assessment_current; mv bigint; sv bigint; session_v bigint; facility_v bigint; operator_v bigint;
BEGIN
 SELECT * INTO cc FROM custody_current WHERE subject_id=NEW.subject_id FOR UPDATE;
 SELECT * INTO cur FROM sanitization_current WHERE subject_id=NEW.subject_id FOR UPDATE;
 SELECT * INTO prior FROM sanitization_records WHERE id=cur.record_id;
 SELECT * INTO ac FROM assessment_current WHERE subject_id=NEW.subject_id;
 SELECT version INTO sv FROM subjects WHERE id=NEW.subject_id;
 SELECT l.version,o.version INTO facility_v,operator_v FROM parties p JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id WHERE p.id=cc.party_id;
 SELECT m.version,u.session_version INTO mv,session_v FROM memberships m JOIN users u ON u.id=m.user_id JOIN parties p ON p.place_id=m.place_id WHERE m.id=NEW.membership_id AND u.id=NEW.actor_id AND p.id=cc.party_id;
 IF NOT sanitization_ready(NEW.subject_id,NEW.actor_id) OR NEW.version<>COALESCE(cur.version,0)+1 OR NEW.predecessor_id IS DISTINCT FROM cur.record_id
 OR NEW.source_subject_version IS DISTINCT FROM sv OR NEW.assessment_version IS DISTINCT FROM ac.version OR NEW.assessment_id IS DISTINCT FROM ac.assessment_id OR NEW.decision_id IS DISTINCT FROM ac.decision_id
 OR NEW.facility_party_id IS DISTINCT FROM cc.party_id OR NEW.custody_event_id IS DISTINCT FROM cc.last_event_id OR NEW.custody_version IS DISTINCT FROM cc.version
 OR NEW.facility_version IS DISTINCT FROM facility_v OR NEW.operator_version IS DISTINCT FROM operator_v OR NEW.membership_version IS DISTINCT FROM mv OR NEW.actor_session_version IS DISTINCT FROM session_v
 OR NEW.observed_at>clock_timestamp() OR NEW.recorded_at<NEW.observed_at OR NEW.observed_at<(SELECT date_trunc('minute',occurred_at) FROM domain_events WHERE id=cc.last_event_id)
 OR (prior.id IS NOT NULL AND NEW.observed_at<date_trunc('minute',prior.observed_at))
 THEN RAISE EXCEPTION 'Sanitization requires current authorized media, custody, decision and consecutive versions'; END IF;
 SELECT * INTO pol FROM sanitization_policies WHERE id=NEW.policy_id;
 tech:=pol.definition->'techniques'->NEW.technique;
 IF tech IS NULL THEN RAISE EXCEPTION 'Unknown frozen sanitization technique'; END IF;
 IF NEW.kind='attempt' THEN
 IF NEW.attempt_id<>NEW.id OR NEW.previous_attempt_id IS DISTINCT FROM cur.attempt_id OR NEW.checks<>'{}'::jsonb
 THEN RAISE EXCEPTION 'Attempt must retain current predecessor and its own physical-work identity'; END IF;
 IF NEW.outcome='success' AND (NEW.executor_ciphertext IS NULL OR NEW.tool_ciphertext IS NULL OR NEW.scope_ciphertext IS NULL OR NEW.procedure_ciphertext IS NULL OR NEW.evidence_kind IS NULL OR (tech->>'software_version'='true' AND NEW.tool_version_ciphertext IS NULL))
 THEN RAISE EXCEPTION 'Reported success requires applicable completion metadata and evidence'; END IF;
 ELSE
 SELECT * INTO a FROM sanitization_records WHERE id=NEW.attempt_id;
 IF a.id IS NULL OR a.id IS DISTINCT FROM cur.attempt_id OR a.subject_id<>NEW.subject_id OR a.kind<>'attempt' OR a.outcome<>'success' OR NEW.technique<>a.technique OR NEW.policy_id<>a.policy_id OR NEW.previous_attempt_id IS NOT NULL OR NEW.evidence_kind IS NULL
 OR NEW.executor_ciphertext IS NOT NULL OR NEW.tool_ciphertext IS NOT NULL OR NEW.tool_version_ciphertext IS NOT NULL OR NEW.scope_ciphertext IS NOT NULL OR NEW.procedure_ciphertext IS NOT NULL
 THEN RAISE EXCEPTION 'Review requires current successful attempt and separate evidence'; END IF;
 keys:=pol.definition->NEW.kind;
 IF keys IS NULL OR jsonb_typeof(NEW.checks)<>'object' OR (SELECT count(*) FROM jsonb_object_keys(keys))<>(SELECT count(*) FROM jsonb_object_keys(NEW.checks)) THEN RAISE EXCEPTION 'Incomplete review checks'; END IF;
 FOR entry IN SELECT * FROM jsonb_each_text(keys) LOOP
 IF COALESCE(NEW.checks->>entry.key,'') NOT IN ('YES','NO','UNKNOWN') THEN RAISE EXCEPTION 'Invalid review answer'; END IF;
 IF NEW.outcome IN ('satisfactory','accepted') AND NEW.checks->>entry.key<>'YES' THEN RAISE EXCEPTION 'Positive assurance cannot hide uncertainty'; END IF;
 END LOOP;
 IF NEW.outcome IN ('satisfactory','accepted') AND NOT sanitization_supported(a) THEN RAISE EXCEPTION 'Unsupported media/technique or destruction concern cannot satisfy assurance'; END IF;
 IF NEW.kind='verification' AND NOT (prior.kind='attempt' OR (prior.kind='verification' AND prior.outcome='satisfactory' AND NOT sanitization_dependencies(prior))) THEN RAISE EXCEPTION 'Verification requires unassured work; negative evidence requires a new attempt'; END IF;
 IF NEW.kind='validation' AND NOT (prior.kind='verification' AND prior.outcome='satisfactory' AND sanitization_dependencies(prior)) THEN RAISE EXCEPTION 'Validation requires separate current satisfactory verification'; END IF;
 IF NEW.kind='revalidation' AND NOT (prior.kind IN ('validation','revalidation') AND prior.outcome='accepted' AND NOT sanitization_dependencies(prior)) THEN RAISE EXCEPTION 'Revalidation requires latest previously accepted evidence and genuine dependency drift'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER validate_sanitization_record BEFORE INSERT ON sanitization_records FOR EACH ROW EXECUTE FUNCTION sanitization_insert_guard();

CREATE FUNCTION sanitization_current_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR (TG_OP='INSERT' AND NEW.version<>1) OR (TG_OP='UPDATE' AND (NEW.subject_id<>OLD.subject_id OR NEW.version<>OLD.version+1)) THEN RAISE EXCEPTION 'Sanitization projection requires retained consecutive ledger'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER retain_sanitization_current BEFORE INSERT OR UPDATE OR DELETE ON sanitization_current FOR EACH ROW EXECUTE FUNCTION sanitization_current_guard();

CREATE FUNCTION sanitization_integrity(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE cur sanitization_current; last_record sanitization_records; r sanitization_records; seq bigint:=0; pred uuid; destroyed boolean:=false; p sanitization_policies;
BEGIN
 SELECT * INTO cur FROM sanitization_current WHERE subject_id=ref;
 FOR r IN SELECT * FROM sanitization_records WHERE subject_id=ref ORDER BY version LOOP
 seq:=seq+1;
 SELECT * INTO p FROM sanitization_policies WHERE id=r.policy_id;
 IF r.version<>seq OR r.predecessor_id IS DISTINCT FROM pred OR NOT EXISTS (SELECT 1 FROM domain_events e JOIN commands c ON c.id=e.command_id
 WHERE e.id=r.event_id AND e.subject_id=ref AND e.aggregate_kind='sanitization' AND e.aggregate_id=ref AND e.aggregate_version=r.version AND e.actor_id=r.actor_id AND e.command_id=r.command_id
 AND e.type='sanitization_'||r.kind AND e.occurred_at=r.observed_at AND e.recorded_at=r.recorded_at AND c.actor_id=r.actor_id AND c.status='completed' AND c.action='sanitization_'||r.kind||':'||ref::text AND c.safe_response->>'record_id'=r.id::text)
 THEN RAISE EXCEPTION 'Incomplete immutable sanitization command/event chain'; END IF;
 IF r.kind='attempt' AND p.definition->'techniques'->r.technique->>'method'='destroy' THEN destroyed:=true; END IF;
 last_record:=r; pred:=r.id;
 END LOOP;
 IF (seq=0 AND cur.subject_id IS NOT NULL) OR (seq>0 AND (cur.subject_id IS NULL OR cur.version<>seq OR cur.record_id IS DISTINCT FROM last_record.id OR cur.attempt_id IS DISTINCT FROM last_record.attempt_id OR cur.destruction_reported IS DISTINCT FROM destroyed))
 OR EXISTS (SELECT 1 FROM domain_events e WHERE e.subject_id=ref AND e.aggregate_kind='sanitization' AND NOT EXISTS (SELECT 1 FROM sanitization_records linked WHERE linked.event_id=e.id))
 THEN RAISE EXCEPTION 'Sanitization gate must project newest complete evidence without erasing destruction'; END IF;
END; $$;
CREATE FUNCTION sanitization_final() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM sanitization_integrity(NEW.subject_id);
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER sanitization_record_complete AFTER INSERT ON sanitization_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sanitization_final();
CREATE CONSTRAINT TRIGGER sanitization_current_complete AFTER INSERT OR UPDATE ON sanitization_current DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sanitization_final();
CREATE CONSTRAINT TRIGGER sanitization_event_complete AFTER INSERT ON domain_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.aggregate_kind='sanitization') EXECUTE FUNCTION sanitization_final();
