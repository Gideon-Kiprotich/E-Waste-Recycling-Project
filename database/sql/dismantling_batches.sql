CREATE TABLE dismantling_batches (
 subject_id uuid PRIMARY KEY REFERENCES subjects(id), facility_party_id uuid NOT NULL REFERENCES parties(id),
 created_by uuid NOT NULL REFERENCES users(id), version bigint NOT NULL DEFAULT 0 CHECK(version>=0),
 record_id uuid, commit_record_id uuid UNIQUE, input_measurement_id uuid,
 state text NOT NULL CHECK(state IN ('draft','processing')), inputs uuid[] NOT NULL
);
CREATE TABLE batch_records (
 id uuid PRIMARY KEY, batch_id uuid NOT NULL REFERENCES dismantling_batches(subject_id), version bigint NOT NULL CHECK(version>0),
 predecessor_id uuid REFERENCES batch_records(id), kind text NOT NULL CHECK(kind IN ('draft','commit','output','measurement','accounted','discrepancy')),
 inputs uuid[] NOT NULL, target_id uuid REFERENCES subjects(id), previous_measurement_id uuid REFERENCES batch_records(id),
 output_type text CHECK(output_type IN ('material','component','residue')), material_code text, source_item_id uuid REFERENCES items(subject_id),
 measure_mode text CHECK(measure_mode IN ('net','gross_tare')), unit text NOT NULL CHECK(unit='kg'),
 net_kg numeric(12,3), gross_kg numeric(12,3), tare_kg numeric(12,3), measurement_source_ciphertext text,
 checks jsonb NOT NULL, detached uuid[] NOT NULL, context jsonb NOT NULL,
 actor_id uuid NOT NULL REFERENCES users(id), command_id uuid NOT NULL UNIQUE REFERENCES commands(id), event_id uuid NOT NULL UNIQUE REFERENCES domain_events(id),
 observed_at timestamptz NOT NULL, recorded_at timestamptz NOT NULL,
 reason_ciphertext text NOT NULL, attachment_id uuid REFERENCES attachments(id), reference_ciphertext text,
 UNIQUE(batch_id,version), CHECK((attachment_id IS NULL)<>(reference_ciphertext IS NULL)),
 CHECK((measure_mode IS NULL AND net_kg IS NULL AND gross_kg IS NULL AND tare_kg IS NULL AND measurement_source_ciphertext IS NULL)
 OR (measure_mode IS NOT NULL AND net_kg IS NOT NULL AND net_kg>0 AND measurement_source_ciphertext IS NOT NULL AND ((measure_mode='net' AND gross_kg IS NULL AND tare_kg IS NULL) OR (measure_mode='gross_tare' AND gross_kg IS NOT NULL AND tare_kg IS NOT NULL AND gross_kg>tare_kg AND tare_kg>=0 AND net_kg=gross_kg-tare_kg)))),
 CHECK(kind NOT IN ('commit','measurement') OR measure_mode IS NOT NULL)
);
ALTER TABLE dismantling_batches ADD FOREIGN KEY(record_id) REFERENCES batch_records(id) DEFERRABLE INITIALLY DEFERRED,
 ADD FOREIGN KEY(commit_record_id) REFERENCES batch_records(id) DEFERRABLE INITIALLY DEFERRED,
 ADD FOREIGN KEY(input_measurement_id) REFERENCES batch_records(id) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE batch_members (
 subject_id uuid PRIMARY KEY REFERENCES items(subject_id), batch_id uuid NOT NULL REFERENCES dismantling_batches(subject_id),
 record_id uuid NOT NULL REFERENCES batch_records(id), decision_id uuid NOT NULL REFERENCES decisions(id), inventory_record_id uuid REFERENCES media_inventory_records(id),
 source_subject_version bigint NOT NULL, source_custody_event_id uuid NOT NULL REFERENCES domain_events(id), source_custody_version bigint NOT NULL,
 facility_party_id uuid NOT NULL REFERENCES parties(id), event_id uuid NOT NULL UNIQUE REFERENCES domain_events(id)
);
CREATE INDEX batch_members_batch ON batch_members(batch_id);
CREATE TABLE batch_detachments (
 subject_id uuid PRIMARY KEY REFERENCES storage_assets(subject_id), batch_id uuid NOT NULL REFERENCES dismantling_batches(subject_id),
 parent_id uuid NOT NULL REFERENCES batch_members(subject_id), placement_id uuid NOT NULL UNIQUE REFERENCES media_inventory_entries(id),
 placement_record_id uuid NOT NULL REFERENCES media_inventory_records(id), record_id uuid NOT NULL REFERENCES batch_records(id),
 source_subject_version bigint NOT NULL, source_custody_event_id uuid NOT NULL REFERENCES domain_events(id), source_custody_version bigint NOT NULL,
 facility_party_id uuid NOT NULL REFERENCES parties(id), event_id uuid NOT NULL UNIQUE REFERENCES domain_events(id)
);
CREATE TABLE batch_outputs (
 subject_id uuid PRIMARY KEY REFERENCES subjects(id), batch_id uuid NOT NULL REFERENCES dismantling_batches(subject_id),
 type text NOT NULL CHECK(type IN ('material','component','residue','storage')), material_code text NOT NULL,
 source_item_id uuid REFERENCES batch_members(subject_id), production_record_id uuid NOT NULL REFERENCES batch_records(id), measurement_record_id uuid REFERENCES batch_records(id)
);
CREATE INDEX batch_outputs_batch ON batch_outputs(batch_id);
CREATE FUNCTION batch_access(actor uuid, party uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('actor_id',u.id,'status',u.status,'session',u.session_version,'membership',m.id,'membership_version',m.version,'active',m.active,'role',m.role,'party',p.id,'facility',l.id,'facility_version',l.version,'operator',o.id,'operator_version',o.version,
 'eligible',u.status='active' AND m.active AND m.role='processor' AND m.place_id=l.id AND m.organization_id=o.id AND l.kind='facility' AND l.active AND l.review_status='approved' AND o.review_status='approved' AND o.kind IN ('processor','both'),
 'capabilities',(SELECT COALESCE(jsonb_agg(jsonb_build_array(category_id,subtype) ORDER BY category_id,subtype),'[]') FROM place_categories WHERE place_id=l.id))
 FROM users u JOIN memberships m ON m.user_id=u.id JOIN parties p ON p.id=party JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id WHERE u.id=actor
$$;
CREATE FUNCTION batch_input_snapshot(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('id',i.subject_id,'owner',i.owner_user_id,'state',i.processing_state,'subject_version',s.version,'party',cc.party_id,'custody_event',cc.last_event_id,'custody_version',cc.version,'assessment_version',ac.version,'assessment_id',ac.assessment_id,'decision_id',ac.decision_id,'inventory_version',ic.version,'inventory_record',ic.record_id,
 'media',(SELECT COALESCE(jsonb_agg(jsonb_build_object('id',e.medium_id,'entry_id',e.id,'placement_record',e.record_id,'state',e.state,'owner',mi.owner_user_id,'subject_version',ms.version,'party',mc.party_id,'custody_event',mc.last_event_id,'custody_version',mc.version) ORDER BY e.medium_id),'[]') FROM media_inventory_entries e LEFT JOIN items mi ON mi.subject_id=e.medium_id LEFT JOIN subjects ms ON ms.id=e.medium_id LEFT JOIN custody_current mc ON mc.subject_id=e.medium_id WHERE e.parent_id=i.subject_id AND e.state<>'not_present'))
 FROM items i JOIN subjects s ON s.id=i.subject_id JOIN custody_current cc ON cc.subject_id=i.subject_id LEFT JOIN assessment_current ac ON ac.subject_id=i.subject_id LEFT JOIN media_inventory_current ic ON ic.parent_id=i.subject_id WHERE i.subject_id=ref
$$;
CREATE FUNCTION batch_context(ref uuid, actor uuid, selected uuid[]) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('access',batch_access(actor,b.facility_party_id),'inputs',(SELECT COALESCE(jsonb_agg(batch_input_snapshot(x) ORDER BY x),'[]') FROM unnest(selected) x)) FROM dismantling_batches b WHERE b.subject_id=ref
$$;
CREATE FUNCTION batch_work_restricted(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM reuse_authorizations WHERE subject_id=ref AND state='pending')
 OR EXISTS(SELECT 1 FROM refurbishment_records WHERE subject_id=ref AND (kind='exception' OR configuration IN ('CHANGED','UNKNOWN') OR answers->>'safety' IN ('FAIL','UNKNOWN') OR answers->>'configuration' IN ('FAIL','UNKNOWN')))
 OR EXISTS(SELECT 1 FROM refurbishment_records r JOIN assessment_current ac ON ac.subject_id=r.subject_id WHERE r.subject_id=ref AND r.kind IN ('work','test') AND r.result IN ('failed','incomplete') AND (ac.assessment_id IS NOT DISTINCT FROM (r.context->'configuration'->'assessment'->>'assessment_id')::uuid OR ac.version<=(r.context->'configuration'->'assessment'->>'version')::bigint))
$$;
CREATE FUNCTION batch_input_ready(ref uuid, actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT COALESCE(inspection_scope(ref,actor) AND i.processing_state='assessed' AND d.pathway='recycle' AND d.assessment_id=ac.assessment_id
 AND NOT EXISTS(SELECT 1 FROM batch_members WHERE subject_id=ref) AND NOT EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=ref)
 AND NOT EXISTS(SELECT 1 FROM media_inventory_entries WHERE medium_id=ref)
 AND NOT EXISTS(SELECT 1 FROM assessments WHERE subject_id=ref AND hard_hold)
 AND NOT batch_work_restricted(ref)
 AND ((i.intake_class='standalone_part' AND i.subtype='ict_cooling_fan' AND a.data_status='NO' AND EXISTS(SELECT 1 FROM assessment_answers WHERE assessment_id=a.id AND question_code='fan_voltage' AND value='WITHIN') AND EXISTS(SELECT 1 FROM assessment_answers WHERE assessment_id=a.id AND question_code='fan_size' AND value='WITHIN') AND EXISTS(SELECT 1 FROM assessment_answers WHERE assessment_id=a.id AND question_code='fan_application' AND value='ICT') AND EXISTS(SELECT 1 FROM assessment_answers WHERE assessment_id=a.id AND question_code='fan_electronics' AND value='BASIC'))
 OR (i.intake_class='whole_device' AND ir.kind='attest' AND ir.completeness='YES' AND media_record_applicable(ir) AND a.data_status IN ('YES','NO')
 AND NOT EXISTS(SELECT 1 FROM media_inventory_entries e LEFT JOIN storage_assets sa ON sa.subject_id=e.medium_id LEFT JOIN custody_current cc ON cc.subject_id=e.medium_id LEFT JOIN items mi ON mi.subject_id=e.medium_id WHERE e.parent_id=ref AND e.state<>'not_present' AND (e.state<>'confirmed' OR e.medium_id IS NULL OR sa.media_kind NOT IN ('hdd','ssd') OR cc.party_id IS DISTINCT FROM pc.party_id OR mi.owner_user_id<>i.owner_user_id OR EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=e.medium_id) OR NOT EXISTS(SELECT 1 FROM place_categories cap JOIN categories cat ON cat.id=cap.category_id JOIN parties pp ON pp.place_id=cap.place_id WHERE pp.id=pc.party_id AND cat.code='standalone_part' AND cap.subtype=sa.media_kind) OR batch_work_restricted(e.medium_id) OR EXISTS(SELECT 1 FROM sanitization_current WHERE subject_id=e.medium_id AND destruction_reported) OR EXISTS(SELECT 1 FROM assessments WHERE subject_id=e.medium_id AND hard_hold) OR EXISTS(SELECT 1 FROM handover_items WHERE subject_id=e.medium_id AND active)))
 AND ((a.data_status='NO' AND NOT EXISTS(SELECT 1 FROM media_inventory_entries WHERE parent_id=ref AND medium_id IS NOT NULL)) OR (a.data_status='YES' AND EXISTS(SELECT 1 FROM media_inventory_entries WHERE parent_id=ref AND medium_id IS NOT NULL))))),false)
 FROM items i JOIN assessment_current ac ON ac.subject_id=i.subject_id JOIN assessments a ON a.id=ac.assessment_id JOIN decisions d ON d.id=ac.decision_id JOIN custody_current pc ON pc.subject_id=i.subject_id LEFT JOIN media_inventory_current ic ON ic.parent_id=i.subject_id LEFT JOIN media_inventory_records ir ON ir.id=ic.record_id WHERE i.subject_id=ref
$$;
CREATE FUNCTION batch_control_ready(ref uuid, actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT COALESCE((batch_access(actor,b.facility_party_id)->>'eligible')::boolean,false)
 AND NOT EXISTS(SELECT 1 FROM (SELECT subject_id FROM batch_members WHERE batch_id=ref UNION SELECT subject_id FROM batch_detachments WHERE batch_id=ref) x WHERE batch_work_restricted(x.subject_id))
 AND NOT EXISTS(SELECT 1 FROM batch_members bm JOIN items i ON i.subject_id=bm.subject_id JOIN parties p ON p.id=b.facility_party_id WHERE bm.batch_id=ref AND NOT EXISTS(SELECT 1 FROM place_categories cap WHERE cap.place_id=p.place_id AND cap.category_id=i.category_id AND cap.subtype=COALESCE(i.subtype,'')))
 AND NOT EXISTS(SELECT 1 FROM batch_detachments bd JOIN items i ON i.subject_id=bd.subject_id JOIN parties p ON p.id=b.facility_party_id WHERE bd.batch_id=ref AND NOT EXISTS(SELECT 1 FROM place_categories cap JOIN categories c ON c.id=cap.category_id WHERE cap.place_id=p.place_id AND c.code='standalone_part' AND cap.subtype=i.subtype))
 FROM dismantling_batches b WHERE b.subject_id=ref
$$;
CREATE FUNCTION batch_source_matches(ref uuid, party uuid, ev uuid, cv bigint, sv bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM (SELECT * FROM (SELECT subject_id,batch_id,record_id,source_subject_version,source_custody_event_id,source_custody_version,facility_party_id,event_id FROM batch_members UNION ALL SELECT subject_id,batch_id,record_id,source_subject_version,source_custody_event_id,source_custody_version,facility_party_id,event_id FROM batch_detachments) q) m JOIN subjects s ON s.id=m.subject_id JOIN custody_current cc ON cc.subject_id=s.id JOIN dismantling_batches b ON b.subject_id=m.batch_id
 WHERE m.subject_id=ref AND m.facility_party_id=party AND m.source_custody_event_id=ev AND m.source_custody_version=cv AND m.source_subject_version=sv AND s.version=sv+1 AND cc.party_id=party AND cc.last_event_id=ev AND cc.version=cv AND b.commit_record_id=m.record_id)
$$;
CREATE FUNCTION batch_balance(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('input_kg',r.net_kg,'output_kg',COALESCE(o.total,0),'difference_kg',r.net_kg-COALESCE(o.total,0),'tolerance_kg',GREATEST(0.100::numeric,r.net_kg*0.02),'unmeasured',o.pending,'recorded_lots',o.lots,
 'excess_variance',r.net_kg IS NOT NULL AND abs(r.net_kg-COALESCE(o.total,0))>GREATEST(0.100::numeric,r.net_kg*0.02),'discrepancy',EXISTS(SELECT 1 FROM batch_records WHERE batch_id=ref AND kind='discrepancy'),
 'status',CASE WHEN b.state='draft' THEN 'draft' WHEN EXISTS(SELECT 1 FROM batch_records WHERE batch_id=ref AND kind='discrepancy') THEN 'blocked_discrepancy' WHEN o.pending>0 OR o.lots=0 OR NOT EXISTS(SELECT 1 FROM batch_records a WHERE a.batch_id=ref AND a.kind='accounted' AND NOT EXISTS(SELECT 1 FROM batch_records later WHERE later.batch_id=ref AND later.version>a.version AND later.kind IN ('output','measurement','discrepancy'))) THEN 'pending_accounting' WHEN abs(r.net_kg-COALESCE(o.total,0))>GREATEST(0.100::numeric,r.net_kg*0.02) THEN 'excess_variance' ELSE 'reconciled_on_site' END,
 'future_dispatch_enabled',false,'recycling_complete',false)
 FROM dismantling_batches b LEFT JOIN batch_records r ON r.id=b.input_measurement_id CROSS JOIN LATERAL(SELECT sum(m.net_kg) AS total,count(*) FILTER(WHERE m.net_kg IS NULL) AS pending,count(*) AS lots FROM batch_outputs out LEFT JOIN batch_records m ON m.id=out.measurement_record_id WHERE out.batch_id=ref) o WHERE b.subject_id=ref
$$;
CREATE FUNCTION batch_record_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE b dismantling_batches; k text; expected uuid[]; x uuid;
BEGIN
 SELECT * INTO b FROM dismantling_batches WHERE subject_id=NEW.batch_id FOR UPDATE;
 IF NEW.version<>b.version+1 OR NEW.predecessor_id IS DISTINCT FROM b.record_id OR NEW.context IS DISTINCT FROM batch_context(NEW.batch_id,NEW.actor_id,NEW.inputs) OR COALESCE((NEW.context->'access'->>'eligible')::boolean,false)<>true OR NEW.observed_at>clock_timestamp() OR NEW.recorded_at<NEW.observed_at OR NEW.observed_at<COALESCE((SELECT date_trunc('minute',observed_at) FROM batch_records WHERE id=b.record_id),'-infinity'::timestamptz) THEN RAISE EXCEPTION 'Batch requires current scoped access, context, versions and actual time'; END IF;
 IF cardinality(NEW.inputs)<1 OR cardinality(NEW.inputs)<>(SELECT count(DISTINCT v) FROM unnest(NEW.inputs) v) OR EXISTS(SELECT 1 FROM unnest(NEW.inputs) v LEFT JOIN custody_current cc ON cc.subject_id=v WHERE cc.party_id IS DISTINCT FROM b.facility_party_id) THEN RAISE EXCEPTION 'Distinct selected inputs require facility control'; END IF;
 IF NEW.kind='draft' THEN IF b.state<>'draft' THEN RAISE EXCEPTION 'Committed membership is permanent'; END IF;
 ELSE
 IF NEW.inputs<>b.inputs THEN RAISE EXCEPTION 'Action cannot change selected membership'; END IF;
 IF NEW.kind='commit' THEN
 IF b.state<>'draft' OR EXISTS(SELECT 1 FROM unnest(NEW.inputs) v WHERE NOT COALESCE(batch_input_ready(v,NEW.actor_id),false)) THEN RAISE EXCEPTION 'Every intact input needs current RECYCLE decision, safe complete supported inventory and facility capability'; END IF;
 IF NEW.checks IS DISTINCT FROM '{"actual_dismantling":"YES","all_media_detached":"YES","identity_safety":"YES"}'::jsonb THEN RAISE EXCEPTION 'Actual evidenced dismantling and all media require explicit attestation'; END IF;
 SELECT COALESCE(array_agg(e.medium_id ORDER BY e.medium_id),'{}'::uuid[]) INTO expected FROM media_inventory_entries e WHERE e.parent_id=ANY(NEW.inputs) AND e.medium_id IS NOT NULL;
 IF NEW.detached<>expected THEN RAISE EXCEPTION 'Every canonical medium must be explicitly accounted and detached once'; END IF;
 IF NEW.observed_at<(SELECT max(date_trunc('minute',e.occurred_at)) FROM domain_events e JOIN custody_current c ON c.last_event_id=e.id WHERE c.subject_id=ANY(NEW.inputs)) THEN RAISE EXCEPTION 'Dismantling cannot precede accepted control'; END IF;
 ELSE
 IF b.state<>'processing' THEN RAISE EXCEPTION 'Record actual dismantling before output accounting'; END IF;
 IF NEW.kind<>'discrepancy' AND NOT batch_control_ready(b.subject_id,NEW.actor_id) THEN RAISE EXCEPTION 'Current input and media facility capabilities are required'; END IF;
 IF NEW.kind<>'discrepancy' AND EXISTS(SELECT 1 FROM batch_records WHERE batch_id=b.subject_id AND kind='discrepancy') THEN RAISE EXCEPTION 'Unexpected media blocks ordinary accounting and treatment pending reviewed resolution'; END IF;
 IF NEW.kind='output' THEN IF NEW.target_id IS NULL OR NEW.output_type IS NULL OR NEW.material_code IS NULL OR NEW.checks IS DISTINCT FROM '{"actual_output":"YES"}'::jsonb OR EXISTS(SELECT 1 FROM batch_outputs WHERE subject_id=NEW.target_id) THEN RAISE EXCEPTION 'Output requires distinct actual production'; END IF;
 ELSIF NEW.kind='measurement' THEN
 IF NEW.target_id=b.subject_id THEN x:=b.input_measurement_id;
 ELSE SELECT measurement_record_id INTO x FROM batch_outputs WHERE subject_id=NEW.target_id AND batch_id=b.subject_id; IF NOT FOUND THEN RAISE EXCEPTION 'Correction requires an existing output'; END IF; END IF;
 IF NEW.previous_measurement_id IS DISTINCT FROM x THEN RAISE EXCEPTION 'Measurement changed; use current original value'; END IF;
 ELSIF NEW.kind='accounted' THEN IF NEW.checks IS DISTINCT FROM '{"all_outputs_accounted":"YES"}'::jsonb OR EXISTS(SELECT 1 FROM batch_outputs WHERE batch_id=b.subject_id AND measurement_record_id IS NULL) OR NOT EXISTS(SELECT 1 FROM batch_outputs WHERE batch_id=b.subject_id) THEN RAISE EXCEPTION 'Explicit complete measured output accounting required'; END IF;
 ELSIF NEW.kind='discrepancy' THEN IF NEW.checks IS DISTINCT FROM '{"unexpected_media":"YES"}'::jsonb THEN RAISE EXCEPTION 'Record actual unexpected-media discrepancy'; END IF;
 END IF;
 END IF;
 END IF;
 IF NEW.source_item_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM batch_members WHERE subject_id=NEW.source_item_id AND batch_id=b.subject_id) THEN RAISE EXCEPTION 'Output source must be an evidenced member of this batch'; END IF;
 IF NEW.measure_mode='gross_tare' AND (NEW.gross_kg IS NULL OR NEW.tare_kg IS NULL) THEN RAISE EXCEPTION 'Gross/tare measurement requires both actual values'; END IF;
 IF (NEW.kind NOT IN ('commit','output','measurement') AND NEW.target_id IS NOT NULL) OR (NEW.kind<>'measurement' AND NEW.previous_measurement_id IS NOT NULL) OR (NEW.kind IN ('draft','measurement') AND NEW.checks<>'{}'::jsonb) THEN RAISE EXCEPTION 'Measurement and operation fields cannot imply other activity'; END IF;
 IF NEW.kind NOT IN ('output','measurement','commit') AND NEW.measure_mode IS NOT NULL THEN RAISE EXCEPTION 'Only measurement actions record quantities'; END IF;
 IF NEW.kind<>'commit' AND cardinality(NEW.detached)>0 THEN RAISE EXCEPTION 'Only actual commitment detaches media'; END IF;
 IF NEW.kind<>'output' AND (NEW.output_type IS NOT NULL OR NEW.material_code IS NOT NULL OR NEW.source_item_id IS NOT NULL) THEN RAISE EXCEPTION 'Corrections cannot mint output production'; END IF;
 IF NEW.attachment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attachments WHERE id=NEW.attachment_id AND uploaded_by=NEW.actor_id AND privacy_class='private') THEN RAISE EXCEPTION 'Evidence must belong to accountable actor'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER validate_batch_record BEFORE INSERT ON batch_records FOR EACH ROW EXECUTE FUNCTION batch_record_insert_guard();
CREATE FUNCTION batch_projection_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Committed batch lineage cannot be removed'; END IF;
 IF TG_TABLE_NAME='dismantling_batches' THEN
 IF TG_OP='UPDATE' AND (ROW(NEW.subject_id,NEW.facility_party_id,NEW.created_by) IS DISTINCT FROM ROW(OLD.subject_id,OLD.facility_party_id,OLD.created_by) OR NEW.version<>OLD.version+1 OR (OLD.commit_record_id IS NOT NULL AND ROW(NEW.commit_record_id,NEW.inputs,NEW.state) IS DISTINCT FROM ROW(OLD.commit_record_id,OLD.inputs,OLD.state))) THEN RAISE EXCEPTION 'Batch projection requires consecutive immutable lineage'; END IF;
 ELSE
 IF TG_OP='UPDATE' AND (ROW(NEW.subject_id,NEW.batch_id,NEW.type,NEW.material_code,NEW.source_item_id,NEW.production_record_id) IS DISTINCT FROM ROW(OLD.subject_id,OLD.batch_id,OLD.type,OLD.material_code,OLD.source_item_id,OLD.production_record_id) OR NEW.measurement_record_id IS NOT DISTINCT FROM OLD.measurement_record_id) THEN RAISE EXCEPTION 'Measurement cannot duplicate or rewrite output production'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER retain_batch_projection BEFORE INSERT OR UPDATE OR DELETE ON dismantling_batches FOR EACH ROW EXECUTE FUNCTION batch_projection_guard();
CREATE TRIGGER retain_output_projection BEFORE INSERT OR UPDATE OR DELETE ON batch_outputs FOR EACH ROW EXECUTE FUNCTION batch_projection_guard();
CREATE TRIGGER retain_batch_records BEFORE UPDATE OR DELETE ON batch_records FOR EACH ROW EXECUTE FUNCTION protect_device_history();
CREATE TRIGGER retain_batch_members BEFORE UPDATE OR DELETE ON batch_members FOR EACH ROW EXECUTE FUNCTION protect_device_history();
CREATE TRIGGER retain_batch_detachments BEFORE UPDATE OR DELETE ON batch_detachments FOR EACH ROW EXECUTE FUNCTION protect_device_history();
CREATE FUNCTION batch_integrity(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE b dismantling_batches; r batch_records; c batch_records; m batch_members; d batch_detachments; o batch_outputs; last uuid; seq bigint:=0; measure uuid; x jsonb; y jsonb; expected uuid[];
BEGIN
 SELECT * INTO b FROM dismantling_batches WHERE subject_id=ref;
 IF NOT FOUND THEN RETURN; END IF;
 IF NOT EXISTS(SELECT 1 FROM subjects WHERE id=ref AND kind='batch' AND version=1) OR EXISTS(SELECT 1 FROM items WHERE subject_id=ref) OR EXISTS(SELECT 1 FROM custody_current WHERE subject_id=ref) THEN RAISE EXCEPTION 'Batch identity needs its own origin, not item ownership or a fabricated receipt'; END IF;
 FOR r IN SELECT * FROM batch_records WHERE batch_id=ref ORDER BY version LOOP
 seq:=seq+1;
 IF seq=1 AND b.created_by<>r.actor_id THEN RAISE EXCEPTION 'Batch origin must retain actual creating actor'; END IF;
 IF r.version<>seq OR r.predecessor_id IS DISTINCT FROM last OR NOT EXISTS(SELECT 1 FROM domain_events ev JOIN commands co ON co.id=ev.command_id WHERE ev.id=r.event_id AND ev.subject_id=ref AND ev.aggregate_kind='batch' AND ev.aggregate_id=ref AND ev.aggregate_version=r.version AND ev.type='batch_'||r.kind AND ev.command_id=r.command_id AND ev.actor_id=r.actor_id AND ev.occurred_at=r.observed_at AND ev.recorded_at=r.recorded_at AND co.actor_id=r.actor_id AND co.action='batch_'||r.kind||':'||ref::text AND co.safe_response->>'record_id'=r.id::text) THEN RAISE EXCEPTION 'Batch operation needs consecutive retained command and event'; END IF;
 IF r.kind='commit' THEN
 IF c.id IS NOT NULL OR r.measure_mode IS NULL OR r.target_id IS DISTINCT FROM ref THEN RAISE EXCEPTION 'Exactly one measured actual dismantling commitment'; END IF;
 c:=r;measure:=r.id;
 IF (SELECT COALESCE(array_agg(subject_id ORDER BY subject_id),'{}'::uuid[]) FROM batch_members WHERE batch_id=ref) IS DISTINCT FROM r.inputs THEN RAISE EXCEPTION 'Exact permanent input membership required'; END IF;
 IF (SELECT COALESCE(array_agg(subject_id ORDER BY subject_id),'{}'::uuid[]) FROM batch_detachments WHERE batch_id=ref) IS DISTINCT FROM r.detached THEN RAISE EXCEPTION 'Exact canonical detachment manifest required'; END IF;
 ELSIF r.kind='measurement' AND r.target_id=ref THEN IF r.previous_measurement_id IS DISTINCT FROM measure THEN RAISE EXCEPTION 'Input measurement correction must retain its predecessor'; END IF;measure:=r.id;
 ELSIF r.kind='output' THEN IF NOT EXISTS(SELECT 1 FROM batch_outputs WHERE subject_id=r.target_id AND batch_id=ref AND production_record_id=r.id AND type=r.output_type AND material_code=r.material_code AND source_item_id IS NOT DISTINCT FROM r.source_item_id) THEN RAISE EXCEPTION 'Output observation requires exactly its own production facet'; END IF;
 END IF;
 last:=r.id;
 END LOOP;
 IF seq=0 OR b.version<>seq OR b.record_id IS DISTINCT FROM last OR b.commit_record_id IS DISTINCT FROM c.id OR b.input_measurement_id IS DISTINCT FROM measure OR b.state IS DISTINCT FROM (CASE WHEN c.id IS NULL THEN 'draft' ELSE 'processing' END) OR b.inputs IS DISTINCT FROM r.inputs THEN RAISE EXCEPTION 'Batch projection must match complete retained ledger'; END IF;
 FOR m IN SELECT * FROM batch_members WHERE batch_id=ref LOOP
 SELECT v INTO x FROM jsonb_array_elements(c.context->'inputs') v WHERE v->>'id'=m.subject_id::text;
 IF x IS NULL OR m.record_id<>c.id OR m.decision_id IS DISTINCT FROM (x->>'decision_id')::uuid OR m.inventory_record_id IS DISTINCT FROM (x->>'inventory_record')::uuid OR m.source_subject_version<>(x->>'subject_version')::bigint OR m.source_custody_event_id<>(x->>'custody_event')::uuid OR m.source_custody_version<>(x->>'custody_version')::bigint OR m.facility_party_id<>b.facility_party_id
 OR NOT batch_source_matches(m.subject_id,m.facility_party_id,m.source_custody_event_id,m.source_custody_version,m.source_subject_version)
 OR NOT EXISTS(SELECT 1 FROM items WHERE subject_id=m.subject_id AND processing_state='dismantled' AND owner_user_id=(x->>'owner')::uuid)
 OR NOT EXISTS(SELECT 1 FROM domain_events WHERE id=m.event_id AND subject_id=m.subject_id AND aggregate_kind='item' AND aggregate_id=m.subject_id AND aggregate_version=m.source_subject_version+1 AND type='dismantled_in_batch' AND actor_id=c.actor_id AND command_id=c.command_id AND occurred_at=c.observed_at)
 THEN RAISE EXCEPTION 'Dismantled input needs frozen decision, original control and terminal event'; END IF;
 SELECT COALESCE(array_agg(e.medium_id ORDER BY e.medium_id),'{}'::uuid[]) INTO expected FROM media_inventory_entries e WHERE e.parent_id=m.subject_id AND e.medium_id IS NOT NULL;
 IF expected IS DISTINCT FROM (SELECT COALESCE(array_agg(subject_id ORDER BY subject_id),'{}'::uuid[]) FROM batch_detachments WHERE parent_id=m.subject_id AND batch_id=ref) THEN RAISE EXCEPTION 'Every retained canonical medium requires its own detachment'; END IF;
 END LOOP;
 FOR d IN SELECT * FROM batch_detachments WHERE batch_id=ref LOOP
 SELECT v INTO x FROM jsonb_array_elements(c.context->'inputs') v WHERE v->>'id'=d.parent_id::text;
 SELECT v INTO y FROM jsonb_array_elements(x->'media') v WHERE v->>'id'=d.subject_id::text;
 IF y IS NULL OR d.record_id<>c.id OR d.facility_party_id<>b.facility_party_id OR d.placement_id<>(y->>'entry_id')::uuid OR d.placement_record_id<>(y->>'placement_record')::uuid OR d.source_subject_version<>(y->>'subject_version')::bigint OR d.source_custody_event_id<>(y->>'custody_event')::uuid OR d.source_custody_version<>(y->>'custody_version')::bigint
 OR NOT batch_source_matches(d.subject_id,d.facility_party_id,d.source_custody_event_id,d.source_custody_version,d.source_subject_version)
 OR NOT EXISTS(SELECT 1 FROM media_inventory_entries WHERE id=d.placement_id AND parent_id=d.parent_id AND medium_id=d.subject_id AND record_id=d.placement_record_id AND state='confirmed')
 OR NOT EXISTS(SELECT 1 FROM items WHERE subject_id=d.subject_id AND owner_user_id=(y->>'owner')::uuid AND data_status='YES')
 OR NOT EXISTS(SELECT 1 FROM batch_outputs WHERE subject_id=d.subject_id AND batch_id=ref AND type='storage' AND source_item_id=d.parent_id AND production_record_id=c.id)
 OR NOT EXISTS(SELECT 1 FROM domain_events WHERE id=d.event_id AND subject_id=d.subject_id AND aggregate_kind='item' AND aggregate_id=d.subject_id AND aggregate_version=d.source_subject_version+1 AND type='media_detached_in_batch' AND command_id=c.command_id AND actor_id=c.actor_id AND occurred_at=c.observed_at)
 THEN RAISE EXCEPTION 'Detached medium needs unchanged canonical identity, source placement, control and one output facet'; END IF;
 END LOOP;
 FOR o IN SELECT * FROM batch_outputs WHERE batch_id=ref LOOP
 IF o.type='storage' THEN IF NOT EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=o.subject_id AND batch_id=ref AND parent_id=o.source_item_id AND record_id=o.production_record_id) OR NOT EXISTS(SELECT 1 FROM subjects WHERE id=o.subject_id AND kind='item') THEN RAISE EXCEPTION 'Storage output must reuse canonical detached identity'; END IF;
 ELSE IF NOT EXISTS(SELECT 1 FROM subjects WHERE id=o.subject_id AND kind='output' AND version=1) OR EXISTS(SELECT 1 FROM items WHERE subject_id=o.subject_id) OR EXISTS(SELECT 1 FROM custody_current WHERE subject_id=o.subject_id) THEN RAISE EXCEPTION 'Aggregate output has batch provenance, no invented item owner/receipt'; END IF; END IF;
 SELECT rr.id INTO measure FROM batch_records rr WHERE rr.batch_id=ref AND ((rr.kind='output' AND rr.id=o.production_record_id AND rr.measure_mode IS NOT NULL) OR (rr.kind='measurement' AND rr.target_id=o.subject_id)) ORDER BY rr.version DESC LIMIT 1;
 IF o.measurement_record_id IS DISTINCT FROM measure THEN RAISE EXCEPTION 'Output stock must project latest measurement once'; END IF;
 last:=NULL;
 FOR r IN SELECT * FROM batch_records WHERE batch_id=ref AND ((kind='output' AND id=o.production_record_id AND measure_mode IS NOT NULL) OR (kind='measurement' AND target_id=o.subject_id)) ORDER BY version LOOP
 IF r.kind='measurement' AND r.previous_measurement_id IS DISTINCT FROM last THEN RAISE EXCEPTION 'Output correction must retain original measurement'; END IF;last:=r.id;
 END LOOP;
 END LOOP;
 IF EXISTS(SELECT 1 FROM domain_events WHERE aggregate_kind='batch' AND aggregate_id=ref AND NOT EXISTS(SELECT 1 FROM batch_records WHERE event_id=domain_events.id)) THEN RAISE EXCEPTION 'Unbacked batch event'; END IF;
END; $$;
CREATE FUNCTION batch_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r jsonb; ref uuid; b uuid;
BEGIN
 r:=to_jsonb(NEW);
 IF TG_TABLE_NAME='batch_records' THEN
 PERFORM batch_integrity(NEW.batch_id);
 IF NEW.context->'access' IS DISTINCT FROM batch_access(NEW.actor_id,(SELECT facility_party_id FROM dismantling_batches WHERE subject_id=NEW.batch_id)) THEN RAISE EXCEPTION 'Batch access changed before commit'; END IF;
 IF NEW.kind='commit' AND EXISTS(SELECT 1 FROM jsonb_array_elements(NEW.context->'inputs') x LEFT JOIN assessment_current ac ON ac.subject_id=(x->>'id')::uuid LEFT JOIN media_inventory_current ic ON ic.parent_id=ac.subject_id WHERE ac.version IS DISTINCT FROM (x->>'assessment_version')::bigint OR ac.decision_id IS DISTINCT FROM (x->>'decision_id')::uuid OR ic.record_id IS DISTINCT FROM (x->>'inventory_record')::uuid) THEN RAISE EXCEPTION 'Source assessment or inventory changed before commit'; END IF;
 ELSIF TG_TABLE_NAME='commands' THEN
 IF NEW.action LIKE 'batch_%' AND NOT EXISTS(SELECT 1 FROM batch_records WHERE command_id=NEW.id) THEN RAISE EXCEPTION 'Unbacked batch command'; END IF;
 ELSIF TG_TABLE_NAME='domain_events' THEN
 IF NEW.aggregate_kind='batch' THEN PERFORM batch_integrity(NEW.aggregate_id);
 ELSIF NEW.type IN ('dismantled_in_batch','media_detached_in_batch') THEN SELECT batch_id INTO b FROM batch_members WHERE event_id=NEW.id UNION ALL SELECT batch_id FROM batch_detachments WHERE event_id=NEW.id; IF b IS NULL THEN RAISE EXCEPTION 'Physical event needs reciprocal batch lineage'; END IF;PERFORM batch_integrity(b); END IF;
 ELSIF TG_TABLE_NAME IN ('dismantling_batches','batch_members','batch_detachments','batch_outputs') THEN PERFORM batch_integrity(COALESCE((r->>'batch_id')::uuid,(r->>'subject_id')::uuid));
 ELSE
 ref:=CASE WHEN TG_TABLE_NAME='subjects' THEN (r->>'id')::uuid ELSE (r->>'subject_id')::uuid END;
 SELECT batch_id INTO b FROM batch_members WHERE subject_id=ref UNION ALL SELECT batch_id FROM batch_detachments WHERE subject_id=ref UNION ALL SELECT batch_id FROM batch_outputs WHERE subject_id=ref AND type<>'storage';
 IF b IS NOT NULL THEN PERFORM batch_integrity(b); END IF;
 IF EXISTS(SELECT 1 FROM items WHERE subject_id=ref AND processing_state='dismantled') AND NOT EXISTS(SELECT 1 FROM batch_members WHERE subject_id=ref) THEN RAISE EXCEPTION 'Dismantled state requires permanent batch membership'; END IF;
 IF TG_TABLE_NAME='subjects' AND r->>'kind' IN ('batch','output') AND NOT (EXISTS(SELECT 1 FROM dismantling_batches WHERE subject_id=ref) OR EXISTS(SELECT 1 FROM batch_outputs WHERE subject_id=ref)) THEN RAISE EXCEPTION 'Tracked batch/output needs reciprocal origin'; END IF;
 END IF;
 RETURN NULL;
END; $$;
