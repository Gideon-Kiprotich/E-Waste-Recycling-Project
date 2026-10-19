CREATE TABLE output_profiles (
 id uuid PRIMARY KEY, version bigint NOT NULL CHECK(version>=1), record_id uuid NOT NULL
);
CREATE TABLE output_profile_records (
 id uuid PRIMARY KEY, profile_id uuid NOT NULL REFERENCES output_profiles(id) DEFERRABLE INITIALLY DEFERRED, version bigint NOT NULL CHECK(version>=1),
 facility_party_id uuid NOT NULL REFERENCES parties(id), output_type text NOT NULL CHECK(output_type IN ('material','component','residue','storage')),
 material_key text NOT NULL CHECK(length(material_key) BETWEEN 1 AND 120), hazards text NOT NULL CHECK(hazards='NONE'), approved boolean NOT NULL,
 actor_id uuid NOT NULL REFERENCES users(id), command_id uuid NOT NULL UNIQUE REFERENCES commands(id), event_id uuid NOT NULL UNIQUE REFERENCES domain_events(id),
 observed_at timestamptz NOT NULL, recorded_at timestamptz NOT NULL, reason_ciphertext text NOT NULL,
 attachment_id uuid REFERENCES attachments(id), reference_ciphertext text,
 CHECK((attachment_id IS NOT NULL)<>(reference_ciphertext IS NOT NULL))
);
ALTER TABLE output_profiles ADD FOREIGN KEY(record_id) REFERENCES output_profile_records(id) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE downstream_batches (
 batch_id uuid PRIMARY KEY REFERENCES dismantling_batches(subject_id), version bigint NOT NULL CHECK(version>=1), record_id uuid NOT NULL, closure_record_id uuid
);
CREATE TABLE downstream_shipments (
 id uuid PRIMARY KEY, batch_id uuid NOT NULL REFERENCES dismantling_batches(subject_id), output_id uuid NOT NULL REFERENCES batch_outputs(subject_id),
 profile_record_id uuid NOT NULL REFERENCES output_profile_records(id), receiver_actor_id uuid NOT NULL REFERENCES users(id), receiver_membership_id uuid NOT NULL REFERENCES memberships(id), receiver_scope_version bigint NOT NULL,
 sender_actor_id uuid NOT NULL REFERENCES users(id), requested_kg numeric(12,3) NOT NULL CHECK(requested_kg>0), hazards text NOT NULL CHECK(hazards='NONE'),
 source_subject_version bigint, source_custody_version bigint, source_event_id uuid REFERENCES domain_events(id), created_record_id uuid NOT NULL UNIQUE
);
CREATE TABLE downstream_records (
 id uuid PRIMARY KEY, batch_id uuid NOT NULL REFERENCES dismantling_batches(subject_id), version bigint NOT NULL CHECK(version>=1), predecessor_id uuid REFERENCES downstream_records(id),
 kind text NOT NULL CHECK(kind IN ('draft','reserve','withdraw','dispatch','accept','refuse','shortage','dispute','complete','source_loss','downstream_loss','measurement_dispute','close')),
 shipment_id uuid REFERENCES downstream_shipments(id), output_id uuid REFERENCES batch_outputs(subject_id), related_record_id uuid REFERENCES downstream_records(id),
 kg numeric(12,3), canonical boolean NOT NULL DEFAULT false, condition text CHECK(condition IN ('intact','damaged')), outcome text CHECK(outcome IN ('recovered','disposed','controlled_storage')),
 assurance_record_id uuid REFERENCES sanitization_records(id), context jsonb NOT NULL, attested boolean NOT NULL CHECK(attested),
 actor_id uuid NOT NULL REFERENCES users(id), assignee_id uuid NOT NULL REFERENCES users(id), command_id uuid NOT NULL UNIQUE REFERENCES commands(id), event_id uuid NOT NULL UNIQUE REFERENCES domain_events(id),
 physical_event_id uuid UNIQUE REFERENCES domain_events(id), observed_at timestamptz NOT NULL, recorded_at timestamptz NOT NULL, reason_ciphertext text NOT NULL,
 issuer_ciphertext text, attachment_id uuid REFERENCES attachments(id), reference_ciphertext text,
 UNIQUE(batch_id,version), CHECK(kg IS NULL OR kg>0), CHECK(recorded_at>=observed_at), CHECK((attachment_id IS NOT NULL)<>(reference_ciphertext IS NOT NULL))
);
ALTER TABLE downstream_shipments ADD FOREIGN KEY(created_record_id) REFERENCES downstream_records(id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE downstream_batches ADD FOREIGN KEY(record_id) REFERENCES downstream_records(id) DEFERRABLE INITIALLY DEFERRED, ADD FOREIGN KEY(closure_record_id) REFERENCES downstream_records(id) DEFERRABLE INITIALLY DEFERRED;
CREATE UNIQUE INDEX downstream_one_drive_dispatch ON downstream_records(output_id) WHERE kind='dispatch' AND canonical;
CREATE UNIQUE INDEX downstream_one_reservation ON downstream_records(shipment_id) WHERE kind='reserve';
CREATE INDEX downstream_named_inbox ON downstream_shipments(receiver_actor_id,id);
CREATE INDEX downstream_output_ledger ON downstream_records(output_id,kind);
CREATE INDEX downstream_related_ledger ON downstream_records(related_record_id,kind);
CREATE FUNCTION downstream_material(value text) RETURNS text LANGUAGE sql IMMUTABLE AS $$ SELECT lower(regexp_replace(btrim(value),'[[:space:]]+',' ','g')) $$;
CREATE FUNCTION downstream_access(actor uuid, party uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$ SELECT batch_access(actor,party) $$;
CREATE FUNCTION downstream_stock(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('produced',m.net_kg,'dispatched',q.dispatched,'source_loss',q.loss,'reserved',q.reserved,'accounted',m.net_kg-q.dispatched-q.loss,'reservable',m.net_kg-q.dispatched-q.loss-q.reserved,
 'accepted',q.accepted,'completed',q.completed,'downstream_loss',q.downstream_loss,'refused',q.refused,'shortage',q.shortage)
 FROM batch_outputs o LEFT JOIN batch_records m ON m.id=o.measurement_record_id CROSS JOIN LATERAL(
 SELECT COALESCE(sum(kg) FILTER(WHERE kind='dispatch'),0) dispatched,COALESCE(sum(kg) FILTER(WHERE kind='source_loss'),0) loss,
 COALESCE(sum(CASE WHEN kind='reserve' THEN kg WHEN kind IN ('withdraw','dispatch') THEN -kg ELSE 0 END),0) reserved,
 COALESCE(sum(kg) FILTER(WHERE kind='accept'),0) accepted,COALESCE(sum(kg) FILTER(WHERE kind='complete'),0) completed,
 COALESCE(sum(kg) FILTER(WHERE kind='downstream_loss'),0) downstream_loss,COALESCE(sum(kg) FILTER(WHERE kind='refuse'),0) refused,COALESCE(sum(kg) FILTER(WHERE kind='shortage'),0) shortage
 FROM downstream_records WHERE output_id=ref) q WHERE o.subject_id=ref
$$;
CREATE FUNCTION downstream_reserved(ref uuid) RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT COALESCE(sum(CASE WHEN kind='reserve' THEN kg WHEN kind IN ('withdraw','dispatch') THEN -kg ELSE 0 END),0) FROM downstream_records WHERE shipment_id=ref
$$;
CREATE FUNCTION downstream_remaining(ref uuid) RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT r.kg-COALESCE((SELECT sum(kg) FROM downstream_records WHERE related_record_id=r.id AND kind IN ('accept','refuse','shortage','dispute','downstream_loss','complete')),0) FROM downstream_records r WHERE r.id=ref
$$;
CREATE FUNCTION downstream_blocked(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM downstream_records WHERE batch_id=ref AND (kind IN ('refuse','shortage','dispute','source_loss','downstream_loss','measurement_dispute') OR (kind='accept' AND condition='damaged')))
 OR EXISTS(SELECT 1 FROM batch_records WHERE batch_id=ref AND kind='discrepancy')
$$;
CREATE FUNCTION downstream_closed(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT EXISTS(SELECT 1 FROM downstream_records WHERE batch_id=ref AND kind='close') $$;
CREATE FUNCTION downstream_profile_ready(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM downstream_shipments h JOIN output_profile_records pr ON pr.id=h.profile_record_id JOIN output_profiles p ON p.id=pr.profile_id AND p.record_id=pr.id
 JOIN dismantling_batches b ON b.subject_id=h.batch_id JOIN batch_outputs out ON out.subject_id=h.output_id
 JOIN memberships m ON m.id=h.receiver_membership_id JOIN users u ON u.id=h.receiver_actor_id AND m.user_id=u.id
 WHERE h.id=ref AND pr.approved AND pr.hazards=h.hazards AND pr.output_type=out.type AND pr.material_key=downstream_material(out.material_code)
 AND m.version=h.receiver_scope_version AND COALESCE((downstream_access(u.id,pr.facility_party_id)->>'eligible')::boolean,false)
 AND pr.facility_party_id<>b.facility_party_id AND (out.type<>'storage' OR EXISTS(SELECT 1 FROM place_categories pc JOIN parties pp ON pp.place_id=pc.place_id JOIN items i ON i.subject_id=out.subject_id JOIN categories c ON c.id=pc.category_id WHERE pp.id=pr.facility_party_id AND c.code='standalone_part' AND pc.subtype=i.subtype)))
$$;
CREATE FUNCTION downstream_context(ref uuid, actor uuid, shipment uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('actor',(SELECT jsonb_build_array(u.status,u.session_version,m.id,m.version,m.role,m.active,m.organization_id,m.place_id) FROM users u JOIN memberships m ON m.user_id=u.id WHERE u.id=actor),
 'source',downstream_access(actor,b.facility_party_id),'batch_record',b.record_id,'input_measurement',b.input_measurement_id,
 'measurements',(SELECT jsonb_agg(jsonb_build_array(subject_id,measurement_record_id) ORDER BY subject_id) FROM batch_outputs WHERE batch_id=ref),
 'destination',(SELECT jsonb_build_object('profile',p.record_id,'access',downstream_access(h.receiver_actor_id,pr.facility_party_id)) FROM downstream_shipments h JOIN output_profile_records pr ON pr.id=h.profile_record_id JOIN output_profiles p ON p.id=pr.profile_id WHERE h.id=shipment)) FROM dismantling_batches b WHERE b.subject_id=ref
$$;
CREATE FUNCTION downstream_ready(ref uuid, actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT COALESCE(batch_control_ready(ref,actor),false) AND batch_balance(ref)->>'status'='reconciled_on_site' AND NOT downstream_blocked(ref) AND NOT downstream_closed(ref)
$$;
CREATE FUNCTION downstream_closable(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT NOT downstream_blocked(ref) AND batch_balance(ref)->>'status'='reconciled_on_site'
 AND EXISTS(SELECT 1 FROM batch_outputs WHERE batch_id=ref)
 AND NOT EXISTS(SELECT 1 FROM batch_outputs o WHERE batch_id=ref AND ((downstream_stock(o.subject_id)->>'accounted')::numeric<>0 OR (downstream_stock(o.subject_id)->>'reserved')::numeric<>0 OR o.measurement_record_id IS NULL))
 AND NOT EXISTS(SELECT 1 FROM downstream_records r WHERE batch_id=ref AND ((kind='dispatch' AND downstream_remaining(r.id)<>0) OR (kind='accept' AND downstream_remaining(r.id)<>0)))
 AND NOT EXISTS(SELECT 1 FROM batch_outputs o WHERE batch_id=ref AND type='storage' AND (sanitization_state(o.subject_id)<>'satisfied' OR EXISTS(SELECT 1 FROM sanitization_current WHERE subject_id=o.subject_id AND destruction_reported)))
$$;
CREATE FUNCTION downstream_profile_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.version<>COALESCE((SELECT version FROM output_profiles WHERE id=NEW.profile_id),0)+1
 OR NOT EXISTS(SELECT 1 FROM memberships m JOIN users u ON u.id=m.user_id WHERE u.id=NEW.actor_id AND u.status='active' AND m.active AND m.role='administrator')
 OR NOT EXISTS(SELECT 1 FROM parties p JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id WHERE p.id=NEW.facility_party_id AND l.kind='facility' AND l.active AND l.review_status='approved' AND o.review_status='approved' AND o.kind IN ('processor','both'))
 OR NEW.material_key<>downstream_material(NEW.material_key) OR NEW.observed_at>clock_timestamp() OR NEW.recorded_at<NEW.observed_at
 OR NOT EXISTS(SELECT 1 FROM commands c JOIN domain_events e ON e.command_id=c.id WHERE c.id=NEW.command_id AND e.id=NEW.event_id AND e.actor_id=NEW.actor_id AND e.aggregate_kind='output_profile' AND e.aggregate_id=NEW.profile_id AND e.aggregate_version=NEW.version AND e.type='output_profile_reviewed' AND c.action='output_profile:'||NEW.profile_id::text)
 THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Reviewed output acceptance requires current admin, facility, exact stream, version and evidence'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER downstream_profile_record BEFORE INSERT ON output_profile_records FOR EACH ROW EXECUTE FUNCTION downstream_profile_guard();
CREATE FUNCTION downstream_record_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cur downstream_batches; h downstream_shipments; pr output_profile_records; out batch_outputs; related downstream_records; n numeric; actor_ok boolean;
BEGIN
 PERFORM 1 FROM dismantling_batches WHERE subject_id=NEW.batch_id FOR UPDATE;
 SELECT * INTO cur FROM downstream_batches WHERE batch_id=NEW.batch_id FOR UPDATE;
 SELECT * INTO h FROM downstream_shipments WHERE id=NEW.shipment_id;
 SELECT * INTO pr FROM output_profile_records WHERE id=h.profile_record_id;
 SELECT * INTO out FROM batch_outputs WHERE subject_id=NEW.output_id;
 SELECT * INTO related FROM downstream_records WHERE id=NEW.related_record_id;
 IF NEW.version<>COALESCE(cur.version,0)+1 OR NEW.predecessor_id IS DISTINCT FROM cur.record_id OR NEW.context IS DISTINCT FROM downstream_context(NEW.batch_id,NEW.actor_id,NEW.shipment_id)
 OR NEW.observed_at>clock_timestamp() OR NEW.observed_at<COALESCE((SELECT date_trunc('minute',observed_at) FROM downstream_records WHERE id=cur.record_id),(SELECT date_trunc('minute',observed_at) FROM batch_records WHERE id=(SELECT commit_record_id FROM dismantling_batches WHERE subject_id=NEW.batch_id)))
 OR NOT EXISTS(SELECT 1 FROM commands c JOIN domain_events e ON e.command_id=c.id WHERE c.id=NEW.command_id AND c.actor_id=NEW.actor_id AND c.status='completed' AND c.action='downstream_'||NEW.kind||':'||NEW.batch_id::text AND c.safe_response->>'record_id'=NEW.id::text AND e.id=NEW.event_id AND e.aggregate_kind='downstream' AND e.aggregate_id=NEW.batch_id AND e.aggregate_version=NEW.version AND e.actor_id=NEW.actor_id AND e.type='downstream_'||NEW.kind AND e.occurred_at=NEW.observed_at)
 THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Downstream operation needs current context, consecutive version, command and event'; END IF;
 actor_ok:=COALESCE((NEW.context->'source'->>'eligible')::boolean,false);
 IF NEW.kind IN ('accept','refuse','shortage','dispute','complete','downstream_loss') THEN
 actor_ok:=h.receiver_actor_id=NEW.actor_id AND COALESCE((downstream_access(NEW.actor_id,pr.facility_party_id)->>'eligible')::boolean,false) AND h.receiver_scope_version=(SELECT version FROM memberships WHERE id=h.receiver_membership_id);
 END IF;
 IF NOT COALESCE(actor_ok,false) OR (downstream_closed(NEW.batch_id) AND NEW.kind<>'measurement_dispute') THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Current authorized scope required; closed history is protected'; END IF;
 IF (NEW.kind IN ('draft','reserve','withdraw','dispatch','accept','refuse','shortage','dispute','complete','downstream_loss'))<>(NEW.shipment_id IS NOT NULL)
 OR (NEW.kind IN ('close','measurement_dispute'))<>(NEW.output_id IS NULL)
 OR (NEW.kind IN ('draft','close','measurement_dispute'))<>(NEW.kg IS NULL)
 OR NEW.canonical IS DISTINCT FROM (out.type IS NOT DISTINCT FROM 'storage')
 OR (NEW.output_id IS NOT NULL AND out.batch_id IS DISTINCT FROM NEW.batch_id)
 OR (NEW.shipment_id IS NOT NULL AND (h.batch_id<>NEW.batch_id OR h.output_id IS DISTINCT FROM NEW.output_id))
 OR (NEW.kind IN ('accept','refuse','shortage','dispute','complete','downstream_loss'))<>(NEW.related_record_id IS NOT NULL)
 OR (NEW.kind='accept')<>(NEW.condition IS NOT NULL) OR (NEW.kind='complete')<>(NEW.outcome IS NOT NULL)
 OR (NEW.kind='complete')<>(NEW.issuer_ciphertext IS NOT NULL)
 OR (NEW.kind='dispatch' AND NEW.canonical)<>(NEW.assurance_record_id IS NOT NULL)
 OR (NEW.kind='accept' AND NEW.canonical)<>(NEW.physical_event_id IS NOT NULL)
 THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Invalid downstream action fields, subject or reciprocal references'; END IF;
 IF NEW.kind IN ('draft','reserve','dispatch','source_loss','close') AND NOT downstream_ready(NEW.batch_id,NEW.actor_id) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Current reconciled complete accounting, capabilities and no restriction required'; END IF;
 IF NEW.kind IN ('draft','reserve','dispatch','accept','complete') AND NOT downstream_profile_ready(h.id) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Current reviewed matching output profile and named recipient scope required'; END IF;
 IF NEW.kind='draft' AND (h.created_record_id<>NEW.id OR h.sender_actor_id<>NEW.actor_id OR (out.type='storage' AND h.requested_kg<>(downstream_stock(out.subject_id)->>'produced')::numeric)) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Draft needs exact original output and whole canonical medium'; END IF;
 IF NEW.kind='reserve' AND (NEW.kg<>h.requested_kg OR EXISTS(SELECT 1 FROM downstream_records WHERE shipment_id=h.id AND kind='reserve') OR NEW.kg>(downstream_stock(out.subject_id)->>'reservable')::numeric) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Insufficient unreserved stock or duplicate reservation'; END IF;
 IF NEW.kind IN ('withdraw','dispatch') AND NEW.kg>downstream_reserved(h.id) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Quantity exceeds active reservation'; END IF;
 IF NEW.kind='source_loss' AND (out.type='storage' OR NEW.kg>(downstream_stock(out.subject_id)->>'reservable')::numeric) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Source loss needs available aggregate stock, never a canonical identity adjustment'; END IF;
 IF NEW.kind='dispatch' AND NEW.canonical AND (NEW.kg<>h.requested_kg OR sanitization_state(out.subject_id)<>'satisfied' OR EXISTS(SELECT 1 FROM sanitization_current WHERE subject_id=out.subject_id AND destruction_reported) OR NEW.assurance_record_id IS DISTINCT FROM (SELECT record_id FROM sanitization_current WHERE subject_id=out.subject_id)) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Canonical dispatch needs whole current supported assurance'; END IF;
 IF NEW.canonical AND NEW.kg IS NOT NULL AND NEW.kg<>h.requested_kg THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Canonical media is indivisible'; END IF;
 IF NEW.related_record_id IS NOT NULL AND (related.shipment_id IS DISTINCT FROM h.id OR related.output_id<>out.subject_id OR NEW.kg>downstream_remaining(related.id) OR related.kind<>(CASE WHEN NEW.kind='complete' THEN 'accept' ELSE 'dispatch' END)) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Outcome must partition the correct unaccounted dispatched/accepted quantity'; END IF;
 IF NEW.kind='complete' AND ((out.type='storage' AND (NEW.outcome<>'controlled_storage' OR sanitization_state(out.subject_id)<>'satisfied' OR EXISTS(SELECT 1 FROM sanitization_current WHERE subject_id=out.subject_id AND destruction_reported))) OR (out.type='residue' AND NEW.outcome<>'disposed') OR (out.type IN ('material','component') AND NEW.outcome NOT IN ('recovered','disposed'))) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Unsupported outcome or unresolved media assurance'; END IF;
 IF NEW.kind='close' AND NOT downstream_closable(NEW.batch_id) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Closure requires zero stock, complete outcomes, cleared media and no discrepancies'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER downstream_operation BEFORE INSERT ON downstream_records FOR EACH ROW EXECUTE FUNCTION downstream_record_guard();
CREATE FUNCTION downstream_integrity(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE cur downstream_batches; r downstream_records; seq bigint:=0; prev uuid; closed uuid; h downstream_shipments; st jsonb;
BEGIN
 SELECT * INTO cur FROM downstream_batches WHERE batch_id=ref;
 FOR r IN SELECT * FROM downstream_records WHERE batch_id=ref ORDER BY version LOOP
 seq:=seq+1;
 IF r.version<>seq OR r.predecessor_id IS DISTINCT FROM prev THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Downstream ledger chain is incomplete'; END IF;
 IF r.kind='close' THEN IF closed IS NOT NULL THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='One immutable closure only'; END IF;closed:=r.id; END IF;
 prev:=r.id;
 END LOOP;
 IF seq=0 THEN IF cur.batch_id IS NOT NULL THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Projection without downstream ledger'; END IF;RETURN; END IF;
 IF cur.version IS DISTINCT FROM seq OR cur.record_id IS DISTINCT FROM prev OR cur.closure_record_id IS DISTINCT FROM closed THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Downstream projection must equal immutable ledger'; END IF;
 FOR h IN SELECT * FROM downstream_shipments WHERE batch_id=ref LOOP
 IF NOT EXISTS(SELECT 1 FROM downstream_records WHERE id=h.created_record_id AND shipment_id=h.id AND kind='draft' AND output_id=h.output_id AND batch_id=ref) OR downstream_reserved(h.id)<0 THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Shipment needs reciprocal origin and nonnegative reservation'; END IF;
 END LOOP;
 FOR st IN SELECT downstream_stock(subject_id) FROM batch_outputs WHERE batch_id=ref LOOP
 IF (st->>'produced') IS NULL OR (st->>'accounted')::numeric<0 OR (st->>'reserved')::numeric<0 OR (st->>'reservable')::numeric<0 THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Stock must conserve measured production without overdraw'; END IF;
 END LOOP;
 FOR r IN SELECT * FROM downstream_records WHERE batch_id=ref AND kind IN ('dispatch','accept') LOOP
 IF downstream_remaining(r.id)<0 THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Overlapping downstream outcome quantities'; END IF;
 IF r.kind='accept' AND r.canonical AND NOT downstream_receipt_matches(r.output_id,(SELECT facility_party_id FROM output_profile_records WHERE id=(SELECT profile_record_id FROM downstream_shipments WHERE id=r.shipment_id)),r.physical_event_id,(SELECT source_custody_version+1 FROM downstream_shipments WHERE id=r.shipment_id)) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Canonical receipt requires reciprocal custody'; END IF;
 END LOOP;
END; $$;
CREATE FUNCTION downstream_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid;
BEGIN
 IF TG_TABLE_NAME='downstream_batches' THEN ref:=NEW.batch_id;
 ELSIF TG_TABLE_NAME IN ('downstream_records','downstream_shipments') THEN ref:=NEW.batch_id;
 ELSIF TG_TABLE_NAME='batch_records' THEN ref:=NEW.batch_id;
 ELSIF TG_TABLE_NAME='batch_outputs' THEN ref:=NEW.batch_id;
 ELSE SELECT batch_id INTO ref FROM batch_outputs WHERE subject_id=NEW.subject_id; END IF;
 IF ref IS NOT NULL THEN PERFORM downstream_integrity(ref); END IF;
 IF TG_TABLE_NAME='downstream_records' THEN
 IF NEW.context IS DISTINCT FROM downstream_context(NEW.batch_id,NEW.actor_id,NEW.shipment_id) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Downstream dependencies changed before commit'; END IF;
 END IF;
 RETURN NULL;
END; $$;
CREATE FUNCTION downstream_measurement_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF downstream_closed(NEW.batch_id) AND NEW.kind<>'discrepancy' THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Closed production history cannot be changed'; END IF;
 IF NEW.kind='measurement' AND (EXISTS(SELECT 1 FROM downstream_records WHERE batch_id=NEW.batch_id AND kind IN ('dispatch','source_loss') AND (NEW.target_id=NEW.batch_id OR output_id=NEW.target_id)) OR EXISTS(SELECT 1 FROM downstream_shipments h WHERE h.batch_id=NEW.batch_id AND (NEW.target_id=NEW.batch_id OR output_id=NEW.target_id) AND downstream_reserved(h.id)>0)) THEN RAISE EXCEPTION USING ERRCODE='P1300', MESSAGE='Withdraw affected reservations before correction; after dispatch/loss record a blocking measurement dispute'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER downstream_measurement BEFORE INSERT ON batch_records FOR EACH ROW EXECUTE FUNCTION downstream_measurement_guard();
