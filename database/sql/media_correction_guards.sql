CREATE FUNCTION media_batch_onsite(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM dismantling_batches WHERE subject_id=ref AND state='processing' AND input_measurement_id=commit_record_id)
 AND NOT EXISTS(SELECT 1 FROM downstream_records WHERE batch_id=ref AND kind IN ('dispatch','source_loss','downstream_loss','accept','refuse','shortage','dispute','complete','close','measurement_dispute'))
 AND NOT EXISTS(SELECT 1 FROM downstream_shipments h WHERE h.batch_id=ref AND downstream_reserved(h.id)>0)
$$;
CREATE FUNCTION media_no_terminal(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT NOT EXISTS(SELECT 1 FROM items WHERE subject_id=ref AND processing_state IN ('released','dismantled'))
 AND NOT EXISTS(SELECT 1 FROM reuse_return_items WHERE subject_id=ref)
 AND NOT EXISTS(SELECT 1 FROM batch_members WHERE subject_id=ref)
 AND NOT EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=ref)
 AND NOT EXISTS(SELECT 1 FROM sanitization_current WHERE subject_id=ref AND destruction_reported)
$$;
CREATE FUNCTION media_record_superseded(kind text,ref uuid,fact text) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM restriction_supersessions s JOIN restriction_cases c ON c.id=s.case_id JOIN media_review_commits m ON m.record_id=s.record_id
 WHERE c.contract='P15' AND s.source_kind='media_correction' AND s.source_id=ref AND (fact IS NULL OR s.fact_key=fact))
$$;
CREATE FUNCTION media_work_value(ref uuid,fact text) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT COALESCE((SELECT s.proposed_value FROM restriction_supersessions s JOIN media_review_commits m ON m.record_id=s.record_id WHERE s.source_kind='media_correction' AND s.source_id=ref AND s.fact_key=fact),
 (SELECT CASE fact WHEN 'work_configuration' THEN r.configuration WHEN 'work_outcome' THEN upper(r.result) ELSE r.answers->>substring(fact FROM 6) END FROM refurbishment_records r WHERE r.id=ref))
$$;
CREATE FUNCTION media_work_blocked(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM refurbishment_records r WHERE r.subject_id=ref AND
 ((r.kind='exception' AND NOT media_record_superseded('work',r.id,'return_report'))
 OR (r.kind='work' AND media_work_value(r.id,'work_configuration')<>'UNCHANGED')
 OR (r.kind='test' AND (media_work_value(r.id,'test_safety') IN ('FAIL','UNKNOWN') OR media_work_value(r.id,'test_configuration') IN ('FAIL','UNKNOWN')))))
$$;
CREATE FUNCTION media_obligation_context(ref uuid,impact uuid DEFAULT NULL) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('family',(SELECT jsonb_agg(
 (f - CASE WHEN EXISTS(SELECT 1 FROM restriction_impacts source JOIN domain_events event ON event.id=source.target_id WHERE source.id=impact AND event.type IN ('dismantled_in_batch','media_detached_in_batch','batch_commit')) THEN ARRAY['work','batch','media_authorization','assurance'] ELSE ARRAY['work','batch'] END) || jsonb_build_object('work',CASE WHEN EXISTS(SELECT 1 FROM restriction_impacts source JOIN domain_events event ON event.id=source.target_id WHERE source.id=impact AND event.type IN ('dismantled_in_batch','media_detached_in_batch','batch_commit')) THEN NULL ELSE jsonb_build_array(
 (SELECT id FROM refurbishment_records WHERE subject_id=(f->>'id')::uuid AND kind='work' ORDER BY version DESC LIMIT 1),
 (SELECT id FROM refurbishment_records WHERE subject_id=(f->>'id')::uuid AND kind='work_applicability' ORDER BY version DESC LIMIT 1),
 (SELECT id FROM refurbishment_records WHERE subject_id=(f->>'id')::uuid AND kind='test' ORDER BY version DESC LIMIT 1),
 (SELECT id FROM refurbishment_records WHERE subject_id=(f->>'id')::uuid AND kind='fan_na' ORDER BY version DESC LIMIT 1)) END,
 'batch',(SELECT jsonb_build_array(b.subject_id,b.commit_record_id,b.input_measurement_id,b.inputs,b.facility_party_id) FROM dismantling_batches b WHERE b.subject_id=(f->>'id')::uuid)) ORDER BY f->>'id') FROM jsonb_array_elements(restriction_dependency_context(ref)->'family') f),
 'sources',restriction_source_generation(ref),
 'downstream',(SELECT jsonb_agg(jsonb_build_array(r.id,r.version,r.kind,r.kg) ORDER BY r.batch_id,r.version) FROM downstream_records r WHERE r.batch_id IN(SELECT subject_id FROM restriction_family(ref))))
$$;
CREATE FUNCTION media_impact_fulfilled(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM restriction_impact_reviews v JOIN restriction_records r ON r.id=v.record_id JOIN restriction_cases c ON c.id=r.case_id
 JOIN media_review_commits m ON m.record_id=r.id JOIN media_review_effects e ON e.record_id=r.id
 WHERE v.impact_id=ref AND v.stage='fulfilled' AND v.epoch=restriction_epoch(c.subject_id) AND v.context=media_obligation_context(c.subject_id,v.impact_id)
 AND restriction_admin(r.actor_id) AND media_review_scope(e.observer_id,c.subject_id))
$$;
CREATE FUNCTION media_recovery_allowed(ref uuid,operation text) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT NOT EXISTS(SELECT 1 FROM restriction_impacts i WHERE i.subject_id IN(SELECT subject_id FROM restriction_family(ref)) AND NOT media_impact_fulfilled(i.id)
 AND (operation NOT IN ('inspection','decision','inventory','authorization','assurance_review','work_applicability','test') OR NOT EXISTS(
 SELECT 1 FROM restriction_impact_reviews v JOIN restriction_records r ON r.id=v.record_id JOIN restriction_cases c ON c.id=r.case_id JOIN media_review_effects e ON e.record_id=r.id JOIN media_review_commits m ON m.record_id=r.id
 WHERE v.impact_id=i.id AND v.stage='continuity' AND v.epoch=restriction_epoch(c.subject_id) AND c.active AND c.state='revalidation_pending'
 AND restriction_admin(r.actor_id) AND restriction_investigator(c.id,e.observer_id) AND media_review_scope(e.observer_id,c.subject_id))))
$$;
CREATE OR REPLACE FUNCTION restriction_dependent(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT NOT media_recovery_allowed(ref,'progress') $$;
CREATE FUNCTION media_review_eligible(c restriction_cases,p restriction_records) RETURNS boolean LANGUAGE plpgsql STABLE AS $$
DECLARE entry media_inventory_entries;source refurbishment_records;parent items;medium items;cc custody_current;ids uuid[];impact restriction_impacts;ev domain_events;statement restriction_records;
BEGIN
 IF c.contract<>'P15' OR p.proposed_value IS DISTINCT FROM media_review_value(c.fact_key) OR p.original_value<>c.original_value OR p.identity_code IS DISTINCT FROM (SELECT public_code FROM subjects WHERE id=c.subject_id)
 OR NOT media_review_scope(p.actor_id,c.subject_id) OR NOT restriction_investigator(c.id,p.actor_id)
 OR p.actor_context IS DISTINCT FROM restriction_actor_context(p.actor_id) OR p.context<>restriction_dependency_context(c.subject_id)
 OR p.grant_record_id IS DISTINCT FROM c.grant_record_id OR jsonb_typeof(p.checks)<>'object' OR EXISTS(SELECT 1 FROM jsonb_each_text(p.checks) x WHERE x.value<>'YES') THEN RETURN false; END IF;
 IF c.fact_key IN ('association_confirm','association_end') THEN
 SELECT e.* INTO entry FROM media_inventory_records r JOIN media_inventory_entries e ON e.id=r.entry_id WHERE r.id=c.source_id;
 SELECT * INTO parent FROM items WHERE subject_id=c.subject_id;SELECT * INTO medium FROM items WHERE subject_id=entry.medium_id;
 IF entry.state<>'disputed' OR entry.record_id<>c.source_id OR NOT media_no_terminal(c.subject_id) OR NOT media_no_terminal(entry.medium_id)
 OR parent.owner_user_id<>medium.owner_user_id OR NOT media_review_scope(p.actor_id,entry.medium_id)
 OR NOT EXISTS(SELECT 1 FROM custody_current a JOIN custody_current b ON a.party_id=b.party_id WHERE a.subject_id=c.subject_id AND b.subject_id=entry.medium_id)
 OR EXISTS(SELECT 1 FROM reuse_authorizations WHERE subject_id=c.subject_id AND state IN ('pending','exception','acknowledged')) THEN RETURN false; END IF;
 IF c.fact_key='association_end' AND (medium.intake_class<>'standalone_part' OR medium.subtype NOT IN ('hdd','ssd') OR EXISTS(SELECT 1 FROM media_origins WHERE subject_id=medium.subject_id)
 OR NOT EXISTS(SELECT 1 FROM first_receipts WHERE subject_id=medium.subject_id)) THEN RETURN false; END IF;
 RETURN true;
 ELSIF c.fact_key IN ('discovery_error','discovery_supplement') THEN
 RETURN media_batch_onsite(c.subject_id) AND EXISTS(SELECT 1 FROM batch_records WHERE id=c.source_id AND batch_id=c.subject_id AND kind='discrepancy');
 ELSIF c.fact_key='return_report' THEN
 SELECT * INTO source FROM refurbishment_records WHERE id=c.source_id AND kind='exception';
 SELECT r.* INTO statement FROM restriction_records r WHERE r.case_id=c.id AND r.kind='owner_clarify' AND r.id=(p.details->>'owner_statement_id')::uuid AND r.id=(SELECT id FROM restriction_records WHERE case_id=c.id AND kind='owner_clarify' ORDER BY version DESC LIMIT 1);
 SELECT array_agg(subject_id ORDER BY subject_id) INTO ids FROM reuse_manifest WHERE authorization_id=source.authorization_id;
 RETURN media_no_terminal(c.subject_id) AND NOT EXISTS(SELECT 1 FROM reuse_return_items WHERE authorization_id=source.authorization_id)
 AND EXISTS(SELECT 1 FROM reuse_authorizations WHERE id=source.authorization_id AND state='exception')
 AND statement.id IS NOT NULL AND statement.actor_context=restriction_actor_context(statement.actor_id)
 AND statement.actor_id=source.actor_id AND statement.actor_id=(SELECT owner_user_id FROM items WHERE subject_id=c.subject_id)
 AND statement.details->>'no_handover'='YES' AND statement.details->'manifest'=to_jsonb(ids)
 AND ids IS NOT DISTINCT FROM (SELECT array_agg((manifest_item->>'subject_id')::uuid ORDER BY (manifest_item->>'subject_id')::uuid) FROM jsonb_array_elements(reuse_manifest_snapshot(c.subject_id)) manifest_item)
 AND NOT EXISTS(SELECT 1 FROM unnest(ids) id WHERE NOT media_review_scope(p.actor_id,id) OR NOT media_no_terminal(id)
 OR (SELECT owner_user_id FROM items WHERE subject_id=id)<>statement.actor_id
 OR NOT EXISTS(SELECT 1 FROM custody_current manifest_custody WHERE manifest_custody.subject_id=id AND manifest_custody.party_id=(SELECT party_id FROM reuse_manifest WHERE authorization_id=source.authorization_id AND subject_id=id)));
 ELSIF c.fact_key='impact_continuity' THEN
 SELECT * INTO impact FROM restriction_impacts WHERE id=c.source_id;SELECT * INTO ev FROM domain_events WHERE id=impact.target_id;
 RETURN impact.id IS NOT NULL AND ev.type IN ('sanitization_attempt','refurbishment_work','dismantled_in_batch','media_detached_in_batch','batch_commit')
 AND NOT EXISTS(SELECT 1 FROM reuse_return_items WHERE subject_id=c.subject_id)
 AND NOT EXISTS(SELECT 1 FROM sanitization_current WHERE subject_id=c.subject_id AND destruction_reported)
 AND (ev.type NOT IN ('dismantled_in_batch','media_detached_in_batch','batch_commit') OR EXISTS(SELECT 1 FROM batch_members b WHERE (b.subject_id=c.subject_id OR b.batch_id=c.subject_id) AND media_batch_onsite(b.batch_id)));
 ELSE
 SELECT * INTO source FROM refurbishment_records WHERE id=c.source_id;
 RETURN source.subject_id=c.subject_id AND media_no_terminal(c.subject_id) AND NOT EXISTS(SELECT 1 FROM reuse_authorizations WHERE subject_id=c.subject_id AND state='acknowledged')
 AND ((c.fact_key='work_configuration' AND source.kind='work' AND source.configuration IN ('CHANGED','UNKNOWN'))
 OR (c.fact_key='work_outcome' AND source.kind='work' AND source.result IN ('failed','incomplete'))
 OR (c.fact_key LIKE 'test\_%' AND source.kind='test' AND source.answers ? substring(c.fact_key FROM 6)));
 END IF;
END; $$;
CREATE FUNCTION media_validate_case(n restriction_cases,o restriction_cases,op text) RETURNS void LANGUAGE plpgsql AS $$
DECLARE r restriction_records;s record;expected jsonb;
BEGIN
 IF op='DELETE' THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Correction history is retained.'; END IF;
 IF op='INSERT' THEN
 SELECT * INTO s FROM media_correction_sources() WHERE source_id=n.source_id AND fact_key=n.fact_key;
 IF n.source_kind<>'media_correction' OR s.source_id IS NULL OR NOT s.currently_blocking OR n.subject_id<>s.subject_id OR n.source_event_id<>s.source_event_id OR n.source_version<>s.source_version OR n.original_value<>s.original_value
 OR NOT n.supported OR n.version<>1 OR n.state<>'open' OR NOT n.active OR n.assigned_to IS NOT NULL OR NOT restriction_admin(n.created_by)
 OR EXISTS(SELECT 1 FROM restriction_cases c WHERE c.active AND media_source_scope(c.source_kind,c.source_id)=media_source_scope(n.source_kind,n.source_id)) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='A current source and an exclusive typed investigation are required. Close any legacy investigation without change first.'; END IF;
 ELSE
 SELECT * INTO r FROM restriction_records WHERE id=n.record_id AND case_id=o.id AND version=o.version+1;
 expected:=to_jsonb(o)||jsonb_build_object('version',o.version+1,'record_id',r.id,'state',media_case_state(o,r),'active',media_case_state(o,r) IN ('open','under_review','revalidation_pending'));
 IF r.kind='assign' THEN expected:=expected||jsonb_build_object('assigned_to',r.assigned_to,'grant_record_id',r.id,'assignment_context',restriction_actor_context(r.assigned_to),'pre_receipt',false,'allowed_evidence',r.allowed_evidence);
 ELSIF r.kind='revoke' THEN expected:=expected||'{"assigned_to":null,"grant_record_id":null,"assignment_context":null,"pre_receipt":false,"allowed_evidence":[]}'::jsonb; END IF;
 IF r.id IS NULL OR NOT o.active OR to_jsonb(n)<>expected THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Typed case projection requires its exact next retained action.'; END IF;
 END IF;
END; $$;
CREATE FUNCTION media_validate_record(n restriction_records) RETURNS void LANGUAGE plpgsql AS $$
DECLARE c restriction_cases;p restriction_records;uid uuid;source record;keys text[];
BEGIN
 SELECT * INTO c FROM restriction_cases WHERE id=n.case_id FOR UPDATE;SELECT * INTO source FROM media_correction_sources() WHERE source_id=c.source_id AND fact_key=c.fact_key;
 IF c.id IS NULL OR source.source_id IS NULL OR NOT c.active OR n.version<>(CASE n.kind WHEN 'open' THEN 1 ELSE c.version+1 END) OR n.predecessor_id IS DISTINCT FROM (CASE n.kind WHEN 'open' THEN NULL::uuid ELSE c.record_id END)
 OR n.source_version<>c.source_version OR n.restriction_version<>restriction_epoch(c.subject_id) OR n.context<>restriction_dependency_context(c.subject_id) OR n.actor_context IS DISTINCT FROM restriction_actor_context(n.actor_id)
 OR n.observed_at>clock_timestamp() OR n.observed_at<date_trunc('minute',GREATEST(c.created_at,source.source_time)) OR n.recorded_at<n.observed_at THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Source, scope or dependencies changed; reload the typed impact preview.'; END IF;
 IF n.kind IN ('open','assign','revoke','continue','close','approve','fulfill') AND NOT restriction_admin(n.actor_id)
 OR n.kind IN ('proposal','note') AND NOT (restriction_investigator(c.id,n.actor_id) AND media_review_scope(n.actor_id,c.subject_id) OR n.kind='note' AND restriction_admin(n.actor_id))
 OR n.kind='clarify' THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Current typed investigation authority is required.'; END IF;
 IF n.kind='owner_clarify' AND (c.fact_key<>'return_report' OR NOT EXISTS(SELECT 1 FROM users u JOIN memberships m ON m.user_id=u.id JOIN items i ON i.owner_user_id=u.id WHERE i.subject_id=c.subject_id AND u.id=n.actor_id AND u.status='active' AND m.active AND m.role='consumer')
 OR n.details->>'no_handover' IS DISTINCT FROM 'YES') THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Only the original active owner can clarify their own unacknowledged report; this records no receipt.'; END IF;
 IF n.attachment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attachments WHERE id=n.attachment_id AND uploaded_by=n.actor_id AND privacy_class='private' AND redacted_at IS NULL) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Use your own private evidence'; END IF;
 IF n.kind='assign' THEN
 IF n.pre_receipt OR NOT media_review_scope(n.assigned_to,c.subject_id) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Choose an active capable Processor at the actual controlling facility. No pre-receipt or point-only authority applies.'; END IF;
 FOREACH uid IN ARRAY n.allowed_evidence LOOP IF uid IS DISTINCT FROM source.source_attachment_id THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Only explicitly authorized source evidence may be granted'; END IF; END LOOP;
 ELSIF n.assigned_to IS NOT NULL OR n.pre_receipt OR n.allowed_evidence<>'{}'::uuid[] THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Only assignment changes investigation scope'; END IF;
 IF n.kind IN ('proposal','approve','fulfill','owner_clarify') AND n.attachment_id IS NULL AND n.reference_ciphertext IS NULL THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='This typed finding, clarification or review requires private accountable evidence.'; END IF;
 IF n.kind='owner_clarify' AND (jsonb_typeof(n.details)<>'object' OR (SELECT count(*) FROM jsonb_object_keys(n.details))<>2 OR NOT(n.details ?& ARRAY['no_handover','manifest']) OR jsonb_typeof(n.details->'manifest')<>'array') THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Use the exact owner clarification manifest'; END IF;
 IF n.kind='proposal' AND c.fact_key NOT IN ('discovery_error','discovery_supplement','return_report') AND n.details NOT IN ('{}'::jsonb,'[]'::jsonb) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='This typed fact has no arbitrary detail fields'; END IF;
 IF n.kind='proposal' AND c.fact_key='return_report' AND (jsonb_typeof(n.details)<>'object' OR (SELECT count(*) FROM jsonb_object_keys(n.details))<>1 OR NOT(n.details ? 'owner_statement_id')) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Return findings must cite the exact original owner clarification'; END IF;
 IF n.kind='proposal' THEN
 keys:=CASE c.fact_key WHEN 'discovery_supplement' THEN ARRAY['distinct_units','source_owner_control','safe_observation','included_input_mass','accounting_known','no_movement','onsite_only','all_units_accounted'] WHEN 'impact_continuity' THEN ARRAY['same_identity','original_obligation','current_control','history_preserved','no_physical_change','supported_fulfillment'] ELSE ARRAY['direct_observation','same_subject','recording_error','then_and_now','no_remediation','no_movement','no_configuration_change','supported_known_fact'] END;
 IF n.original_value<>c.original_value OR n.identity_code IS DISTINCT FROM (SELECT public_code FROM subjects WHERE id=c.subject_id) OR n.grant_record_id IS DISTINCT FROM c.grant_record_id
 OR n.proposed_value NOT IN (media_review_value(c.fact_key),'UNKNOWN') OR NOT (n.checks ?& keys) OR (SELECT count(*) FROM jsonb_object_keys(n.checks))<>cardinality(keys)
 OR EXISTS(SELECT 1 FROM jsonb_each_text(n.checks) q WHERE q.value NOT IN ('YES','NO','UNKNOWN')) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Use the exact typed evidence template and supported controlled facts; unknown remains blocked.'; END IF;
 ELSIF n.kind IN ('approve','fulfill') THEN
 SELECT * INTO p FROM restriction_records WHERE id=n.proposal_id;
 IF p.case_id<>c.id OR p.kind<>'proposal' OR n.observed_at<p.observed_at OR p.actor_id=n.actor_id OR EXISTS(SELECT 1 FROM restriction_records cited WHERE cited.case_id=c.id AND cited.kind='proposal' AND (cited.actor_id=n.actor_id OR cited.actor_context<>restriction_actor_context(cited.actor_id) OR NOT media_review_scope(cited.actor_id,c.subject_id)))
 OR NOT COALESCE(media_review_eligible(c,p),false) OR (n.kind='fulfill' AND (c.fact_key<>'impact_continuity' OR c.state<>'revalidation_pending')) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Independent review requires current supported evidence from the controlling facility; unknown, moved, changed or unsupported facts remain held.'; END IF;
 END IF;
END; $$;

CREATE FUNCTION media_begin_review(n restriction_records) RETURNS void LANGUAGE plpgsql AS $$
DECLARE c restriction_cases;p restriction_records;f record;t record;
BEGIN
 IF n.kind NOT IN ('approve','fulfill') THEN RETURN; END IF;
 SELECT * INTO c FROM restriction_cases WHERE id=n.case_id;SELECT * INTO p FROM restriction_records WHERE id=n.proposal_id;
 IF c.fact_key='impact_continuity' THEN
 INSERT INTO restriction_impact_reviews VALUES(n.id,c.source_id,CASE n.kind WHEN 'fulfill' THEN 'fulfilled' ELSE 'continuity' END,restriction_epoch(c.subject_id),media_obligation_context(c.subject_id,c.source_id));
 ELSE
 INSERT INTO restriction_supersessions VALUES(n.id,c.id,p.id,c.subject_id,c.source_kind,c.source_id,c.fact_key,c.original_value,p.proposed_value);
 FOR f IN SELECT subject_id FROM restriction_family(c.subject_id) ORDER BY subject_id LOOP
 INSERT INTO restriction_epoch_steps VALUES(n.id,f.subject_id,restriction_epoch(f.subject_id)+1);
 INSERT INTO restriction_epochs VALUES(f.subject_id,1,n.id) ON CONFLICT(subject_id) DO UPDATE SET version=restriction_epochs.version+1,record_id=n.id;
 FOR t IN SELECT id FROM domain_events WHERE subject_id=f.subject_id AND type IN ('sanitization_attempt','refurbishment_work','reuse_returned','dismantled_in_batch','media_detached_in_batch','downstream_accepted')
 UNION SELECT event_id FROM batch_records WHERE batch_id=f.subject_id AND kind='commit' UNION SELECT event_id FROM downstream_records WHERE batch_id=f.subject_id AND kind IN ('dispatch','close') LOOP
 INSERT INTO restriction_impacts VALUES(gen_random_uuid(),n.id,f.subject_id,'completed_dependency',t.id) ON CONFLICT DO NOTHING;
 END LOOP;
 END LOOP;
 END IF;
END; $$;
CREATE FUNCTION media_review_commit_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' OR NEW.context IS DISTINCT FROM restriction_dependency_context((SELECT subject_id FROM restriction_cases WHERE id=(SELECT case_id FROM restriction_records WHERE id=NEW.record_id)))
 OR NOT EXISTS(SELECT 1 FROM media_review_effects WHERE record_id=NEW.record_id) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Typed correction commit must freeze the exact completed effects'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER media_review_commit_valid BEFORE INSERT OR UPDATE OR DELETE ON media_review_commits FOR EACH ROW EXECUTE FUNCTION media_review_commit_guard();
CREATE FUNCTION media_review_final(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE c restriction_cases;r restriction_records;seq bigint:=0;last restriction_records;e media_review_effects;
BEGIN
 SELECT * INTO c FROM restriction_cases WHERE id=ref;
 FOR r IN SELECT * FROM restriction_records WHERE case_id=ref ORDER BY version LOOP
 seq:=seq+1;
 IF r.version<>seq OR NOT EXISTS(SELECT 1 FROM commands cmd JOIN domain_events ev ON ev.command_id=cmd.id WHERE cmd.id=r.command_id AND cmd.actor_id=r.actor_id AND cmd.safe_response->>'record_id'=c.id::text AND ev.id=r.event_id AND ev.actor_id=r.actor_id AND ev.aggregate_kind='restriction' AND ev.aggregate_id=c.id AND ev.aggregate_version=r.version AND ev.type='restriction_'||r.kind AND ev.occurred_at=r.observed_at AND ev.recorded_at=r.recorded_at) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Typed case requires reciprocal commands/events and consecutive history'; END IF;
 IF r.kind IN ('approve','fulfill') THEN
 SELECT * INTO e FROM media_review_effects WHERE record_id=r.id;
 IF e.record_id IS NULL OR e.case_id<>c.id OR e.proposal_id<>r.proposal_id OR e.observer_id IS DISTINCT FROM (SELECT actor_id FROM restriction_records WHERE id=r.proposal_id)
 OR NOT EXISTS(SELECT 1 FROM media_review_commits WHERE record_id=r.id) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Typed review requires reciprocal domain effects and completed commit evidence'; END IF;
 END IF;
 last:=r;
 END LOOP;
 IF c.version<>seq OR c.record_id<>last.id OR c.state<>media_case_state(c,last) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Typed case projection does not match its retained outcome'; END IF;
END; $$;
