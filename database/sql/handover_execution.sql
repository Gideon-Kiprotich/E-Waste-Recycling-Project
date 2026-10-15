-- Only the version increment backed by an accepted handover can preserve a consumed stop.
CREATE FUNCTION handover_subject_step(ref uuid, before_row jsonb, after_row jsonb) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT (before_row-'version')=(after_row-'version') AND EXISTS (
 SELECT 1 FROM handover_receipts a JOIN handover_items hi ON hi.id=a.handover_item_id
 WHERE a.subject_id=ref AND hi.source_subject_version=(before_row->>'version')::bigint AND a.subject_version=(after_row->>'version')::bigint)
$$;
CREATE FUNCTION handover_receipt_projection(visit uuid, ref uuid, expected jsonb, actual jsonb) RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
 IF (expected-'version') IS DISTINCT FROM (actual-'version') OR NOT EXISTS (
 SELECT 1 FROM first_receipts f JOIN receipt_outcomes o ON o.id=f.outcome_id WHERE f.subject_id=ref AND o.visit_id=visit AND f.subject_version=(expected->>'version')::bigint
 ) OR NOT EXISTS (SELECT 1 FROM handover_receipts WHERE subject_id=ref AND subject_version=(actual->>'version')::bigint) THEN RETURN false; END IF;
 PERFORM handover_chain_check(ref);
 RETURN true;
END; $$;
CREATE OR REPLACE FUNCTION invalidate_route_dependency() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid;
BEGIN
 IF TG_OP='UPDATE' AND NEW IS NOT DISTINCT FROM OLD THEN RETURN NEW; END IF;
 IF TG_TABLE_NAME='place_categories' THEN ref:=COALESCE(NEW.place_id,OLD.place_id);
 ELSE ref:=COALESCE((to_jsonb(NEW)->>'id')::uuid,(to_jsonb(OLD)->>'id')::uuid); END IF;
 PERFORM set_config('ewaste.route_cause','dependency_changed:'||TG_TABLE_NAME,true);
 UPDATE route_approvals a SET state='stale',version=version+1 WHERE a.state='approved' AND EXISTS (
 SELECT 1 FROM route_dependencies d WHERE d.run_id=a.run_id AND d.kind=CASE WHEN TG_TABLE_NAME='place_categories' THEN 'places' ELSE TG_TABLE_NAME END AND d.record_id=ref)
 AND NOT (TG_OP='UPDATE' AND EXISTS (SELECT 1 FROM receipt_route_changes ch JOIN route_consumptions co ON co.visit_id=ch.visit_id JOIN routes ro ON ro.id=co.route_id
 WHERE ro.run_id=a.run_id AND ch.kind=TG_TABLE_NAME AND ch.record_id=ref AND ch.before_row=to_jsonb(OLD) AND ch.after_row=to_jsonb(NEW)))
 AND NOT (TG_OP='UPDATE' AND TG_TABLE_NAME='subjects' AND handover_subject_step(ref,to_jsonb(OLD),to_jsonb(NEW)) AND EXISTS (
 SELECT 1 FROM first_receipts f JOIN receipt_outcomes o ON o.id=f.outcome_id JOIN route_consumptions co ON co.visit_id=o.visit_id JOIN routes ro ON ro.id=co.route_id WHERE f.subject_id=ref AND ro.run_id=a.run_id));
 PERFORM set_config('ewaste.route_cause','',true);
 RETURN COALESCE(NEW,OLD);
END; $$;
CREATE OR REPLACE FUNCTION receipt_change_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actual jsonb;
BEGIN
 EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE id=$1',NEW.kind) INTO actual USING NEW.record_id;
 IF actual IS DISTINCT FROM NEW.after_row AND NOT (NEW.kind='subjects' AND handover_receipt_projection(NEW.visit_id,NEW.record_id,NEW.after_row,actual)) THEN RAISE EXCEPTION 'Receipt projection was not applied exactly'; END IF;
 RETURN NULL;
END; $$;
