-- Read adapters retain original physical identity and observed-date cohorts.
CREATE VIEW reporting_collections AS
 SELECT f.subject_id,i.owner_user_id,i.intake_class,c.code category,i.subtype,s.public_code,e.occurred_at observed_at,e.recorded_at,
 f.event_id,f.recipient_party_id,p.place_id,ri.id request_item_id,ri.estimated_kg
 FROM first_receipts f JOIN domain_events e ON e.id=f.event_id AND e.type='first_receipt' AND e.subject_id=f.subject_id
 JOIN items i ON i.subject_id=f.subject_id JOIN subjects s ON s.id=i.subject_id JOIN categories c ON c.id=i.category_id
 JOIN parties p ON p.id=f.recipient_party_id
 LEFT JOIN receipt_outcomes o ON o.id=f.outcome_id LEFT JOIN request_items ri ON ri.id=o.request_item_id AND ri.item_id=f.subject_id
 WHERE i.intake_class IN ('whole_device','standalone_part');
-- Classification uses frozen physical evidence and immutable request ancestry, never today's place kind.
CREATE FUNCTION reporting_admission_kind(event_type text,frozen_kind text,request_mode text,point_matches boolean,has_place boolean) RETURNS text LANGUAGE sql IMMUTABLE AS $$
 SELECT CASE
 WHEN event_type='handover_accepted' AND has_place AND frozen_kind IN ('facility','collection_point') THEN frozen_kind
 WHEN event_type='first_receipt' AND request_mode='drop_off' AND point_matches AND has_place AND (frozen_kind IS NULL OR frozen_kind='collection_point') THEN 'collection_point'
 WHEN event_type='first_receipt' AND request_mode='pickup' AND NOT has_place AND frozen_kind IS NULL THEN 'collector'
 ELSE 'unknown' END
$$;
CREATE FUNCTION reporting_first_facility_version(facts jsonb) RETURNS bigint LANGUAGE sql IMMUTABLE AS $$
 WITH chain AS(SELECT * FROM jsonb_to_recordset(facts) AS x(version bigint,kind text)), candidate AS(SELECT min(version) version FROM chain WHERE kind='facility')
 SELECT version FROM candidate WHERE NOT EXISTS(SELECT 1 FROM chain WHERE chain.version<=candidate.version AND (kind IS NULL OR kind NOT IN ('collector','collection_point','facility')))
$$;
CREATE VIEW reporting_custody_admissions AS
 SELECT f.subject_id,f.event_id,f.custody_version,f.recipient_party_id,e.occurred_at observed_at,
 reporting_admission_kind(e.type,e.safe_payload->>'destination_kind',r.mode,r.point_id=p.place_id,p.place_id IS NOT NULL) destination_kind
 FROM first_receipts f JOIN domain_events e ON e.id=f.event_id AND e.type='first_receipt' AND e.subject_id=f.subject_id
 JOIN parties p ON p.id=f.recipient_party_id LEFT JOIN receipt_outcomes o ON o.id=f.outcome_id LEFT JOIN request_items ri ON ri.id=o.request_item_id AND ri.item_id=f.subject_id LEFT JOIN requests r ON r.id=ri.request_id
 UNION ALL
 SELECT receipt.subject_id,receipt.event_id,receipt.custody_version,h.receiver_party_id,e.occurred_at,
 reporting_admission_kind(e.type,e.safe_payload->>'destination_kind',NULL,false,p.place_id IS NOT NULL)
 FROM handover_receipts receipt JOIN handover_items hi ON hi.id=receipt.handover_item_id AND hi.subject_id=receipt.subject_id JOIN handovers h ON h.id=hi.handover_id
 JOIN parties p ON p.id=h.receiver_party_id JOIN domain_events e ON e.id=receipt.event_id AND e.type='handover_accepted' AND e.subject_id=receipt.subject_id;
CREATE VIEW reporting_facility_admissions AS
 WITH first_admission AS (
 SELECT subject_id,reporting_first_facility_version(jsonb_agg(jsonb_build_object('version',custody_version,'kind',destination_kind) ORDER BY custody_version,event_id)) version
 FROM reporting_custody_admissions GROUP BY subject_id
 )
 SELECT a.*,p.place_id,i.owner_user_id,i.intake_class,c.code category,i.subtype,s.public_code
 FROM reporting_custody_admissions a JOIN first_admission first ON first.subject_id=a.subject_id AND first.version=a.custody_version
 JOIN parties p ON p.id=a.recipient_party_id JOIN items i ON i.subject_id=a.subject_id JOIN subjects s ON s.id=i.subject_id JOIN categories c ON c.id=i.category_id
 WHERE i.intake_class IN ('whole_device','standalone_part');
CREATE FUNCTION reporting_media_evidence_missing(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM sanitization_current sc JOIN sanitization_records proof ON proof.id=ANY(ARRAY[sc.record_id,sc.attempt_id,(SELECT id FROM sanitization_records WHERE subject_id=ref AND attempt_id=sc.attempt_id AND kind='verification' ORDER BY version DESC LIMIT 1)])
 JOIN attachments a ON a.id=proof.attachment_id WHERE sc.subject_id=ref AND a.redacted_at IS NOT NULL)
$$;
CREATE FUNCTION reporting_reuse_evidence_missing(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM reuse_return_items ret JOIN refurbishment_records auth_record ON auth_record.id=ret.authorization_id
 JOIN refurbishment_records proof ON proof.id=ANY(ARRAY[ret.record_id,ret.authorization_id,(auth_record.context->>'work_id')::uuid,(auth_record.context->>'test_id')::uuid,(auth_record.context->>'na_id')::uuid])
 JOIN attachments a ON a.id=proof.attachment_id WHERE ret.subject_id=ref AND a.redacted_at IS NOT NULL)
$$;
CREATE FUNCTION reporting_subject_exception(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT reporting_reuse_evidence_missing(ref) OR reporting_media_evidence_missing(ref) OR EXISTS(SELECT 1 FROM effective_restrictions WHERE subject_id=ref)
 OR EXISTS(SELECT 1 FROM restriction_cases WHERE subject_id=ref AND active)
 OR EXISTS(SELECT 1 FROM restriction_impacts WHERE subject_id=ref AND NOT media_impact_fulfilled(id) AND NOT downstream_impact_fulfilled(id))
 OR EXISTS(SELECT 1 FROM handover_items WHERE subject_id=ref AND active AND state='disputed')
 OR EXISTS(SELECT 1 FROM batch_members m WHERE m.subject_id=ref AND (downstream_blocked(m.batch_id) OR downstream_status(m.batch_id)='closed_later_evidence_exception' OR EXISTS(SELECT 1 FROM downstream_records r JOIN attachments a ON a.id=r.attachment_id WHERE r.batch_id=m.batch_id AND r.kind='complete' AND a.redacted_at IS NOT NULL)))
$$;
CREATE FUNCTION reporting_reuse_historical(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM reuse_return_items r JOIN reuse_manifest m ON m.authorization_id=r.authorization_id AND m.subject_id=r.subject_id
 JOIN reuse_authorizations a ON a.id=r.authorization_id JOIN refurbishment_records rec ON rec.id=r.record_id AND rec.kind='acknowledge' AND rec.authorization_id=a.id
 JOIN domain_events e ON e.id=r.event_id AND e.type='reuse_returned' AND e.subject_id=r.subject_id
 WHERE r.subject_id=ref)
$$;
CREATE FUNCTION reporting_reuse_qualified(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT reporting_reuse_historical(ref) AND NOT reporting_subject_exception(ref)
 AND NOT EXISTS(SELECT 1 FROM reuse_return_items ret JOIN reuse_manifest m ON m.authorization_id=ret.authorization_id WHERE ret.subject_id=ref AND reporting_subject_exception(m.subject_id))
 AND EXISTS(SELECT 1 FROM reuse_return_items r JOIN reuse_authorizations a ON a.id=r.authorization_id AND a.state='acknowledged'
 JOIN custody_current cc ON cc.subject_id=r.subject_id AND cc.last_event_id=r.event_id AND cc.party_id=r.recipient_party_id
 WHERE r.subject_id=ref)
 AND NOT EXISTS(SELECT 1 FROM refurbishment_records r JOIN reuse_return_items ret ON ret.subject_id=r.subject_id WHERE r.subject_id=ref AND r.recorded_at>=(SELECT recorded_at FROM refurbishment_records WHERE id=ret.record_id) AND r.kind='exception')
$$;
CREATE TABLE notice_cutovers (
 version text PRIMARY KEY CHECK(version='C17-1'),activated_at timestamptz NOT NULL,event_watermark jsonb NOT NULL,transaction_watermark bigint NOT NULL
);
INSERT INTO notice_cutovers SELECT 'C17-1',clock_timestamp(),COALESCE((SELECT jsonb_build_array(recorded_at,id) FROM domain_events ORDER BY recorded_at DESC,id DESC LIMIT 1),'[]'),txid_current();
CREATE FUNCTION reporting_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Append-only reporting history'; END; $$;
CREATE TRIGGER notice_cutover_immutable BEFORE UPDATE OR DELETE ON notice_cutovers FOR EACH ROW EXECUTE FUNCTION reporting_immutable();
ALTER TABLE notification_outbox ADD COLUMN delivery_version text CHECK(delivery_version IS NULL OR delivery_version='C17-1'),ADD COLUMN delivery_error text CHECK(delivery_error IS NULL OR delivery_error IN ('delivery_failed','authority_revoked')),ADD COLUMN last_attempt_at timestamptz,ADD COLUMN delivery_context jsonb;
ALTER TABLE notification_outbox DROP CONSTRAINT notification_outbox_state_check;
ALTER TABLE notification_outbox ADD CHECK(state IN ('pending','delivered','failed','suppressed'));
CREATE TABLE milestone_notifications (
 id uuid PRIMARY KEY,outbox_id uuid NOT NULL UNIQUE REFERENCES notification_outbox(id),event_id uuid NOT NULL REFERENCES domain_events(id),
 recipient_id uuid NOT NULL REFERENCES users(id),read_at timestamptz
);
CREATE INDEX milestone_recipient ON milestone_notifications(recipient_id,read_at,id);
CREATE INDEX notice_retry_due ON notification_outbox(next_attempt_at,id) WHERE state='pending';
CREATE FUNCTION milestone_type(t text) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
 SELECT t=ANY(ARRAY['item_registered','self_report_revised','request_submitted','request_items_cancelled','request_date_preference_changed','request_eligibility_revalidated','request_review_recorded','route_plan_approved','route_plan_withdrawn','sanitization_attempt','sanitization_verification','sanitization_validation','sanitization_revalidation','refurbishment_work','refurbishment_work_applicability','refurbishment_test','refurbishment_fan_na','refurbishment_authorize','refurbishment_withdraw','refurbishment_exception','refurbishment_acknowledge','batch_commit','batch_output','batch_measurement','batch_accounted','batch_discrepancy','downstream_reserve','downstream_dispatch','downstream_accept','downstream_refuse','downstream_shortage','downstream_dispute','downstream_complete','downstream_source_loss','downstream_downstream_loss','downstream_measurement_dispute','downstream_close','downstream_recipient_accepted','downstream_recipient_cancelled'])
$$;
CREATE FUNCTION milestone_new_transaction() RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT transaction_timestamp()>activated_at AND txid_current()>transaction_watermark FROM notice_cutovers WHERE version='C17-1'
$$;
CREATE FUNCTION milestone_outbox_tag() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='UPDATE' THEN
 IF NEW.delivery_version IS DISTINCT FROM OLD.delivery_version OR NEW.delivery_context IS DISTINCT FROM OLD.delivery_context OR (OLD.delivery_version IS NOT NULL AND (NEW.event_id<>OLD.event_id OR NEW.recipient_id<>OLD.recipient_id OR NEW.channel<>OLD.channel OR OLD.state IN ('delivered','suppressed') AND NEW.state<>OLD.state)) THEN RAISE EXCEPTION 'Notice family version is immutable'; END IF;
 ELSIF COALESCE(milestone_new_transaction(),false) AND EXISTS(SELECT 1 FROM domain_events WHERE id=NEW.event_id AND milestone_type(type)) THEN NEW.delivery_version='C17-1';NEW.delivery_context=restriction_actor_context(NEW.recipient_id);
 ELSIF NEW.delivery_version IS NOT NULL THEN RAISE EXCEPTION 'Unsupported notice cutover'; END IF;
 IF TG_OP='INSERT' AND COALESCE(milestone_new_transaction(),false) AND NEW.delivery_context IS NULL THEN NEW.delivery_context=restriction_actor_context(NEW.recipient_id); END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER milestone_tag BEFORE INSERT OR UPDATE ON notification_outbox FOR EACH ROW EXECUTE FUNCTION milestone_outbox_tag();
-- Deferred production sees the complete authoritative operation; old transactions never acquire the new family.
CREATE FUNCTION milestone_recipients(ev domain_events) RETURNS TABLE(recipient_id uuid) LANGUAGE sql STABLE AS $$
 SELECT DISTINCT id FROM (
 SELECT ev.actor_id id
 UNION SELECT owner_user_id FROM items WHERE subject_id=ev.subject_id
 UNION SELECT consumer_id FROM requests WHERE id=ev.aggregate_id AND ev.aggregate_kind='request'
 UNION SELECT i.owner_user_id FROM batch_members m JOIN items i ON i.subject_id=m.subject_id WHERE m.batch_id=ev.aggregate_id AND ev.aggregate_kind IN ('batch','downstream') AND ev.type<>'downstream_reserve'
 UNION SELECT r.collector_id FROM routes r WHERE r.run_id=(SELECT run_id FROM route_approvals WHERE id=ev.aggregate_id) AND r.method='proposed' AND ev.type IN ('route_plan_approved','route_plan_withdrawn')
 UNION SELECT req.consumer_id FROM routes r JOIN route_stops st ON st.route_id=r.id JOIN assignments a ON a.id=st.assignment_id JOIN requests req ON req.id=a.request_id WHERE r.run_id=(SELECT run_id FROM route_approvals WHERE id=ev.aggregate_id) AND r.method='proposed' AND ev.type IN ('route_plan_approved','route_plan_withdrawn')
 UNION SELECT h.receiver_actor_id FROM downstream_effective_shipments h JOIN downstream_records r ON r.shipment_id=h.id WHERE r.event_id=ev.id
 UNION SELECT h.sender_actor_id FROM downstream_effective_shipments h JOIN downstream_records r ON r.shipment_id=h.id WHERE r.event_id=ev.id
 UNION SELECT h.sender_actor_id FROM downstream_effective_shipments h WHERE h.id=ev.aggregate_id AND ev.aggregate_kind='downstream_recipient'
 UNION SELECT h.receiver_actor_id FROM downstream_effective_shipments h WHERE h.id=ev.aggregate_id AND ev.aggregate_kind='downstream_recipient'
 UNION SELECT receiver_actor_id FROM downstream_recipient_records WHERE event_id=ev.id AND ev.aggregate_kind='downstream_recipient'
 UNION SELECT a.collector_id FROM assignments a WHERE a.request_id=ev.aggregate_id AND ev.aggregate_kind='request' AND a.active AND a.state IN ('offered','accepted')
 ) candidates WHERE id IS NOT NULL
$$;
CREATE FUNCTION milestone_produce() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE recipient uuid;
BEGIN
 IF NOT COALESCE(milestone_new_transaction(),false) OR NOT milestone_type(NEW.type) THEN RETURN NULL; END IF;
 IF NEW.type IN ('sanitization_attempt','sanitization_verification') AND EXISTS(SELECT 1 FROM sanitization_records WHERE event_id=NEW.id AND outcome IN ('success','satisfactory')) THEN RETURN NULL; END IF;
 FOR recipient IN SELECT recipient_id FROM milestone_recipients(NEW)
 LOOP
 INSERT INTO notification_outbox(id,event_id,recipient_id,channel,attempts,state,delivery_version) VALUES(gen_random_uuid(),NEW.id,recipient,'in_app',0,'pending','C17-1') ON CONFLICT(event_id,recipient_id,channel) DO NOTHING;
 END LOOP;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER milestone_producer AFTER INSERT ON domain_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION milestone_produce();
CREATE FUNCTION milestone_notice_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='UPDATE' AND (to_jsonb(NEW)-'read_at') IS DISTINCT FROM (to_jsonb(OLD)-'read_at') THEN RAISE EXCEPTION 'Notice identity is immutable'; END IF;
 IF NOT EXISTS(SELECT 1 FROM notification_outbox o JOIN domain_events e ON e.id=o.event_id WHERE o.id=NEW.outbox_id AND o.event_id=NEW.event_id AND o.recipient_id=NEW.recipient_id AND o.channel='in_app' AND o.delivery_version='C17-1' AND milestone_type(e.type)) THEN RAISE EXCEPTION 'Notice requires matching versioned authoritative outbox'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER milestone_valid BEFORE INSERT OR UPDATE ON milestone_notifications FOR EACH ROW EXECUTE FUNCTION milestone_notice_guard();
CREATE TABLE report_exports (
 id uuid PRIMARY KEY,requester_id uuid NOT NULL REFERENCES users(id),kind text NOT NULL CHECK(kind IN ('collections','facility','batches','incoming','manifest')),
 state text NOT NULL CHECK(state IN ('generating','ready','failed','invalidated','expired')),filters jsonb NOT NULL,revision text NOT NULL CHECK(length(revision)=64),
 authority text NOT NULL CHECK(length(authority)=64),session_version bigint NOT NULL,session_fingerprint text CHECK(session_fingerprint IS NULL OR session_fingerprint ~ '^[a-f0-9]{64}$'),generated_at timestamptz NOT NULL,expires_at timestamptz NOT NULL,
 storage_key text UNIQUE,temporary_key text UNIQUE CHECK(temporary_key IS NULL OR temporary_key=id::text||'.csv.part'),checksum text,row_count integer,byte_count bigint,failure_code text CHECK(failure_code IS NULL OR failure_code IN ('generation_failed','limit_exceeded','stale_context','authority_revoked')),
 CHECK(expires_at=generated_at+CASE WHEN kind='manifest' THEN interval '30 minutes' ELSE interval '24 hours' END),
 CHECK(storage_key IS NULL OR storage_key=id::text||'.csv'),CHECK(checksum IS NULL OR checksum ~ '^[a-f0-9]{64}$'),
 CHECK(row_count IS NULL OR row_count BETWEEN 0 AND CASE WHEN kind='manifest' THEN 50 ELSE 10000 END),
 CHECK(byte_count IS NULL OR byte_count BETWEEN 0 AND CASE WHEN kind='manifest' THEN 2097152 ELSE 10485760 END),
 CHECK(state<>'ready' OR (storage_key=id::text||'.csv' AND checksum IS NOT NULL AND row_count IS NOT NULL AND byte_count IS NOT NULL))
);
CREATE UNIQUE INDEX report_one_generation ON report_exports(requester_id) WHERE state='generating';
CREATE INDEX report_request_window ON report_exports(requester_id,generated_at);
CREATE INDEX report_expiry ON report_exports(expires_at,id) WHERE storage_key IS NOT NULL;
CREATE TABLE report_export_accesses (
 id uuid PRIMARY KEY,export_id uuid REFERENCES report_exports(id),actor_id uuid REFERENCES users(id),
 outcome text NOT NULL CHECK(outcome IN ('requested','generated','failed','download','denied','expired','invalidated','cleaned','retry')),recorded_at timestamptz NOT NULL
);
ALTER TABLE report_exports ADD COLUMN request_audit_id uuid REFERENCES report_export_accesses(id);
CREATE INDEX report_request_audit_window ON report_export_accesses(actor_id,recorded_at) WHERE outcome='requested';
CREATE TRIGGER report_access_immutable BEFORE UPDATE OR DELETE ON report_export_accesses FOR EACH ROW EXECUTE FUNCTION reporting_immutable();
CREATE FUNCTION report_export_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Export audit is retained pending privacy policy'; END IF;
 IF TG_OP='UPDATE' THEN
 IF OLD.state IN ('failed','invalidated','expired') AND NEW.state<>OLD.state OR OLD.state='ready' AND NEW.state NOT IN ('ready','invalidated','expired')
 OR (to_jsonb(NEW)-ARRAY['state','storage_key','temporary_key','checksum','row_count','byte_count','failure_code']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','storage_key','temporary_key','checksum','row_count','byte_count','failure_code']) THEN RAISE EXCEPTION 'Export identity or terminal state cannot be revived'; END IF;
 END IF;RETURN NEW;
END; $$;
CREATE TRIGGER report_export_valid BEFORE UPDATE OR DELETE ON report_exports FOR EACH ROW EXECUTE FUNCTION report_export_guard();
CREATE TABLE notice_delivery_retries(id uuid PRIMARY KEY,outbox_id uuid NOT NULL REFERENCES notification_outbox(id),actor_id uuid NOT NULL REFERENCES users(id),previous_attempts integer NOT NULL CHECK(previous_attempts>=0),recorded_at timestamptz NOT NULL);
CREATE TRIGGER notice_retry_immutable BEFORE UPDATE OR DELETE ON notice_delivery_retries FOR EACH ROW EXECUTE FUNCTION reporting_immutable();

CREATE TRIGGER milestone_no_delete BEFORE DELETE ON milestone_notifications FOR EACH ROW EXECUTE FUNCTION reporting_immutable();
CREATE FUNCTION milestone_authoritative(ev domain_events) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT CASE
 WHEN ev.type IN ('item_registered','self_report_revised') THEN EXISTS(SELECT 1 FROM item_observations WHERE item_id=ev.subject_id AND revision=ev.aggregate_version)
 WHEN ev.type='request_review_recorded' THEN EXISTS(SELECT 1 FROM request_reviews WHERE event_id=ev.id AND request_id=ev.aggregate_id)
 WHEN ev.aggregate_kind='request' THEN EXISTS(SELECT 1 FROM requests WHERE id=ev.aggregate_id)
 WHEN ev.aggregate_kind='route_approval' THEN EXISTS(SELECT 1 FROM route_approval_history WHERE approval_id=ev.aggregate_id AND version=ev.aggregate_version AND state=CASE ev.type WHEN 'route_plan_approved' THEN 'approved' ELSE 'withdrawn' END)
 WHEN ev.aggregate_kind='sanitization' THEN EXISTS(SELECT 1 FROM sanitization_records WHERE event_id=ev.id AND NOT(kind IN ('attempt','verification') AND outcome IN ('success','satisfactory')))
 WHEN ev.aggregate_kind='refurbishment' THEN EXISTS(SELECT 1 FROM refurbishment_records WHERE event_id=ev.id)
 WHEN ev.aggregate_kind='batch' THEN EXISTS(SELECT 1 FROM batch_records WHERE event_id=ev.id)
 WHEN ev.aggregate_kind='downstream' THEN EXISTS(SELECT 1 FROM downstream_records WHERE event_id=ev.id)
 WHEN ev.aggregate_kind='downstream_recipient' THEN EXISTS(SELECT 1 FROM downstream_recipient_records WHERE event_id=ev.id)
 ELSE false END
$$;
CREATE FUNCTION milestone_delivery_complete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' AND NEW.delivery_version='C17-1' AND NOT EXISTS(SELECT 1 FROM domain_events e WHERE e.id=NEW.event_id AND milestone_authoritative(e) AND EXISTS(SELECT 1 FROM milestone_recipients(e) WHERE recipient_id=NEW.recipient_id)) THEN RAISE EXCEPTION 'Versioned notice requires proven operation and recipient'; END IF;
 IF NEW.delivery_version='C17-1' AND EXISTS(SELECT 1 FROM notification_outbox WHERE id=NEW.id AND state='delivered')
 AND NOT EXISTS(SELECT 1 FROM milestone_notifications WHERE outbox_id=NEW.id AND event_id=NEW.event_id AND recipient_id=NEW.recipient_id) THEN RAISE EXCEPTION 'Delivered notice requires matching persisted inbox at COMMIT'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER milestone_delivery_reciprocal AFTER INSERT OR UPDATE ON notification_outbox DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION milestone_delivery_complete();

CREATE FUNCTION reporting_restriction_fanout() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE recipient uuid;
BEGIN
 IF NOT COALESCE(milestone_new_transaction(),false) OR NEW.aggregate_kind<>'restriction' THEN RETURN NULL; END IF;
 FOR recipient IN SELECT DISTINCT i.owner_user_id FROM restriction_records r JOIN restriction_cases c ON c.id=r.case_id JOIN batch_members m ON m.batch_id=c.subject_id JOIN items i ON i.subject_id=m.subject_id WHERE r.event_id=NEW.id AND i.owner_user_id IS NOT NULL LOOP
 INSERT INTO notification_outbox(id,event_id,recipient_id,channel,attempts,state) VALUES(gen_random_uuid(),NEW.id,recipient,'in_app',0,'pending') ON CONFLICT(event_id,recipient_id,channel) DO NOTHING;
 END LOOP;RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER reporting_restriction_notices AFTER INSERT ON domain_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reporting_restriction_fanout();
-- A conservative source change invalidates every old artifact permanently, including revoke/restore
-- between retrievals. This is invalidation metadata, never another operational projection.
CREATE FUNCTION reporting_source_changed() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE artifact uuid;
BEGIN
 PERFORM pg_advisory_xact_lock(481204);
 FOR artifact IN UPDATE report_exports SET state='invalidated' WHERE state IN ('ready','generating') RETURNING id LOOP
 INSERT INTO report_export_accesses(id,export_id,actor_id,outcome,recorded_at) VALUES(gen_random_uuid(),artifact,NULL,'invalidated',clock_timestamp());
 END LOOP;
 RETURN NULL;
END; $$;
DO $$ DECLARE source text; BEGIN
 FOREACH source IN ARRAY ARRAY['domain_events','subjects','users','memberships','organizations','places','place_categories','requests','request_items','request_private_details','assignments','custody_current','assessment_current','sanitization_current','refurbishment_current','reuse_authorizations','reuse_return_items','dismantling_batches','batch_records','batch_outputs','downstream_batches','downstream_records','downstream_fact_revisions','downstream_recipient_records','output_profiles','output_profile_records','restriction_cases','restriction_epochs','restriction_impact_reviews','route_approvals','route_consumptions','route_finishes','attachments','media_inventory_entries','media_inventory_records','media_review_effects','downstream_review_effects'] LOOP
 EXECUTE format('CREATE TRIGGER reporting_invalidate BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH STATEMENT EXECUTE FUNCTION reporting_source_changed()',source);
 END LOOP;
END; $$;
CREATE VIEW reporting_admission_issues AS
 SELECT DISTINCT chain.subject_id,i.owner_user_id,i.intake_class,c.code category,i.subtype,p.place_id,chain.observed_at
 FROM reporting_custody_admissions chain JOIN parties p ON p.id=chain.recipient_party_id JOIN items i ON i.subject_id=chain.subject_id JOIN categories c ON c.id=i.category_id
 WHERE i.intake_class IN ('whole_device','standalone_part') AND chain.destination_kind='unknown'
 AND NOT EXISTS(SELECT 1 FROM reporting_facility_admissions earlier WHERE earlier.subject_id=chain.subject_id AND earlier.custody_version<chain.custody_version);
-- Personal destination generations are checked again at delivery, independently of generic role scope.
CREATE FUNCTION milestone_delivery_allowed(ev domain_events, recipient uuid) RETURNS boolean LANGUAGE plpgsql STABLE AS $$
DECLARE shipment downstream_effective_shipments; rec downstream_records; member memberships; batch uuid; destination uuid;
BEGIN
 SELECT * INTO member FROM memberships WHERE user_id=recipient AND active;
 IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM users WHERE id=recipient AND status='active') THEN RETURN false; END IF;
 IF member.role<>'processor' OR ev.aggregate_kind NOT IN ('downstream','downstream_recipient') THEN RETURN true; END IF;
 IF ev.aggregate_kind='downstream' THEN SELECT * INTO rec FROM downstream_records WHERE event_id=ev.id; batch=rec.batch_id; SELECT * INTO shipment FROM downstream_effective_shipments WHERE id=rec.shipment_id;
 ELSE SELECT * INTO shipment FROM downstream_effective_shipments WHERE id=ev.aggregate_id;batch=shipment.batch_id; END IF;
 IF EXISTS(SELECT 1 FROM dismantling_batches b WHERE b.subject_id=batch AND COALESCE((downstream_access(recipient,b.facility_party_id)->>'eligible')::boolean,false)) THEN RETURN true; END IF;
 SELECT facility_party_id INTO destination FROM output_profile_records WHERE id=shipment.profile_record_id;
 IF NOT COALESCE((downstream_access(recipient,destination)->>'eligible')::boolean,false) THEN RETURN false; END IF;
 IF shipment.receiver_actor_id=recipient AND shipment.receiver_scope_version=member.version THEN RETURN true; END IF;
 RETURN ev.type='downstream_recipient_cancelled' AND EXISTS(SELECT 1 FROM downstream_recipient_records WHERE event_id=ev.id AND receiver_actor_id=recipient);
END; $$;
CREATE FUNCTION reporting_delivery_authority() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.delivery_context IS NOT NULL AND EXISTS(SELECT 1 FROM notification_outbox WHERE id=NEW.id AND state='delivered')
 AND (NEW.delivery_context IS DISTINCT FROM restriction_actor_context(NEW.recipient_id) OR (NEW.delivery_version='C17-1' AND NOT EXISTS(SELECT 1 FROM domain_events e WHERE e.id=NEW.event_id AND milestone_delivery_allowed(e,NEW.recipient_id)))) THEN
 RAISE EXCEPTION USING ERRCODE='P1701',MESSAGE='Notice authority changed before COMMIT.';
 END IF;RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER reporting_delivery_current AFTER INSERT OR UPDATE ON notification_outbox DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reporting_delivery_authority();
