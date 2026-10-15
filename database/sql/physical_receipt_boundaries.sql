CREATE FUNCTION receipt_actor_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM requests r JOIN users u ON u.id=NEW.actor_id JOIN memberships m ON m.user_id=u.id
 WHERE r.id=NEW.request_id AND u.status='active' AND m.active AND r.review_state='ready' AND r.version+1=NEW.request_version AND
 ((r.mode='pickup' AND m.role='collector' AND EXISTS (SELECT 1 FROM assignments a JOIN organizations o ON o.id=a.operator_id WHERE a.id=NEW.assignment_id AND a.request_id=r.id AND a.collector_id=u.id AND a.membership_id=m.id AND a.operator_id=m.organization_id AND a.active AND a.state='accepted' AND o.review_status='approved'))
 OR (r.mode='drop_off' AND NEW.assignment_id IS NULL AND m.role='processor' AND m.place_id=r.point_id AND EXISTS (SELECT 1 FROM places p JOIN organizations o ON o.id=p.organization_id WHERE p.id=r.point_id AND p.organization_id=m.organization_id AND p.kind='collection_point' AND p.active AND p.review_status='approved' AND o.review_status='approved' AND o.kind IN ('processor','both')
 AND NOT EXISTS (SELECT 1 FROM request_items ri JOIN items i ON i.subject_id=ri.item_id WHERE ri.request_id=r.id AND ri.active AND NOT EXISTS (SELECT 1 FROM place_categories pc WHERE pc.place_id=p.id AND pc.category_id=i.category_id AND pc.subtype=COALESCE(i.subtype,''))))))) THEN RAISE EXCEPTION 'Unauthorized physical visit'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER receipt_actor BEFORE INSERT ON receipt_visits FOR EACH ROW EXECUTE FUNCTION receipt_actor_guard();
CREATE FUNCTION receipt_assignment_final() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.state IN ('completed','remainder_review') AND (NOT EXISTS (SELECT 1 FROM receipt_visits v JOIN route_consumptions co ON co.visit_id=v.id WHERE v.assignment_id=NEW.id AND co.assignment_id=NEW.id)
 OR ((NEW.state='remainder_review') IS DISTINCT FROM EXISTS (SELECT 1 FROM request_items WHERE request_id=NEW.request_id AND active))) THEN RAISE EXCEPTION 'Assignment closure requires a matching physical visit'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER physical_assignment_final AFTER UPDATE ON assignments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receipt_assignment_final();
CREATE FUNCTION receipt_execution_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE run uuid; approval uuid;
BEGIN
 SELECT r.run_id,a.id INTO run,approval FROM routes r JOIN route_approvals a ON a.run_id=r.run_id WHERE r.id=NEW.route_id AND a.state='approved';
 IF approval IS NOT NULL AND (EXISTS (SELECT * FROM expected_route_claims(run) EXCEPT SELECT kind,record_id FROM route_claims WHERE approval_id=approval)
 OR EXISTS (SELECT kind,record_id FROM route_claims WHERE approval_id=approval EXCEPT SELECT * FROM expected_route_claims(run))) THEN RAISE EXCEPTION 'Incomplete execution claim projection'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER consumed_claims AFTER INSERT ON route_consumptions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receipt_execution_final();
CREATE CONSTRAINT TRIGGER finished_claims AFTER INSERT ON route_finishes DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receipt_execution_final();
