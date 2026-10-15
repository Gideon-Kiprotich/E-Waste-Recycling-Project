-- Trigger records have different row types; JSON access is safe before a first receipt exists.
CREATE OR REPLACE FUNCTION receipt_integrity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid; f first_receipts; o receipt_outcomes; v receipt_visits; r requests; i items; c custody_current;
BEGIN
 IF TG_TABLE_NAME='first_receipts' THEN ref:=NEW.subject_id;
 ELSIF TG_TABLE_NAME='receipt_outcomes' THEN
   SELECT item_id INTO ref FROM request_items WHERE id=NEW.request_item_id;
 ELSE ref:=COALESCE(to_jsonb(NEW)->>'subject_id',to_jsonb(NEW)->>'item_id')::uuid; END IF;
 SELECT * INTO f FROM first_receipts WHERE subject_id=ref;
 IF NOT FOUND THEN
   IF TG_TABLE_NAME='receipt_outcomes' AND to_jsonb(NEW)->>'outcome'='received' THEN RAISE EXCEPTION 'Received outcome requires first receipt'; END IF;
   IF TG_TABLE_NAME='request_items' AND to_jsonb(NEW)->>'outcome'='collected' THEN RAISE EXCEPTION 'Collected item requires first receipt'; END IF;
   IF TG_TABLE_NAME='items' AND to_jsonb(NEW)->>'processing_state'<>'registered' THEN RAISE EXCEPTION 'Physical item state requires first receipt'; END IF;
   IF TG_TABLE_NAME='custody_current' THEN RAISE EXCEPTION 'Custody change requires first receipt'; END IF;
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
