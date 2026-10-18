CREATE TABLE refurbishment_profiles (version text PRIMARY KEY, definition jsonb NOT NULL, checksum text NOT NULL);
CREATE TABLE refurbishment_records (
 id uuid PRIMARY KEY, subject_id uuid NOT NULL REFERENCES items(subject_id), version bigint NOT NULL CHECK(version>0),
 predecessor_id uuid REFERENCES refurbishment_records(id), kind text NOT NULL CHECK(kind IN ('work','test','fan_na','authorize','withdraw','exception','acknowledge')),
 result text NOT NULL CHECK(result IN ('completed','failed','incomplete','passed','not_applicable','pending','withdrawn','exception','acknowledged')),
 configuration text CHECK(configuration IN ('UNCHANGED','CHANGED','UNKNOWN')), profile_version text REFERENCES refurbishment_profiles(version),
 answers jsonb NOT NULL DEFAULT '{}', na_reasons_ciphertext text,
 work_id uuid REFERENCES refurbishment_records(id), authorization_id uuid REFERENCES refurbishment_records(id),
 context jsonb NOT NULL, actor_id uuid NOT NULL REFERENCES users(id), command_id uuid NOT NULL UNIQUE REFERENCES commands(id), event_id uuid NOT NULL UNIQUE REFERENCES domain_events(id),
 observed_at timestamptz NOT NULL, recorded_at timestamptz NOT NULL, reason_ciphertext text NOT NULL,
 attachment_id uuid REFERENCES attachments(id), reference_ciphertext text,
 UNIQUE(subject_id,version), CHECK ((attachment_id IS NULL)<>(reference_ciphertext IS NULL)),
 CHECK((kind='work' AND result IN ('completed','failed','incomplete') AND configuration IS NOT NULL) OR (kind='test' AND result IN ('passed','failed','incomplete') AND profile_version IS NOT NULL AND work_id IS NOT NULL) OR (kind='fan_na' AND result='not_applicable') OR (kind='authorize' AND result='pending') OR (kind='withdraw' AND result='withdrawn' AND authorization_id IS NOT NULL) OR (kind='exception' AND result='exception' AND authorization_id IS NOT NULL) OR (kind='acknowledge' AND result='acknowledged' AND authorization_id IS NOT NULL))
);
CREATE TABLE refurbishment_current (subject_id uuid PRIMARY KEY REFERENCES items(subject_id), version bigint NOT NULL, record_id uuid NOT NULL UNIQUE REFERENCES refurbishment_records(id));
CREATE TABLE reuse_authorizations (id uuid PRIMARY KEY REFERENCES refurbishment_records(id), subject_id uuid NOT NULL REFERENCES items(subject_id), state text NOT NULL CHECK(state IN ('pending','withdrawn','exception','acknowledged')), record_id uuid NOT NULL UNIQUE REFERENCES refurbishment_records(id));
CREATE UNIQUE INDEX one_pending_reuse ON reuse_authorizations(subject_id) WHERE state='pending';
CREATE TABLE reuse_manifest (
 authorization_id uuid NOT NULL REFERENCES reuse_authorizations(id), subject_id uuid NOT NULL REFERENCES items(subject_id),
 owner_id uuid NOT NULL REFERENCES users(id), party_id uuid NOT NULL REFERENCES parties(id), event_id uuid NOT NULL REFERENCES domain_events(id), custody_version bigint NOT NULL, subject_version bigint NOT NULL,
 PRIMARY KEY(authorization_id,subject_id)
);
CREATE TABLE reuse_return_items (
 subject_id uuid PRIMARY KEY REFERENCES items(subject_id), authorization_id uuid NOT NULL, record_id uuid NOT NULL REFERENCES refurbishment_records(id),
 event_id uuid NOT NULL UNIQUE REFERENCES domain_events(id), recipient_party_id uuid NOT NULL REFERENCES parties(id), custody_version bigint NOT NULL, subject_version bigint NOT NULL,
 FOREIGN KEY(authorization_id,subject_id) REFERENCES reuse_manifest(authorization_id,subject_id)
);
CREATE FUNCTION reuse_manifest_snapshot(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT COALESCE(jsonb_agg(jsonb_build_object('subject_id',s.id,'owner_id',i.owner_user_id,'party_id',cc.party_id,'event_id',cc.last_event_id,'custody_version',cc.version,'subject_version',s.version,'processing_state',i.processing_state,'category_id',i.category_id,'subtype',i.subtype) ORDER BY s.id),'[]')
 FROM subjects s JOIN items i ON i.subject_id=s.id JOIN custody_current cc ON cc.subject_id=s.id
 WHERE s.id=ref OR s.id IN(SELECT medium_id FROM media_inventory_entries WHERE parent_id=ref AND medium_id IS NOT NULL)
$$;
CREATE FUNCTION refurbishment_configuration(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('manifest',reuse_manifest_snapshot(ref),'assessment',(SELECT to_jsonb(a) FROM assessment_current a WHERE subject_id=ref),'inventory',(SELECT to_jsonb(a) FROM media_inventory_current a WHERE parent_id=ref),'entries',COALESCE((SELECT jsonb_agg(to_jsonb(e) ORDER BY e.id) FROM media_inventory_entries e WHERE e.parent_id=ref),'[]'))
$$;
CREATE FUNCTION refurbishment_context(ref uuid, actor uuid, facility uuid DEFAULT NULL) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('configuration',refurbishment_configuration(ref),'staff',(SELECT jsonb_build_object('actor_id',u.id,'session_version',u.session_version,'status',u.status,'membership',to_jsonb(m),'facility_party_id',p.id,'facility_version',l.version,'facility_active',l.active,'facility_review',l.review_status,'operator_version',o.version,'operator_review',o.review_status) FROM users u JOIN memberships m ON m.user_id=u.id JOIN parties p ON p.id=COALESCE(facility,(SELECT party_id FROM custody_current WHERE subject_id=ref)) JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id WHERE u.id=actor),
 'assurance',COALESCE((SELECT jsonb_agg(jsonb_build_object('subject_id',i.subject_id,'assurance',to_jsonb(sc),'authorization',to_jsonb(ma),'assessment',to_jsonb(ac)) ORDER BY i.subject_id) FROM items i LEFT JOIN sanitization_current sc ON sc.subject_id=i.subject_id LEFT JOIN media_authorization_current ma ON ma.subject_id=i.subject_id LEFT JOIN assessment_current ac ON ac.subject_id=i.subject_id WHERE i.subject_id=ref OR i.subject_id IN(SELECT medium_id FROM media_inventory_entries WHERE parent_id=ref)),'[]'),
 'work_id',(SELECT id FROM refurbishment_records WHERE subject_id=ref AND kind='work' ORDER BY version DESC LIMIT 1),'test_id',(SELECT id FROM refurbishment_records WHERE subject_id=ref AND kind='test' ORDER BY version DESC LIMIT 1),'na_id',(SELECT id FROM refurbishment_records WHERE subject_id=ref AND kind='fan_na' ORDER BY version DESC LIMIT 1))
$$;
CREATE FUNCTION refurbishment_ready(ref uuid, actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT inspection_scope(ref,actor) AND NOT EXISTS(SELECT 1 FROM assessments WHERE subject_id=ref AND hard_hold)
 AND EXISTS(SELECT 1 FROM items i JOIN assessment_current ac ON ac.subject_id=i.subject_id JOIN decisions d ON d.id=ac.decision_id WHERE i.subject_id=ref AND i.intake_class IN ('whole_device','standalone_part') AND d.pathway='refurbish')
 AND NOT EXISTS(SELECT 1 FROM media_inventory_entries WHERE medium_id=ref)
 AND NOT EXISTS(SELECT 1 FROM media_inventory_entries WHERE parent_id=ref AND state IN ('unresolved','disputed'))
 AND NOT EXISTS(SELECT 1 FROM refurbishment_records WHERE subject_id=ref AND kind='test' AND (answers->>'safety' IN ('FAIL','UNKNOWN') OR answers->>'configuration' IN ('FAIL','UNKNOWN')))
 AND NOT EXISTS(SELECT 1 FROM refurbishment_records WHERE subject_id=ref AND (kind='exception' OR (kind='work' AND configuration<>'UNCHANGED')))
 AND NOT EXISTS(SELECT 1 FROM refurbishment_records r JOIN assessment_current ac ON ac.subject_id=r.subject_id WHERE r.subject_id=ref AND r.kind IN ('work','test') AND r.result IN ('failed','incomplete') AND (ac.assessment_id IS NOT DISTINCT FROM (r.context->'configuration'->'assessment'->>'assessment_id')::uuid OR ac.version<=(r.context->'configuration'->'assessment'->>'version')::bigint))
$$;
CREATE FUNCTION refurbishment_data_ready(ref uuid) RETURNS boolean LANGUAGE plpgsql STABLE AS $$
DECLARE i items; gate jsonb; r refurbishment_records;
BEGIN
 SELECT * INTO i FROM items WHERE subject_id=ref;
 IF i.intake_class='whole_device' THEN
 gate:=media_coverage(ref); RETURN gate->>'status' IN ('satisfied_attested','not_applicable_attested') AND NOT (gate->>'destruction_concern')::boolean;
 ELSIF i.subtype IN ('hdd','ssd') THEN
 RETURN sanitization_state(ref)='satisfied' AND NOT COALESCE((SELECT destruction_reported FROM sanitization_current WHERE subject_id=ref),false);
 ELSIF i.subtype='ict_cooling_fan' THEN
 SELECT * INTO r FROM refurbishment_records WHERE subject_id=ref AND kind='fan_na' ORDER BY version DESC LIMIT 1;
 RETURN r.id IS NOT NULL AND r.context->'configuration'=refurbishment_configuration(ref) AND EXISTS(SELECT 1 FROM assessment_current ac JOIN assessments a ON a.id=ac.assessment_id WHERE ac.subject_id=ref AND a.data_status='NO');
 END IF;
 RETURN false;
END; $$;
CREATE FUNCTION refurbishment_work_ready(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM refurbishment_records w JOIN custody_current cc ON cc.subject_id=w.subject_id
 WHERE w.id=(SELECT id FROM refurbishment_records WHERE subject_id=ref AND kind='work' ORDER BY version DESC LIMIT 1)
 AND w.result='completed' AND w.configuration='UNCHANGED' AND EXISTS(SELECT 1 FROM jsonb_array_elements(w.context->'configuration'->'manifest') source WHERE (source->>'subject_id')::uuid=ref AND (source->>'party_id')::uuid=cc.party_id AND (source->>'event_id')::uuid=cc.last_event_id AND (source->>'custody_version')::bigint=cc.version))
$$;
CREATE FUNCTION refurbishment_test_ready(ref uuid) RETURNS boolean LANGUAGE plpgsql STABLE AS $$
DECLARE w refurbishment_records; t refurbishment_records;
BEGIN
 SELECT * INTO w FROM refurbishment_records WHERE subject_id=ref AND kind='work' ORDER BY version DESC LIMIT 1;
 SELECT * INTO t FROM refurbishment_records WHERE subject_id=ref AND kind='test' ORDER BY version DESC LIMIT 1;
 RETURN w.id IS NOT NULL AND t.id IS NOT NULL AND w.result='completed' AND w.configuration='UNCHANGED' AND t.result='passed' AND t.work_id=w.id AND t.context->'configuration'=refurbishment_configuration(ref) AND refurbishment_work_ready(ref);
END; $$;
CREATE FUNCTION reuse_source_matches(ref uuid, party uuid, ev uuid, cv bigint, sv bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM reuse_return_items ri JOIN reuse_manifest m USING(authorization_id,subject_id) JOIN refurbishment_records r ON r.id=ri.record_id JOIN reuse_authorizations a ON a.id=ri.authorization_id JOIN custody_current cc ON cc.subject_id=ri.subject_id JOIN subjects s ON s.id=ri.subject_id JOIN items i ON i.subject_id=s.id JOIN parties p ON p.id=ri.recipient_party_id
 WHERE ri.subject_id=ref AND m.party_id=party AND m.event_id=ev AND m.custody_version=cv AND m.subject_version=sv AND a.state='acknowledged' AND a.record_id=r.id AND r.kind='acknowledge' AND r.actor_id=m.owner_id
 AND ri.custody_version=cv+1 AND ri.subject_version=sv+1 AND cc.party_id=ri.recipient_party_id AND cc.last_event_id=ri.event_id AND cc.version=ri.custody_version AND s.version=ri.subject_version AND i.processing_state='released' AND i.owner_user_id=m.owner_id AND p.kind='user' AND p.user_id=m.owner_id)
$$;
CREATE FUNCTION reuse_target_matches(ref uuid, party uuid, ev uuid, cv bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM reuse_return_items ri JOIN reuse_manifest m USING(authorization_id,subject_id) JOIN refurbishment_records r ON r.id=ri.record_id JOIN reuse_authorizations a ON a.id=ri.authorization_id
 WHERE ri.subject_id=ref AND ri.recipient_party_id=party AND ri.event_id=ev AND ri.custody_version=cv AND ri.custody_version=m.custody_version+1 AND ri.subject_version=m.subject_version+1 AND a.state='acknowledged' AND r.kind='acknowledge' AND r.actor_id=m.owner_id)
$$;
CREATE FUNCTION refurbishment_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cur refurbishment_current; a refurbishment_records; w refurbishment_records; profile jsonb; q record; key text; value text; expected text; staff boolean; owner uuid;
BEGIN
 SELECT * INTO cur FROM refurbishment_current WHERE subject_id=NEW.subject_id FOR UPDATE;
 IF NEW.version<>COALESCE(cur.version,0)+1 OR NEW.predecessor_id IS DISTINCT FROM cur.record_id OR NEW.observed_at>clock_timestamp() OR NEW.recorded_at<NEW.observed_at
 OR NEW.observed_at<COALESCE((SELECT date_trunc('minute',observed_at) FROM refurbishment_records WHERE id=cur.record_id),(SELECT date_trunc('minute',occurred_at) FROM custody_current cc JOIN domain_events e ON e.id=cc.last_event_id WHERE cc.subject_id=NEW.subject_id)) THEN RAISE EXCEPTION 'Reuse requires current consecutive versions and observed time'; END IF;
 IF NEW.attachment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attachments WHERE id=NEW.attachment_id AND uploaded_by=NEW.actor_id AND privacy_class='private') THEN RAISE EXCEPTION 'Private evidence must belong to actor'; END IF;
 SELECT owner_user_id INTO owner FROM items WHERE subject_id=NEW.subject_id;
 IF NEW.kind IN ('acknowledge','exception') THEN
 SELECT r.* INTO a FROM refurbishment_records r JOIN reuse_authorizations x ON x.id=r.id WHERE r.id=NEW.authorization_id AND x.state='pending' AND x.subject_id=NEW.subject_id;
 IF a.id IS NULL OR owner<>NEW.actor_id OR NOT EXISTS(SELECT 1 FROM users u JOIN memberships m ON m.user_id=u.id WHERE u.id=NEW.actor_id AND u.status='active' AND m.active AND m.role='consumer') THEN RAISE EXCEPTION 'Only the active existing owner may acknowledge the pending exact return'; END IF;
 IF NEW.kind='acknowledge' THEN
 IF a.context<>refurbishment_context(NEW.subject_id,a.actor_id) OR NEW.context<>a.context OR NOT refurbishment_ready(NEW.subject_id,a.actor_id) OR NOT refurbishment_test_ready(NEW.subject_id) OR NOT refurbishment_data_ready(NEW.subject_id) THEN RAISE EXCEPTION 'Return authorization is stale or release gates are blocked'; END IF;
 IF NEW.answers IS DISTINCT FROM (SELECT jsonb_object_agg(subject_id::text,'RECEIVED'::text) FROM reuse_manifest WHERE authorization_id=a.id) THEN RAISE EXCEPTION 'Every exact manifest item requires explicit actual receipt'; END IF;
 END IF;
 ELSE
 IF (NEW.kind<>'withdraw' AND NOT refurbishment_ready(NEW.subject_id,NEW.actor_id)) OR (NEW.kind='withdraw' AND NOT EXISTS(SELECT 1 FROM users u JOIN memberships m ON m.user_id=u.id JOIN custody_current cc ON cc.subject_id=NEW.subject_id JOIN parties p ON p.id=cc.party_id JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id WHERE u.id=NEW.actor_id AND u.status='active' AND m.active AND m.role='processor' AND m.place_id=l.id AND m.organization_id=o.id AND l.kind='facility' AND l.active AND l.review_status='approved' AND o.review_status='approved' AND o.kind IN ('processor','both'))) OR NEW.context<>refurbishment_context(NEW.subject_id,NEW.actor_id) THEN RAISE EXCEPTION 'Work requires current compatible facility, REFURBISH decision and no unresolved restrictions'; END IF;
 IF NEW.kind='test' THEN
 SELECT * INTO w FROM refurbishment_records WHERE subject_id=NEW.subject_id AND kind='work' ORDER BY version DESC LIMIT 1;
 IF w.id IS NULL OR NEW.work_id IS DISTINCT FROM w.id OR w.result<>'completed' OR w.configuration<>'UNCHANGED' OR NOT refurbishment_work_ready(NEW.subject_id) THEN RAISE EXCEPTION 'Test requires latest applicable completed unchanged-configuration work'; END IF;
 SELECT p.definition->'profiles'->CASE WHEN c.code IN ('peripheral','standalone_part') THEN i.subtype ELSE c.code END INTO profile FROM refurbishment_profiles p JOIN items i ON i.subject_id=NEW.subject_id JOIN categories c ON c.id=i.category_id WHERE p.version=NEW.profile_version AND p.version='REUSE-TEST-1.0';
 IF profile IS NULL OR jsonb_typeof(NEW.answers)<>'object' OR (SELECT count(*) FROM jsonb_object_keys(profile))<>(SELECT count(*) FROM jsonb_object_keys(NEW.answers)) THEN RAISE EXCEPTION 'Incomplete frozen functional profile'; END IF;
 expected:='passed';
 FOR q IN SELECT * FROM jsonb_each(profile) LOOP
 value:=NEW.answers->>q.key;
 IF value IS NULL OR NOT (q.value->'values' ? value) OR (value='NOT_APPLICABLE' AND NEW.na_reasons_ciphertext IS NULL) THEN RAISE EXCEPTION 'Invalid functional answer or missing explicit N/A reason'; END IF;
 IF value='FAIL' THEN expected:='failed'; ELSIF value='UNKNOWN' AND expected<>'failed' THEN expected:='incomplete'; END IF;
 END LOOP;
 IF NEW.result<>expected THEN RAISE EXCEPTION 'Functional result cannot override recorded answers'; END IF;
 ELSIF NEW.kind='fan_na' THEN
 IF NOT EXISTS(SELECT 1 FROM items i JOIN assessment_current ac ON ac.subject_id=i.subject_id JOIN assessments inspector ON inspector.id=ac.assessment_id WHERE i.subject_id=NEW.subject_id AND i.intake_class='standalone_part' AND i.subtype='ict_cooling_fan' AND inspector.data_status='NO') THEN RAISE EXCEPTION 'Fan N/A requires current qualifying basic-fan technician findings and evidence'; END IF;
 ELSIF NEW.kind='authorize' THEN
 IF NOT refurbishment_test_ready(NEW.subject_id) OR NOT refurbishment_data_ready(NEW.subject_id) OR EXISTS(SELECT 1 FROM reuse_authorizations WHERE subject_id=NEW.subject_id AND state='pending') THEN RAISE EXCEPTION 'Release needs current passed work/test and live data gate; withdraw older pending authorization'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(NEW.context->'configuration'->'manifest') m WHERE (m->>'owner_id')::uuid<>owner OR m->>'party_id'<>NEW.context->'staff'->>'facility_party_id' OR EXISTS(SELECT 1 FROM handover_items WHERE subject_id=(m->>'subject_id')::uuid AND active) OR EXISTS(SELECT 1 FROM assessments WHERE subject_id=(m->>'subject_id')::uuid AND hard_hold)) THEN RAISE EXCEPTION 'Manifest requires consistent ownership, safe identity and coherent control'; END IF;
 ELSIF NEW.kind='withdraw' THEN
 IF NOT EXISTS(SELECT 1 FROM reuse_authorizations WHERE id=NEW.authorization_id AND subject_id=NEW.subject_id AND state='pending') THEN RAISE EXCEPTION 'Only pending authorization may be withdrawn'; END IF;
 END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER validate_refurbishment BEFORE INSERT ON refurbishment_records FOR EACH ROW EXECUTE FUNCTION refurbishment_insert_guard();
CREATE FUNCTION refurbishment_projection_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r refurbishment_records;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Reuse history and projections are retained'; END IF;
 SELECT * INTO r FROM refurbishment_records WHERE id=NEW.record_id;
 IF TG_TABLE_NAME='refurbishment_current' THEN
 IF r.subject_id IS DISTINCT FROM NEW.subject_id OR r.version IS DISTINCT FROM NEW.version OR NEW.version<>(CASE WHEN TG_OP='INSERT' THEN 1 ELSE OLD.version+1 END) OR (TG_OP='UPDATE' AND NEW.subject_id<>OLD.subject_id) THEN RAISE EXCEPTION 'Reuse current must project consecutive retained record'; END IF;
 ELSE
 IF TG_OP='INSERT' THEN
 IF NEW.id<>r.id OR r.kind<>'authorize' OR NEW.state<>'pending' OR NEW.subject_id<>r.subject_id THEN RAISE EXCEPTION 'Authorization must project human authorization'; END IF;
 ELSE
 IF NEW.id<>OLD.id OR NEW.subject_id<>OLD.subject_id OR OLD.state<>'pending' OR r.authorization_id IS DISTINCT FROM NEW.id OR r.subject_id<>NEW.subject_id OR NEW.state<>(CASE r.kind WHEN 'acknowledge' THEN 'acknowledged' WHEN 'withdraw' THEN 'withdrawn' WHEN 'exception' THEN 'exception' ELSE '' END) THEN RAISE EXCEPTION 'Authorization terminal state needs matching retained action'; END IF;
 END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER project_refurbishment BEFORE INSERT OR UPDATE OR DELETE ON refurbishment_current FOR EACH ROW EXECUTE FUNCTION refurbishment_projection_guard();
CREATE TRIGGER project_reuse_authorization BEFORE INSERT OR UPDATE OR DELETE ON reuse_authorizations FOR EACH ROW EXECUTE FUNCTION refurbishment_projection_guard();
CREATE FUNCTION refurbishment_integrity(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE r refurbishment_records; prev uuid; seq bigint:=0; cur refurbishment_current; a reuse_authorizations; auth refurbishment_records; ack refurbishment_records; m reuse_manifest; ri reuse_return_items; snapshot jsonb; actual jsonb;
BEGIN
 SELECT * INTO cur FROM refurbishment_current WHERE subject_id=ref;
 FOR r IN SELECT * FROM refurbishment_records WHERE subject_id=ref ORDER BY version LOOP
 seq:=seq+1;
 IF r.version<>seq OR r.predecessor_id IS DISTINCT FROM prev OR NOT EXISTS(SELECT 1 FROM domain_events e JOIN commands c ON c.id=e.command_id WHERE e.id=r.event_id AND e.subject_id=ref AND e.aggregate_kind='refurbishment' AND e.aggregate_id=ref AND e.aggregate_version=r.version AND e.type='refurbishment_'||r.kind AND e.actor_id=r.actor_id AND e.command_id=r.command_id AND e.occurred_at=r.observed_at AND e.recorded_at=r.recorded_at AND c.actor_id=r.actor_id AND c.status='completed' AND c.action='refurbishment_'||r.kind||':'||ref::text AND c.safe_response->>'record_id'=r.id::text) THEN RAISE EXCEPTION 'Incomplete reuse command/event history'; END IF;
 IF r.kind='authorize' AND NOT EXISTS(SELECT 1 FROM reuse_authorizations WHERE id=r.id) THEN RAISE EXCEPTION 'Authorization record needs reciprocal projection'; END IF;
 IF r.kind IN ('acknowledge','withdraw','exception') AND NOT EXISTS(SELECT 1 FROM reuse_authorizations WHERE id=r.authorization_id AND record_id=r.id) THEN RAISE EXCEPTION 'Terminal action must project authorization'; END IF;
 prev:=r.id;
 END LOOP;
 IF (seq=0 AND cur.subject_id IS NOT NULL) OR (seq>0 AND (cur.version IS DISTINCT FROM seq OR cur.record_id IS DISTINCT FROM prev)) THEN RAISE EXCEPTION 'Reuse projection must retain latest history'; END IF;
 FOR a IN SELECT * FROM reuse_authorizations WHERE subject_id=ref LOOP
 SELECT * INTO auth FROM refurbishment_records WHERE id=a.id;
 SELECT COALESCE(jsonb_agg(jsonb_build_object('subject_id',m0.subject_id,'owner_id',m0.owner_id,'party_id',m0.party_id,'event_id',m0.event_id,'custody_version',m0.custody_version,'subject_version',m0.subject_version) ORDER BY m0.subject_id),'[]') INTO actual FROM reuse_manifest m0 WHERE authorization_id=a.id;
 SELECT COALESCE(jsonb_agg(x - ARRAY['processing_state','category_id','subtype'] ORDER BY x->>'subject_id'),'[]') INTO snapshot FROM jsonb_array_elements(auth.context->'configuration'->'manifest') x;
 IF actual<>snapshot OR NOT EXISTS(SELECT 1 FROM reuse_manifest WHERE authorization_id=a.id AND subject_id=ref) THEN RAISE EXCEPTION 'Frozen exact manifest must match authorization context'; END IF;
 IF a.state='acknowledged' THEN
 SELECT * INTO ack FROM refurbishment_records WHERE id=a.record_id;
 IF ack.kind<>'acknowledge' OR ack.authorization_id<>a.id OR ack.context<>auth.context OR ack.answers IS DISTINCT FROM (SELECT jsonb_object_agg(subject_id::text,'RECEIVED'::text) FROM reuse_manifest WHERE authorization_id=a.id) THEN RAISE EXCEPTION 'Acknowledgment must preserve exact human authorization'; END IF;
 FOR m IN SELECT * FROM reuse_manifest WHERE authorization_id=a.id LOOP
 SELECT * INTO ri FROM reuse_return_items WHERE subject_id=m.subject_id;
 IF ri.subject_id IS NULL OR ri.authorization_id<>a.id OR ri.record_id<>ack.id OR NOT reuse_source_matches(m.subject_id,m.party_id,m.event_id,m.custody_version,m.subject_version)
 OR NOT EXISTS(SELECT 1 FROM domain_events e WHERE e.id=ri.event_id AND e.subject_id=m.subject_id AND e.aggregate_kind='item' AND e.aggregate_id=m.subject_id AND e.aggregate_version=ri.subject_version AND e.type='reuse_returned' AND e.command_id=ack.command_id AND e.actor_id=ack.actor_id AND e.occurred_at=ack.observed_at AND e.recorded_at=ack.recorded_at) THEN RAISE EXCEPTION 'Complete manifest requires atomic acknowledged custody and retained event'; END IF;
 END LOOP;
 ELSE
 IF EXISTS(SELECT 1 FROM reuse_return_items WHERE authorization_id=a.id) THEN RAISE EXCEPTION 'Unacknowledged authorization cannot change custody'; END IF;
 END IF;
 END LOOP;
 IF EXISTS(SELECT 1 FROM domain_events WHERE aggregate_kind='refurbishment' AND aggregate_id=ref AND NOT EXISTS(SELECT 1 FROM refurbishment_records WHERE event_id=domain_events.id)) THEN RAISE EXCEPTION 'Unbacked reuse workflow event'; END IF;
END; $$;
CREATE FUNCTION refurbishment_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid; j jsonb; auth_record refurbishment_records; current_context jsonb;
BEGIN
 j:=to_jsonb(NEW);
 IF TG_TABLE_NAME='commands' THEN
 IF NEW.action LIKE 'refurbishment_%' AND NOT EXISTS(SELECT 1 FROM refurbishment_records WHERE command_id=NEW.id) THEN RAISE EXCEPTION 'Unbacked reuse command'; END IF;
 ELSIF TG_TABLE_NAME='domain_events' THEN
 IF NEW.aggregate_kind='refurbishment' THEN PERFORM refurbishment_integrity(NEW.aggregate_id);
 ELSIF NEW.type='reuse_returned' THEN
 IF NOT EXISTS(SELECT 1 FROM reuse_return_items WHERE event_id=NEW.id) THEN RAISE EXCEPTION 'Unbacked physical reuse event'; END IF;
 SELECT a.subject_id INTO ref FROM reuse_return_items ri JOIN reuse_authorizations a ON a.id=ri.authorization_id WHERE ri.event_id=NEW.id;
 PERFORM refurbishment_integrity(ref);
 END IF;
 ELSIF TG_TABLE_NAME IN ('refurbishment_records','refurbishment_current','reuse_authorizations') THEN
 IF TG_TABLE_NAME='refurbishment_records' AND j->>'kind'='acknowledge' THEN
 SELECT * INTO auth_record FROM refurbishment_records WHERE id=(j->>'authorization_id')::uuid;
 current_context:=refurbishment_context(auth_record.subject_id,auth_record.actor_id,(auth_record.context->'staff'->>'facility_party_id')::uuid);
 current_context:=jsonb_set(current_context,'{configuration,manifest}',auth_record.context->'configuration'->'manifest');
 IF current_context<>auth_record.context THEN RAISE EXCEPTION 'Dependencies changed during acknowledgment'; END IF;
 END IF;
 PERFORM refurbishment_integrity((j->>'subject_id')::uuid);
 ELSIF TG_TABLE_NAME IN ('reuse_manifest','reuse_return_items') THEN
 SELECT subject_id INTO ref FROM reuse_authorizations WHERE id=(j->>'authorization_id')::uuid; PERFORM refurbishment_integrity(ref);
 ELSE
 ref:=CASE WHEN TG_TABLE_NAME='subjects' THEN (j->>'id')::uuid ELSE (j->>'subject_id')::uuid END;
 IF EXISTS(SELECT 1 FROM items WHERE subject_id=ref AND processing_state='released') AND NOT EXISTS(SELECT 1 FROM reuse_return_items WHERE subject_id=ref) THEN RAISE EXCEPTION 'Released state requires acknowledged reuse ledger'; END IF;
 SELECT a.subject_id INTO ref FROM reuse_return_items ri JOIN reuse_authorizations a ON a.id=ri.authorization_id WHERE ri.subject_id=ref;
 IF ref IS NOT NULL THEN PERFORM refurbishment_integrity(ref); END IF;
 END IF;
 RETURN NULL;
END; $$;
CREATE FUNCTION retain_reuse_rows() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Committed reuse evidence and manifest are immutable'; END IF;
 IF TG_TABLE_NAME='reuse_return_items' THEN
 IF NOT EXISTS(SELECT 1 FROM reuse_manifest m JOIN reuse_authorizations a ON a.id=m.authorization_id JOIN refurbishment_records r ON r.id=a.record_id JOIN parties p ON p.id=NEW.recipient_party_id
 WHERE m.authorization_id=NEW.authorization_id AND m.subject_id=NEW.subject_id AND a.state='acknowledged' AND r.kind='acknowledge' AND r.id=NEW.record_id AND r.actor_id=m.owner_id AND p.kind='user' AND p.user_id=m.owner_id AND NEW.custody_version=m.custody_version+1 AND NEW.subject_version=m.subject_version+1) THEN RAISE EXCEPTION 'Return item must reference matching owner acknowledgment'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER retain_reuse_manifest BEFORE INSERT OR UPDATE OR DELETE ON reuse_manifest FOR EACH ROW EXECUTE FUNCTION retain_reuse_rows();
CREATE TRIGGER retain_reuse_return BEFORE INSERT OR UPDATE OR DELETE ON reuse_return_items FOR EACH ROW EXECUTE FUNCTION retain_reuse_rows();
CREATE TRIGGER retain_refurbishment BEFORE UPDATE OR DELETE ON refurbishment_records FOR EACH ROW EXECUTE FUNCTION protect_device_history();
CREATE TRIGGER retain_reuse_profiles BEFORE UPDATE OR DELETE ON refurbishment_profiles FOR EACH ROW EXECUTE FUNCTION protect_device_history();
