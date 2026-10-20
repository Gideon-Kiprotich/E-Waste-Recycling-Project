CREATE FUNCTION restriction_stamp() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid;
BEGIN
 ref:=COALESCE((to_jsonb(NEW)->>'subject_id')::uuid,(to_jsonb(NEW)->>'parent_id')::uuid,(to_jsonb(NEW)->>'batch_id')::uuid);
 INSERT INTO restriction_stamps VALUES(TG_TABLE_NAME,NEW.id,ref,restriction_epoch(ref));
 RETURN NULL;
END; $$;
CREATE FUNCTION restriction_stamp_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE found_ref uuid;fresh boolean;column_name text;
BEGIN
 IF NEW.kind NOT IN ('assessments','sanitization_records','media_authorizations','media_inventory_records','refurbishment_records','batch_records','downstream_records') THEN RAISE EXCEPTION 'Unsupported workflow restriction stamp'; END IF;
 column_name:=CASE WHEN NEW.kind='media_inventory_records' THEN 'parent_id' WHEN NEW.kind IN ('batch_records','downstream_records') THEN 'batch_id' ELSE 'subject_id' END;
 EXECUTE format('SELECT %I,xmin::text::bigint=(pg_current_xact_id()::text::bigint %% 4294967296) FROM %I WHERE id=$1',column_name,NEW.kind) INTO found_ref,fresh USING NEW.record_id;
 IF NOT COALESCE(fresh,false) OR found_ref IS DISTINCT FROM NEW.subject_id OR NEW.version<>restriction_epoch(NEW.subject_id) THEN RAISE EXCEPTION 'Only a newly recorded workflow may capture its current restriction epoch'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER restriction_stamp_valid BEFORE INSERT ON restriction_stamps FOR EACH ROW EXECUTE FUNCTION restriction_stamp_guard();
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['assessments','sanitization_records','media_authorizations','media_inventory_records','refurbishment_records','batch_records','downstream_records'] LOOP
 EXECUTE format('CREATE TRIGGER capture_restriction_epoch AFTER INSERT ON %I FOR EACH ROW EXECUTE FUNCTION restriction_stamp()',t);
 END LOOP;
END; $$;
CREATE FUNCTION restriction_record_current(kind text,rec uuid,ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT COALESCE((SELECT s.version FROM restriction_stamps s WHERE s.kind=$1 AND s.record_id=rec AND s.subject_id=ref),0)=restriction_epoch(ref) $$;
CREATE FUNCTION restriction_fresh_inspection(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT NOT restriction_dependent(ref) AND (restriction_epoch(ref)=0 OR EXISTS(SELECT 1 FROM assessment_current ac WHERE ac.subject_id=ref AND restriction_record_current('assessments',ac.assessment_id,ref)))
$$;
CREATE FUNCTION restriction_inspection_epoch_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='decisions' AND NEW.pathway<>'hold' AND NOT restriction_fresh_inspection(NEW.subject_id) THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='Restriction facts changed. Record a fresh inspection before a new human decision.'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER restriction_decision_epoch BEFORE INSERT ON decisions FOR EACH ROW EXECUTE FUNCTION restriction_inspection_epoch_guard();

ALTER FUNCTION media_record_applicable(media_inventory_records) RENAME TO media_record_applicable_before_restriction;
CREATE FUNCTION media_record_applicable(r media_inventory_records) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT media_record_applicable_before_restriction(r) AND restriction_record_current('media_inventory_records',r.id,r.parent_id) AND NOT restriction_dependent(r.parent_id) $$;
ALTER FUNCTION media_authorization_applicable(media_authorizations) RENAME TO media_authorization_applicable_before_restriction;
CREATE FUNCTION media_authorization_applicable(a media_authorizations) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT media_authorization_applicable_before_restriction(a) AND restriction_record_current('media_authorizations',a.id,a.subject_id) AND NOT restriction_dependent(a.subject_id) AND NOT restriction_receipt_blocked(a.subject_id) AND NOT restriction_inspection_blocked(a.subject_id) $$;
ALTER FUNCTION sanitization_dependencies(sanitization_records) RENAME TO sanitization_dependencies_before_restriction;
CREATE FUNCTION sanitization_dependencies(r sanitization_records) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT sanitization_dependencies_before_restriction(r) AND restriction_record_current('sanitization_records',r.id,r.subject_id) AND NOT restriction_dependent(r.subject_id) $$;
ALTER FUNCTION sanitization_ready(uuid,uuid) RENAME TO sanitization_ready_before_restriction;
CREATE FUNCTION sanitization_ready(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT sanitization_ready_before_restriction(ref,actor) AND NOT restriction_dependent(ref) AND restriction_fresh_inspection(COALESCE((SELECT parent_id FROM media_authorizations WHERE id=(SELECT record_id FROM media_authorization_current WHERE subject_id=ref)),ref))
$$;
ALTER FUNCTION media_parent_ready(uuid,uuid) RENAME TO media_parent_ready_before_restriction;
CREATE FUNCTION media_parent_ready(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT media_parent_ready_before_restriction(ref,actor) AND restriction_fresh_inspection(ref) $$;
ALTER FUNCTION refurbishment_ready(uuid,uuid) RENAME TO refurbishment_ready_before_restriction;
CREATE FUNCTION refurbishment_ready(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT refurbishment_ready_before_restriction(ref,actor) AND restriction_fresh_inspection(ref) $$;
ALTER FUNCTION refurbishment_test_ready(uuid) RENAME TO refurbishment_test_ready_before_restriction;
CREATE FUNCTION refurbishment_test_ready(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT refurbishment_test_ready_before_restriction(ref) AND restriction_record_current('refurbishment_records',(SELECT id FROM refurbishment_records WHERE subject_id=ref AND kind='test' ORDER BY version DESC LIMIT 1),ref) $$;
ALTER FUNCTION refurbishment_configuration(uuid) RENAME TO refurbishment_configuration_before_restriction;
CREATE FUNCTION refurbishment_configuration(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$ SELECT refurbishment_configuration_before_restriction(ref)||CASE WHEN restriction_epoch(ref)>0 THEN jsonb_build_object('restriction_epoch',restriction_epoch(ref)) ELSE '{}'::jsonb END $$;
ALTER FUNCTION batch_input_ready(uuid,uuid) RENAME TO batch_input_ready_before_restriction;
CREATE FUNCTION batch_input_ready(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT batch_input_ready_before_restriction(ref,actor) AND restriction_fresh_inspection(ref) $$;
ALTER FUNCTION batch_control_ready(uuid,uuid) RENAME TO batch_control_ready_before_restriction;
CREATE FUNCTION batch_control_ready(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT batch_control_ready_before_restriction(ref,actor) AND NOT restriction_dependent(ref) $$;
ALTER FUNCTION downstream_blocked(uuid) RENAME TO downstream_blocked_before_restriction;
CREATE FUNCTION downstream_blocked(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT downstream_blocked_before_restriction(ref) OR restriction_dependent(ref) $$;

-- Native projection checks reject fabricated or incomplete dependency history at COMMIT.
CREATE FUNCTION restriction_dependency_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r restriction_records;c restriction_cases;ref uuid;source_count bigint;actual_count bigint;
BEGIN
 ref:=COALESCE((to_jsonb(NEW)->>'subject_id')::uuid,(to_jsonb(NEW)->>'batch_id')::uuid);
 IF TG_TABLE_NAME='restriction_epochs' THEN
 SELECT count(*) INTO actual_count FROM restriction_epoch_steps WHERE subject_id=ref;
 IF NEW.version<>actual_count THEN RAISE EXCEPTION 'Restriction epoch must equal its retained effective reviews'; END IF;
 ELSIF TG_TABLE_NAME='restriction_impacts' THEN
 IF NOT EXISTS(SELECT 1 FROM restriction_supersessions s JOIN restriction_cases c ON c.id=s.case_id JOIN domain_events e ON e.id=NEW.target_id WHERE s.record_id=NEW.record_id AND NEW.subject_id IN(SELECT subject_id FROM restriction_family(c.subject_id))) THEN RAISE EXCEPTION 'Dependent exception requires actual completed evidence and reviewed source'; END IF;
 ELSIF TG_TABLE_NAME='restriction_records' AND NEW.kind='approve' THEN
 SELECT * INTO c FROM restriction_cases WHERE id=NEW.case_id;
 SELECT * INTO r FROM restriction_records WHERE id=NEW.proposal_id;
 IF r.actor_context IS DISTINCT FROM restriction_actor_context(r.actor_id) OR NOT restriction_staff(r.actor_id,c.subject_id)
 OR (SELECT jsonb_agg(x-'epoch' ORDER BY x->>'id') FROM jsonb_array_elements(NEW.context->'family') x) IS DISTINCT FROM
 (SELECT jsonb_agg(x-'epoch' ORDER BY x->>'id') FROM jsonb_array_elements(restriction_dependency_context(c.subject_id)->'family') x)
 THEN RAISE EXCEPTION 'Technical scope or completed dependencies changed before review commit'; END IF;
 END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER restriction_epoch_complete AFTER INSERT OR UPDATE ON restriction_epochs DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restriction_dependency_final();
CREATE CONSTRAINT TRIGGER restriction_impact_complete AFTER INSERT ON restriction_impacts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restriction_dependency_final();
CREATE CONSTRAINT TRIGGER restriction_dependency_complete AFTER INSERT ON restriction_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restriction_dependency_final();
