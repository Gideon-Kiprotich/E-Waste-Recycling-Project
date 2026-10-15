CREATE OR REPLACE FUNCTION receipt_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.role='processor' AND NEW.place_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM places p WHERE p.id=NEW.place_id AND p.organization_id=NEW.organization_id AND p.kind IN ('collection_point','facility')) THEN RAISE EXCEPTION 'Processor place scope mismatch'; END IF;
 RETURN NEW;
END; $$;

CREATE FUNCTION handover_scope(h handovers, side text) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS (
 SELECT 1 FROM memberships m JOIN users u ON u.id=m.user_id JOIN organizations o ON o.id=m.organization_id
 JOIN parties p ON p.id=CASE WHEN side='sender' THEN h.sender_party_id ELSE h.receiver_party_id END
 LEFT JOIN places l ON l.id=p.place_id
 WHERE u.status='active' AND m.active AND o.review_status='approved'
 AND m.id=CASE WHEN side='sender' THEN h.sender_membership_id ELSE h.receiver_membership_id END
 AND u.id=CASE WHEN side='sender' THEN h.sender_actor_id ELSE h.receiver_actor_id END
 AND m.version=CASE WHEN side='sender' THEN h.sender_scope_version ELSE h.receiver_scope_version END
 AND ((side='sender' AND m.role='collector' AND p.kind='user' AND p.user_id=u.id AND o.kind IN ('collector','both'))
 OR (m.role='processor' AND p.kind='place' AND m.place_id=l.id AND m.organization_id=l.organization_id AND l.active AND l.review_status='approved' AND o.kind IN ('processor','both')
 AND ((side='sender' AND l.kind='collection_point') OR (side='receiver' AND l.kind IN ('collection_point','facility') AND l.version=h.destination_version AND o.version=h.operator_version)))))
$$;

CREATE FUNCTION handover_header_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Handover history is retained'; END IF;
 IF TG_OP='INSERT' THEN
 IF NEW.version<>1 OR NEW.state<>'pending' OR NOT handover_scope(NEW,'sender') OR NOT handover_scope(NEW,'receiver') OR NOT EXISTS (
 SELECT 1 FROM parties s JOIN parties r ON r.id=NEW.receiver_party_id JOIN places p ON p.id=r.place_id LEFT JOIN places sp ON sp.id=s.place_id
 WHERE s.id=NEW.sender_party_id AND ((s.kind='user' AND p.kind IN ('collection_point','facility')) OR (sp.kind='collection_point' AND p.kind='facility'))
 ) THEN RAISE EXCEPTION 'Ineligible handover parties'; END IF;
 ELSIF (to_jsonb(NEW)-ARRAY['version','state']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['version','state']) OR NEW.version<>OLD.version+1 THEN RAISE EXCEPTION 'Handover identity or version is immutable'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER handover_header BEFORE INSERT OR UPDATE OR DELETE ON handovers FOR EACH ROW EXECUTE FUNCTION handover_header_guard();

CREATE FUNCTION handover_item_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE h handovers;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Handover items are retained'; END IF;
 SELECT * INTO h FROM handovers WHERE id=NEW.handover_id;
 IF TG_OP='INSERT' THEN
 IF NEW.state<>'pending' OR NOT NEW.active OR NOT EXISTS (SELECT 1 FROM custody_current c JOIN subjects s ON s.id=c.subject_id JOIN items i ON i.subject_id=s.id JOIN first_receipts f ON f.subject_id=s.id
 WHERE c.subject_id=NEW.subject_id AND c.party_id=h.sender_party_id AND c.last_event_id=NEW.source_event_id AND c.version=NEW.source_custody_version AND s.version=NEW.source_subject_version AND i.processing_state='received'
 AND EXISTS (SELECT 1 FROM parties p JOIN place_categories pc ON pc.place_id=p.place_id WHERE p.id=h.receiver_party_id AND pc.category_id=i.category_id AND pc.subtype=COALESCE(i.subtype,'')))
 THEN RAISE EXCEPTION 'Handover item custody or destination incompatible'; END IF;
 ELSE
 IF (to_jsonb(NEW)-ARRAY['state','active']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','active']) OR NOT ((OLD.state='pending' AND NEW.state IN ('accepted','disputed','withdrawn')) OR (OLD.state='disputed' AND NEW.state='resolved')) THEN RAISE EXCEPTION 'Illegal handover item transition'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER handover_item BEFORE INSERT OR UPDATE OR DELETE ON handover_items FOR EACH ROW EXECUTE FUNCTION handover_item_guard();

CREATE FUNCTION handover_operation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE h handovers;
BEGIN
 SELECT * INTO h FROM handovers WHERE id=NEW.handover_id FOR UPDATE;
 IF NEW.version<>(h.version+(CASE WHEN NEW.kind='initiated' THEN 0 ELSE 1 END)) OR (NEW.kind='initiated')<>(NEW.version=1) OR NOT EXISTS (SELECT 1 FROM users u JOIN memberships m ON m.user_id=u.id WHERE u.id=NEW.actor_id AND u.status='active' AND m.active) THEN RAISE EXCEPTION 'Invalid handover operation version or actor'; END IF;
 IF NEW.kind='initiated' AND (NEW.actor_id<>h.sender_actor_id OR NOT handover_scope(h,'sender') OR NOT handover_scope(h,'receiver')) THEN RAISE EXCEPTION 'Unauthorized handover sender'; END IF;
 IF NEW.kind='acknowledged' AND (NEW.actor_id<>h.receiver_actor_id OR NOT handover_scope(h,'receiver') OR NOT handover_scope(h,'sender') OR EXISTS (SELECT 1 FROM handover_items hi JOIN items i ON i.subject_id=hi.subject_id JOIN parties p ON p.id=h.receiver_party_id WHERE hi.handover_id=h.id AND hi.state='pending' AND NOT EXISTS (SELECT 1 FROM place_categories pc WHERE pc.place_id=p.place_id AND pc.category_id=i.category_id AND pc.subtype=COALESCE(i.subtype,'')))) THEN RAISE EXCEPTION 'Unauthorized or incompatible handover acknowledgment'; END IF;
 IF NEW.kind='resolved' AND NOT EXISTS (SELECT 1 FROM memberships WHERE user_id=NEW.actor_id AND role='administrator' AND active) THEN RAISE EXCEPTION 'Administrator resolution required'; END IF;
 IF NEW.kind='withdrawn' AND NOT ((NEW.actor_id=h.sender_actor_id AND handover_scope(h,'sender')) OR EXISTS (SELECT 1 FROM memberships WHERE user_id=NEW.actor_id AND role='administrator' AND active)) THEN RAISE EXCEPTION 'Unauthorized handover withdrawal'; END IF;
 IF NOT EXISTS (SELECT 1 FROM domain_events e JOIN commands c ON c.id=e.command_id WHERE e.id=NEW.event_id AND e.aggregate_kind='handover' AND e.aggregate_id=h.id AND e.aggregate_version=NEW.version AND e.actor_id=NEW.actor_id AND e.type='handover_'||NEW.kind AND c.id=NEW.command_id AND c.actor_id=NEW.actor_id AND c.status='completed') THEN RAISE EXCEPTION 'Handover operation requires committed command event'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER handover_operation BEFORE INSERT ON handover_operations FOR EACH ROW EXECUTE FUNCTION handover_operation_guard();

CREATE FUNCTION handover_outcome_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE op handover_operations; hi handover_items;
BEGIN
 SELECT * INTO op FROM handover_operations WHERE id=NEW.operation_id;
 SELECT * INTO hi FROM handover_items WHERE id=NEW.handover_item_id FOR UPDATE;
 IF hi.handover_id<>op.handover_id OR NOT ((op.kind='initiated' AND hi.state='pending' AND NEW.outcome='pending')
 OR (op.kind='acknowledged' AND hi.state='pending' AND NEW.outcome IN ('accepted','missing','damaged','mismatch','refused'))
 OR (op.kind='withdrawn' AND hi.state='pending' AND NEW.outcome='withdrawn')
 OR (op.kind='resolved' AND hi.state='disputed' AND NEW.outcome IN ('closed_no_transfer','review_continues'))) THEN RAISE EXCEPTION 'Invalid handover outcome'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER handover_outcome BEFORE INSERT ON handover_outcomes FOR EACH ROW EXECUTE FUNCTION handover_outcome_guard();

CREATE FUNCTION handover_receipt_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE hi handover_items; h handovers; op handover_operations; oc handover_outcomes;
BEGIN
 SELECT * INTO hi FROM handover_items WHERE id=NEW.handover_item_id;
 SELECT * INTO h FROM handovers WHERE id=hi.handover_id;
 SELECT * INTO oc FROM handover_outcomes WHERE id=NEW.outcome_id;
 SELECT * INTO op FROM handover_operations WHERE id=oc.operation_id;
 IF NEW.subject_id<>hi.subject_id OR oc.handover_item_id<>hi.id OR oc.outcome<>'accepted' OR op.kind<>'acknowledged' OR NOT oc.attested OR NEW.custody_version<>hi.source_custody_version+1 OR NEW.subject_version<>hi.source_subject_version+1
 OR NOT EXISTS (SELECT 1 FROM custody_current WHERE subject_id=hi.subject_id AND party_id=h.sender_party_id AND version=hi.source_custody_version AND last_event_id=hi.source_event_id)
 OR NOT EXISTS (SELECT 1 FROM domain_events WHERE id=NEW.event_id AND subject_id=hi.subject_id AND aggregate_kind='item' AND aggregate_id=hi.subject_id AND aggregate_version=NEW.subject_version AND actor_id=h.receiver_actor_id AND command_id=op.command_id AND type='handover_accepted' AND occurred_at=op.observed_at) THEN RAISE EXCEPTION 'Invalid acknowledged custody transition'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER handover_receipt BEFORE INSERT ON handover_receipts FOR EACH ROW EXECUTE FUNCTION handover_receipt_guard();

CREATE FUNCTION handover_chain_check(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE f first_receipts; rec record; party uuid; ev uuid; cv bigint; sv bigint;
BEGIN
 SELECT * INTO f FROM first_receipts WHERE subject_id=ref;
 IF NOT FOUND THEN
 IF EXISTS (SELECT 1 FROM handover_items WHERE subject_id=ref) THEN RAISE EXCEPTION 'Handover requires first receipt'; END IF;
 RETURN;
 END IF;
 party:=f.recipient_party_id; ev:=f.event_id; cv:=f.custody_version; sv:=f.subject_version;
 FOR rec IN SELECT a.*,hi.source_event_id,hi.source_custody_version,hi.source_subject_version,h.sender_party_id,h.receiver_party_id,hi.state FROM handover_receipts a JOIN handover_items hi ON hi.id=a.handover_item_id JOIN handovers h ON h.id=hi.handover_id WHERE a.subject_id=ref ORDER BY a.custody_version LOOP
 IF rec.source_event_id<>ev OR rec.source_custody_version<>cv OR rec.source_subject_version<>sv OR rec.sender_party_id<>party OR rec.custody_version<>cv+1 OR rec.subject_version<>sv+1 OR rec.state<>'accepted' THEN RAISE EXCEPTION 'Broken acknowledged custody chain'; END IF;
 party:=rec.receiver_party_id;ev:=rec.event_id;cv:=rec.custody_version;sv:=rec.subject_version;
 END LOOP;
 IF NOT EXISTS (SELECT 1 FROM custody_current c JOIN subjects s ON s.id=c.subject_id WHERE c.subject_id=ref AND c.party_id=party AND c.last_event_id=ev AND c.version=cv AND s.version=sv) THEN RAISE EXCEPTION 'Current custody must match acknowledged chain'; END IF;
END; $$;

CREATE FUNCTION handover_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE hid uuid; h handovers; hi handover_items; op handover_operations; oc handover_outcomes; expected text;
BEGIN
 IF TG_TABLE_NAME='handovers' THEN hid:=NEW.id;
 ELSIF TG_TABLE_NAME='handover_items' OR TG_TABLE_NAME='handover_operations' THEN hid:=NEW.handover_id;
 ELSE SELECT o.handover_id INTO hid FROM handover_operations o JOIN handover_outcomes x ON x.operation_id=o.id WHERE x.id=CASE WHEN TG_TABLE_NAME='handover_outcomes' THEN (to_jsonb(NEW)->>'id')::uuid ELSE (to_jsonb(NEW)->>'outcome_id')::uuid END; END IF;
 SELECT * INTO h FROM handovers WHERE id=hid;
 IF h.id IS NULL OR NOT EXISTS (SELECT 1 FROM handover_items WHERE handover_id=hid) OR (SELECT count(*) FROM handover_operations WHERE handover_id=hid)<>h.version OR NOT EXISTS (SELECT 1 FROM handover_operations WHERE handover_id=hid AND version=1 AND kind='initiated') THEN RAISE EXCEPTION 'Incomplete handover operation history'; END IF;
 FOR op IN SELECT * FROM handover_operations WHERE handover_id=hid LOOP
 IF NOT EXISTS (SELECT 1 FROM handover_outcomes WHERE operation_id=op.id) THEN RAISE EXCEPTION 'Operation requires item outcomes'; END IF;
 END LOOP;
 FOR hi IN SELECT * FROM handover_items WHERE handover_id=hid LOOP
 IF NOT EXISTS (SELECT 1 FROM handover_outcomes x JOIN handover_operations o ON o.id=x.operation_id WHERE x.handover_item_id=hi.id AND o.kind='initiated') THEN RAISE EXCEPTION 'Manifest requires sender acknowledgment'; END IF;
 SELECT x.* INTO oc FROM handover_outcomes x JOIN handover_operations o ON o.id=x.operation_id WHERE x.handover_item_id=hi.id ORDER BY o.version DESC LIMIT 1;
 expected:=CASE WHEN oc.outcome='accepted' THEN 'accepted' WHEN oc.outcome='withdrawn' THEN 'withdrawn' WHEN oc.outcome='closed_no_transfer' THEN 'resolved' WHEN oc.outcome='pending' THEN 'pending' ELSE 'disputed' END;
 IF hi.state<>expected OR ((hi.state='accepted') IS DISTINCT FROM EXISTS (SELECT 1 FROM handover_receipts WHERE handover_item_id=hi.id)) THEN RAISE EXCEPTION 'Inconsistent handover outcome projection'; END IF;
 PERFORM handover_chain_check(hi.subject_id);
 END LOOP;
 SELECT CASE WHEN bool_and(state='accepted') THEN 'accepted' WHEN bool_or(state='disputed') THEN 'disputed' WHEN bool_and(state='pending') THEN 'pending' WHEN bool_or(state='pending') THEN 'partial' ELSE 'closed' END INTO expected FROM handover_items WHERE handover_id=hid;
 IF h.state<>expected THEN RAISE EXCEPTION 'Inconsistent handover header'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER handover_final_header AFTER INSERT OR UPDATE ON handovers DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION handover_final();
CREATE CONSTRAINT TRIGGER handover_final_item AFTER INSERT OR UPDATE ON handover_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION handover_final();
CREATE CONSTRAINT TRIGGER handover_final_operation AFTER INSERT ON handover_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION handover_final();
CREATE CONSTRAINT TRIGGER handover_final_outcome AFTER INSERT ON handover_outcomes DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION handover_final();
CREATE CONSTRAINT TRIGGER handover_final_receipt AFTER INSERT ON handover_receipts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION handover_final();

CREATE FUNCTION handover_subject_final() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM handover_chain_check(NEW.id);
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER acknowledged_subject_chain AFTER UPDATE ON subjects DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION handover_subject_final();
