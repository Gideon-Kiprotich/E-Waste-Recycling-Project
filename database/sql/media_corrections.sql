ALTER TABLE restriction_cases ADD COLUMN contract text NOT NULL DEFAULT 'C14' CHECK(contract IN ('C14','P15'));
ALTER TABLE restriction_records ADD COLUMN details jsonb NOT NULL DEFAULT '{}',ADD COLUMN private_details_ciphertext text;
ALTER TABLE restriction_cases DROP CONSTRAINT restriction_cases_state_check, DROP CONSTRAINT restriction_cases_check;
ALTER TABLE restriction_cases ADD CHECK(state IN ('open','under_review','closed_no_change','superseded','provenance_supplement_recorded','revalidation_pending','obligation_fulfilled')),
 ADD CHECK(active=(state IN ('open','under_review','revalidation_pending')));
ALTER TABLE restriction_records DROP CONSTRAINT restriction_records_kind_check;
ALTER TABLE restriction_records ADD CHECK(kind IN ('open','assign','revoke','clarify','note','proposal','continue','close','approve','owner_clarify','fulfill'));
DO $$ DECLARE c record; BEGIN
 FOR c IN SELECT conname FROM pg_constraint WHERE conrelid='restriction_records'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%proposal_id IS NOT NULL%' LOOP EXECUTE format('ALTER TABLE restriction_records DROP CONSTRAINT %I',c.conname); END LOOP;
END; $$;
ALTER TABLE restriction_records ADD CHECK((kind IN ('approve','fulfill'))=(proposal_id IS NOT NULL));

CREATE TABLE media_review_effects (
 record_id uuid PRIMARY KEY REFERENCES restriction_records(id),case_id uuid NOT NULL REFERENCES restriction_cases(id),
 proposal_id uuid NOT NULL REFERENCES restriction_records(id),observer_id uuid NOT NULL REFERENCES users(id),
 action text NOT NULL CHECK(action IN ('association_confirm','association_end','discovery_error','discovery_supplement','work_configuration','work_outcome','return_report','impact_continuity') OR action LIKE 'test\_%'),
 refs jsonb NOT NULL DEFAULT '{}'
);
CREATE TABLE media_review_commits (record_id uuid PRIMARY KEY REFERENCES restriction_records(id),context jsonb NOT NULL);
CREATE TABLE restriction_impact_reviews (
 record_id uuid PRIMARY KEY REFERENCES restriction_records(id),impact_id uuid NOT NULL REFERENCES restriction_impacts(id),
 stage text NOT NULL CHECK(stage IN ('continuity','fulfilled')),epoch bigint NOT NULL CHECK(epoch>=0),context jsonb NOT NULL
);
CREATE TABLE batch_media_discoveries (
 id uuid PRIMARY KEY,review_id uuid NOT NULL REFERENCES restriction_records(id),proposal_id uuid NOT NULL REFERENCES restriction_records(id),
 discrepancy_id uuid NOT NULL REFERENCES batch_records(id),batch_id uuid NOT NULL REFERENCES dismantling_batches(subject_id),
 parent_id uuid NOT NULL REFERENCES batch_members(subject_id),medium_id uuid NOT NULL UNIQUE REFERENCES storage_assets(subject_id),
 unit_count integer NOT NULL CHECK(unit_count BETWEEN 1 AND 10),is_new boolean NOT NULL,
 mode text NOT NULL CHECK(mode IN ('unaccounted','reclassified')),source_output_id uuid REFERENCES batch_outputs(subject_id),
 production_id uuid NOT NULL UNIQUE REFERENCES batch_records(id),measurement_id uuid REFERENCES batch_records(id),remaining_measurement_id uuid REFERENCES batch_records(id),
 observer_id uuid NOT NULL REFERENCES users(id),observed_at timestamptz NOT NULL,recorded_at timestamptz NOT NULL,
 CHECK((mode='reclassified')=(source_output_id IS NOT NULL)),CHECK(mode<>'reclassified' OR (measurement_id IS NOT NULL AND remaining_measurement_id IS NOT NULL)),
 UNIQUE(discrepancy_id,id)
);
ALTER TABLE media_origins ADD COLUMN origin_kind text NOT NULL DEFAULT 'embedded_inventory' CHECK(origin_kind IN ('embedded_inventory','batch_discovery')),
 ADD COLUMN discovery_id uuid UNIQUE REFERENCES batch_media_discoveries(id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE media_origins ALTER COLUMN entry_id DROP NOT NULL,ALTER COLUMN record_id DROP NOT NULL;
ALTER TABLE media_origins ADD CHECK((origin_kind='embedded_inventory' AND entry_id IS NOT NULL AND record_id IS NOT NULL AND discovery_id IS NULL)
 OR (origin_kind='batch_discovery' AND entry_id IS NULL AND record_id IS NULL AND discovery_id IS NOT NULL));
ALTER TABLE media_inventory_entries DROP CONSTRAINT media_inventory_entries_medium_id_key;
CREATE UNIQUE INDEX media_one_effective_claim ON media_inventory_entries(medium_id) WHERE medium_id IS NOT NULL AND state IN ('confirmed','disputed');
ALTER TABLE media_inventory_entries DROP CONSTRAINT media_inventory_entries_state_check;
ALTER TABLE media_inventory_entries ADD CHECK(state IN ('confirmed','unresolved','not_present','disputed','association_superseded'));
DO $$ DECLARE c record; BEGIN
 FOR c IN SELECT conname FROM pg_constraint WHERE conrelid='media_inventory_entries'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%medium_id IS NOT NULL%' LOOP EXECUTE format('ALTER TABLE media_inventory_entries DROP CONSTRAINT %I',c.conname); END LOOP;
END; $$;
ALTER TABLE media_inventory_entries ADD CHECK((medium_id IS NOT NULL AND state IN ('confirmed','disputed','association_superseded')) OR (medium_id IS NULL AND state IN ('unresolved','not_present')));
ALTER TABLE media_inventory_records DROP CONSTRAINT media_inventory_records_kind_check, DROP CONSTRAINT media_inventory_records_entry_state_check;
ALTER TABLE media_inventory_records ADD COLUMN correction_review_id uuid REFERENCES restriction_records(id),ADD COLUMN observer_id uuid REFERENCES users(id),
 ADD CHECK(kind IN ('identify','link','unresolved','correct','attest','review_binding')),ADD CHECK(entry_state IN ('confirmed','unresolved','not_present','disputed','association_superseded')),
 ADD CHECK((kind='review_binding')=(correction_review_id IS NOT NULL));
ALTER TABLE batch_records ADD COLUMN correction_review_id uuid REFERENCES restriction_records(id),ADD COLUMN observer_id uuid REFERENCES users(id);
ALTER TABLE refurbishment_records ADD COLUMN correction_review_id uuid REFERENCES restriction_records(id);
DO $$ DECLARE c record; BEGIN
 FOR c IN SELECT conname FROM pg_constraint WHERE conrelid='refurbishment_records'::regclass AND contype='c' AND (pg_get_constraintdef(oid) LIKE '%kind%' OR pg_get_constraintdef(oid) LIKE '%result%') LOOP EXECUTE format('ALTER TABLE refurbishment_records DROP CONSTRAINT %I',c.conname); END LOOP;
END; $$;
ALTER TABLE refurbishment_records ADD CHECK(kind IN ('work','test','fan_na','authorize','withdraw','exception','acknowledge','work_applicability')),
 ADD CHECK(result IN ('completed','failed','incomplete','passed','not_applicable','pending','withdrawn','exception','acknowledged','applicable')),
 ADD CHECK((kind='work' AND result IN ('completed','failed','incomplete') AND configuration IS NOT NULL)
 OR (kind='test' AND result IN ('passed','failed','incomplete') AND profile_version IS NOT NULL AND work_id IS NOT NULL)
 OR (kind='fan_na' AND result='not_applicable') OR (kind='authorize' AND result='pending')
 OR (kind='withdraw' AND result='withdrawn' AND authorization_id IS NOT NULL)
 OR (kind='exception' AND result='exception' AND authorization_id IS NOT NULL)
 OR (kind='acknowledge' AND result='acknowledged' AND authorization_id IS NOT NULL)
 OR (kind='work_applicability' AND result='applicable' AND configuration='UNCHANGED' AND work_id IS NOT NULL AND correction_review_id IS NOT NULL));

CREATE VIEW media_current_bindings AS SELECT * FROM media_inventory_entries WHERE state<>'association_superseded';
CREATE FUNCTION media_review_action(kind text) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
 SELECT kind IN ('association_confirm','association_end','discovery_error','discovery_supplement','work_configuration','work_outcome','return_report','impact_continuity') OR kind LIKE 'test\_%'
$$;
CREATE FUNCTION media_review_value(kind text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
 SELECT CASE kind WHEN 'association_confirm' THEN 'CONFIRMED' WHEN 'association_end' THEN 'ASSOCIATION_SUPERSEDED'
 WHEN 'discovery_error' THEN 'NO_ADDITIONAL_MEDIUM' WHEN 'discovery_supplement' THEN 'PROVENANCE_SUPPLEMENT'
 WHEN 'work_configuration' THEN 'UNCHANGED' WHEN 'work_outcome' THEN 'COMPLETED' WHEN 'return_report' THEN 'REPORT_ERROR_NO_HANDOVER'
 WHEN 'impact_continuity' THEN 'CONTINUITY_REVIEWED' ELSE CASE WHEN kind LIKE 'test\_%' THEN 'PASS' END END
$$;
CREATE FUNCTION media_correction_sources() RETURNS TABLE(source_kind text,source_id uuid,subject_id uuid,fact_key text,original_value text,supported boolean,source_event_id uuid,source_version bigint,source_actor_id uuid,source_time timestamptz,source_attachment_id uuid,request_id uuid,currently_blocking boolean) LANGUAGE sql STABLE AS $$
 SELECT 'media_correction',r.id,r.parent_id,k,r.entry_state,true,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid,e.record_id=r.id AND e.state='disputed'
 FROM media_inventory_records r JOIN media_inventory_entries e ON e.id=r.entry_id CROSS JOIN (VALUES('association_confirm'),('association_end')) kinds(k) WHERE r.entry_state='disputed'
 UNION ALL SELECT 'media_correction',r.id,r.batch_id,k,'unexpected_media',true,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid,
 NOT EXISTS(SELECT 1 FROM restriction_supersessions s WHERE s.source_kind='media_correction' AND s.source_id=r.id AND s.fact_key IN ('discovery_error','discovery_supplement'))
 FROM batch_records r CROSS JOIN (VALUES('discovery_error'),('discovery_supplement')) kinds(k) WHERE r.kind='discrepancy'
 UNION ALL SELECT 'media_correction',r.id,r.subject_id,'work_configuration',r.configuration,true,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid,true FROM refurbishment_records r WHERE r.kind='work' AND r.configuration IN ('CHANGED','UNKNOWN')
 UNION ALL SELECT 'media_correction',r.id,r.subject_id,'work_outcome',upper(r.result),true,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid,true FROM refurbishment_records r WHERE r.kind='work' AND r.result IN ('failed','incomplete')
 UNION ALL SELECT 'media_correction',r.id,r.subject_id,'test_'||q.key,q.value,true,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid,true FROM refurbishment_records r CROSS JOIN LATERAL jsonb_each_text(r.answers) q WHERE r.kind='test' AND q.value IN ('FAIL','UNKNOWN')
 UNION ALL SELECT 'media_correction',r.id,r.subject_id,'return_report','exception',true,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid,true FROM refurbishment_records r WHERE r.kind='exception'
 UNION ALL SELECT 'media_correction',i.id,i.subject_id,'impact_continuity',i.type,true,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid,true FROM restriction_impacts i JOIN restriction_records r ON r.id=i.record_id
$$;
CREATE FUNCTION media_source_scope(kind text,ref uuid) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN kind IN ('inventory','media_correction') AND EXISTS(SELECT 1 FROM media_inventory_records WHERE id=ref AND entry_id IS NOT NULL) THEN 'entry:'||(SELECT entry_id FROM media_inventory_records WHERE id=ref)::text
 WHEN kind IN ('work','media_correction') AND EXISTS(SELECT 1 FROM refurbishment_records WHERE id=ref) THEN 'work:'||ref::text
 WHEN kind IN ('batch','media_correction') AND EXISTS(SELECT 1 FROM batch_records WHERE id=ref AND batch_records.kind='discrepancy') THEN 'discovery:'||ref::text
 WHEN kind IN ('dependent','media_correction') AND EXISTS(SELECT 1 FROM restriction_impacts WHERE id=ref) THEN 'impact:'||ref::text ELSE kind||':'||ref::text END
$$;
CREATE FUNCTION media_case_state(c restriction_cases,r restriction_records) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT CASE r.kind WHEN 'close' THEN 'closed_no_change' WHEN 'fulfill' THEN 'obligation_fulfilled'
 WHEN 'approve' THEN CASE c.fact_key WHEN 'impact_continuity' THEN 'revalidation_pending' WHEN 'discovery_supplement' THEN 'provenance_supplement_recorded' ELSE 'superseded' END
 WHEN 'open' THEN 'open' ELSE CASE WHEN c.fact_key='impact_continuity' AND EXISTS(SELECT 1 FROM restriction_records WHERE case_id=c.id AND kind='approve') THEN 'revalidation_pending' ELSE 'under_review' END END
$$;
CREATE FUNCTION media_review_scope(actor uuid,ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(
 SELECT 1 FROM users u JOIN memberships m ON m.user_id=u.id
 JOIN places l ON l.id=m.place_id AND l.organization_id=m.organization_id
 JOIN organizations o ON o.id=l.organization_id JOIN parties p ON p.place_id=l.id
 WHERE u.id=actor AND u.status='active' AND m.active AND m.role='processor'
 AND l.kind='facility' AND l.active AND l.review_status='approved'
 AND o.review_status='approved' AND o.kind IN ('processor','both')
 AND (
   EXISTS(SELECT 1 FROM items i JOIN categories c ON c.id=i.category_id JOIN custody_current cc ON cc.subject_id=i.subject_id
   WHERE i.subject_id=ref AND cc.party_id=p.id AND (
     EXISTS(SELECT 1 FROM place_categories cap WHERE cap.place_id=l.id AND cap.category_id=i.category_id AND cap.subtype=COALESCE(i.subtype,''))
     OR (c.code='processor_media' AND i.subtype IN ('hdd','ssd') AND EXISTS(
       SELECT 1 FROM place_categories cap JOIN categories supported ON supported.id=cap.category_id
       WHERE cap.place_id=l.id AND supported.code='standalone_part' AND cap.subtype=i.subtype))
     OR (c.code='processor_media' AND i.subtype='other_nonvolatile' AND EXISTS(
       SELECT 1 FROM media_origins origin JOIN items parent ON parent.subject_id=origin.parent_id
       JOIN custody_current parent_custody ON parent_custody.subject_id=parent.subject_id
       JOIN place_categories parent_cap ON parent_cap.place_id=l.id AND parent_cap.category_id=parent.category_id AND parent_cap.subtype=COALESCE(parent.subtype,'')
       WHERE origin.subject_id=i.subject_id AND origin.origin_kind='embedded_inventory'
       AND parent_custody.party_id=p.id AND parent.owner_user_id=i.owner_user_id))
   ))
   OR EXISTS(SELECT 1 FROM dismantling_batches b WHERE b.subject_id=ref AND b.facility_party_id=p.id
     AND NOT EXISTS(SELECT 1 FROM batch_members bm JOIN items bi ON bi.subject_id=bm.subject_id
       WHERE bm.batch_id=ref AND NOT EXISTS(SELECT 1 FROM place_categories cap
         WHERE cap.place_id=l.id AND cap.category_id=bi.category_id AND cap.subtype=COALESCE(bi.subtype,'')))
     AND NOT EXISTS(SELECT 1 FROM (SELECT subject_id FROM batch_detachments WHERE batch_id=ref UNION SELECT medium_id FROM batch_media_discoveries WHERE batch_id=ref) controlled JOIN items di ON di.subject_id=controlled.subject_id
       WHERE true AND NOT EXISTS(SELECT 1 FROM place_categories cap JOIN categories dc ON dc.id=cap.category_id
         WHERE cap.place_id=l.id AND dc.code='standalone_part' AND cap.subtype=di.subtype)))
 ))
$$;
