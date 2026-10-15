CREATE OR REPLACE FUNCTION protect_assignment() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Assignment history is retained'; END IF;
    IF TG_OP='INSERT' THEN
        IF NEW.state<>'offered' OR NEW.version<>1 OR NOT EXISTS (
            SELECT 1 FROM memberships m JOIN users u ON u.id=m.user_id JOIN organizations o ON o.id=m.organization_id
            WHERE m.id=NEW.membership_id AND m.user_id=NEW.collector_id AND m.organization_id=NEW.operator_id AND m.role='collector' AND m.active AND u.status='active' AND o.review_status='approved' AND o.kind IN ('collector','both')
        ) OR NOT EXISTS (SELECT 1 FROM requests r JOIN request_reviews v ON v.id=r.review_id WHERE r.id=NEW.request_id AND r.mode='pickup' AND r.version=NEW.request_version AND r.review_id=NEW.review_id AND r.review_state='ready' AND v.request_id=r.id)
        OR NOT EXISTS (SELECT 1 FROM places p JOIN organizations o ON o.id=p.organization_id WHERE p.id=NEW.facility_id AND p.active AND p.review_status='approved' AND p.kind IN ('facility','collection_point') AND o.review_status='approved' AND o.kind IN ('processor','both')) THEN
            RAISE EXCEPTION 'Invalid assignment references or offer state';
        END IF;
    ELSE
        IF (to_jsonb(NEW)-ARRAY['state','active','version','accepted_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','active','version','accepted_at']) OR NEW.version<>OLD.version+1
        OR NOT ((OLD.state='offered' AND NEW.state IN ('accepted','declined','withdrawn','replaced','invalidated')) OR (OLD.state='accepted' AND NEW.state IN ('withdrawn','replaced','invalidated','completed','remainder_review')))
        OR (OLD.accepted_at IS NOT NULL AND NEW.accepted_at IS DISTINCT FROM OLD.accepted_at) THEN RAISE EXCEPTION 'Invalid assignment transition or identity change'; END IF;
    END IF;
    RETURN NEW;
END; $$;
CREATE FUNCTION receipt_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.role='processor' AND NEW.place_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM places p WHERE p.id=NEW.place_id AND p.organization_id=NEW.organization_id AND p.kind='collection_point') THEN RAISE EXCEPTION 'Processor point scope mismatch'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER receipt_scope BEFORE INSERT OR UPDATE ON memberships FOR EACH ROW EXECUTE FUNCTION receipt_scope_guard();
CREATE TRIGGER permanent_party BEFORE UPDATE OR DELETE ON parties FOR EACH ROW EXECUTE FUNCTION protect_device_history();

CREATE FUNCTION receipt_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid; f first_receipts; o receipt_outcomes; v receipt_visits; r requests; i items; c custody_current;
BEGIN
 IF TG_TABLE_NAME='first_receipts' THEN ref:=NEW.subject_id;
 ELSIF TG_TABLE_NAME='receipt_outcomes' THEN
   SELECT item_id INTO ref FROM request_items WHERE id=NEW.request_item_id;
 ELSE ref:=COALESCE(to_jsonb(NEW)->>'subject_id',to_jsonb(NEW)->>'item_id')::uuid; END IF;
 SELECT * INTO f FROM first_receipts WHERE subject_id=ref;
 IF NOT FOUND THEN
   IF TG_TABLE_NAME='receipt_outcomes' AND NEW.outcome='received' THEN RAISE EXCEPTION 'Received outcome requires first receipt'; END IF;
   IF TG_TABLE_NAME='request_items' AND NEW.outcome='collected' THEN RAISE EXCEPTION 'Collected item requires first receipt'; END IF;
   RETURN NULL;
 END IF;
 SELECT * INTO o FROM receipt_outcomes WHERE id=f.outcome_id;
 SELECT * INTO v FROM receipt_visits WHERE id=o.visit_id;
 SELECT * INTO r FROM requests WHERE id=v.request_id;
 SELECT * INTO i FROM items WHERE subject_id=ref;
 SELECT * INTO c FROM custody_current WHERE subject_id=ref;
 IF o.outcome<>'received' OR NOT o.attested OR NOT EXISTS (SELECT 1 FROM request_items WHERE id=o.request_item_id AND request_id=r.id AND item_id=ref AND outcome='collected' AND NOT active)
 OR i.owner_user_id<>r.consumer_id OR i.processing_state<>'received' OR c.party_id<>f.recipient_party_id OR c.last_event_id<>f.event_id OR c.version<>f.custody_version
 OR f.recipient_party_id<>v.recipient_party_id OR f.actor_id<>v.actor_id OR NOT EXISTS (SELECT 1 FROM subjects WHERE id=ref AND version=f.subject_version)
 OR NOT EXISTS (SELECT 1 FROM parties WHERE id=f.from_party_id AND kind='user' AND user_id=r.consumer_id)
 OR NOT EXISTS (SELECT 1 FROM domain_events WHERE id=f.event_id AND subject_id=ref AND aggregate_kind='item' AND aggregate_id=ref AND aggregate_version=f.subject_version AND actor_id=v.actor_id AND command_id=v.command_id AND type='first_receipt' AND occurred_at=v.observed_at)
 OR NOT EXISTS (SELECT 1 FROM parties p WHERE p.id=f.recipient_party_id AND ((r.mode='pickup' AND p.kind='user' AND p.user_id=v.actor_id AND EXISTS (SELECT 1 FROM assignments WHERE id=v.assignment_id AND collector_id=v.actor_id AND request_id=r.id AND state IN ('completed','remainder_review')))
 OR (r.mode='drop_off' AND v.assignment_id IS NULL AND p.kind='place' AND p.place_id=r.point_id))) THEN RAISE EXCEPTION 'Inconsistent physical first receipt'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER receipt_consistency AFTER INSERT ON first_receipts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receipt_integrity();
CREATE CONSTRAINT TRIGGER outcome_consistency AFTER INSERT ON receipt_outcomes DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receipt_integrity();
CREATE CONSTRAINT TRIGGER collected_consistency AFTER UPDATE ON request_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receipt_integrity();
CREATE CONSTRAINT TRIGGER custody_receipt_consistency AFTER UPDATE ON custody_current DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receipt_integrity();
CREATE CONSTRAINT TRIGGER item_receipt_consistency AFTER UPDATE ON items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receipt_integrity();

CREATE FUNCTION receipt_outcome_target() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM receipt_visits v JOIN request_items ri ON ri.request_id=v.request_id WHERE v.id=NEW.visit_id AND ri.id=NEW.request_item_id AND ri.active AND ri.outcome='outstanding') THEN RAISE EXCEPTION 'Outcome outside outstanding visit work'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER outcome_target BEFORE INSERT ON receipt_outcomes FOR EACH ROW EXECUTE FUNCTION receipt_outcome_target();
CREATE FUNCTION receipt_visit_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM requests r JOIN domain_events e ON e.aggregate_id=r.id AND e.aggregate_kind='request' JOIN commands c ON c.id=e.command_id WHERE r.id=NEW.request_id AND e.id=NEW.event_id AND e.command_id=NEW.command_id AND e.actor_id=NEW.actor_id AND e.type='physical_visit_recorded' AND e.aggregate_version=NEW.request_version AND c.actor_id=NEW.actor_id) THEN RAISE EXCEPTION 'Invalid visit event'; END IF;
 IF NOT EXISTS (SELECT 1 FROM receipt_outcomes WHERE visit_id=NEW.id) THEN RAISE EXCEPTION 'Empty visit'; END IF;
 IF NEW.assignment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM route_consumptions WHERE visit_id=NEW.id AND assignment_id=NEW.assignment_id) THEN RAISE EXCEPTION 'Pickup visit requires consumed route stop'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER complete_visit AFTER INSERT ON receipt_visits DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receipt_visit_guard();
CREATE FUNCTION consumption_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM routes r JOIN route_approvals a ON a.run_id=r.run_id JOIN receipt_visits v ON v.assignment_id=NEW.assignment_id JOIN assignments j ON j.id=v.assignment_id
 WHERE r.id=NEW.route_id AND r.method='proposed' AND a.state='approved' AND v.id=NEW.visit_id AND v.actor_id=r.collector_id AND j.collector_id=v.actor_id AND j.state='accepted' AND j.active)
 OR EXISTS (SELECT 1 FROM route_finishes WHERE route_id=NEW.route_id) THEN RAISE EXCEPTION 'Invalid stop consumption'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER consume_route_stop BEFORE INSERT ON route_consumptions FOR EACH ROW EXECUTE FUNCTION consumption_guard();

-- A narrowly scoped before/after record authorizes only the four receipt-driven projections.
CREATE FUNCTION receipt_route_change_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v receipt_visits; permitted text[];
BEGIN
 SELECT * INTO v FROM receipt_visits WHERE id=NEW.visit_id;
 IF NOT EXISTS (SELECT 1 FROM route_consumptions WHERE visit_id=v.id) THEN RAISE EXCEPTION 'Change lacks consumed stop'; END IF;
 IF NEW.kind='requests' AND NEW.record_id=v.request_id THEN permitted:=ARRAY['version','state','review_state'];
 ELSIF NEW.kind='assignments' AND NEW.record_id=v.assignment_id THEN permitted:=ARRAY['version','state','active'];
 ELSIF NEW.kind='request_items' AND EXISTS (SELECT 1 FROM receipt_outcomes o JOIN request_items ri ON ri.id=o.request_item_id WHERE o.visit_id=v.id AND o.outcome='received' AND ri.id=NEW.record_id) THEN permitted:=ARRAY['outcome','active'];
 ELSIF NEW.kind='subjects' AND EXISTS (SELECT 1 FROM receipt_outcomes o JOIN request_items ri ON ri.id=o.request_item_id WHERE o.visit_id=v.id AND o.outcome='received' AND ri.item_id=NEW.record_id) THEN permitted:=ARRAY['version'];
 ELSE RAISE EXCEPTION 'Unrelated receipt dependency change'; END IF;
 IF (NEW.before_row-permitted) IS DISTINCT FROM (NEW.after_row-permitted) OR NEW.before_row=NEW.after_row THEN RAISE EXCEPTION 'Unrelated receipt projection fields'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER expected_receipt_change BEFORE INSERT ON receipt_route_changes FOR EACH ROW EXECUTE FUNCTION receipt_route_change_guard();
CREATE FUNCTION receipt_change_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actual jsonb;
BEGIN
 EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE id=$1',NEW.kind) INTO actual USING NEW.record_id;
 IF actual IS DISTINCT FROM NEW.after_row THEN RAISE EXCEPTION 'Receipt projection was not applied exactly'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER applied_receipt_change AFTER INSERT ON receipt_route_changes DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receipt_change_final();
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
 WHERE ro.run_id=a.run_id AND ch.kind=TG_TABLE_NAME AND ch.record_id=ref AND ch.before_row=to_jsonb(OLD) AND ch.after_row=to_jsonb(NEW)));
 PERFORM set_config('ewaste.route_cause','',true);
 RETURN COALESCE(NEW,OLD);
END; $$;
CREATE OR REPLACE FUNCTION guard_route_claim() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN
 IF NOT EXISTS (SELECT 1 FROM route_approvals WHERE id=NEW.approval_id AND state='approved') THEN RAISE EXCEPTION 'Inactive route claim'; END IF;
 IF NEW.kind='assignment' AND NOT EXISTS (SELECT 1 FROM route_approvals a JOIN routes r ON r.run_id=a.run_id AND r.method='proposed' JOIN route_stops s ON s.route_id=r.id JOIN assignments j ON j.id=s.assignment_id WHERE a.id=NEW.approval_id AND j.id=NEW.record_id AND j.active AND j.state='accepted' AND NOT EXISTS (SELECT 1 FROM route_consumptions WHERE route_id=r.id AND assignment_id=j.id) AND NOT EXISTS (SELECT 1 FROM route_finishes WHERE route_id=r.id)) THEN RAISE EXCEPTION 'Invalid assignment claim'; END IF;
 RETURN NEW;
 END IF;
 IF TG_OP='UPDATE' THEN RAISE EXCEPTION 'Claims cannot be rewritten'; END IF;
 IF EXISTS (SELECT 1 FROM route_approvals WHERE id=OLD.approval_id AND state='approved') AND NOT EXISTS (
 SELECT 1 FROM routes r JOIN route_approvals a ON a.run_id=r.run_id WHERE a.id=OLD.approval_id AND r.method='proposed' AND
 ((OLD.kind='assignment' AND EXISTS (SELECT 1 FROM route_consumptions WHERE route_id=r.id AND assignment_id=OLD.record_id)) OR
 (EXISTS (SELECT 1 FROM route_finishes WHERE route_id=r.id) AND ((OLD.kind='vehicle' AND OLD.record_id=r.vehicle_id) OR (OLD.kind='collector' AND OLD.record_id=r.collector_id) OR (OLD.kind='assignment' AND EXISTS (SELECT 1 FROM route_stops WHERE route_id=r.id AND assignment_id=OLD.record_id)))))) THEN RAISE EXCEPTION 'Active claims require consumption, finish or invalidation'; END IF;
 RETURN OLD;
END; $$;
CREATE OR REPLACE FUNCTION route_approval_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Route approval history is retained'; END IF;
 IF TG_OP='INSERT' THEN
 IF NEW.state<>'approved' OR NEW.version<>1 THEN RAISE EXCEPTION 'Invalid initial approval'; END IF;
 ELSE
 IF OLD.state<>'approved' OR NEW.state NOT IN ('stale','superseded','withdrawn','finished') OR NEW.version<>OLD.version+1 OR (to_jsonb(NEW)-ARRAY['state','version']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','version']) THEN RAISE EXCEPTION 'Invalid approval transition'; END IF;
 IF NEW.state='finished' AND EXISTS (SELECT 1 FROM routes r WHERE r.run_id=NEW.run_id AND r.method='proposed' AND NOT EXISTS (SELECT 1 FROM route_finishes WHERE route_id=r.id)) THEN RAISE EXCEPTION 'Unfinished tours'; END IF;
 DELETE FROM route_claims WHERE approval_id=NEW.id;
 END IF;
 INSERT INTO route_approval_history VALUES (gen_random_uuid(),NEW.id,NEW.version,NEW.state,COALESCE(NULLIF(current_setting('ewaste.route_cause',true),''),'explicit_command'),CURRENT_TIMESTAMP);
 RETURN NEW;
END; $$;
CREATE FUNCTION expected_route_claims(run uuid) RETURNS TABLE(kind text,ref uuid) LANGUAGE sql AS $$
 SELECT 'assignment',s.assignment_id FROM routes r JOIN route_stops s ON s.route_id=r.id WHERE r.run_id=run AND r.method='proposed' AND NOT EXISTS (SELECT 1 FROM route_finishes WHERE route_id=r.id) AND NOT EXISTS (SELECT 1 FROM route_consumptions WHERE route_id=r.id AND assignment_id=s.assignment_id)
 UNION SELECT 'vehicle',r.vehicle_id FROM routes r WHERE r.run_id=run AND r.method='proposed' AND NOT EXISTS (SELECT 1 FROM route_finishes WHERE route_id=r.id)
 UNION SELECT 'collector',r.collector_id FROM routes r WHERE r.run_id=run AND r.method='proposed' AND NOT EXISTS (SELECT 1 FROM route_finishes WHERE route_id=r.id)
$$;
CREATE OR REPLACE FUNCTION route_claim_set_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE approval uuid; run uuid; state text;
BEGIN
 IF TG_TABLE_NAME='route_approvals' THEN approval:=COALESCE(NEW.id,OLD.id); ELSE approval:=COALESCE(NEW.approval_id,OLD.approval_id); END IF;
 SELECT a.run_id,a.state INTO run,state FROM route_approvals a WHERE a.id=approval;
 IF state='approved' THEN
 IF EXISTS (SELECT * FROM expected_route_claims(run) EXCEPT SELECT c.kind,c.record_id FROM route_claims c WHERE c.approval_id=approval)
 OR EXISTS (SELECT c.kind,c.record_id FROM route_claims c WHERE c.approval_id=approval EXCEPT SELECT * FROM expected_route_claims(run))
 OR NOT EXISTS (SELECT 1 FROM routes WHERE run_id=run AND method='proposed') THEN RAISE EXCEPTION 'Incomplete or inconsistent approval claims'; END IF;
 ELSIF EXISTS (SELECT 1 FROM route_claims WHERE approval_id=approval) THEN RAISE EXCEPTION 'Closed approval retains claims'; END IF;
 RETURN NULL;
END; $$;
CREATE FUNCTION finish_route_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE expected jsonb;
BEGIN
 IF NOT EXISTS (SELECT 1 FROM routes r JOIN route_approvals a ON a.run_id=r.run_id WHERE r.id=NEW.route_id AND r.collector_id=NEW.actor_id AND r.method='proposed' AND a.state='approved') THEN RAISE EXCEPTION 'Unauthorized finish'; END IF;
 SELECT COALESCE(jsonb_agg(assignment_id::text ORDER BY assignment_id),'[]') INTO expected FROM route_stops s WHERE route_id=NEW.route_id AND NOT EXISTS (SELECT 1 FROM route_consumptions WHERE route_id=s.route_id AND assignment_id=s.assignment_id);
 IF NEW.unfinished<>expected THEN RAISE EXCEPTION 'Unfinished stops must be explicitly retained'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER finish_route BEFORE INSERT ON route_finishes FOR EACH ROW EXECUTE FUNCTION finish_route_guard();
