-- Pending continuity permits only fresh evidence, never physical work or movement.
CREATE FUNCTION media_fresh_for(ref uuid,operation text) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT media_recovery_allowed(ref,operation) AND (restriction_epoch(ref)=0 OR EXISTS(SELECT 1 FROM assessment_current ac WHERE ac.subject_id=ref AND restriction_record_current('assessments',ac.assessment_id,ref)))
$$;
CREATE OR REPLACE FUNCTION media_parent_ready(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT media_parent_ready_before_restriction(ref,actor) AND media_fresh_for(ref,'inventory') $$;
CREATE OR REPLACE FUNCTION media_record_applicable(r media_inventory_records) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT media_record_applicable_before_restriction(r) AND (NOT EXISTS(SELECT 1 FROM media_inventory_records stored WHERE stored.id=r.id) OR restriction_record_current('media_inventory_records',r.id,r.parent_id)) AND media_recovery_allowed(r.parent_id,'inventory')
$$;
CREATE FUNCTION media_work_basis(ref uuid) RETURNS uuid LANGUAGE sql STABLE AS $$
 SELECT id FROM refurbishment_records WHERE subject_id=ref AND kind IN ('work','work_applicability') ORDER BY version DESC LIMIT 1
$$;
CREATE OR REPLACE FUNCTION refurbishment_work_ready(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM refurbishment_records b JOIN refurbishment_records w ON w.id=CASE b.kind WHEN 'work_applicability' THEN b.work_id ELSE b.id END JOIN custody_current cc ON cc.subject_id=w.subject_id
 WHERE b.id=media_work_basis(ref) AND w.id=(SELECT id FROM refurbishment_records WHERE subject_id=ref AND kind='work' ORDER BY version DESC LIMIT 1)
 AND media_work_value(w.id,'work_outcome')='COMPLETED' AND media_work_value(w.id,'work_configuration')='UNCHANGED'
 AND (b.kind='work' OR (b.context->'configuration'=refurbishment_configuration(ref) AND restriction_record_current('refurbishment_records',b.id,ref)))
 AND EXISTS(SELECT 1 FROM jsonb_array_elements(w.context->'configuration'->'manifest') source WHERE (source->>'subject_id')::uuid=ref AND (source->>'party_id')::uuid=cc.party_id AND (source->>'event_id')::uuid=cc.last_event_id AND (source->>'custody_version')::bigint=cc.version))
$$;
CREATE OR REPLACE FUNCTION refurbishment_test_ready_before_restriction(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM refurbishment_records t WHERE t.id=(SELECT id FROM refurbishment_records WHERE subject_id=ref AND kind='test' ORDER BY version DESC LIMIT 1)
 AND t.result='passed' AND t.work_id=media_work_basis(ref) AND t.context->'configuration'=refurbishment_configuration(ref) AND refurbishment_work_ready(ref))
$$;
CREATE FUNCTION media_refurbishment_ready(ref uuid,actor uuid,operation text) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT refurbishment_ready_before_restriction(ref,actor) AND media_fresh_for(ref,operation)
$$;
CREATE OR REPLACE FUNCTION refurbishment_ready(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT media_refurbishment_ready(ref,actor,'progress') $$;
CREATE FUNCTION media_work_applicability_guard(n refurbishment_records) RETURNS void LANGUAGE plpgsql AS $$
DECLARE w refurbishment_records; r restriction_records;c restriction_cases;
BEGIN
 SELECT * INTO w FROM refurbishment_records WHERE subject_id=n.subject_id AND kind='work' ORDER BY version DESC LIMIT 1;
 SELECT * INTO r FROM restriction_records WHERE id=n.correction_review_id AND kind='approve';SELECT * INTO c FROM restriction_cases WHERE id=r.case_id;
 IF n.work_id IS DISTINCT FROM w.id OR n.result<>'applicable' OR n.configuration<>'UNCHANGED' OR c.contract IS DISTINCT FROM 'P15' OR c.subject_id IS DISTINCT FROM n.subject_id
 OR NOT EXISTS(SELECT 1 FROM media_review_commits WHERE record_id=r.id) OR NOT media_refurbishment_ready(n.subject_id,n.actor_id,'work_applicability') OR NOT refurbishment_work_ready(n.subject_id)
 OR NOT refurbishment_data_ready(n.subject_id) OR n.answers<>'{}'::jsonb OR n.authorization_id IS NOT NULL OR n.profile_version IS NOT NULL
 THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Retained work needs a supported exact correction, fresh inspection/decision, live data gates and unchanged actual configuration; this records no repair.'; END IF;
END; $$;
CREATE FUNCTION media_assurance_scope(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT sanitization_ready_before_restriction(ref,actor) AND media_recovery_allowed(ref,'assurance_review')
 AND (EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=ref) OR EXISTS(SELECT 1 FROM batch_media_discoveries WHERE medium_id=ref)
 OR media_fresh_for(COALESCE((SELECT parent_id FROM media_authorizations WHERE id=(SELECT record_id FROM media_authorization_current WHERE subject_id=ref)),ref),'assurance_review'))
$$;
CREATE FUNCTION media_sanitization_ready(ref uuid,actor uuid,operation text) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT media_assurance_scope(ref,actor) AND media_recovery_allowed(ref,CASE operation WHEN 'attempt' THEN 'treatment' ELSE 'assurance_review' END)
$$;
CREATE OR REPLACE FUNCTION sanitization_ready(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT media_sanitization_ready(ref,actor,'attempt') $$;
CREATE OR REPLACE FUNCTION sanitization_dependencies(r sanitization_records) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT sanitization_dependencies_before_restriction(r) AND restriction_record_current('sanitization_records',r.id,r.subject_id) AND media_recovery_allowed(r.subject_id,'assurance_review')
$$;
CREATE OR REPLACE FUNCTION media_authorization_applicable(a media_authorizations) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT media_authorization_applicable_before_restriction(a) AND (NOT EXISTS(SELECT 1 FROM media_authorizations stored WHERE stored.id=a.id) OR restriction_record_current('media_authorizations',a.id,a.subject_id))
 AND media_recovery_allowed(a.subject_id,'authorization') AND NOT restriction_receipt_blocked(a.subject_id) AND NOT restriction_inspection_blocked(a.subject_id)
$$;
