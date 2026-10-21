CREATE FUNCTION media_pending_effect(ref uuid,action text) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM restriction_records r JOIN restriction_cases c ON c.id=r.case_id WHERE r.id=ref AND r.kind='approve' AND c.contract='P15' AND c.fact_key=action AND NOT EXISTS(SELECT 1 FROM media_review_effects WHERE record_id=ref))
$$;
CREATE FUNCTION media_review_capability(actor uuid,kind text) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM memberships m JOIN place_categories pc ON pc.place_id=m.place_id JOIN categories cat ON cat.id=pc.category_id WHERE m.user_id=actor AND m.active AND m.role='processor' AND cat.code='standalone_part' AND pc.subtype=kind)
$$;
CREATE FUNCTION media_units_valid(c restriction_cases,p restriction_records) RETURNS boolean LANGUAGE plpgsql STABLE AS $$
DECLARE unit jsonb;parent items;medium items;cc custody_current;out batch_outputs;quantity numeric;
BEGIN
 IF c.fact_key='discovery_error' THEN
 IF jsonb_typeof(p.details)<>'object' OR (SELECT count(*) FROM jsonb_object_keys(p.details))<>1 THEN RETURN false; END IF;
 RETURN jsonb_typeof(p.details->'accounted_media')='array' AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements_text(p.details->'accounted_media') v WHERE NOT EXISTS(SELECT 1 FROM batch_outputs WHERE batch_id=c.subject_id AND subject_id=v::uuid AND type='storage'));
 END IF;
 IF jsonb_typeof(p.details)<>'object' OR (SELECT count(*) FROM jsonb_object_keys(p.details))<>2 OR NOT (p.details ?& ARRAY['count','units']) THEN RETURN false; END IF;
 IF jsonb_typeof(p.details->'units')<>'array' OR jsonb_array_length(p.details->'units')<>COALESCE((p.details->>'count')::int,0) OR jsonb_array_length(p.details->'units') NOT BETWEEN 1 AND 10 THEN RETURN false; END IF;
 IF (SELECT count(DISTINCT u->>'id') FROM jsonb_array_elements(p.details->'units') u)<>jsonb_array_length(p.details->'units') THEN RETURN false; END IF;
 IF (SELECT count(DISTINCT COALESCE(u->>'existing_id',u->>'id')) FROM jsonb_array_elements(p.details->'units') u)<>jsonb_array_length(p.details->'units') THEN RETURN false; END IF;
 IF EXISTS(SELECT 1 FROM restriction_records prior WHERE prior.case_id=c.id AND prior.kind='proposal' AND prior.id<>p.id AND prior.details ? 'units'
 AND (SELECT jsonb_agg(jsonb_build_array(u->>'id',u->>'parent_id',u->>'media_kind') ORDER BY u->>'id') FROM jsonb_array_elements(prior.details->'units') u) IS DISTINCT FROM (SELECT jsonb_agg(jsonb_build_array(u->>'id',u->>'parent_id',u->>'media_kind') ORDER BY u->>'id') FROM jsonb_array_elements(p.details->'units') u)) THEN RETURN false; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(p.details->'units') a JOIN jsonb_array_elements(p.details->'units') b ON a->>'source_output_id'=b->>'source_output_id' WHERE a->'remaining'<>b->'remaining') THEN RETURN false; END IF;
 FOR unit IN SELECT * FROM jsonb_array_elements(p.details->'units') LOOP
 IF jsonb_typeof(unit)<>'object' OR NOT(unit ?& ARRAY['id','parent_id','media_kind','mode','measurement','remaining']) OR EXISTS(SELECT 1 FROM jsonb_object_keys(unit) k WHERE k NOT IN ('id','parent_id','media_kind','mode','measurement','remaining','source_output_id','existing_code','existing_id','existing_version','existing_custody_version','existing_custody_event','existing_context')) THEN RETURN false; END IF;
 IF unit->>'media_kind' IS NULL OR unit->>'mode' IS NULL THEN RETURN false; END IF;

 SELECT * INTO parent FROM items WHERE subject_id=(unit->>'parent_id')::uuid;
 IF parent.subject_id IS NULL OR NOT EXISTS(SELECT 1 FROM batch_members WHERE subject_id=parent.subject_id AND batch_id=c.subject_id)
 OR NOT media_review_scope(p.actor_id,parent.subject_id) OR unit->>'media_kind' NOT IN ('hdd','ssd') OR NOT media_review_capability(p.actor_id,unit->>'media_kind')
 OR EXISTS(SELECT 1 FROM batch_media_discoveries WHERE id=(unit->>'id')::uuid) THEN RETURN false; END IF;
 IF unit->>'existing_id' IS NOT NULL THEN
 SELECT * INTO medium FROM items WHERE subject_id=(unit->>'existing_id')::uuid;SELECT * INTO cc FROM custody_current WHERE subject_id=medium.subject_id;
 IF medium.subject_id IS NULL OR medium.owner_user_id<>parent.owner_user_id OR medium.subtype<>unit->>'media_kind' OR NOT media_review_scope(p.actor_id,medium.subject_id) OR NOT media_no_terminal(medium.subject_id)
 OR cc.party_id IS DISTINCT FROM (SELECT facility_party_id FROM dismantling_batches WHERE subject_id=c.subject_id)
 OR EXISTS(SELECT 1 FROM media_current_bindings WHERE medium_id=medium.subject_id) OR EXISTS(SELECT 1 FROM batch_outputs WHERE subject_id=medium.subject_id)
 OR EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=medium.subject_id)
 OR cc.version IS DISTINCT FROM (unit->>'existing_custody_version')::bigint OR cc.last_event_id IS DISTINCT FROM (unit->>'existing_custody_event')::uuid
 OR (SELECT version FROM subjects WHERE id=medium.subject_id) IS DISTINCT FROM (unit->>'existing_version')::bigint OR unit->'existing_context' IS DISTINCT FROM restriction_dependency_context(medium.subject_id) THEN RETURN false; END IF;
 ELSIF EXISTS(SELECT 1 FROM subjects WHERE id=(unit->>'id')::uuid) THEN RETURN false;
 END IF;
 IF unit->>'mode'='reclassified' THEN
 SELECT * INTO out FROM batch_outputs WHERE subject_id=(unit->>'source_output_id')::uuid AND batch_id=c.subject_id;
 IF out.subject_id IS NULL OR out.type='storage' OR out.measurement_record_id IS NULL OR unit->'measurement'->>'measure_mode' IS NULL OR unit->'remaining'->>'measure_mode' IS NULL
 OR NOT downstream_measurement_allowed(c.subject_id,out.subject_id) THEN RETURN false; END IF;
 ELSIF unit->>'mode'<>'unaccounted' OR unit->>'source_output_id' IS NOT NULL THEN RETURN false;
 END IF;
 END LOOP;
 RETURN true;
END; $$;
CREATE FUNCTION media_binding_record_guard(n media_inventory_records) RETURNS void LANGUAGE plpgsql AS $$
DECLARE r restriction_records;p restriction_records;c restriction_cases;entry media_inventory_entries;source media_inventory_records;
BEGIN
 SELECT * INTO r FROM restriction_records WHERE id=n.correction_review_id;SELECT * INTO p FROM restriction_records WHERE id=r.proposal_id;SELECT * INTO c FROM restriction_cases WHERE id=r.case_id;
 SELECT * INTO source FROM media_inventory_records WHERE id=c.source_id;SELECT * INTO entry FROM media_inventory_entries WHERE id=source.entry_id;
 IF n.kind<>'review_binding' OR NOT media_pending_effect(r.id,c.fact_key) OR c.fact_key NOT IN ('association_confirm','association_end') OR n.parent_id<>c.subject_id OR n.entry_id<>entry.id OR n.medium_id<>entry.medium_id
 OR n.actor_id<>r.actor_id OR n.observer_id<>p.actor_id OR NOT media_review_scope(n.observer_id,n.parent_id)
 OR n.entry_state<>(CASE c.fact_key WHEN 'association_confirm' THEN 'confirmed' ELSE 'association_superseded' END)
 OR n.version<>(SELECT version+1 FROM media_inventory_current WHERE parent_id=n.parent_id) OR n.predecessor_id IS DISTINCT FROM (SELECT record_id FROM media_inventory_current WHERE parent_id=n.parent_id)
 OR n.source_subject_version IS DISTINCT FROM (SELECT version FROM subjects WHERE id=n.parent_id)
 OR n.custody_event_id IS DISTINCT FROM (SELECT last_event_id FROM custody_current WHERE subject_id=n.parent_id) OR n.custody_version IS DISTINCT FROM (SELECT version FROM custody_current WHERE subject_id=n.parent_id)
 OR n.facility_party_id IS DISTINCT FROM (SELECT party_id FROM custody_current WHERE subject_id=n.parent_id)
 OR n.assessment_version IS DISTINCT FROM COALESCE((SELECT version FROM assessment_current WHERE subject_id=n.parent_id),0)
 OR n.assessment_id IS DISTINCT FROM (SELECT assessment_id FROM assessment_current WHERE subject_id=n.parent_id) OR n.decision_id IS DISTINCT FROM (SELECT decision_id FROM assessment_current WHERE subject_id=n.parent_id)
 OR NOT EXISTS(SELECT 1 FROM memberships m JOIN users observer ON observer.id=m.user_id JOIN places l ON l.id=m.place_id JOIN parties party ON party.place_id=l.id JOIN organizations o ON o.id=l.organization_id WHERE m.id=n.membership_id AND m.user_id=n.observer_id AND m.version=n.membership_version AND observer.session_version=n.actor_session_version AND party.id=n.facility_party_id AND l.version=n.facility_version AND o.version=n.operator_version)
 OR n.placement_ciphertext IS DISTINCT FROM source.placement_ciphertext OR n.observed_at<>r.observed_at OR n.recorded_at<>r.recorded_at OR n.attachment_id IS DISTINCT FROM r.attachment_id OR n.reference_ciphertext IS DISTINCT FROM r.reference_ciphertext THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Binding correction needs its exact independent review, original identity and new ledger step'; END IF;
END; $$;
CREATE FUNCTION media_batch_record_guard(n batch_records) RETURNS void LANGUAGE plpgsql AS $$
DECLARE r restriction_records;p restriction_records;c restriction_cases;u jsonb;expected jsonb;b dismantling_batches;
BEGIN
 SELECT * INTO r FROM restriction_records WHERE id=n.correction_review_id;SELECT * INTO p FROM restriction_records WHERE id=r.proposal_id;SELECT * INTO c FROM restriction_cases WHERE id=r.case_id;SELECT * INTO b FROM dismantling_batches WHERE subject_id=n.batch_id;
 IF NOT media_pending_effect(r.id,'discovery_supplement') OR c.subject_id<>n.batch_id OR n.actor_id<>r.actor_id OR n.observer_id<>p.actor_id OR NOT media_batch_onsite(n.batch_id)
 OR n.context IS DISTINCT FROM batch_context(n.batch_id,n.observer_id,n.inputs) OR NOT media_review_scope(n.observer_id,n.batch_id) OR n.kind NOT IN ('output','measurement') OR n.version<>b.version+1 OR n.predecessor_id IS DISTINCT FROM b.record_id OR n.inputs<>b.inputs
 OR n.observed_at<>r.observed_at OR n.recorded_at<>r.recorded_at OR n.attachment_id IS DISTINCT FROM r.attachment_id OR n.reference_ciphertext IS DISTINCT FROM r.reference_ciphertext THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Supplementary accounting requires exact on-site approved effects and observer provenance'; END IF;
 SELECT x INTO u FROM jsonb_array_elements(p.details->'units') x WHERE CASE n.kind WHEN 'output' THEN COALESCE((x->>'existing_id')::uuid,(x->>'id')::uuid)=n.target_id ELSE (x->>'source_output_id')::uuid=n.target_id END;
 expected:=CASE n.kind WHEN 'output' THEN u->'measurement' ELSE u->'remaining' END;
 IF u IS NULL OR jsonb_build_object('measure_mode',n.measure_mode,'net_kg',n.net_kg::text,'gross_kg',n.gross_kg::text,'tare_kg',n.tare_kg::text)<>expected
 OR (n.kind='output' AND (n.output_type<>'storage' OR n.source_item_id IS DISTINCT FROM (u->>'parent_id')::uuid OR EXISTS(SELECT 1 FROM batch_outputs WHERE subject_id=n.target_id)))
 OR (n.kind='measurement' AND (n.target_id=n.batch_id OR n.previous_measurement_id IS DISTINCT FROM (SELECT measurement_record_id FROM batch_outputs WHERE subject_id=n.target_id))) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Supplementary output/measurement must equal its reviewed non-duplicating accounting plan'; END IF;
END; $$;
CREATE FUNCTION media_discovery_integrity(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE d batch_media_discoveries;r restriction_records;p restriction_records;c restriction_cases;u jsonb;o batch_outputs;origin media_origins;cc custody_current;prod batch_records;
BEGIN
 SELECT * INTO d FROM batch_media_discoveries WHERE id=ref;SELECT * INTO r FROM restriction_records WHERE id=d.review_id;SELECT * INTO p FROM restriction_records WHERE id=d.proposal_id;SELECT * INTO c FROM restriction_cases WHERE id=r.case_id;
 SELECT x INTO u FROM jsonb_array_elements(p.details->'units') x WHERE x->>'id'=d.id::text;
 SELECT * INTO o FROM batch_outputs WHERE subject_id=d.medium_id;SELECT * INTO prod FROM batch_records WHERE id=d.production_id;
 SELECT * INTO cc FROM custody_current WHERE subject_id=d.medium_id;SELECT * INTO origin FROM media_origins WHERE subject_id=d.medium_id;
 IF d.id IS NULL OR u IS NULL OR c.contract<>'P15' OR c.fact_key<>'discovery_supplement' OR r.kind<>'approve' OR p.id<>r.proposal_id OR d.discrepancy_id<>c.source_id OR d.batch_id<>c.subject_id OR d.parent_id<>(u->>'parent_id')::uuid
 OR d.medium_id<>COALESCE((u->>'existing_id')::uuid,(u->>'id')::uuid) OR d.observer_id<>p.actor_id OR d.unit_count<>(p.details->>'count')::int
 OR d.is_new<>(u->>'existing_id' IS NULL) OR d.mode<>u->>'mode' OR d.source_output_id IS DISTINCT FROM (u->>'source_output_id')::uuid
 OR d.measurement_id IS DISTINCT FROM (CASE WHEN prod.measure_mode IS NOT NULL THEN prod.id ELSE NULL::uuid END) OR prod.correction_review_id IS DISTINCT FROM r.id OR prod.target_id<>d.medium_id OR prod.kind<>'output' OR o.production_record_id<>prod.id OR o.type<>'storage' OR o.batch_id<>d.batch_id OR o.source_item_id<>d.parent_id
 OR (SELECT owner_user_id FROM items WHERE subject_id=d.medium_id) IS DISTINCT FROM (SELECT owner_user_id FROM items WHERE subject_id=d.parent_id)
 OR cc.party_id IS DISTINCT FROM (SELECT facility_party_id FROM dismantling_batches WHERE subject_id=d.batch_id)
 OR d.observed_at<>p.observed_at OR d.recorded_at<>r.recorded_at OR EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=d.medium_id)
 OR (d.is_new AND (origin.origin_kind<>'batch_discovery' OR origin.discovery_id IS DISTINCT FROM d.id OR EXISTS(SELECT 1 FROM first_receipts WHERE subject_id=d.medium_id)))
 OR (NOT d.is_new AND cc.last_event_id IS DISTINCT FROM (u->>'existing_custody_event')::uuid)
 OR (d.mode='reclassified' AND NOT EXISTS(SELECT 1 FROM batch_records m WHERE m.id=d.remaining_measurement_id AND m.correction_review_id=r.id AND m.kind='measurement' AND m.target_id=d.source_output_id))
 THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Discovery requires exact reciprocal identity, current evidenced control, production and accounting without fabricated detachment'; END IF;
END; $$;
CREATE FUNCTION media_new_origin_integrity(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE o media_origins;d batch_media_discoveries;cc custody_current;r restriction_records;i items;
BEGIN
 SELECT * INTO o FROM media_origins WHERE subject_id=ref;SELECT * INTO d FROM batch_media_discoveries WHERE id=o.discovery_id;SELECT * INTO cc FROM custody_current WHERE subject_id=ref;SELECT * INTO r FROM restriction_records WHERE id=d.review_id;SELECT * INTO i FROM items WHERE subject_id=ref;
 IF d.id IS NULL OR NOT d.is_new OR i.intake_class<>'processor_derived' OR i.processing_state<>'inventoried' OR i.data_status<>'YES' OR i.subtype NOT IN ('hdd','ssd') OR cc.last_event_id IS DISTINCT FROM o.event_id OR cc.version<>1 OR o.parent_id<>d.parent_id
 OR NOT EXISTS(SELECT 1 FROM subjects WHERE id=ref AND version=1) OR EXISTS(SELECT 1 FROM first_receipts WHERE subject_id=ref) OR EXISTS(SELECT 1 FROM handover_items WHERE subject_id=ref)
 OR NOT EXISTS(SELECT 1 FROM domain_events e WHERE e.id=o.event_id AND e.subject_id=ref AND e.aggregate_kind='item' AND e.aggregate_id=ref AND e.aggregate_version=1 AND e.type='media_discovered_in_batch' AND e.actor_id=r.actor_id AND e.command_id=r.command_id AND e.occurred_at=d.observed_at AND e.recorded_at=r.recorded_at) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='New discovery origin requires current evidenced identification, not an earlier receipt or removal'; END IF;
 PERFORM media_discovery_integrity(d.id);
END; $$;
CREATE FUNCTION media_discovery_final() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM media_discovery_integrity(NEW.id);RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER media_discovery_complete AFTER INSERT ON batch_media_discoveries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION media_discovery_final();
CREATE FUNCTION media_discovery_epoch() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE affected record;completed record;
BEGIN
 FOR affected IN SELECT family.subject_id FROM restriction_family(NEW.medium_id) family
 WHERE NOT EXISTS(SELECT 1 FROM restriction_epoch_steps step WHERE step.record_id=NEW.review_id AND step.subject_id=family.subject_id) ORDER BY family.subject_id LOOP
 INSERT INTO restriction_epoch_steps VALUES(NEW.review_id,affected.subject_id,restriction_epoch(affected.subject_id)+1);
 INSERT INTO restriction_epochs VALUES(affected.subject_id,1,NEW.review_id) ON CONFLICT(subject_id) DO UPDATE SET version=restriction_epochs.version+1,record_id=NEW.review_id;
 FOR completed IN SELECT id FROM domain_events WHERE subject_id=affected.subject_id AND type IN ('sanitization_attempt','refurbishment_work','reuse_returned','dismantled_in_batch','media_detached_in_batch','downstream_accepted')
 UNION SELECT event_id FROM batch_records WHERE batch_id=affected.subject_id AND kind='commit' UNION SELECT event_id FROM downstream_records WHERE batch_id=affected.subject_id AND kind IN ('dispatch','close') LOOP
 INSERT INTO restriction_impacts VALUES(gen_random_uuid(),NEW.review_id,affected.subject_id,'completed_dependency',completed.id) ON CONFLICT DO NOTHING;
 END LOOP;
 END LOOP;
 RETURN NULL;
END; $$;
CREATE TRIGGER media_discovery_epoch_capture AFTER INSERT ON batch_media_discoveries FOR EACH ROW EXECUTE FUNCTION media_discovery_epoch();
CREATE FUNCTION media_batch_unresolved(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM batch_records r WHERE r.batch_id=ref AND r.kind='discrepancy' AND NOT media_record_superseded('batch',r.id,NULL))
$$;
CREATE FUNCTION media_fulfillment_ready(ref uuid) RETURNS boolean LANGUAGE plpgsql STABLE AS $$
DECLARE i restriction_impacts;e domain_events;w refurbishment_records;t refurbishment_records;basis refurbishment_records;data sanitization_records;b uuid;
BEGIN
 SELECT * INTO i FROM restriction_impacts WHERE id=ref;SELECT * INTO e FROM domain_events WHERE id=i.target_id;
 IF e.type='sanitization_attempt' THEN
 SELECT r.* INTO data FROM sanitization_current c JOIN sanitization_records r ON r.id=c.record_id WHERE c.subject_id=i.subject_id;
 RETURN data.kind IN ('validation','revalidation') AND data.outcome='accepted' AND restriction_record_current('sanitization_records',data.id,i.subject_id) AND sanitization_dependencies(data)
 AND (SELECT version FROM sanitization_records WHERE id=data.attempt_id)>=(SELECT version FROM sanitization_records WHERE event_id=e.id) AND NOT EXISTS(SELECT 1 FROM sanitization_current WHERE subject_id=i.subject_id AND destruction_reported);
 ELSIF e.type='refurbishment_work' THEN
 SELECT * INTO w FROM refurbishment_records WHERE subject_id=i.subject_id AND kind='work' ORDER BY version DESC LIMIT 1;
 SELECT * INTO basis FROM refurbishment_records WHERE subject_id=i.subject_id AND kind='work_applicability' ORDER BY version DESC LIMIT 1;
 SELECT * INTO t FROM refurbishment_records WHERE subject_id=i.subject_id AND kind='test' ORDER BY version DESC LIMIT 1;
 RETURN w.version>=(SELECT version FROM refurbishment_records WHERE event_id=e.id) AND basis.work_id=w.id AND t.work_id=basis.id AND t.result='passed' AND restriction_record_current('refurbishment_records',basis.id,i.subject_id) AND restriction_record_current('refurbishment_records',t.id,i.subject_id)
 AND t.context->'configuration'=refurbishment_configuration(i.subject_id) AND NOT media_work_blocked(i.subject_id);
 ELSIF e.type IN ('dismantled_in_batch','media_detached_in_batch','batch_commit') THEN
 SELECT batch_id INTO b FROM batch_members WHERE subject_id=i.subject_id UNION SELECT batch_id FROM batch_detachments WHERE subject_id=i.subject_id UNION SELECT subject_id FROM dismantling_batches WHERE subject_id=i.subject_id;
 RETURN b IS NOT NULL AND media_batch_onsite(b) AND NOT media_batch_unresolved(b)
 AND NOT EXISTS(SELECT 1 FROM batch_members m JOIN items item ON item.subject_id=m.subject_id WHERE m.batch_id=b AND item.processing_state<>'dismantled');
 END IF;
 RETURN false;
END; $$;
CREATE FUNCTION media_domain_effects_complete(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE r restriction_records;p restriction_records;c restriction_cases;u record;
BEGIN
 SELECT * INTO r FROM restriction_records WHERE id=ref;SELECT * INTO p FROM restriction_records WHERE id=r.proposal_id;SELECT * INTO c FROM restriction_cases WHERE id=r.case_id;
 IF c.fact_key IN ('association_confirm','association_end') AND (SELECT count(*) FROM media_inventory_records WHERE correction_review_id=r.id)<>1 THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Binding review requires its one exact retained binding effect'; END IF;
 IF c.fact_key='discovery_supplement' THEN
 IF (SELECT count(*) FROM batch_media_discoveries WHERE review_id=r.id)<>(p.details->>'count')::int THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Every observed discovery unit requires reciprocal provenance and production'; END IF;
 FOR u IN SELECT id FROM batch_media_discoveries WHERE review_id=r.id LOOP PERFORM media_discovery_integrity(u.id); END LOOP;
 END IF;
 IF c.fact_key='impact_continuity' THEN
 IF NOT EXISTS(SELECT 1 FROM restriction_impact_reviews WHERE record_id=r.id AND impact_id=c.source_id AND stage=(CASE r.kind WHEN 'fulfill' THEN 'fulfilled' ELSE 'continuity' END))
 OR EXISTS(SELECT 1 FROM restriction_epoch_steps WHERE record_id=r.id) OR (r.kind='fulfill' AND NOT COALESCE(media_fulfillment_ready(c.source_id),false)) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Impact requires its exact fulfilled original obligation without another epoch bump'; END IF;
 END IF;
END; $$;
