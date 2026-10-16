ALTER FUNCTION sanitization_ready(uuid,uuid) RENAME TO sanitization_ready_standalone;
ALTER FUNCTION sanitization_dependencies(sanitization_records) RENAME TO sanitization_dependencies_standalone;
CREATE FUNCTION sanitization_ready(ref uuid, actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN EXISTS(SELECT 1 FROM items WHERE subject_id=ref AND intake_class='processor_derived') THEN
 EXISTS(SELECT 1 FROM media_authorization_current mc JOIN media_authorizations a ON a.id=mc.record_id
 JOIN memberships m ON m.user_id=actor JOIN users u ON u.id=actor JOIN parties p ON p.id=a.facility_party_id
 JOIN places l ON l.id=p.place_id
 WHERE mc.subject_id=ref AND media_authorization_applicable(a) AND u.status='active' AND m.active AND m.role='processor' AND m.place_id=l.id AND m.organization_id=l.organization_id)
 ELSE sanitization_ready_standalone(ref,actor) AND NOT EXISTS(SELECT 1 FROM media_inventory_entries WHERE medium_id=ref AND state<>'confirmed') END
$$;
CREATE FUNCTION sanitization_dependencies(r sanitization_records) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN r.authorization_context_version=1 THEN r.authorization_id IS NULL AND r.placement_record_id IS NOT DISTINCT FROM (SELECT record_id FROM media_inventory_entries WHERE medium_id=r.subject_id) AND sanitization_dependencies_standalone(r)
 ELSE EXISTS(SELECT 1 FROM media_authorization_current mc JOIN media_authorizations a ON a.id=mc.record_id
 JOIN subjects s ON s.id=a.subject_id JOIN custody_current cc ON cc.subject_id=s.id JOIN parties p ON p.id=cc.party_id
 JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id
 JOIN users u ON u.id=r.actor_id JOIN memberships m ON m.id=r.membership_id AND m.user_id=u.id
 WHERE a.id=r.authorization_id AND mc.subject_id=r.subject_id AND media_authorization_applicable(a)
 AND s.version=r.source_subject_version AND cc.party_id=r.facility_party_id AND cc.last_event_id=r.custody_event_id AND cc.version=r.custody_version
 AND l.version=r.facility_version AND o.version=r.operator_version AND a.assessment_version=r.assessment_version AND a.assessment_id=r.assessment_id AND a.decision_id=r.decision_id
 AND u.session_version=r.actor_session_version AND m.version=r.membership_version AND sanitization_ready(r.subject_id,r.actor_id)) END
$$;
CREATE OR REPLACE FUNCTION sanitization_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cur sanitization_current; prior sanitization_records; a sanitization_records; pol sanitization_policies; tech jsonb; keys jsonb; entry record; cc custody_current; ac assessment_current; mv bigint; sv bigint; session_v bigint; facility_v bigint; operator_v bigint;
BEGIN
 IF NEW.placement_record_id IS DISTINCT FROM (SELECT record_id FROM media_inventory_entries WHERE medium_id=NEW.subject_id) THEN RAISE EXCEPTION 'Treatment must freeze current placement'; END IF;
 SELECT * INTO cc FROM custody_current WHERE subject_id=NEW.subject_id FOR UPDATE;
 SELECT * INTO cur FROM sanitization_current WHERE subject_id=NEW.subject_id FOR UPDATE;
 SELECT * INTO prior FROM sanitization_records WHERE id=cur.record_id;
 IF EXISTS(SELECT 1 FROM items WHERE subject_id=NEW.subject_id AND intake_class='processor_derived') THEN
 IF NEW.authorization_context_version<>2 OR NEW.authorization_id IS DISTINCT FROM (SELECT record_id FROM media_authorization_current WHERE subject_id=NEW.subject_id) THEN RAISE EXCEPTION 'Derived media requires explicit current parent authorization'; END IF;
 SELECT ac0.* INTO ac FROM assessment_current ac0 JOIN media_authorizations ma ON ma.parent_id=ac0.subject_id WHERE ma.id=NEW.authorization_id;
 ELSE
 IF NEW.authorization_context_version<>1 OR NEW.authorization_id IS NOT NULL THEN RAISE EXCEPTION 'Standalone assurance must retain own assessment authorization'; END IF;
 SELECT * INTO ac FROM assessment_current WHERE subject_id=NEW.subject_id;
 END IF;
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
