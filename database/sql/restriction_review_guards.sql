CREATE FUNCTION restriction_known_value(key text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
 SELECT CASE WHEN key IN ('mismatch','safety_hold') THEN 'ERRONEOUS_RECORD' WHEN key IN ('safety_concern','charging_risk','battery_risk','thermal_issue') THEN 'NO' WHEN key='media_identified' THEN 'YES' WHEN key IN ('fan_voltage','fan_size') THEN 'WITHIN' WHEN key='fan_application' THEN 'ICT' WHEN key='fan_electronics' THEN 'BASIC' END
$$;
CREATE FUNCTION restriction_proposal_valid(p restriction_records,c restriction_cases) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT p.case_id=c.id AND p.kind='proposal' AND c.supported AND p.original_value=c.original_value AND p.proposed_value=restriction_known_value(c.fact_key)
 AND p.identity_code=(SELECT public_code FROM subjects WHERE id=c.subject_id) AND p.grant_record_id=c.grant_record_id
 AND p.actor_id=c.assigned_to AND restriction_investigator(c.id,p.actor_id) AND p.actor_context=restriction_actor_context(p.actor_id)
 AND p.context=restriction_dependency_context(c.subject_id) AND p.restriction_version=restriction_epoch(c.subject_id)
 AND p.checks='{"direct_observation":"YES","same_subject":"YES","recording_error":"YES","then_and_now":"YES","no_remediation":"YES","no_movement":"YES","no_configuration_change":"YES","supported_known_fact":"YES"}'::jsonb
$$;
CREATE FUNCTION restriction_case_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r restriction_records;s record;expected jsonb;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Restriction investigation history cannot be deleted.'; END IF;
 IF TG_OP='INSERT' THEN
 SELECT * INTO s FROM restriction_sources WHERE source_kind=NEW.source_kind AND source_id=NEW.source_id AND fact_key=NEW.fact_key;
 IF s.source_id IS NULL OR NEW.subject_id<>s.subject_id OR NEW.source_event_id<>s.source_event_id OR NEW.source_version<>s.source_version OR NEW.original_value<>s.original_value OR NEW.supported<>s.supported
 OR NOT restriction_admin(NEW.created_by) OR NEW.version<>1 OR NEW.state<>'open' OR NOT NEW.active OR NEW.assigned_to IS NOT NULL OR NEW.grant_record_id IS NOT NULL OR NEW.assignment_context IS NOT NULL OR NEW.pre_receipt OR NEW.allowed_evidence<>'{}'::uuid[]
 OR EXISTS(SELECT 1 FROM restriction_supersessions WHERE source_kind=NEW.source_kind AND source_id=NEW.source_id AND fact_key=NEW.fact_key)
 THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Open a case against an existing current restriction; no historical correction is inferred.'; END IF;
 ELSE
 SELECT * INTO r FROM restriction_records WHERE id=NEW.record_id AND case_id=OLD.id AND version=OLD.version+1;
 expected:=to_jsonb(OLD)||jsonb_build_object('version',OLD.version+1,'record_id',r.id,'state',CASE r.kind WHEN 'approve' THEN 'superseded' WHEN 'close' THEN 'closed_no_change' ELSE 'under_review' END,'active',r.kind NOT IN ('approve','close'));
 IF r.kind='assign' THEN expected:=expected||jsonb_build_object('assigned_to',r.assigned_to,'grant_record_id',r.id,'assignment_context',restriction_actor_context(r.assigned_to),'pre_receipt',r.pre_receipt,'allowed_evidence',r.allowed_evidence);
 ELSIF r.kind='revoke' THEN expected:=expected||'{"assigned_to":null,"grant_record_id":null,"assignment_context":null,"pre_receipt":false,"allowed_evidence":[]}'::jsonb; END IF;
 IF r.id IS NULL OR NOT OLD.active OR to_jsonb(NEW)<>expected THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Case projection requires its exact next immutable operation.'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER restriction_case_protected BEFORE INSERT OR UPDATE OR DELETE ON restriction_cases FOR EACH ROW EXECUTE FUNCTION restriction_case_guard();

CREATE FUNCTION restriction_record_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c restriction_cases;p restriction_records;s record;uid uuid;known text;
BEGIN
 SELECT * INTO c FROM restriction_cases WHERE id=NEW.case_id FOR UPDATE;
 SELECT * INTO s FROM restriction_sources WHERE source_kind=c.source_kind AND source_id=c.source_id AND fact_key=c.fact_key;
 IF c.id IS NULL OR s.source_id IS NULL OR NEW.source_version<>c.source_version OR NEW.restriction_version<>restriction_epoch(c.subject_id)
 OR NEW.context<>restriction_dependency_context(c.subject_id) OR NEW.actor_context IS DISTINCT FROM restriction_actor_context(NEW.actor_id)
 OR NEW.observed_at>clock_timestamp() OR NEW.recorded_at<NEW.observed_at OR NEW.observed_at<date_trunc('minute',GREATEST(c.created_at,s.source_time))
 OR (NEW.kind='open' AND (c.version<>1 OR NEW.version<>1 OR c.record_id<>NEW.id OR NEW.predecessor_id IS NOT NULL OR c.created_by<>NEW.actor_id))
 OR (NEW.kind<>'open' AND (NOT c.active OR NEW.version<>c.version+1 OR NEW.predecessor_id IS DISTINCT FROM c.record_id))
 THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Case, source, restriction or dependencies changed. Reload the impact preview; nothing was recorded.'; END IF;
 IF NEW.kind IN ('open','assign','revoke','continue','close','approve') AND NOT restriction_admin(NEW.actor_id)
 OR (NEW.kind='proposal' AND NOT restriction_investigator(c.id,NEW.actor_id))
 OR (NEW.kind='clarify' AND NOT restriction_clarifier(c.id,NEW.actor_id))
 OR (NEW.kind='note' AND NOT (restriction_admin(NEW.actor_id) OR restriction_investigator(c.id,NEW.actor_id)))
 THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Current case-specific authority is required for this action.'; END IF;
 IF NEW.attachment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attachments WHERE id=NEW.attachment_id AND uploaded_by=NEW.actor_id AND privacy_class='private' AND redacted_at IS NULL) THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Use your own private case evidence.'; END IF;
 IF NEW.kind<>'assign' AND (NEW.assigned_to IS NOT NULL OR NEW.pre_receipt OR NEW.allowed_evidence<>'{}'::uuid[]) THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Only explicit assignment may grant investigation access.'; END IF;
 IF NEW.kind='assign' THEN
 IF NOT restriction_staff(NEW.assigned_to,c.subject_id) AND NOT (NOT c.supported AND restriction_admin(NEW.assigned_to)) THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Choose a named active Processor with current approved place and exact capability, or an Administrator for unsupported investigation coordination.'; END IF;
 IF NEW.pre_receipt AND (NOT c.supported OR c.source_kind<>'receipt' OR EXISTS(SELECT 1 FROM first_receipts WHERE subject_id=c.subject_id) OR NOT restriction_staff(NEW.assigned_to,c.subject_id)) THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Investigation-only grants are limited to this pre-receipt subject and a capable named Processor.'; END IF;
 IF c.supported AND NOT NEW.pre_receipt AND NOT EXISTS(SELECT 1 FROM memberships m JOIN custody_current cc ON cc.subject_id=c.subject_id JOIN parties p ON p.id=cc.party_id WHERE m.user_id=NEW.assigned_to AND m.place_id=p.place_id) THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Explicit pre-receipt access is required; assignment does not create facility scope.'; END IF;
 FOREACH uid IN ARRAY NEW.allowed_evidence LOOP
 IF uid IS DISTINCT FROM s.source_attachment_id OR NOT EXISTS(SELECT 1 FROM attachments WHERE id=uid AND redacted_at IS NULL) THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Only specifically authorized evidence from this source may be granted.'; END IF;
 END LOOP;
 END IF;
 IF NEW.kind='proposal' THEN
 known:=restriction_known_value(c.fact_key);
 IF NOT c.supported OR NEW.grant_record_id IS DISTINCT FROM c.grant_record_id OR NEW.original_value<>c.original_value OR NEW.proposed_value NOT IN (known,'UNKNOWN')
 OR NEW.identity_code IS DISTINCT FROM (SELECT public_code FROM subjects WHERE id=c.subject_id) OR (SELECT count(*) FROM jsonb_object_keys(NEW.checks))<>8
 OR NOT (NEW.checks ?& ARRAY['direct_observation','same_subject','recording_error','then_and_now','no_remediation','no_movement','no_configuration_change','supported_known_fact'])
 OR EXISTS(SELECT 1 FROM jsonb_each_text(NEW.checks) q WHERE q.value NOT IN ('YES','NO','UNKNOWN'))
 THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Record the same subject and original fact with known or explicitly unknown evidence; identity changes and unsupported corrections are unavailable.'; END IF;
 ELSIF NEW.kind='approve' THEN
 SELECT * INTO p FROM restriction_records WHERE id=NEW.proposal_id;
 IF p.actor_id=NEW.actor_id OR NOT COALESCE(restriction_proposal_valid(p,c),false) THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Independent approval requires current evidenced same-subject recording-error findings. Unknown, changed, remediated or unsupported facts remain blocked.'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER restriction_record_valid BEFORE INSERT ON restriction_records FOR EACH ROW EXECUTE FUNCTION restriction_record_guard();

CREATE FUNCTION restriction_record_effects() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c restriction_cases;p restriction_records;f record;t record;
BEGIN
 IF NEW.kind<>'approve' THEN RETURN NULL; END IF;
 SELECT * INTO c FROM restriction_cases WHERE id=NEW.case_id;
 SELECT * INTO p FROM restriction_records WHERE id=NEW.proposal_id;
 INSERT INTO restriction_supersessions VALUES(NEW.id,c.id,p.id,c.subject_id,c.source_kind,c.source_id,c.fact_key,c.original_value,p.proposed_value);
 FOR f IN SELECT subject_id FROM restriction_family(c.subject_id) ORDER BY subject_id LOOP
 INSERT INTO restriction_epoch_steps VALUES(NEW.id,f.subject_id,restriction_epoch(f.subject_id)+1);
 INSERT INTO restriction_epochs VALUES(f.subject_id,1,NEW.id) ON CONFLICT(subject_id) DO UPDATE SET version=restriction_epochs.version+1,record_id=NEW.id;
 -- An impact is not a reversal or clearance. Later bounded contracts must resolve it.
 FOR t IN SELECT id FROM domain_events WHERE subject_id=f.subject_id AND
 (type IN ('reuse_return_acknowledged','item_dismantled','batch_committed','downstream_accepted') OR
 (recorded_at>c.created_at AND type IN ('physical_visit_recorded','handover_accepted','sanitization_attempt','refurbishment_work')))
 UNION SELECT event_id FROM downstream_records WHERE batch_id=f.subject_id AND kind IN ('dispatch','close')
 UNION SELECT event_id FROM batch_records WHERE batch_id=f.subject_id AND kind='commit'
 LOOP
 INSERT INTO restriction_impacts VALUES(gen_random_uuid(),NEW.id,f.subject_id,'completed_dependency',t.id) ON CONFLICT DO NOTHING;
 END LOOP;
 END LOOP;
 RETURN NULL;
END; $$;
CREATE TRIGGER restriction_effects AFTER INSERT ON restriction_records FOR EACH ROW EXECUTE FUNCTION restriction_record_effects();

CREATE FUNCTION restriction_append_only() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Restriction findings, review, provenance and effective history are append-only.'; END; $$;
CREATE TRIGGER restriction_records_retained BEFORE UPDATE OR DELETE ON restriction_records FOR EACH ROW EXECUTE FUNCTION restriction_append_only();
CREATE TRIGGER restriction_supersessions_retained BEFORE UPDATE OR DELETE ON restriction_supersessions FOR EACH ROW EXECUTE FUNCTION restriction_append_only();
CREATE TRIGGER restriction_epoch_steps_retained BEFORE UPDATE OR DELETE ON restriction_epoch_steps FOR EACH ROW EXECUTE FUNCTION restriction_append_only();
CREATE TRIGGER restriction_impacts_retained BEFORE UPDATE OR DELETE ON restriction_impacts FOR EACH ROW EXECUTE FUNCTION restriction_append_only();
CREATE TRIGGER restriction_stamps_retained BEFORE UPDATE OR DELETE ON restriction_stamps FOR EACH ROW EXECUTE FUNCTION restriction_append_only();
CREATE FUNCTION restriction_supersession_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r restriction_records;c restriction_cases;p restriction_records;
BEGIN
 SELECT * INTO r FROM restriction_records WHERE id=NEW.record_id;SELECT * INTO c FROM restriction_cases WHERE id=NEW.case_id;SELECT * INTO p FROM restriction_records WHERE id=NEW.proposal_id;
 IF r.kind IS DISTINCT FROM 'approve' OR r.case_id IS DISTINCT FROM c.id OR r.proposal_id IS DISTINCT FROM p.id OR r.actor_id=p.actor_id
 OR NOT c.supported OR NEW.subject_id<>c.subject_id OR NEW.source_kind<>c.source_kind OR NEW.source_id<>c.source_id OR NEW.fact_key<>c.fact_key OR NEW.original_value<>c.original_value OR NEW.proposed_value<>restriction_known_value(c.fact_key) OR NEW.proposed_value<>p.proposed_value
 THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Only the exact supported independent reviewed proposal may supersede this fact.'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER restriction_supersession_valid BEFORE INSERT ON restriction_supersessions FOR EACH ROW EXECUTE FUNCTION restriction_supersession_guard();
CREATE FUNCTION restriction_epoch_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR NOT EXISTS(SELECT 1 FROM restriction_epoch_steps s WHERE s.record_id=NEW.record_id AND s.subject_id=NEW.subject_id AND s.version=(CASE WHEN TG_OP='UPDATE' THEN NEW.version ELSE restriction_epoch(NEW.subject_id)+1 END))
 OR (TG_OP='INSERT' AND NEW.version<>1) OR (TG_OP='UPDATE' AND (NEW.subject_id<>OLD.subject_id OR NEW.version<>OLD.version+1 OR NEW.record_id=OLD.record_id))
 THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Effective restriction versions require consecutive reviewed facts.'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER restriction_epochs_valid BEFORE INSERT OR UPDATE OR DELETE ON restriction_epochs FOR EACH ROW EXECUTE FUNCTION restriction_epoch_guard();
CREATE FUNCTION restriction_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c restriction_cases;r restriction_records;rec record;expected bigint:=0;
BEGIN
 IF TG_TABLE_NAME='restriction_records' THEN SELECT * INTO c FROM restriction_cases WHERE id=NEW.case_id;
 ELSIF TG_TABLE_NAME='restriction_cases' THEN SELECT * INTO c FROM restriction_cases WHERE id=NEW.id;
 ELSE
 IF TG_TABLE_NAME='commands' AND NEW.action LIKE 'restriction_%' AND NOT EXISTS(SELECT 1 FROM restriction_records WHERE command_id=NEW.id) THEN RAISE EXCEPTION 'Restriction command needs reciprocal record'; END IF;
 IF TG_TABLE_NAME='domain_events' AND NEW.aggregate_kind='restriction' AND NOT EXISTS(SELECT 1 FROM restriction_records WHERE event_id=NEW.id) THEN RAISE EXCEPTION 'Restriction event needs reciprocal record'; END IF;
 RETURN NULL;
 END IF;
 FOR r IN SELECT * FROM restriction_records WHERE case_id=c.id ORDER BY version LOOP
 expected:=expected+1;
 IF r.version<>expected OR NOT EXISTS(SELECT 1 FROM domain_events e JOIN commands cmd ON cmd.id=e.command_id WHERE e.id=r.event_id AND e.aggregate_kind='restriction' AND e.aggregate_id=c.id AND e.aggregate_version=r.version AND e.actor_id=r.actor_id AND e.command_id=r.command_id AND e.type='restriction_'||r.kind AND e.occurred_at=r.observed_at AND e.recorded_at=r.recorded_at AND cmd.actor_id=r.actor_id AND cmd.safe_response->>'record_id'=c.id::text)
 THEN RAISE EXCEPTION 'Restriction history needs consecutive reciprocal actor/command/event records'; END IF;
 IF r.kind='approve' AND NOT EXISTS(SELECT 1 FROM restriction_supersessions s WHERE s.record_id=r.id AND s.case_id=c.id AND s.proposal_id=r.proposal_id) THEN RAISE EXCEPTION 'Approved review needs exact effective supersession'; END IF;
 END LOOP;
 IF expected<>c.version OR c.record_id IS DISTINCT FROM r.id OR c.state<>(CASE r.kind WHEN 'open' THEN 'open' WHEN 'approve' THEN 'superseded' WHEN 'close' THEN 'closed_no_change' ELSE 'under_review' END) THEN RAISE EXCEPTION 'Case projection does not match retained history'; END IF;
 IF TG_TABLE_NAME='restriction_records' THEN
 IF NEW.kind='proposal' AND (NEW.actor_context IS DISTINCT FROM restriction_actor_context(NEW.actor_id) OR NOT restriction_staff(NEW.actor_id,c.subject_id) OR NEW.grant_record_id IS DISTINCT FROM c.grant_record_id) THEN RAISE EXCEPTION 'Investigation authority changed before commit'; END IF;
 IF NEW.kind='approve' THEN
 IF NOT restriction_admin(NEW.actor_id) OR NEW.actor_context IS DISTINCT FROM restriction_actor_context(NEW.actor_id) OR NEW.context->'sources' IS DISTINCT FROM restriction_dependency_context(c.subject_id)->'sources' THEN RAISE EXCEPTION 'Reviewer or source facts changed before commit'; END IF;
 FOR rec IN SELECT subject_id FROM restriction_family(c.subject_id) LOOP
 IF NOT EXISTS(SELECT 1 FROM restriction_epochs WHERE subject_id=rec.subject_id AND record_id=NEW.id) THEN RAISE EXCEPTION 'Review must advance every affected restriction dependency'; END IF;
 END LOOP;
 END IF;
 END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER restriction_record_complete AFTER INSERT ON restriction_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restriction_final();
CREATE CONSTRAINT TRIGGER restriction_case_complete AFTER INSERT OR UPDATE ON restriction_cases DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restriction_final();
CREATE CONSTRAINT TRIGGER restriction_command_complete AFTER INSERT ON commands DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restriction_final();
CREATE CONSTRAINT TRIGGER restriction_event_complete AFTER INSERT ON domain_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restriction_final();
