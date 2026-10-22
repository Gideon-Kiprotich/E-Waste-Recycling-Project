CREATE FUNCTION downstream_impact_batch(i restriction_impacts) RETURNS uuid LANGUAGE sql STABLE AS $$
 SELECT COALESCE((SELECT c.subject_id FROM restriction_records r JOIN restriction_cases c ON c.id=r.case_id JOIN dismantling_batches b ON b.subject_id=c.subject_id WHERE r.id=i.record_id),
 (SELECT batch_id FROM downstream_records WHERE event_id=i.target_id OR physical_event_id=i.target_id),
 (SELECT (array_agg(DISTINCT d.batch_id))[1] FROM batch_media_discoveries d WHERE i.subject_id IN(d.medium_id,d.parent_id,d.batch_id) HAVING count(DISTINCT d.batch_id)=1))
$$;
CREATE FUNCTION downstream_correction_sources() RETURNS TABLE(source_kind text,source_id uuid,subject_id uuid,fact_key text,original_value text,supported boolean,source_event_id uuid,source_version bigint,source_actor_id uuid,source_time timestamptz,source_attachment_id uuid,request_id uuid,currently_blocking boolean) LANGUAGE sql STABLE AS $$
 SELECT 'downstream_correction',r.id,r.batch_id,'downstream_'||a.action,r.kind,true,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid,true
 FROM downstream_records r CROSS JOIN LATERAL unnest(CASE
 WHEN r.kind IN ('refuse','shortage','dispute') THEN ARRAY['report_error','located','loss_confirm']
 WHEN r.kind='accept' AND r.condition='damaged' THEN ARRAY['damage_error','damage_disposition']
 WHEN r.kind IN ('source_loss','downstream_loss') THEN ARRAY['loss_error','loss_confirm']
 WHEN r.kind='measurement_dispute' THEN ARRAY['measurement']
 WHEN r.kind='draft' THEN ARRAY['recipient'] WHEN r.kind='complete' THEN ARRAY['completion_error'] WHEN r.kind='close' THEN ARRAY['closure_review'] ELSE ARRAY[]::text[] END) a(action)
 UNION ALL SELECT 'downstream_correction',r.id,r.batch_id,'downstream_measurement',r.kind,true,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid,true FROM batch_records r WHERE r.kind IN ('output','measurement','commit') AND r.net_kg IS NOT NULL
 UNION ALL SELECT 'downstream_correction',r.id,r.batch_id,'downstream_discovery_movement','discovery',true,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid,true FROM batch_records r JOIN batch_media_discoveries d ON d.medium_id=r.target_id WHERE r.kind='output'
 UNION ALL SELECT 'downstream_correction',i.id,downstream_impact_batch(i),'downstream_downstream_continuity',i.type,true,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid,true FROM restriction_impacts i JOIN restriction_records r ON r.id=i.record_id JOIN domain_events e ON e.id=i.target_id WHERE downstream_impact_batch(i) IS NOT NULL AND (e.type IN ('downstream_dispatch','downstream_accept','downstream_accepted','downstream_complete','downstream_close') OR (e.type IN ('batch_commit','dismantled_in_batch','media_detached_in_batch','sanitization_attempt') AND EXISTS(SELECT 1 FROM batch_media_discoveries discovery JOIN downstream_fact_revisions reviewed ON reviewed.anchor_kind='discovery' AND reviewed.anchor_id=discovery.medium_id AND reviewed.fact='movement' WHERE discovery.batch_id=downstream_impact_batch(i))))
$$;
CREATE FUNCTION downstream_case_state(c restriction_cases,r restriction_records) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT CASE r.kind WHEN 'close' THEN 'closed_no_change' WHEN 'fulfill' THEN 'obligation_fulfilled' WHEN 'approve' THEN CASE WHEN c.fact_key IN ('downstream_downstream_continuity','downstream_closure_review') THEN 'revalidation_pending' ELSE 'superseded' END WHEN 'open' THEN 'open' ELSE CASE WHEN c.state='revalidation_pending' THEN c.state ELSE 'under_review' END END
$$;
CREATE FUNCTION downstream_validate_case(n restriction_cases,o restriction_cases,op text) RETURNS void LANGUAGE plpgsql AS $$
DECLARE s record;r restriction_records;expected jsonb;
BEGIN
 IF op='DELETE' THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Downstream review history is retained.'; END IF;
 IF op='INSERT' THEN
 SELECT * INTO s FROM downstream_correction_sources() WHERE source_id=n.source_id AND fact_key=n.fact_key;
 IF n.source_kind<>'downstream_correction' OR s.source_id IS NULL OR n.subject_id<>s.subject_id OR n.source_event_id<>s.source_event_id OR n.source_version<>s.source_version OR n.original_value<>s.original_value OR NOT n.supported OR n.version<>1 OR n.state<>'open' OR NOT n.active OR n.assigned_to IS NOT NULL OR NOT restriction_admin(n.created_by) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Open an exclusive investigation against the exact existing downstream fact.'; END IF;
 ELSE
 SELECT * INTO r FROM restriction_records WHERE id=n.record_id AND case_id=o.id AND version=o.version+1;
 expected:=to_jsonb(o)||jsonb_build_object('version',o.version+1,'record_id',r.id,'state',downstream_case_state(o,r),'active',downstream_case_state(o,r) IN ('open','under_review','revalidation_pending'));
 IF r.kind='assign' THEN expected:=expected||jsonb_build_object('assigned_to',r.assigned_to,'grant_record_id',r.id,'assignment_context',restriction_actor_context(r.assigned_to),'pre_receipt',false,'allowed_evidence',r.allowed_evidence);
 ELSIF r.kind='revoke' THEN expected:=expected||' {"assigned_to":null,"grant_record_id":null,"assignment_context":null,"pre_receipt":false,"allowed_evidence":[]}'::jsonb; END IF;
 IF r.id IS NULL OR NOT o.active OR to_jsonb(n) IS DISTINCT FROM expected THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Downstream case projection must match its next retained action.'; END IF;
 END IF;
END; $$;
CREATE FUNCTION downstream_party(ref uuid,actor uuid) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN COALESCE((batch_access(actor,b.facility_party_id)->>'eligible')::boolean,false) THEN 'source'
 WHEN EXISTS(SELECT 1 FROM downstream_shipments h JOIN output_profile_records p ON p.id=h.profile_record_id WHERE h.batch_id=ref AND COALESCE((downstream_access(actor,p.facility_party_id)->>'eligible')::boolean,false)) THEN 'destination' END FROM dismantling_batches b WHERE b.subject_id=ref
$$;
CREATE FUNCTION downstream_destination_actor(actor uuid,source uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT EXISTS(SELECT 1 FROM downstream_records r JOIN downstream_shipments h ON h.id=r.shipment_id JOIN output_profile_records p ON p.id=h.profile_record_id WHERE r.id=source AND COALESCE((downstream_access(actor,p.facility_party_id)->>'eligible')::boolean,false)) $$;
CREATE FUNCTION downstream_destination_signed(p restriction_records,source uuid,required_fact text DEFAULT NULL) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT (downstream_destination_actor(p.actor_id,source) AND (required_fact IS NULL OR p.details->'facts'->>required_fact='YES'))
 OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(COALESCE(p.details->'party_findings','[]'::jsonb)) cited JOIN restriction_records signature ON signature.id=cited::uuid WHERE signature.case_id=p.case_id AND signature.kind='clarify' AND downstream_destination_actor(signature.actor_id,source) AND (required_fact IS NULL OR signature.details->'facts'->>required_fact='YES'))
$$;
CREATE FUNCTION downstream_source_signed(p restriction_records,source uuid,required_fact text DEFAULT NULL) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT (downstream_party(source,p.actor_id)='source' AND (required_fact IS NULL OR p.details->'facts'->>required_fact='YES'))
 OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(COALESCE(p.details->'party_findings','[]'::jsonb)) cited JOIN restriction_records signature ON signature.id=cited::uuid WHERE signature.case_id=p.case_id AND signature.kind='clarify' AND downstream_party(source,signature.actor_id)='source' AND (required_fact IS NULL OR signature.details->'facts'->>required_fact='YES'))
$$;
CREATE FUNCTION downstream_named_destination_signed(p restriction_records,source uuid,required_fact text) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT (h.receiver_actor_id=p.actor_id AND downstream_destination_actor(p.actor_id,source) AND p.details->'facts'->>required_fact='YES')
 OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(p.details->'party_findings') cited JOIN restriction_records signature ON signature.id=cited::uuid WHERE signature.actor_id=h.receiver_actor_id AND downstream_destination_actor(signature.actor_id,source) AND signature.details->'facts'->>required_fact='YES')
 FROM downstream_records r JOIN downstream_effective_shipments h ON h.id=r.shipment_id WHERE r.id=source
$$;
CREATE FUNCTION downstream_vector_valid(c restriction_cases,p restriction_records) RETURNS boolean LANGUAGE plpgsql STABLE AS $$
DECLARE a text:=substring(c.fact_key FROM 12);v jsonb;row downstream_records;br batch_records;last downstream_fact_revisions;value text;has_source boolean;has_destination boolean;signature restriction_records;f text;k text;ref uuid;original numeric;h downstream_shipments;pr output_profile_records;oldpr output_profile_records;out batch_outputs;
BEGIN
 IF p.proposed_value IS DISTINCT FROM 'REVIEWED' OR p.original_value<>c.original_value OR p.identity_code IS DISTINCT FROM (SELECT public_code FROM subjects WHERE id=c.subject_id) OR p.actor_context IS DISTINCT FROM restriction_actor_context(p.actor_id) OR NOT downstream_case_access(c.id,p.actor_id) OR p.grant_record_id IS DISTINCT FROM c.grant_record_id
 OR (a<>'recipient' AND p.details ?| ARRAY['receiver_actor_id','profile_record_id','receiver_context'])
 OR EXISTS(SELECT 1 FROM jsonb_object_keys(p.details) key WHERE key NOT IN ('revisions','party_findings','facts','receiver_actor_id','profile_record_id','receiver_context','before_stock'))
 OR EXISTS(SELECT 1 FROM jsonb_each_text(p.details->'facts') f WHERE f.key NOT IN ('identity_known','supported_facts','original_error','located_later','continuous_control','present_unallocated','quantified_loss','known_safe','same_allocation') OR f.value NOT IN ('YES','NO','UNKNOWN') OR f.value IS NULL)
 OR jsonb_typeof(p.details)<>'object'  OR NOT(p.details ?& ARRAY['revisions','party_findings','facts']) OR jsonb_typeof(p.details->'revisions')<>'array' OR jsonb_array_length(p.details->'revisions')>50
 OR jsonb_typeof(p.details->'facts')<>'object' OR p.details->'facts'->>'identity_known' IS DISTINCT FROM 'YES' OR p.details->'facts'->>'supported_facts' IS DISTINCT FROM 'YES'
 OR (SELECT count(*) FROM jsonb_object_keys(p.checks))<>6 OR NOT(p.checks ?& ARRAY['identity_known','quantities_evidenced','scope_known','history_preserved','no_invented_movement','supported_facts']) OR EXISTS(SELECT 1 FROM jsonb_each_text(p.checks) q WHERE q.value IS DISTINCT FROM 'YES') THEN RETURN false; END IF;
 has_source:=downstream_party(c.subject_id,p.actor_id)='source';has_destination:=downstream_party(c.subject_id,p.actor_id)='destination';
 FOR v IN SELECT * FROM jsonb_array_elements(p.details->'party_findings') LOOP
 SELECT * INTO signature FROM restriction_records WHERE id=(v#>>'{}')::uuid;
 IF signature.case_id IS DISTINCT FROM c.id OR signature.kind<>'clarify' OR NOT downstream_case_access(c.id,signature.actor_id) OR signature.actor_context IS DISTINCT FROM restriction_actor_context(signature.actor_id) OR EXISTS(SELECT 1 FROM jsonb_array_elements(signature.details->'revisions') claimed WHERE NOT EXISTS(SELECT 1 FROM jsonb_array_elements(p.details->'revisions') proposed WHERE proposed=claimed)) OR signature.details->'facts'->>'identity_known' IS DISTINCT FROM 'YES' OR signature.details->'facts'->>'supported_facts' IS DISTINCT FROM 'YES' OR EXISTS(SELECT 1 FROM jsonb_each_text(signature.checks) check_value WHERE check_value.value IS DISTINCT FROM 'YES') THEN RETURN false; END IF;
 has_source:=has_source OR downstream_party(c.subject_id,signature.actor_id)='source';has_destination:=has_destination OR downstream_party(c.subject_id,signature.actor_id)='destination';
 END LOOP;
 IF a IN ('report_error','located','damage_error','damage_disposition','completion_error') AND NOT has_destination THEN RETURN false; END IF;
 IF a IN ('measurement','recipient','discovery_movement','downstream_continuity','closure_review') AND NOT has_source THEN RETURN false; END IF;
 SELECT * INTO row FROM downstream_records WHERE id=c.source_id;
 IF a IN ('report_error','located','damage_error','damage_disposition','completion_error') AND NOT downstream_destination_signed(p,c.source_id) THEN RETURN false; END IF;
 IF a IN ('loss_error','loss_confirm') AND ((row.kind='source_loss' AND NOT has_source) OR (row.kind<>'source_loss' AND NOT downstream_destination_signed(p,c.source_id))) THEN RETURN false; END IF;
 IF a IN ('report_error','damage_error','loss_error','measurement','completion_error') AND p.details->'facts'->>'original_error' IS DISTINCT FROM 'YES' THEN RETURN false; END IF;
 IF a IN ('report_error','damage_error','completion_error') AND NOT downstream_destination_signed(p,c.source_id,'original_error') THEN RETURN false; END IF;
 IF a='located' AND NOT downstream_destination_signed(p,c.source_id,'located_later') THEN RETURN false; END IF;
 IF a='damage_disposition' AND NOT downstream_destination_signed(p,c.source_id,'known_safe') THEN RETURN false; END IF;
 IF a IN ('loss_error','loss_confirm') AND NOT (CASE WHEN row.kind='source_loss' THEN downstream_source_signed(p,c.subject_id,CASE a WHEN 'loss_error' THEN 'original_error' ELSE 'quantified_loss' END) ELSE downstream_destination_signed(p,c.source_id,CASE a WHEN 'loss_error' THEN 'original_error' ELSE 'quantified_loss' END) END) THEN RETURN false; END IF;
 IF a='loss_error' AND row.kind='source_loss' AND (NOT downstream_source_signed(p,c.subject_id,'continuous_control') OR NOT downstream_source_signed(p,c.subject_id,'present_unallocated')) THEN RETURN false; END IF;
 IF a='located' AND p.details->'facts'->>'located_later' IS DISTINCT FROM 'YES' THEN RETURN false; END IF;
 IF a='loss_error' AND row.kind='source_loss' AND (p.details->'facts'->>'continuous_control' IS DISTINCT FROM 'YES' OR p.details->'facts'->>'present_unallocated' IS DISTINCT FROM 'YES') THEN RETURN false; END IF;
 IF a='loss_confirm' AND p.details->'facts'->>'quantified_loss' IS DISTINCT FROM 'YES' THEN RETURN false; END IF;
 IF a='damage_disposition' AND p.details->'facts'->>'known_safe' IS DISTINCT FROM 'YES' THEN RETURN false; END IF;
 IF p.details->>'before_stock' IS NULL OR (p.details->>'before_stock')::jsonb IS DISTINCT FROM downstream_stock_map(c.subject_id) THEN RETURN false; END IF;
 IF a='measurement' AND (NOT downstream_source_signed(p,c.subject_id,'original_error') OR NOT downstream_source_signed(p,c.subject_id,'same_allocation')) THEN RETURN false; END IF;
 IF a='measurement' AND (p.details->'facts'->>'same_allocation' IS DISTINCT FROM 'YES' OR EXISTS(SELECT 1 FROM downstream_shipments WHERE batch_id=c.subject_id AND downstream_reserved(id)>0)) THEN RETURN false; END IF;
 FOR v IN SELECT * FROM jsonb_array_elements(p.details->'revisions') LOOP
 IF jsonb_typeof(v)<>'object' OR NOT(v ?& ARRAY['anchor_kind','anchor_id','fact','value','expected_version']) OR EXISTS(SELECT 1 FROM jsonb_object_keys(v) key WHERE key NOT IN ('anchor_kind','anchor_id','fact','value','expected_version','predecessor_id')) THEN RETURN false; END IF;
 k:=v->>'anchor_kind';ref:=(v->>'anchor_id')::uuid;f:=v->>'fact';value:=v->>'value';SELECT * INTO last FROM downstream_fact_revisions WHERE anchor_kind=k AND anchor_id=ref AND fact=f ORDER BY version DESC LIMIT 1;
 IF (SELECT count(*) FROM jsonb_array_elements(p.details->'revisions') other WHERE other->>'anchor_kind'=k AND other->>'anchor_id'=ref::text AND other->>'fact'=f)<>1 OR (f='kg' AND EXISTS(SELECT 1 FROM jsonb_array_elements(p.details->'revisions') other WHERE other->>'anchor_kind'=k AND other->>'anchor_id'=ref::text AND other->>'fact'='active')) THEN RETURN false; END IF;
 IF value IS NULL OR (v->>'expected_version')::bigint<>COALESCE(last.version,0) OR (v->>'predecessor_id')::uuid IS DISTINCT FROM last.id THEN RETURN false; END IF;
 IF k='downstream' THEN
 SELECT * INTO row FROM downstream_records WHERE id=ref;IF row.batch_id IS DISTINCT FROM c.subject_id THEN RETURN false; END IF;
 IF a<>'measurement' AND ref<>c.source_id THEN RETURN false; END IF;
 IF a IN ('report_error','located') AND (row.kind NOT IN ('refuse','shortage','dispute') OR f NOT IN ('active','kg')) THEN RETURN false; END IF;
 IF a='completion_error' AND (row.kind<>'complete' OR f<>'active' OR value<>'0') THEN RETURN false; END IF;
 IF a='damage_error' AND (row.kind<>'accept' OR row.condition<>'damaged' OR f<>'condition' OR value<>'intact') THEN RETURN false; END IF;
 IF a='damage_disposition' AND EXISTS(SELECT 1 FROM batch_outputs o WHERE o.subject_id=row.output_id AND ((o.type='storage' AND value<>'controlled_storage') OR (o.type='residue' AND value<>'disposed'))) THEN RETURN false; END IF;
 IF a='damage_disposition' AND (row.kind<>'accept' OR row.condition<>'damaged' OR f<>'disposition' OR value NOT IN ('recovered','disposed','controlled_storage')) THEN RETURN false; END IF;
 IF a='loss_error' AND (row.kind NOT IN ('source_loss','downstream_loss') OR f NOT IN ('active','kg')) THEN RETURN false; END IF;
 IF a='loss_confirm' AND NOT ((row.kind IN ('source_loss','downstream_loss','refuse','shortage','dispute') AND f='loss_status' AND value='confirmed') OR (row.kind IN ('refuse','shortage','dispute') AND f='kind' AND value='downstream_loss')) THEN RETURN false; END IF;
 IF a='measurement' AND NOT ((f='kg' AND row.kind IN ('reserve','withdraw','dispatch','accept','complete','source_loss','downstream_loss','refuse','shortage','dispute')) OR (row.kind='measurement_dispute' AND ref=c.source_id AND f='active' AND value='0')) THEN RETURN false; END IF;
 IF f='kg' AND (value!~'^(0|[1-9][0-9]{0,8})(\.[0-9]{1,3})?$' OR value::numeric<=0 OR (a<>'measurement' AND value::numeric>row.kg)) THEN RETURN false; END IF;
 IF f='active' AND value<>'0' THEN RETURN false; END IF;
 IF a='measurement' AND f='kg' AND row.kind IN ('accept','complete','refuse','shortage','dispute','downstream_loss') AND (NOT downstream_named_destination_signed(p,row.id,'original_error') OR NOT downstream_named_destination_signed(p,row.id,'same_allocation')) THEN RETURN false; END IF;
 IF a='loss_confirm' AND row.kind IN ('refuse','shortage','dispute') AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(p.details->'revisions') change WHERE change->>'anchor_id'=ref::text AND change->>'fact'='kind' AND change->>'value'='downstream_loss') THEN RETURN false; END IF;
 ELSIF k='batch' THEN
 SELECT * INTO br FROM batch_records WHERE id=ref;IF to_jsonb(br)->>f IS NULL OR NOT EXISTS(SELECT 1 FROM batch_outputs WHERE measurement_record_id=ref AND batch_id=c.subject_id UNION ALL SELECT 1 FROM dismantling_batches WHERE subject_id=c.subject_id AND input_measurement_id=ref) OR a<>'measurement' OR br.batch_id IS DISTINCT FROM c.subject_id OR f NOT IN ('net_kg','gross_kg','tare_kg') OR value!~'^(0|[1-9][0-9]{0,8})(\.[0-9]{1,3})?$' OR (f='net_kg' AND value::numeric<=0) THEN RETURN false; END IF;
 IF f='net_kg' AND br.target_id<>c.subject_id AND value::numeric>br.net_kg AND p.details->'facts'->>'present_unallocated' IS DISTINCT FROM 'YES' AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(p.details->'revisions') change JOIN downstream_records dispatch ON dispatch.id=(change->>'anchor_id')::uuid WHERE change->>'anchor_kind'='downstream' AND change->>'fact'='kg' AND dispatch.kind='dispatch' AND dispatch.output_id=br.target_id AND (change->>'value')::numeric>dispatch.kg) THEN RETURN false; END IF;
 ELSIF k='discovery' THEN
 IF a<>'discovery_movement' OR f<>'movement' OR value<>'approved' OR NOT EXISTS(SELECT 1 FROM batch_media_discoveries d JOIN batch_records prod ON prod.target_id=d.medium_id WHERE d.medium_id=ref AND d.batch_id=c.subject_id AND prod.id=c.source_id AND prod.kind='output' AND NOT EXISTS(SELECT 1 FROM downstream_records sent WHERE sent.output_id=ref AND sent.kind='dispatch') AND NOT media_batch_unresolved(c.subject_id) AND sanitization_state(ref)='satisfied' AND NOT EXISTS(SELECT 1 FROM sanitization_current WHERE subject_id=ref AND destruction_reported)) THEN RETURN false; END IF;
 ELSE RETURN false; END IF;
 IF a NOT IN ('report_error','located','damage_error','damage_disposition','loss_error','loss_confirm','measurement','discovery_movement','completion_error') THEN RETURN false; END IF;
 END LOOP;
 IF a='downstream_continuity' AND EXISTS(SELECT 1 FROM restriction_impacts impact JOIN restriction_cases origin_case ON origin_case.id=(SELECT case_id FROM restriction_records WHERE id=impact.record_id) JOIN downstream_records original ON original.id=origin_case.source_id WHERE impact.id=c.source_id AND origin_case.contract='P16' AND original.shipment_id IS NOT NULL AND NOT downstream_destination_signed(p,original.id)) THEN RETURN false; END IF;
 IF a='downstream_continuity' AND EXISTS(SELECT 1 FROM restriction_impacts impact JOIN downstream_records target ON target.event_id=impact.target_id OR target.physical_event_id=impact.target_id WHERE impact.id=c.source_id AND target.kind IN ('accept','complete') AND NOT downstream_destination_signed(p,target.id)) THEN RETURN false; END IF;
 IF a IN ('recipient','downstream_continuity','closure_review') THEN
 IF jsonb_array_length(p.details->'revisions')<>0 THEN RETURN false; END IF;
 ELSE IF jsonb_array_length(p.details->'revisions')=0 THEN RETURN false; END IF; END IF;
 IF a='recipient' THEN
 SELECT * INTO h FROM downstream_shipments WHERE created_record_id=c.source_id;SELECT * INTO oldpr FROM output_profile_records WHERE id=h.profile_record_id;SELECT * INTO pr FROM output_profile_records WHERE id=(p.details->>'profile_record_id')::uuid;
 IF h.id IS NULL OR pr.profile_id IS DISTINCT FROM oldpr.profile_id OR pr.facility_party_id<>oldpr.facility_party_id OR NOT pr.approved OR pr.id IS DISTINCT FROM (SELECT record_id FROM output_profiles WHERE id=pr.profile_id) OR downstream_reserved(h.id)<>0 OR NOT COALESCE((downstream_access((p.details->>'receiver_actor_id')::uuid,pr.facility_party_id)->>'eligible')::boolean,false) OR p.details->'receiver_context' IS DISTINCT FROM restriction_actor_context((p.details->>'receiver_actor_id')::uuid) THEN RETURN false; END IF;
 END IF;
 RETURN true;
END; $$;
CREATE FUNCTION downstream_validate_record(n restriction_records) RETURNS void LANGUAGE plpgsql AS $$
DECLARE c restriction_cases;p restriction_records;s record;signature restriction_records;required_keys text[]:=ARRAY['identity_known','quantities_evidenced','scope_known','history_preserved','no_invented_movement','supported_facts'];
BEGIN
 SELECT * INTO c FROM restriction_cases WHERE id=n.case_id FOR UPDATE;SELECT * INTO s FROM downstream_correction_sources() WHERE source_id=c.source_id AND fact_key=c.fact_key;
 IF c.id IS NULL OR s.source_id IS NULL OR NOT c.active OR n.version<>(CASE WHEN n.kind='open' THEN 1 ELSE c.version+1 END) OR n.predecessor_id IS DISTINCT FROM (CASE WHEN n.kind='open' THEN NULL::uuid ELSE c.record_id END) OR n.source_version<>c.source_version OR n.restriction_version<>restriction_epoch(c.subject_id) OR n.context IS DISTINCT FROM restriction_dependency_context(c.subject_id) OR n.actor_context IS DISTINCT FROM restriction_actor_context(n.actor_id) OR n.observed_at>clock_timestamp() OR n.observed_at<date_trunc('minute',GREATEST(s.source_time,CASE WHEN n.kind='open' THEN s.source_time ELSE c.created_at END)) OR n.recorded_at<n.observed_at THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Downstream case, source, scope or accounting changed. Reload the impact preview.'; END IF;
 IF n.kind NOT IN ('open','assign','revoke','continue','close','approve','fulfill','proposal','clarify','note') OR (n.kind IN ('open','assign','revoke','continue','close','approve','fulfill') AND NOT restriction_admin(n.actor_id)) OR (n.kind IN ('proposal','clarify') AND NOT downstream_case_access(c.id,n.actor_id)) OR (n.kind='note' AND NOT (restriction_admin(n.actor_id) OR downstream_case_access(c.id,n.actor_id))) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Use the assigned source/destination investigator or independent Administrator; no proxy facts are permitted.'; END IF;
 IF n.kind='assign' THEN
 IF EXISTS(SELECT 1 FROM unnest(n.allowed_evidence) allowed_id WHERE allowed_id IS DISTINCT FROM s.source_attachment_id) OR n.pre_receipt OR NOT downstream_review_scope(n.assigned_to,c.subject_id) OR (n.details ? 'party_actor_id' AND (NOT downstream_review_scope((n.details->>'party_actor_id')::uuid,c.subject_id) OR n.details->'party_context' IS DISTINCT FROM restriction_actor_context((n.details->>'party_actor_id')::uuid))) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Explicitly choose current capable source/destination investigators; no pre-receipt authority applies.'; END IF;
 ELSIF n.assigned_to IS NOT NULL OR n.pre_receipt OR n.allowed_evidence<>'{}'::uuid[] THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Only assignment can grant evidence access.'; END IF;
 IF n.attachment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attachments WHERE id=n.attachment_id AND uploaded_by=n.actor_id AND privacy_class='private' AND redacted_at IS NULL) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Use your own available private evidence.'; END IF;
 IF n.kind IN ('proposal','clarify','approve','fulfill') AND n.attachment_id IS NULL AND n.reference_ciphertext IS NULL THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Current accountable private evidence is required.'; END IF;
 IF n.kind IN ('proposal','clarify') THEN
 IF n.identity_code IS DISTINCT FROM (SELECT public_code FROM subjects WHERE id=c.subject_id) OR n.original_value<>c.original_value OR n.proposed_value NOT IN ('REVIEWED','UNKNOWN') OR NOT(n.checks ?& required_keys) OR (SELECT count(*) FROM jsonb_object_keys(n.checks))<>6 OR EXISTS(SELECT 1 FROM jsonb_each_text(n.checks) q WHERE COALESCE(q.value,'') NOT IN ('YES','NO','UNKNOWN')) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Use the exact downstream evidence template; unknown findings cannot relax restrictions.'; END IF;
 ELSIF n.kind IN ('approve','fulfill') THEN
 SELECT * INTO p FROM restriction_records WHERE id=n.proposal_id;
 IF p.case_id IS DISTINCT FROM c.id OR p.kind<>'proposal' OR n.observed_at<p.observed_at OR n.actor_id=p.actor_id OR p.context IS DISTINCT FROM n.context OR NOT COALESCE(downstream_vector_valid(c,p),false) OR EXISTS(SELECT 1 FROM restriction_records author WHERE author.case_id=c.id AND author.kind IN ('proposal','clarify') AND (author.actor_id=n.actor_id OR author.actor_context IS DISTINCT FROM restriction_actor_context(author.actor_id))) OR (n.kind='fulfill' AND (c.state<>'revalidation_pending' OR c.fact_key NOT IN ('downstream_downstream_continuity','downstream_closure_review'))) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Independent review needs current signed source/destination evidence and the exact complete supported accounting vector.'; END IF;
 END IF;
END; $$;
CREATE FUNCTION downstream_review_begin(n restriction_records) RETURNS void LANGUAGE plpgsql AS $$
DECLARE c restriction_cases;f record;t record;
BEGIN
 IF n.kind NOT IN ('approve','fulfill') THEN RETURN; END IF;SELECT * INTO c FROM restriction_cases WHERE id=n.case_id;
 IF c.fact_key IN ('downstream_downstream_continuity','downstream_closure_review') THEN
 IF c.fact_key='downstream_downstream_continuity' THEN INSERT INTO restriction_impact_reviews VALUES(n.id,c.source_id,CASE n.kind WHEN 'fulfill' THEN 'fulfilled' ELSE 'continuity' END,restriction_epoch(c.subject_id),downstream_obligation_context(c.subject_id,c.source_id)); END IF;
 RETURN;
 END IF;
 FOR f IN SELECT subject_id FROM restriction_family(c.subject_id) ORDER BY subject_id LOOP
 INSERT INTO restriction_epoch_steps VALUES(n.id,f.subject_id,restriction_epoch(f.subject_id)+1);
 INSERT INTO restriction_epochs VALUES(f.subject_id,1,n.id) ON CONFLICT(subject_id) DO UPDATE SET version=restriction_epochs.version+1,record_id=n.id;
 END LOOP;
 FOR t IN SELECT r.event_id target,c.subject_id subject FROM downstream_records r WHERE r.batch_id=c.subject_id AND r.kind IN ('dispatch','accept','complete','close')
 UNION SELECT r.physical_event_id,r.output_id FROM downstream_records r WHERE r.batch_id=c.subject_id AND r.physical_event_id IS NOT NULL LOOP
 INSERT INTO restriction_impacts VALUES(gen_random_uuid(),n.id,t.subject,'completed_dependency',t.target) ON CONFLICT DO NOTHING;
 END LOOP;
END; $$;
CREATE FUNCTION downstream_signature_context(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('review',restriction_actor_context(r.actor_id),'author',restriction_actor_context(p.actor_id),
 'parties',(SELECT COALESCE(jsonb_agg(jsonb_build_array(signature.id,restriction_actor_context(signature.actor_id)) ORDER BY signature.id),'[]'::jsonb) FROM jsonb_array_elements_text(COALESCE(p.details->'party_findings','[]'::jsonb)) cited JOIN restriction_records signature ON signature.id=cited::uuid))
 FROM restriction_records r LEFT JOIN restriction_records p ON p.id=r.proposal_id WHERE r.id=ref
$$;
CREATE FUNCTION downstream_obligation_context(ref uuid,impact uuid DEFAULT NULL) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('accounting',downstream_accounting_context(ref),
 'origin_signatures',(SELECT downstream_signature_context(i.record_id) FROM restriction_impacts i WHERE i.id=impact),
 'target',(SELECT jsonb_build_array(r.id,r.kind,r.kg,r.condition,r.outcome,r.related_record_id) FROM restriction_impacts i JOIN downstream_effective_records r ON r.event_id=i.target_id OR r.physical_event_id=i.target_id WHERE i.id=impact),
 'control',(SELECT jsonb_build_array(c.party_id,c.version,c.last_event_id,s.record_id,s.version) FROM restriction_impacts i JOIN downstream_records r ON r.event_id=i.target_id OR r.physical_event_id=i.target_id LEFT JOIN custody_current c ON c.subject_id=r.output_id LEFT JOIN sanitization_current s ON s.subject_id=r.output_id WHERE i.id=impact AND r.kind IN ('accept','complete') AND r.canonical),
 'origin_obligation',(SELECT jsonb_build_array(e.id,e.type,e.subject_id,b.commit_record_id,to_jsonb(b.inputs)) FROM restriction_impacts i JOIN domain_events e ON e.id=i.target_id JOIN dismantling_batches b ON b.subject_id=downstream_impact_batch(i) WHERE i.id=impact),
 'media_history',(SELECT jsonb_build_array(cc.party_id,cc.version,cc.last_event_id,cur.record_id,cur.version) FROM restriction_impacts i JOIN domain_events e ON e.id=i.target_id LEFT JOIN custody_current cc ON cc.subject_id=i.subject_id LEFT JOIN sanitization_current cur ON cur.subject_id=i.subject_id WHERE i.id=impact AND e.type='sanitization_attempt'),
 'closure_operations',CASE WHEN EXISTS(SELECT 1 FROM restriction_impacts i JOIN downstream_records r ON r.event_id=i.target_id WHERE i.id=impact AND r.kind='close') THEN (SELECT jsonb_agg(jsonb_build_array(id,kind,kg,condition,outcome) ORDER BY version) FROM downstream_effective_records WHERE batch_id=ref) END)
$$;
CREATE FUNCTION downstream_live_proof(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('dependencies',restriction_dependency_context(ref),'accounting',downstream_accounting_context(ref),'historical_closure',(SELECT closure_record_id FROM downstream_batches WHERE batch_id=ref),'assurance',(SELECT COALESCE(jsonb_agg(jsonb_build_array(o.subject_id,s.record_id,s.version) ORDER BY o.subject_id),'[]'::jsonb) FROM batch_outputs o LEFT JOIN sanitization_current s ON s.subject_id=o.subject_id WHERE o.batch_id=ref AND o.type='storage'))
$$;
CREATE FUNCTION downstream_fulfillment_ready(ref uuid) RETURNS boolean LANGUAGE plpgsql STABLE AS $$
DECLARE i restriction_impacts;e domain_events;r downstream_effective_records;canonical uuid;b uuid;data sanitization_records;
BEGIN
 SELECT * INTO i FROM restriction_impacts WHERE id=ref;SELECT * INTO e FROM domain_events WHERE id=i.target_id;
 SELECT * INTO r FROM downstream_effective_records WHERE event_id=e.id OR physical_event_id=e.id;
 IF r.id IS NULL THEN
 b:=downstream_impact_batch(i);
 IF e.type IN ('batch_commit','dismantled_in_batch','media_detached_in_batch') THEN
 RETURN b IS NOT NULL AND NOT media_batch_unresolved(b) AND EXISTS(SELECT 1 FROM dismantling_batches batch JOIN batch_records original ON original.id=batch.commit_record_id WHERE batch.subject_id=b AND original.kind='commit') AND NOT EXISTS(SELECT 1 FROM batch_members member JOIN items item ON item.subject_id=member.subject_id WHERE member.batch_id=b AND item.processing_state<>'dismantled');
 ELSIF e.type='sanitization_attempt' THEN
 SELECT record.* INTO data FROM sanitization_current current JOIN sanitization_records record ON record.id=current.record_id WHERE current.subject_id=i.subject_id;
 RETURN data.kind IN ('validation','revalidation') AND data.outcome='accepted' AND sanitization_dependencies(data) AND (SELECT version FROM sanitization_records WHERE id=data.attempt_id)>=(SELECT version FROM sanitization_records WHERE event_id=e.id) AND NOT EXISTS(SELECT 1 FROM sanitization_current WHERE subject_id=i.subject_id AND destruction_reported);
 END IF;RETURN false;END IF;
 IF r.kind='dispatch' THEN RETURN r.kg>0 AND downstream_remaining(r.id)>=0;
 ELSIF r.kind='accept' THEN
 IF r.canonical THEN RETURN EXISTS(SELECT 1 FROM custody_current c JOIN downstream_effective_shipments h ON h.id=r.shipment_id JOIN output_profile_records p ON p.id=h.profile_record_id WHERE c.subject_id=r.output_id AND c.party_id=p.facility_party_id AND downstream_profile_ready(h.id)); END IF;
 RETURN downstream_profile_ready(r.shipment_id) AND downstream_remaining(r.id)>=0;
 ELSIF r.kind='complete' THEN
 IF r.kg=0 THEN RETURN EXISTS(SELECT 1 FROM downstream_fact_revisions revoked JOIN downstream_review_effects proof ON proof.record_id=revoked.review_id WHERE revoked.anchor_kind='downstream' AND revoked.anchor_id=r.id AND revoked.fact='active' AND revoked.value='0' AND proof.action='completion_error' AND downstream_proof_current(proof.record_id)); END IF;
 RETURN downstream_profile_ready(r.shipment_id) AND (NOT r.canonical OR sanitization_state(r.output_id)='satisfied');
 ELSIF r.kind='close' THEN RETURN downstream_outcomes_ready(r.batch_id);
 END IF;RETURN false;
END; $$;
CREATE FUNCTION downstream_review_final(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE c restriction_cases;r restriction_records;last restriction_records;e downstream_review_effects;seq bigint:=0;fact record;br batch_records;v record;out batch_outputs;raw downstream_records;expected numeric;
BEGIN
 SELECT * INTO c FROM restriction_cases WHERE id=ref;
 FOR r IN SELECT * FROM restriction_records WHERE case_id=ref ORDER BY version LOOP
 seq:=seq+1;
 IF r.version<>seq OR r.predecessor_id IS DISTINCT FROM last.id OR NOT EXISTS(SELECT 1 FROM commands cmd JOIN domain_events ev ON ev.command_id=cmd.id WHERE cmd.id=r.command_id AND cmd.actor_id=r.actor_id AND cmd.status='completed' AND cmd.action='restriction_'||r.kind||':'||CASE WHEN r.kind='open' THEN c.source_kind||':'||c.source_id::text||':'||c.fact_key ELSE c.id::text END AND cmd.safe_response->>'record_id'=c.id::text AND ev.id=r.event_id AND ev.actor_id=r.actor_id AND ev.aggregate_kind='restriction' AND ev.aggregate_id=c.id AND ev.aggregate_version=r.version AND ev.type='restriction_'||r.kind AND ev.occurred_at=r.observed_at AND ev.recorded_at=r.recorded_at) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Downstream review requires consecutive reciprocal command/event history.'; END IF;
 IF r.kind IN ('approve','fulfill') THEN
 SELECT * INTO e FROM downstream_review_effects WHERE record_id=r.id;
 IF e.record_id IS NULL OR e.case_id<>c.id OR e.proposal_id IS DISTINCT FROM r.proposal_id OR e.action<>substring(c.fact_key FROM 12) OR e.observer_id IS DISTINCT FROM (SELECT actor_id FROM restriction_records WHERE id=r.proposal_id) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Downstream approval requires exact reciprocal effects.'; END IF;
 END IF;last:=r;
 END LOOP;
 IF c.version<>seq OR c.record_id IS DISTINCT FROM last.id OR c.state<>downstream_case_state(c,last) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Downstream case projection must retain its actual outcome.'; END IF;
 PERFORM downstream_integrity(c.subject_id);
END; $$;
CREATE FUNCTION downstream_review_boundary(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE r restriction_records;p restriction_records;c restriction_cases;e downstream_review_effects;v jsonb;count_expected int;stock record;after_stock jsonb;has_presence boolean;
BEGIN
 SELECT * INTO r FROM restriction_records WHERE id=ref;SELECT * INTO c FROM restriction_cases WHERE id=r.case_id;
 IF r.kind IN ('proposal','clarify') AND (r.actor_context IS DISTINCT FROM restriction_actor_context(r.actor_id) OR NOT downstream_case_access(c.id,r.actor_id)) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Technical signature changed before COMMIT.'; END IF;
 IF r.kind NOT IN ('approve','fulfill') THEN RETURN; END IF;
 SELECT * INTO p FROM restriction_records WHERE id=r.proposal_id;SELECT * INTO e FROM downstream_review_effects WHERE record_id=r.id;
 IF NOT downstream_proof_current(r.id) OR e.context IS DISTINCT FROM downstream_live_proof(c.subject_id) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Reviewed effects, signatures or dependencies changed before COMMIT.'; END IF;
 count_expected:=jsonb_array_length(p.details->'revisions');
 IF (SELECT count(*) FROM downstream_fact_revisions WHERE review_id=r.id)<>count_expected THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Every approved fact must be applied atomically, exactly once.'; END IF;
 FOR v IN SELECT * FROM jsonb_array_elements(p.details->'revisions') LOOP
 IF NOT EXISTS(SELECT 1 FROM downstream_fact_revisions f WHERE f.review_id=r.id AND f.anchor_kind=v->>'anchor_kind' AND f.anchor_id=(v->>'anchor_id')::uuid AND f.fact=v->>'fact' AND f.value=v->>'value' AND f.version=(v->>'expected_version')::bigint+1 AND f.predecessor_id IS NOT DISTINCT FROM (v->>'predecessor_id')::uuid) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Reviewed revision differs from its exact signed proposal.'; END IF;
 END LOOP;
 IF r.kind='approve' AND c.fact_key='downstream_measurement' THEN
 has_presence:=downstream_party(c.subject_id,p.actor_id)='source' AND p.details->'facts'->>'present_unallocated'='YES';
 has_presence:=has_presence OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(p.details->'party_findings') cited JOIN restriction_records signature ON signature.id=cited::uuid WHERE downstream_party(c.subject_id,signature.actor_id)='source' AND signature.details->'facts'->>'present_unallocated'='YES');
 FOR stock IN SELECT * FROM jsonb_each((p.details->>'before_stock')::jsonb) LOOP
 after_stock:=downstream_stock(stock.key::uuid);
 IF (after_stock->>'reserved')::numeric<>0 OR ((after_stock->>'reservable')::numeric>(stock.value->>'reservable')::numeric AND NOT COALESCE(has_presence,false)) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Increased available stock requires current source evidence of the same production physically present and unallocated; withdraw reservations first.'; END IF;
 END LOOP;
 END IF;
 IF c.fact_key='downstream_recipient' AND NOT EXISTS(SELECT 1 FROM downstream_recipient_records WHERE review_id=r.id AND kind='proposed') THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Recipient review requires its proposed responsibility generation.'; END IF;
 IF r.kind='fulfill' AND ((c.fact_key='downstream_downstream_continuity' AND NOT downstream_fulfillment_ready(c.source_id)) OR (c.fact_key='downstream_closure_review' AND NOT downstream_outcomes_ready(c.subject_id))) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Exact current outcome/closure obligations remain incomplete.'; END IF;
END; $$;
CREATE FUNCTION downstream_fact_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prior downstream_fact_revisions;c restriction_cases;r restriction_records;p restriction_records;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Reviewed facts are append-only.'; END IF;
 SELECT * INTO prior FROM downstream_fact_revisions WHERE anchor_kind=NEW.anchor_kind AND anchor_id=NEW.anchor_id AND fact=NEW.fact ORDER BY version DESC LIMIT 1;
 SELECT * INTO r FROM restriction_records WHERE id=NEW.review_id;SELECT * INTO p FROM restriction_records WHERE id=r.proposal_id;SELECT * INTO c FROM restriction_cases WHERE id=r.case_id;
 IF NEW.version<>COALESCE(prior.version,0)+1 OR NEW.predecessor_id IS DISTINCT FROM prior.id THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='The reviewed fact predecessor/version changed.'; END IF;
 IF c.contract IS DISTINCT FROM 'P16' OR r.kind IS DISTINCT FROM 'approve' THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='The fact must reference an approved P16 review.'; END IF;
 IF NOT EXISTS(SELECT 1 FROM jsonb_array_elements(p.details->'revisions') v WHERE v->>'anchor_kind'=NEW.anchor_kind AND v->>'anchor_id'=NEW.anchor_id::text AND v->>'fact'=NEW.fact AND v->>'value'=NEW.value AND (v->>'expected_version')::bigint+1=NEW.version) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='A fact needs its exact newly approved consecutive revision; history cannot be replayed.'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER downstream_fact_valid BEFORE INSERT OR UPDATE OR DELETE ON downstream_fact_revisions FOR EACH ROW EXECUTE FUNCTION downstream_fact_guard();
CREATE FUNCTION downstream_effect_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' OR NOT EXISTS(SELECT 1 FROM restriction_records r JOIN restriction_cases c ON c.id=r.case_id JOIN restriction_records p ON p.id=r.proposal_id WHERE r.id=NEW.record_id AND c.id=NEW.case_id AND c.contract='P16' AND p.id=NEW.proposal_id AND p.actor_id=NEW.observer_id AND r.kind IN ('approve','fulfill') AND NEW.action=substring(c.fact_key FROM 12)) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Exact independent downstream approval and effects are required.'; END IF;RETURN NEW;
END; $$;
CREATE TRIGGER downstream_effect_valid BEFORE INSERT OR UPDATE OR DELETE ON downstream_review_effects FOR EACH ROW EXECUTE FUNCTION downstream_effect_guard();
CREATE FUNCTION downstream_correction_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid;
BEGIN
 SELECT case_id INTO ref FROM downstream_review_effects WHERE record_id=COALESCE((to_jsonb(NEW)->>'review_id')::uuid,(to_jsonb(NEW)->>'record_id')::uuid);
 IF ref IS NOT NULL THEN PERFORM downstream_review_final(ref); END IF;RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER downstream_fact_complete AFTER INSERT ON downstream_fact_revisions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION downstream_correction_final();
CREATE CONSTRAINT TRIGGER downstream_effect_complete AFTER INSERT ON downstream_review_effects DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION downstream_correction_final();
