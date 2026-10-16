CREATE TABLE media_inventory_records (
 id uuid PRIMARY KEY, parent_id uuid NOT NULL REFERENCES items(subject_id) ON DELETE RESTRICT,
 version bigint NOT NULL CHECK(version>0), predecessor_id uuid REFERENCES media_inventory_records(id) ON DELETE RESTRICT,
 kind text NOT NULL CHECK(kind IN ('identify','link','unresolved','correct','attest')), entry_id uuid,
 medium_id uuid REFERENCES storage_assets(subject_id) ON DELETE RESTRICT,
 entry_state text CHECK(entry_state IN ('confirmed','unresolved','not_present','disputed')),
 completeness text CHECK(completeness IN ('YES','NO','UNKNOWN')), checks jsonb NOT NULL,
 source_subject_version bigint NOT NULL, custody_event_id uuid NOT NULL REFERENCES domain_events(id), custody_version bigint NOT NULL,
 facility_party_id uuid NOT NULL REFERENCES parties(id), facility_version bigint NOT NULL, operator_version bigint NOT NULL,
 assessment_id uuid REFERENCES assessments(id), assessment_version bigint NOT NULL, decision_id uuid REFERENCES decisions(id),
 actor_id uuid NOT NULL REFERENCES users(id), membership_id uuid NOT NULL REFERENCES memberships(id), membership_version bigint NOT NULL, actor_session_version bigint NOT NULL,
 command_id uuid NOT NULL UNIQUE REFERENCES commands(id), event_id uuid NOT NULL UNIQUE REFERENCES domain_events(id),
 observed_at timestamptz NOT NULL, recorded_at timestamptz NOT NULL,
 reason_ciphertext text NOT NULL, placement_ciphertext text,
 attachment_id uuid REFERENCES attachments(id), reference_ciphertext text,
 UNIQUE(parent_id,version), CHECK ((attachment_id IS NULL)<>(reference_ciphertext IS NULL)),
 CHECK ((kind='attest' AND entry_id IS NULL AND medium_id IS NULL AND entry_state IS NULL AND completeness IS NOT NULL)
 OR (kind<>'attest' AND entry_id IS NOT NULL AND entry_state IS NOT NULL AND completeness IS NULL))
);
CREATE TABLE media_inventory_current (
 parent_id uuid PRIMARY KEY REFERENCES items(subject_id), version bigint NOT NULL, record_id uuid NOT NULL UNIQUE REFERENCES media_inventory_records(id)
);
CREATE TABLE media_inventory_entries (
 id uuid PRIMARY KEY, parent_id uuid NOT NULL REFERENCES items(subject_id),
 medium_id uuid UNIQUE REFERENCES storage_assets(subject_id), state text NOT NULL CHECK(state IN ('confirmed','unresolved','not_present','disputed')),
 created_record_id uuid NOT NULL UNIQUE REFERENCES media_inventory_records(id), record_id uuid NOT NULL UNIQUE REFERENCES media_inventory_records(id),
 CHECK ((medium_id IS NOT NULL AND state IN ('confirmed','disputed')) OR (medium_id IS NULL AND state IN ('unresolved','not_present')))
);
ALTER TABLE media_inventory_records ADD FOREIGN KEY(entry_id) REFERENCES media_inventory_entries(id) DEFERRABLE INITIALLY DEFERRED;
CREATE INDEX media_entries_parent ON media_inventory_entries(parent_id);
CREATE TABLE media_origins (
 subject_id uuid PRIMARY KEY REFERENCES storage_assets(subject_id), parent_id uuid NOT NULL REFERENCES items(subject_id),
 entry_id uuid NOT NULL UNIQUE REFERENCES media_inventory_entries(id), record_id uuid NOT NULL UNIQUE REFERENCES media_inventory_records(id), event_id uuid NOT NULL UNIQUE REFERENCES domain_events(id)
);
CREATE TABLE media_authorizations (
 id uuid PRIMARY KEY, subject_id uuid NOT NULL REFERENCES storage_assets(subject_id), parent_id uuid NOT NULL REFERENCES items(subject_id), version bigint NOT NULL CHECK(version>0),
 predecessor_id uuid REFERENCES media_authorizations(id), inventory_version bigint NOT NULL,
 source_subject_version bigint NOT NULL, parent_subject_version bigint NOT NULL,
 custody_event_id uuid NOT NULL REFERENCES domain_events(id), custody_version bigint NOT NULL,
 parent_custody_event_id uuid NOT NULL REFERENCES domain_events(id), parent_custody_version bigint NOT NULL,
 facility_party_id uuid NOT NULL REFERENCES parties(id), facility_version bigint NOT NULL, operator_version bigint NOT NULL,
 assessment_id uuid NOT NULL REFERENCES assessments(id), assessment_version bigint NOT NULL, decision_id uuid NOT NULL REFERENCES decisions(id),
 actor_id uuid NOT NULL REFERENCES users(id), membership_id uuid NOT NULL REFERENCES memberships(id), membership_version bigint NOT NULL, actor_session_version bigint NOT NULL,
 checks jsonb NOT NULL, command_id uuid NOT NULL UNIQUE REFERENCES commands(id), event_id uuid NOT NULL UNIQUE REFERENCES domain_events(id),
 observed_at timestamptz NOT NULL, recorded_at timestamptz NOT NULL, reason_ciphertext text NOT NULL,
 attachment_id uuid REFERENCES attachments(id), reference_ciphertext text,
 UNIQUE(subject_id,version), CHECK((attachment_id IS NULL)<>(reference_ciphertext IS NULL))
);
CREATE TABLE media_authorization_current (
 subject_id uuid PRIMARY KEY REFERENCES storage_assets(subject_id), version bigint NOT NULL, record_id uuid NOT NULL UNIQUE REFERENCES media_authorizations(id)
);
CREATE FUNCTION media_parent_ready(ref uuid, actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT inspection_scope(ref,actor) AND EXISTS(SELECT 1 FROM items WHERE subject_id=ref AND intake_class='whole_device')
 AND NOT EXISTS(SELECT 1 FROM assessments WHERE subject_id=ref AND hard_hold)
$$;
CREATE FUNCTION media_record_applicable(r media_inventory_records) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM subjects s JOIN custody_current cc ON cc.subject_id=s.id JOIN parties p ON p.id=cc.party_id
 JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id
 JOIN users u ON u.id=r.actor_id JOIN memberships m ON m.id=r.membership_id AND m.user_id=u.id
 LEFT JOIN assessment_current ac ON ac.subject_id=s.id
 WHERE s.id=r.parent_id AND s.version=r.source_subject_version AND cc.last_event_id=r.custody_event_id AND cc.version=r.custody_version AND cc.party_id=r.facility_party_id
 AND l.version=r.facility_version AND o.version=r.operator_version AND COALESCE(ac.version,0)=r.assessment_version AND ac.assessment_id IS NOT DISTINCT FROM r.assessment_id AND ac.decision_id IS NOT DISTINCT FROM r.decision_id
 AND m.version=r.membership_version AND u.session_version=r.actor_session_version AND media_parent_ready(r.parent_id,r.actor_id))
$$;
CREATE FUNCTION media_authorization_applicable(a media_authorizations) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM media_inventory_entries e JOIN media_inventory_current ic ON ic.parent_id=e.parent_id
 JOIN subjects s ON s.id=e.medium_id JOIN subjects ps ON ps.id=e.parent_id JOIN items i ON i.subject_id=s.id
 JOIN custody_current cc ON cc.subject_id=s.id JOIN custody_current pc ON pc.subject_id=ps.id JOIN parties p ON p.id=pc.party_id
 JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id
 JOIN assessment_current ac ON ac.subject_id=ps.id JOIN decisions d ON d.id=ac.decision_id
 JOIN users u ON u.id=a.actor_id JOIN memberships m ON m.id=a.membership_id AND m.user_id=u.id
 WHERE e.medium_id=a.subject_id AND e.parent_id=a.parent_id AND e.state='confirmed' AND i.intake_class='processor_derived' AND i.subtype IN ('hdd','ssd') AND i.data_status='YES'
 AND s.version=a.source_subject_version AND ps.version=a.parent_subject_version AND ic.version=a.inventory_version
 AND cc.party_id=pc.party_id AND pc.party_id=a.facility_party_id AND cc.last_event_id=a.custody_event_id AND cc.version=a.custody_version
 AND pc.last_event_id=a.parent_custody_event_id AND pc.version=a.parent_custody_version
 AND l.version=a.facility_version AND o.version=a.operator_version AND ac.assessment_id=a.assessment_id AND ac.version=a.assessment_version AND ac.decision_id=a.decision_id AND d.pathway<>'hold'
 AND u.session_version=a.actor_session_version AND m.version=a.membership_version AND media_parent_ready(e.parent_id,a.actor_id)
 AND EXISTS(SELECT 1 FROM place_categories caps JOIN categories c ON c.id=caps.category_id WHERE caps.place_id=l.id AND caps.subtype=i.subtype AND c.code='standalone_part')
 AND NOT EXISTS(SELECT 1 FROM handover_items WHERE subject_id=e.medium_id AND active)
 AND NOT EXISTS(SELECT 1 FROM assessments WHERE subject_id=e.medium_id AND hard_hold))
$$;
CREATE FUNCTION media_coverage(ref uuid) RETURNS jsonb LANGUAGE plpgsql STABLE AS $$
DECLARE r media_inventory_records; data text; e record; reasons jsonb:='[]'; destroyed boolean:=false; gate text;
BEGIN
 SELECT a.data_status INTO data FROM assessment_current ac JOIN assessments a ON a.id=ac.assessment_id WHERE ac.subject_id=ref;
 SELECT ir.* INTO r FROM media_inventory_current ic JOIN media_inventory_records ir ON ir.id=ic.record_id WHERE ic.parent_id=ref;
 IF r.id IS NULL THEN reasons:=reasons||'"Media inventory missing"'::jsonb;
 ELSIF r.kind<>'attest' OR r.completeness<>'YES' THEN reasons:=reasons||'"Complete evidenced inventory attestation required"'::jsonb;
 ELSIF NOT media_record_applicable(r) THEN reasons:=reasons||'"Inventory applicability review required"'::jsonb; END IF;
 IF data IS NULL OR data='UNKNOWN' THEN reasons:=reasons||'"Current technician data-bearing status is unknown"'::jsonb; END IF;
 IF NOT EXISTS(SELECT 1 FROM assessment_current ac JOIN decisions d ON d.id=ac.decision_id WHERE ac.subject_id=ref AND d.pathway<>'hold')
 OR EXISTS(SELECT 1 FROM assessments WHERE subject_id=ref AND hard_hold) THEN reasons:=reasons||'"Current non-HOLD decision and no unresolved restriction required"'::jsonb; END IF;
 FOR e IN SELECT me.*,sa.media_kind FROM media_inventory_entries me LEFT JOIN storage_assets sa ON sa.subject_id=me.medium_id WHERE me.parent_id=ref LOOP
 IF e.state IN ('unresolved','disputed') THEN reasons:=reasons||'"Unresolved or disputed media identity remains"'::jsonb; END IF;
 IF e.medium_id IS NOT NULL THEN
 destroyed:=destroyed OR COALESCE((SELECT destruction_reported FROM sanitization_current WHERE subject_id=e.medium_id),false);
 IF data='NO' THEN reasons:=reasons||'"Technician NO conflicts with identified storage; reassess"'::jsonb; END IF;
 IF e.media_kind='other_nonvolatile' THEN reasons:=reasons||'"Treatment support unavailable"'::jsonb;
 ELSE
 gate:=sanitization_state(e.medium_id);
 IF gate<>'satisfied' THEN reasons:=reasons||jsonb_build_array('Media treatment: '||gate); END IF;
 END IF;
 END IF;
 END LOOP;
 IF data='YES' AND NOT EXISTS(SELECT 1 FROM media_inventory_entries WHERE parent_id=ref AND medium_id IS NOT NULL) THEN reasons:=reasons||'"Data-bearing parent requires identified media"'::jsonb; END IF;
 RETURN jsonb_build_object('status',CASE WHEN jsonb_array_length(reasons)>0 THEN 'blocked' WHEN data='NO' THEN 'not_applicable_attested' ELSE 'satisfied_attested' END,'reasons',reasons,'destruction_concern',destroyed,'reuse_authorized',false);
END; $$;
CREATE FUNCTION media_inventory_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cur media_inventory_current; e media_inventory_entries; cc custody_current; i items; p items; c custody_current; key text; keys text[];
BEGIN
 SELECT * INTO cur FROM media_inventory_current WHERE parent_id=NEW.parent_id FOR UPDATE;
 IF NEW.version<>COALESCE(cur.version,0)+1 OR NEW.predecessor_id IS DISTINCT FROM cur.record_id OR NOT media_record_applicable(NEW)
 OR NEW.observed_at>clock_timestamp() OR NEW.recorded_at<NEW.observed_at
 OR NEW.observed_at<(SELECT date_trunc('minute',occurred_at) FROM domain_events WHERE id=NEW.custody_event_id)
 OR (cur.record_id IS NOT NULL AND NEW.observed_at<(SELECT date_trunc('minute',observed_at) FROM media_inventory_records WHERE id=cur.record_id))
 THEN RAISE EXCEPTION 'Inventory requires current facility scope, custody and consecutive versions'; END IF;
 IF NEW.attachment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attachments WHERE id=NEW.attachment_id AND uploaded_by=NEW.actor_id AND privacy_class='private') THEN RAISE EXCEPTION 'Inventory evidence must belong to this actor'; END IF;
 keys:=CASE WHEN NEW.kind IN ('identify','link') THEN ARRAY['physical_match','ownership_consistent','safe_observation'] WHEN NEW.kind='attest' THEN ARRAY['all_areas_accounted','configuration_storage','identity_count_resolved','no_physical_change'] WHEN NEW.kind='correct' THEN ARRAY['observation_correction_only'] ELSE ARRAY[]::text[] END;
 IF jsonb_typeof(NEW.checks)<>'object' OR (SELECT count(*) FROM jsonb_object_keys(NEW.checks))<>cardinality(keys) THEN RAISE EXCEPTION 'Incomplete inventory checks'; END IF;
 FOREACH key IN ARRAY keys LOOP
 IF COALESCE(NEW.checks->>key,'') NOT IN ('YES','NO','UNKNOWN') OR ((NEW.kind<>'attest' OR NEW.completeness='YES') AND NEW.checks->>key<>'YES') THEN RAISE EXCEPTION 'Inventory cannot hide uncertainty'; END IF;
 END LOOP;
 IF NEW.kind='attest' THEN
 IF NEW.completeness='YES' AND EXISTS(SELECT 1 FROM media_inventory_entries WHERE parent_id=NEW.parent_id AND state IN ('unresolved','disputed')) THEN RAISE EXCEPTION 'Unresolved inventory blocks completeness'; END IF;
 ELSE
 SELECT * INTO e FROM media_inventory_entries WHERE id=NEW.entry_id FOR UPDATE;
 IF e.id IS NOT NULL AND (e.parent_id<>NEW.parent_id OR (NEW.kind<>'correct' AND e.state<>'unresolved')) THEN RAISE EXCEPTION 'Entry already identified or belongs to another parent'; END IF;
 IF NEW.kind IN ('identify','link') THEN
 SELECT * INTO i FROM items WHERE subject_id=NEW.medium_id;
 SELECT * INTO p FROM items WHERE subject_id=NEW.parent_id;
 SELECT * INTO c FROM custody_current WHERE subject_id=NEW.medium_id;
 IF i.subject_id IS NULL OR i.owner_user_id<>p.owner_user_id OR c.party_id IS DISTINCT FROM NEW.facility_party_id OR NEW.entry_state<>'confirmed'
 OR EXISTS(SELECT 1 FROM handover_items WHERE subject_id=NEW.medium_id AND active)
 OR EXISTS(SELECT 1 FROM assessments WHERE subject_id=NEW.medium_id AND hard_hold)
 OR EXISTS(SELECT 1 FROM receipt_discrepancies d JOIN receipt_outcomes ro ON ro.id=d.outcome_id JOIN request_items ri ON ri.id=ro.request_item_id WHERE ri.item_id=NEW.medium_id AND d.kind IN ('mismatch','safety_hold'))
 OR (NEW.kind='identify' AND i.intake_class<>'processor_derived')
 OR (NEW.kind='link' AND (i.intake_class<>'standalone_part' OR NOT inspection_scope(NEW.medium_id,NEW.actor_id)))
 THEN RAISE EXCEPTION 'Media identification requires compatible ownership, evidenced physical identity and coherent facility control'; END IF;
 ELSIF NEW.kind='unresolved' AND (NEW.medium_id IS NOT NULL OR NEW.entry_state<>'unresolved' OR e.id IS NOT NULL) THEN RAISE EXCEPTION 'Unknown observation cannot invent a medium';
 ELSIF NEW.kind='correct' AND (e.id IS NULL OR NEW.medium_id IS DISTINCT FROM e.medium_id OR (e.medium_id IS NOT NULL AND NEW.entry_state<>'disputed') OR (e.medium_id IS NULL AND NEW.entry_state NOT IN ('unresolved','not_present'))) THEN RAISE EXCEPTION 'Correction cannot remove, replace or merge a canonical medium'; END IF;
 IF NEW.placement_ciphertext IS NULL THEN RAISE EXCEPTION 'Distinguishing placement observation required'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER media_inventory_insert BEFORE INSERT ON media_inventory_records FOR EACH ROW EXECUTE FUNCTION media_inventory_insert_guard();
CREATE FUNCTION media_projection_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r media_inventory_records; a media_authorizations;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Media projections must retain history'; END IF;
 IF TG_TABLE_NAME='media_inventory_entries' THEN
 SELECT * INTO r FROM media_inventory_records WHERE id=NEW.record_id;
 IF r.entry_id IS DISTINCT FROM NEW.id OR r.parent_id IS DISTINCT FROM NEW.parent_id OR r.medium_id IS DISTINCT FROM NEW.medium_id OR r.entry_state IS DISTINCT FROM NEW.state
 OR (TG_OP='INSERT' AND NEW.created_record_id<>NEW.record_id)
 OR (TG_OP='UPDATE' AND (NEW.id<>OLD.id OR NEW.parent_id<>OLD.parent_id OR NEW.created_record_id<>OLD.created_record_id OR (OLD.medium_id IS NOT NULL AND NEW.medium_id IS DISTINCT FROM OLD.medium_id) OR r.version<=(SELECT version FROM media_inventory_records WHERE id=OLD.record_id))) THEN RAISE EXCEPTION 'Entry must project retained observation without unlinking identity'; END IF;
 ELSIF TG_TABLE_NAME='media_inventory_current' THEN
 SELECT * INTO r FROM media_inventory_records WHERE id=NEW.record_id;
 IF r.parent_id IS DISTINCT FROM NEW.parent_id OR r.version IS DISTINCT FROM NEW.version OR NEW.version<>(CASE WHEN TG_OP='INSERT' THEN 1 ELSE OLD.version+1 END) OR (TG_OP='UPDATE' AND NEW.parent_id<>OLD.parent_id) THEN RAISE EXCEPTION 'Inventory projection requires consecutive records'; END IF;
 ELSE
 SELECT * INTO a FROM media_authorizations WHERE id=NEW.record_id;
 IF a.subject_id IS DISTINCT FROM NEW.subject_id OR a.version IS DISTINCT FROM NEW.version OR NEW.version<>(CASE WHEN TG_OP='INSERT' THEN 1 ELSE OLD.version+1 END) OR (TG_OP='UPDATE' AND NEW.subject_id<>OLD.subject_id) THEN RAISE EXCEPTION 'Authorization projection requires consecutive records'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER retain_media_entries BEFORE INSERT OR UPDATE OR DELETE ON media_inventory_entries FOR EACH ROW EXECUTE FUNCTION media_projection_guard();
CREATE TRIGGER retain_inventory_projection BEFORE INSERT OR UPDATE OR DELETE ON media_inventory_current FOR EACH ROW EXECUTE FUNCTION media_projection_guard();
CREATE TRIGGER retain_authorization_projection BEFORE INSERT OR UPDATE OR DELETE ON media_authorization_current FOR EACH ROW EXECUTE FUNCTION media_projection_guard();
CREATE FUNCTION media_authorization_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cur media_authorization_current; key text;
BEGIN
 SELECT * INTO cur FROM media_authorization_current WHERE subject_id=NEW.subject_id FOR UPDATE;
 IF NEW.version<>COALESCE(cur.version,0)+1 OR NEW.predecessor_id IS DISTINCT FROM cur.record_id OR NOT media_authorization_applicable(NEW)
 OR NEW.observed_at>clock_timestamp() OR NEW.recorded_at<NEW.observed_at OR NEW.observed_at<(SELECT date_trunc('minute',observed_at) FROM media_inventory_records WHERE parent_id=NEW.parent_id ORDER BY version DESC LIMIT 1)
 OR jsonb_typeof(NEW.checks)<>'object' OR (SELECT count(*) FROM jsonb_object_keys(NEW.checks))<>4 THEN RAISE EXCEPTION 'Media authorization requires current parent, placement, safety and explicit media capability'; END IF;
 FOREACH key IN ARRAY ARRAY['identity_confirmed','safe_media','method_scope_known','no_unresolved_restriction'] LOOP
 IF NEW.checks->>key IS DISTINCT FROM 'YES' THEN RAISE EXCEPTION 'Uncertainty blocks treatment authorization'; END IF;
 END LOOP;
 IF NEW.attachment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attachments WHERE id=NEW.attachment_id AND uploaded_by=NEW.actor_id AND privacy_class='private') THEN RAISE EXCEPTION 'Authorization evidence must belong to actor'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER media_authorization_insert BEFORE INSERT ON media_authorizations FOR EACH ROW EXECUTE FUNCTION media_authorization_insert_guard();
CREATE FUNCTION media_inventory_integrity(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE r media_inventory_records; cur media_inventory_current; pred uuid; seq bigint:=0; e media_inventory_entries; latest media_inventory_records;
BEGIN
 SELECT * INTO cur FROM media_inventory_current WHERE parent_id=ref;
 FOR r IN SELECT * FROM media_inventory_records WHERE parent_id=ref ORDER BY version LOOP
 seq:=seq+1;
 IF r.version<>seq OR r.predecessor_id IS DISTINCT FROM pred OR NOT EXISTS(SELECT 1 FROM domain_events ev JOIN commands co ON co.id=ev.command_id WHERE ev.id=r.event_id AND ev.subject_id=ref AND ev.aggregate_kind='media_inventory' AND ev.aggregate_id=ref AND ev.aggregate_version=r.version AND ev.type='media_inventory_'||r.kind AND ev.actor_id=r.actor_id AND ev.command_id=r.command_id AND ev.occurred_at=r.observed_at AND ev.recorded_at=r.recorded_at AND co.actor_id=r.actor_id AND co.status='completed' AND co.action='media_inventory_'||r.kind||':'||ref::text AND co.safe_response->>'record_id'=r.id::text) THEN RAISE EXCEPTION 'Incomplete inventory command/event chain'; END IF;
 IF r.kind='identify' AND NOT EXISTS(SELECT 1 FROM media_origins WHERE record_id=r.id AND subject_id=r.medium_id AND parent_id=ref AND entry_id=r.entry_id) THEN RAISE EXCEPTION 'Identified media needs evidenced origin'; END IF;
 IF r.entry_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM media_inventory_entries WHERE id=r.entry_id AND parent_id=ref) THEN RAISE EXCEPTION 'Observation requires retained entry'; END IF;
 pred:=r.id;
 END LOOP;
 IF (seq=0 AND cur.parent_id IS NOT NULL) OR (seq>0 AND (cur.version IS DISTINCT FROM seq OR cur.record_id IS DISTINCT FROM pred)) THEN RAISE EXCEPTION 'Inventory projection must retain latest record'; END IF;
 FOR e IN SELECT * FROM media_inventory_entries WHERE parent_id=ref LOOP
 SELECT * INTO latest FROM media_inventory_records WHERE entry_id=e.id ORDER BY version DESC LIMIT 1;
 IF latest.id IS DISTINCT FROM e.record_id OR latest.medium_id IS DISTINCT FROM e.medium_id OR latest.entry_state IS DISTINCT FROM e.state OR NOT EXISTS(SELECT 1 FROM media_inventory_records WHERE id=e.created_record_id AND entry_id=e.id AND kind IN ('identify','link','unresolved')) THEN RAISE EXCEPTION 'Entry projection must match latest retained observation'; END IF;
 END LOOP;
 IF EXISTS(SELECT 1 FROM domain_events ev WHERE ev.aggregate_kind='media_inventory' AND ev.aggregate_id=ref AND NOT EXISTS(SELECT 1 FROM media_inventory_records WHERE event_id=ev.id)) THEN RAISE EXCEPTION 'Unbacked inventory event'; END IF;
END; $$;
CREATE FUNCTION media_origin_integrity(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE o media_origins; r media_inventory_records; i items; cc custody_current;
BEGIN
 SELECT * INTO i FROM items WHERE subject_id=ref;
 SELECT * INTO o FROM media_origins WHERE subject_id=ref;
 IF i.intake_class IS DISTINCT FROM 'processor_derived' THEN IF o.subject_id IS NOT NULL THEN RAISE EXCEPTION 'Existing origin cannot be rewritten'; END IF; RETURN; END IF;
 SELECT * INTO r FROM media_inventory_records WHERE id=o.record_id;
 SELECT * INTO cc FROM custody_current WHERE subject_id=ref;
 IF o.subject_id IS NULL OR r.kind<>'identify' OR r.medium_id<>ref OR r.entry_id<>o.entry_id OR r.parent_id<>o.parent_id OR i.processing_state<>'inventoried' OR i.data_status<>'YES'
 OR EXISTS(SELECT 1 FROM first_receipts WHERE subject_id=ref) OR EXISTS(SELECT 1 FROM handover_items WHERE subject_id=ref) OR EXISTS(SELECT 1 FROM inspection_steps WHERE subject_id=ref)
 OR cc.party_id IS DISTINCT FROM r.facility_party_id OR cc.last_event_id IS DISTINCT FROM o.event_id OR cc.version<>1
 OR NOT EXISTS(SELECT 1 FROM subjects WHERE id=ref AND version=1)
 OR NOT EXISTS(SELECT 1 FROM items WHERE subject_id=o.parent_id AND owner_user_id=i.owner_user_id AND intake_class='whole_device')
 OR NOT EXISTS(SELECT 1 FROM custody_current WHERE subject_id=o.parent_id AND party_id=cc.party_id AND last_event_id=r.custody_event_id AND version=r.custody_version)
 OR NOT EXISTS(SELECT 1 FROM domain_events WHERE id=o.event_id AND subject_id=ref AND aggregate_kind='item' AND aggregate_id=ref AND aggregate_version=1 AND type='media_identified' AND command_id=r.command_id AND actor_id=r.actor_id AND occurred_at=r.observed_at)
 THEN RAISE EXCEPTION 'Derived media needs coherent evidenced facility origin, not invented receipt'; END IF;
 PERFORM media_inventory_integrity(o.parent_id);
END; $$;
CREATE FUNCTION media_authorization_integrity(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE a media_authorizations; cur media_authorization_current; pred uuid; seq bigint:=0;
BEGIN
 SELECT * INTO cur FROM media_authorization_current WHERE subject_id=ref;
 FOR a IN SELECT * FROM media_authorizations WHERE subject_id=ref ORDER BY version LOOP
 seq:=seq+1;
 IF a.version<>seq OR a.predecessor_id IS DISTINCT FROM pred OR NOT EXISTS(SELECT 1 FROM domain_events ev JOIN commands co ON co.id=ev.command_id WHERE ev.id=a.event_id AND ev.subject_id=ref AND ev.aggregate_kind='media_authorization' AND ev.aggregate_id=ref AND ev.aggregate_version=a.version AND ev.type='media_authorized' AND ev.actor_id=a.actor_id AND ev.command_id=a.command_id AND ev.occurred_at=a.observed_at AND ev.recorded_at=a.recorded_at AND co.actor_id=a.actor_id AND co.status='completed' AND co.action='media_authorize:'||ref::text AND co.safe_response->>'record_id'=a.id::text) THEN RAISE EXCEPTION 'Incomplete authorization command/event chain'; END IF;
 pred:=a.id;
 END LOOP;
 IF (seq=0 AND cur.subject_id IS NOT NULL) OR (seq>0 AND (cur.version IS DISTINCT FROM seq OR cur.record_id IS DISTINCT FROM pred)) THEN RAISE EXCEPTION 'Authorization projection must retain latest evidence'; END IF;
 IF EXISTS(SELECT 1 FROM domain_events ev WHERE ev.aggregate_kind='media_authorization' AND ev.aggregate_id=ref AND NOT EXISTS(SELECT 1 FROM media_authorizations WHERE event_id=ev.id)) THEN RAISE EXCEPTION 'Unbacked authorization event'; END IF;
END; $$;
CREATE FUNCTION media_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid; r jsonb;
BEGIN
 r:=to_jsonb(NEW);
 IF TG_TABLE_NAME='commands' THEN
 IF NEW.action LIKE 'media_inventory_%' AND NOT EXISTS(SELECT 1 FROM media_inventory_records WHERE command_id=NEW.id) THEN RAISE EXCEPTION 'Unbacked inventory command'; END IF;
 IF NEW.action LIKE 'media_authorize:%' AND NOT EXISTS(SELECT 1 FROM media_authorizations WHERE command_id=NEW.id) THEN RAISE EXCEPTION 'Unbacked authorization command'; END IF;
 ELSIF TG_TABLE_NAME='domain_events' THEN
 IF NEW.aggregate_kind='media_inventory' THEN PERFORM media_inventory_integrity(NEW.aggregate_id);
 ELSIF NEW.aggregate_kind='media_authorization' THEN PERFORM media_authorization_integrity(NEW.aggregate_id);
 ELSIF NEW.type='media_identified' THEN PERFORM media_origin_integrity(NEW.subject_id); END IF;
 ELSIF TG_TABLE_NAME IN ('media_inventory_records','media_inventory_current','media_inventory_entries') THEN PERFORM media_inventory_integrity((r->>'parent_id')::uuid);
 ELSIF TG_TABLE_NAME IN ('media_authorizations','media_authorization_current') THEN PERFORM media_authorization_integrity((r->>'subject_id')::uuid);
 ELSE
 ref:=CASE WHEN TG_TABLE_NAME='subjects' THEN (r->>'id')::uuid ELSE (r->>'subject_id')::uuid END;
 PERFORM media_origin_integrity(ref);
 END IF;
 RETURN NULL;
END; $$;
CREATE FUNCTION media_custody_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid;
BEGIN
 ref:=NEW.subject_id;
 IF TG_TABLE_NAME='custody_current' AND NEW IS NOT DISTINCT FROM OLD THEN RETURN NEW; END IF;
 IF EXISTS(SELECT 1 FROM media_inventory_entries WHERE medium_id=ref OR (parent_id=ref AND medium_id IS NOT NULL)) OR EXISTS(SELECT 1 FROM items WHERE subject_id=ref AND intake_class='processor_derived') THEN RAISE EXCEPTION 'Embedded media or its parent cannot move independently. Controlled detachment/co-transfer is not implemented; retain facility control.'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER media_control_current BEFORE UPDATE ON custody_current FOR EACH ROW EXECUTE FUNCTION media_custody_guard();
CREATE TRIGGER media_control_handover BEFORE INSERT ON handover_items FOR EACH ROW EXECUTE FUNCTION media_custody_guard();
CREATE TRIGGER retain_media_inventory_records BEFORE UPDATE OR DELETE ON media_inventory_records FOR EACH ROW EXECUTE FUNCTION protect_device_history();
CREATE TRIGGER retain_media_origins BEFORE UPDATE OR DELETE ON media_origins FOR EACH ROW EXECUTE FUNCTION protect_device_history();
CREATE TRIGGER retain_media_authorizations BEFORE UPDATE OR DELETE ON media_authorizations FOR EACH ROW EXECUTE FUNCTION protect_device_history();
CREATE CONSTRAINT TRIGGER media_complete_media_inventory_records AFTER INSERT OR UPDATE ON media_inventory_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_final();
CREATE CONSTRAINT TRIGGER media_complete_media_inventory_current AFTER INSERT OR UPDATE ON media_inventory_current DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_final();
CREATE CONSTRAINT TRIGGER media_complete_media_inventory_entries AFTER INSERT OR UPDATE ON media_inventory_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_final();
CREATE CONSTRAINT TRIGGER media_complete_media_origins AFTER INSERT OR UPDATE ON media_origins DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_final();
CREATE CONSTRAINT TRIGGER media_complete_media_authorizations AFTER INSERT OR UPDATE ON media_authorizations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_final();
CREATE CONSTRAINT TRIGGER media_complete_media_authorization_current AFTER INSERT OR UPDATE ON media_authorization_current DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_final();
CREATE CONSTRAINT TRIGGER media_complete_items AFTER INSERT OR UPDATE ON items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_final();
CREATE CONSTRAINT TRIGGER media_complete_storage_assets AFTER INSERT OR UPDATE ON storage_assets DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_final();
CREATE CONSTRAINT TRIGGER media_complete_subjects AFTER INSERT OR UPDATE ON subjects DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_final();
CREATE CONSTRAINT TRIGGER media_complete_custody_current AFTER INSERT OR UPDATE ON custody_current DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_final();
CREATE CONSTRAINT TRIGGER media_complete_domain_events AFTER INSERT OR UPDATE ON domain_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_final();
CREATE CONSTRAINT TRIGGER media_complete_commands AFTER INSERT OR UPDATE ON commands DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_final();
