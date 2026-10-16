CREATE VIEW inspection_steps AS
 SELECT id,subject_id,version,source_subject_version,subject_version,facility_party_id,custody_event_id,custody_version,actor_id,command_id,event_id,observed_at,'inspection_recorded'::text AS event_type FROM assessments
 UNION ALL
 SELECT id,subject_id,version,source_subject_version,subject_version,facility_party_id,custody_event_id,custody_version,actor_id,command_id,event_id,observed_at,'pathway_decided'::text FROM decisions;

CREATE FUNCTION inspection_scope(ref uuid, actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS (SELECT 1 FROM custody_current cc JOIN parties p ON p.id=cc.party_id JOIN places l ON l.id=p.place_id
 JOIN organizations o ON o.id=l.organization_id JOIN memberships m ON m.place_id=l.id AND m.organization_id=o.id JOIN users u ON u.id=m.user_id
 JOIN items i ON i.subject_id=cc.subject_id
 WHERE cc.subject_id=ref AND u.id=actor AND u.status='active' AND m.active AND m.role='processor' AND l.kind='facility' AND l.active AND l.review_status='approved'
 AND o.review_status='approved' AND o.kind IN ('processor','both') AND i.processing_state IN ('received','assessed')
 AND EXISTS (SELECT 1 FROM place_categories pc WHERE pc.place_id=l.id AND pc.category_id=i.category_id AND pc.subtype=COALESCE(i.subtype,''))
 AND EXISTS (SELECT 1 FROM handover_receipts r JOIN handover_items hi ON hi.id=r.handover_item_id JOIN handovers h ON h.id=hi.handover_id JOIN domain_events e ON e.id=r.event_id
 WHERE r.subject_id=ref AND r.event_id=cc.last_event_id AND h.receiver_party_id=cc.party_id AND hi.state='accepted' AND e.safe_payload->>'destination_kind'='facility')
 AND NOT EXISTS (SELECT 1 FROM handover_items hi WHERE hi.subject_id=ref AND hi.active)
 AND NOT EXISTS (SELECT 1 FROM receipt_discrepancies d JOIN receipt_outcomes ro ON ro.id=d.outcome_id JOIN request_items ri ON ri.id=ro.request_item_id WHERE ri.item_id=ref AND d.kind IN ('mismatch','safety_hold')))
$$;

CREATE FUNCTION inspection_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ac assessment_current; c custody_current; current_subject bigint; old_a assessments;
BEGIN
 SELECT * INTO c FROM custody_current WHERE subject_id=NEW.subject_id FOR UPDATE;
 SELECT version INTO current_subject FROM subjects WHERE id=NEW.subject_id;
 SELECT * INTO ac FROM assessment_current WHERE subject_id=NEW.subject_id;
 IF NOT inspection_scope(NEW.subject_id,NEW.actor_id) OR NEW.version<>COALESCE(ac.version,0)+1
 OR NEW.source_subject_version<>current_subject OR NEW.facility_party_id<>c.party_id OR NEW.custody_event_id<>c.last_event_id OR NEW.custody_version<>c.version
 OR NEW.observed_at>clock_timestamp() OR NEW.observed_at<(SELECT date_trunc('minute',occurred_at) FROM domain_events WHERE id=c.last_event_id)
 THEN RAISE EXCEPTION 'Inspection requires current eligible facility custody and versions'; END IF;
 IF TG_TABLE_NAME='decisions' THEN
 SELECT * INTO old_a FROM assessments WHERE id=NEW.assessment_id;
 IF ac.assessment_id IS DISTINCT FROM NEW.assessment_id OR old_a.subject_id<>NEW.subject_id OR NEW.observed_at<date_trunc('minute',old_a.observed_at)
 OR NEW.override IS DISTINCT FROM (NEW.pathway<>old_a.recommendation)
 OR (NEW.pathway<>'hold' AND EXISTS (SELECT 1 FROM assessments WHERE subject_id=NEW.subject_id AND hard_hold))
 THEN RAISE EXCEPTION 'Decision requires latest inspection and cannot clear restrictions'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER validate_inspection BEFORE INSERT ON assessments FOR EACH ROW EXECUTE FUNCTION inspection_insert_guard();
CREATE TRIGGER validate_decision BEFORE INSERT ON decisions FOR EACH ROW EXECUTE FUNCTION inspection_insert_guard();

CREATE FUNCTION inspection_current_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR (TG_OP='UPDATE' AND (NEW.subject_id<>OLD.subject_id OR NEW.version<>OLD.version+1)) OR (TG_OP='INSERT' AND NEW.version<>1)
 THEN RAISE EXCEPTION 'Current inspection projection requires consecutive retained history'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER retain_inspection_current BEFORE INSERT OR UPDATE OR DELETE ON assessment_current FOR EACH ROW EXECUTE FUNCTION inspection_current_guard();

-- Each assessment/decision advances workflow version, never the acknowledged custody event/version.
CREATE OR REPLACE FUNCTION handover_chain_check(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE f first_receipts; rec record; party uuid; ev uuid; cv bigint; sv bigint;
BEGIN
 SELECT * INTO f FROM first_receipts WHERE subject_id=ref;
 IF NOT FOUND THEN
 IF EXISTS (SELECT 1 FROM handover_items WHERE subject_id=ref) OR EXISTS (SELECT 1 FROM inspection_steps WHERE subject_id=ref) THEN RAISE EXCEPTION 'Workflow requires first receipt'; END IF;
 RETURN;
 END IF;
 party:=f.recipient_party_id;ev:=f.event_id;cv:=f.custody_version;sv:=f.subject_version;
 FOR rec IN SELECT a.*,hi.source_event_id,hi.source_custody_version,hi.source_subject_version,h.sender_party_id,h.receiver_party_id,hi.state FROM handover_receipts a JOIN handover_items hi ON hi.id=a.handover_item_id JOIN handovers h ON h.id=hi.handover_id WHERE a.subject_id=ref ORDER BY a.custody_version LOOP
 IF rec.source_event_id<>ev OR rec.source_custody_version<>cv OR rec.source_subject_version<>sv OR rec.sender_party_id<>party OR rec.custody_version<>cv+1 OR rec.subject_version<>sv+1 OR rec.state<>'accepted' THEN RAISE EXCEPTION 'Broken acknowledged custody chain'; END IF;
 party:=rec.receiver_party_id;ev:=rec.event_id;cv:=rec.custody_version;sv:=rec.subject_version;
 END LOOP;
 FOR rec IN SELECT * FROM inspection_steps WHERE subject_id=ref ORDER BY version LOOP
 IF rec.source_subject_version<>sv OR rec.subject_version<>sv+1 OR rec.facility_party_id<>party OR rec.custody_event_id<>ev OR rec.custody_version<>cv THEN RAISE EXCEPTION 'Inspection cannot alter custody or skip workflow versions'; END IF;
 sv:=rec.subject_version;
 END LOOP;
 IF NOT EXISTS (SELECT 1 FROM custody_current c JOIN subjects s ON s.id=c.subject_id WHERE c.subject_id=ref AND c.party_id=party AND c.last_event_id=ev AND c.version=cv AND s.version=sv) THEN RAISE EXCEPTION 'Current custody must match acknowledged chain and inspection history'; END IF;
END; $$;

CREATE FUNCTION inspection_integrity(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE ac assessment_current; step record; a assessments; rules rule_sets; answers jsonb; qs jsonb; key text; q record; v text; hard boolean; latest assessments; last_step record; seq bigint:=0;
BEGIN
 SELECT * INTO ac FROM assessment_current WHERE subject_id=ref;
 IF NOT FOUND THEN
 IF EXISTS (SELECT 1 FROM inspection_steps WHERE subject_id=ref) OR EXISTS (SELECT 1 FROM items WHERE subject_id=ref AND processing_state='assessed') THEN RAISE EXCEPTION 'Assessment requires current projection'; END IF;
 RETURN;
 END IF;
 FOR step IN SELECT * FROM inspection_steps WHERE subject_id=ref ORDER BY version LOOP
 seq:=seq+1;
 IF step.version<>seq OR NOT EXISTS (SELECT 1 FROM domain_events e JOIN commands c ON c.id=e.command_id WHERE e.id=step.event_id AND e.subject_id=ref AND e.aggregate_kind='item' AND e.aggregate_id=ref AND e.aggregate_version=step.subject_version AND e.actor_id=step.actor_id AND e.command_id=step.command_id AND e.type=step.event_type AND e.occurred_at=step.observed_at AND c.actor_id=step.actor_id AND c.safe_response->>'record_id'=step.id::text)
 THEN RAISE EXCEPTION 'Incomplete inspection command/event history'; END IF;
 last_step:=step;
 END LOOP;
 SELECT * INTO latest FROM assessments WHERE subject_id=ref ORDER BY version DESC LIMIT 1;
 IF seq<>ac.version OR ac.assessment_id IS DISTINCT FROM latest.id OR NOT EXISTS (SELECT 1 FROM items WHERE subject_id=ref AND processing_state='assessed')
 OR (last_step.event_type='inspection_recorded' AND ac.decision_id IS NOT NULL)
 OR (last_step.event_type='pathway_decided' AND NOT EXISTS (SELECT 1 FROM decisions d WHERE d.id=ac.decision_id AND d.id=last_step.id AND d.assessment_id=latest.id))
 OR EXISTS (SELECT 1 FROM decisions d WHERE d.id=ac.decision_id AND d.pathway<>'hold' AND EXISTS (SELECT 1 FROM assessments WHERE subject_id=ref AND hard_hold))
 THEN RAISE EXCEPTION 'Inconsistent latest inspection/decision projection'; END IF;
 FOR a IN SELECT * FROM assessments WHERE subject_id=ref LOOP
 SELECT * INTO rules FROM rule_sets WHERE id=a.rule_set_id;
 SELECT CASE WHEN c.code IN ('peripheral','standalone_part') THEN i.subtype ELSE c.code END INTO key FROM items i JOIN categories c ON c.id=i.category_id WHERE i.subject_id=ref;
 qs:=rules.definition->'schemas'->key;
 SELECT jsonb_object_agg(question_code,value) INTO answers FROM assessment_answers WHERE assessment_id=a.id;
 IF qs IS NULL OR answers IS NULL OR (SELECT count(*) FROM jsonb_object_keys(qs))<>(SELECT count(*) FROM jsonb_object_keys(answers)) OR a.schema_version<>rules.schema_version OR a.rule_checksum<>rules.checksum
 OR a.recommendation<>a.result->>'recommendation' OR a.hard_hold IS DISTINCT FROM (a.result->>'hard_hold')::boolean OR a.data_status<>a.result->>'data_status'
 OR a.result->>'rule' NOT IN ('R01','R02','R03','R04','R05','R06','R07','R08') THEN RAISE EXCEPTION 'Incomplete frozen rule/answer result'; END IF;
 FOR q IN SELECT * FROM jsonb_each(qs) LOOP
 v:=answers->>q.key;
 IF v IS NULL OR NOT (q.value->'values' ? v) OR (v='NOT_APPLICABLE' AND NOT EXISTS (SELECT 1 FROM assessment_answers WHERE assessment_id=a.id AND question_code=q.key AND na_reason_ciphertext IS NOT NULL)) THEN RAISE EXCEPTION 'Invalid inspection answer/applicability'; END IF;
 END LOOP;
 hard:=answers->>'safety_concern' IN ('YES','UNKNOWN') OR COALESCE(answers->>'media_identified' IN ('NO','UNKNOWN'),false)
 OR COALESCE(answers->>'fan_voltage' IN ('OUTSIDE','UNKNOWN'),false) OR COALESCE(answers->>'fan_size' IN ('OUTSIDE','UNKNOWN'),false)
 OR COALESCE(answers->>'fan_application' IN ('OTHER','UNKNOWN'),false) OR COALESCE(answers->>'fan_electronics' IN ('ADDITIONAL','UNKNOWN'),false);
 IF a.hard_hold IS DISTINCT FROM hard OR (key IN ('hdd','ssd') AND a.data_status<>'YES')
 OR (key='ict_cooling_fan' AND a.data_status<>CASE WHEN answers->>'fan_voltage'='WITHIN' AND answers->>'fan_size'='WITHIN' AND answers->>'fan_application'='ICT' AND answers->>'fan_electronics'='BASIC' THEN 'NO' ELSE 'UNKNOWN' END)
 THEN RAISE EXCEPTION 'Safety/data restrictions cannot be cleared'; END IF;
 END LOOP;
 PERFORM handover_chain_check(ref);
END; $$;

CREATE FUNCTION inspection_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid;
BEGIN
 IF TG_TABLE_NAME='assessment_answers' THEN SELECT subject_id INTO ref FROM assessments WHERE id=NEW.assessment_id;
 ELSE ref:=NEW.subject_id; END IF;
 PERFORM inspection_integrity(ref);
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER inspection_complete AFTER INSERT ON assessments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inspection_final();
CREATE CONSTRAINT TRIGGER inspection_answers_complete AFTER INSERT ON assessment_answers DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inspection_final();
CREATE CONSTRAINT TRIGGER inspection_decision_complete AFTER INSERT ON decisions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inspection_final();
CREATE CONSTRAINT TRIGGER inspection_current_complete AFTER INSERT OR UPDATE ON assessment_current DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inspection_final();

-- Preserve only ledger-backed version increments of already consumed route subjects.
CREATE OR REPLACE FUNCTION handover_subject_step(ref uuid, before_row jsonb, after_row jsonb) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT (before_row-'version')=(after_row-'version') AND (EXISTS (
 SELECT 1 FROM handover_receipts a JOIN handover_items hi ON hi.id=a.handover_item_id WHERE a.subject_id=ref AND hi.source_subject_version=(before_row->>'version')::bigint AND a.subject_version=(after_row->>'version')::bigint)
 OR EXISTS (SELECT 1 FROM inspection_steps a WHERE a.subject_id=ref AND a.source_subject_version=(before_row->>'version')::bigint AND a.subject_version=(after_row->>'version')::bigint))
$$;
CREATE OR REPLACE FUNCTION handover_receipt_projection(visit uuid, ref uuid, expected jsonb, actual jsonb) RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
 IF (expected-'version') IS DISTINCT FROM (actual-'version') OR NOT EXISTS (SELECT 1 FROM first_receipts f JOIN receipt_outcomes o ON o.id=f.outcome_id WHERE f.subject_id=ref AND o.visit_id=visit AND f.subject_version=(expected->>'version')::bigint)
 OR NOT (EXISTS (SELECT 1 FROM handover_receipts WHERE subject_id=ref AND subject_version=(actual->>'version')::bigint) OR EXISTS (SELECT 1 FROM inspection_steps WHERE subject_id=ref AND subject_version=(actual->>'version')::bigint)) THEN RETURN false; END IF;
 PERFORM handover_chain_check(ref); PERFORM inspection_integrity(ref);
 RETURN true;
END; $$;
