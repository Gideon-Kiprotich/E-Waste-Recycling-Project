CREATE VIEW restriction_sources AS
 SELECT 'receipt'::text source_kind,d.id source_id,ri.item_id subject_id,d.kind fact_key,d.kind original_value,
 true supported,v.event_id source_event_id,v.request_version source_version,v.actor_id source_actor_id,v.observed_at source_time,v.attachment_id source_attachment_id,
 r.id request_id
 FROM receipt_discrepancies d JOIN receipt_outcomes ro ON ro.id=d.outcome_id JOIN receipt_visits v ON v.id=ro.visit_id
 JOIN request_items ri ON ri.id=ro.request_item_id JOIN requests r ON r.id=ri.request_id WHERE d.kind IN ('mismatch','safety_hold')
 UNION ALL
 SELECT 'inspection',a.id,a.subject_id,q.question_code,q.value,true,a.event_id,a.version,a.actor_id,a.observed_at,a.attachment_id,NULL::uuid
 FROM assessments a JOIN assessment_answers q ON q.assessment_id=a.id WHERE a.hard_hold AND
 ((q.question_code IN ('safety_concern','charging_risk','battery_risk','thermal_issue') AND q.value IN ('YES','UNKNOWN'))
 OR (q.question_code='media_identified' AND q.value IN ('NO','UNKNOWN'))
 OR (q.question_code IN ('fan_voltage','fan_size') AND q.value IN ('OUTSIDE','UNKNOWN'))
 OR (q.question_code='fan_application' AND q.value IN ('OTHER','UNKNOWN')) OR (q.question_code='fan_electronics' AND q.value IN ('ADDITIONAL','UNKNOWN')))
 UNION ALL
 SELECT 'handover',oc.id,hi.subject_id,oc.outcome,oc.outcome,false,op.event_id,op.version,op.actor_id,op.observed_at,op.attachment_id,NULL::uuid
 FROM handover_outcomes oc JOIN handover_operations op ON op.id=oc.operation_id JOIN handover_items hi ON hi.id=oc.handover_item_id WHERE hi.active AND hi.state='disputed' AND oc.outcome IN ('missing','damaged','mismatch','refused')
 UNION ALL
 SELECT 'inventory',e.id,e.parent_id,e.state,e.state,false,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid
 FROM media_inventory_entries e JOIN media_inventory_records r ON r.id=e.record_id WHERE e.state IN ('unresolved','disputed')
 UNION ALL
 SELECT 'work',r.id,r.subject_id,'work_restriction',r.kind,false,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid FROM refurbishment_records r
 WHERE r.kind='exception' OR r.configuration IN ('CHANGED','UNKNOWN') OR r.answers->>'safety' IN ('FAIL','UNKNOWN') OR r.answers->>'configuration' IN ('FAIL','UNKNOWN')
 UNION ALL
 SELECT 'batch',r.id,r.batch_id,'unexpected_media','unexpected_media',false,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid FROM batch_records r WHERE r.kind='discrepancy'
 UNION ALL
 SELECT 'downstream',r.id,r.batch_id,e.type,e.type,false,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid FROM downstream_exceptions e JOIN downstream_records r ON r.id=e.id;

CREATE TABLE restriction_cases (
 id uuid PRIMARY KEY,source_kind text NOT NULL,source_id uuid NOT NULL,fact_key text NOT NULL,
 subject_id uuid NOT NULL REFERENCES subjects(id),source_event_id uuid NOT NULL REFERENCES domain_events(id),source_version bigint NOT NULL CHECK(source_version>0),original_value text NOT NULL,supported boolean NOT NULL,
 created_by uuid NOT NULL REFERENCES users(id),created_at timestamptz NOT NULL,
 state text NOT NULL CHECK(state IN ('open','under_review','closed_no_change','superseded')),active boolean NOT NULL,
 version bigint NOT NULL CHECK(version>0),record_id uuid NOT NULL,
 assigned_to uuid REFERENCES users(id),grant_record_id uuid,assignment_context jsonb,pre_receipt boolean NOT NULL DEFAULT false,allowed_evidence uuid[] NOT NULL DEFAULT '{}',
 CHECK(active=(state IN ('open','under_review'))),CHECK((assigned_to IS NULL)=(grant_record_id IS NULL))
);
CREATE UNIQUE INDEX restriction_one_active_case ON restriction_cases(source_kind,source_id,fact_key) WHERE active;
CREATE INDEX restriction_case_assignee ON restriction_cases(assigned_to,active,created_at,id);
CREATE TABLE restriction_records (
 id uuid PRIMARY KEY,case_id uuid NOT NULL REFERENCES restriction_cases(id) DEFERRABLE INITIALLY DEFERRED,
 version bigint NOT NULL CHECK(version>0),predecessor_id uuid REFERENCES restriction_records(id),
 kind text NOT NULL CHECK(kind IN ('open','assign','revoke','clarify','note','proposal','continue','close','approve')),
 proposal_id uuid REFERENCES restriction_records(id),grant_record_id uuid REFERENCES restriction_records(id),
 assigned_to uuid REFERENCES users(id),pre_receipt boolean NOT NULL DEFAULT false,allowed_evidence uuid[] NOT NULL DEFAULT '{}',
 original_value text,proposed_value text,identity_code text,checks jsonb NOT NULL DEFAULT '{}',basis_ciphertext text,
 source_version bigint NOT NULL,restriction_version bigint NOT NULL CHECK(restriction_version>=0),context jsonb NOT NULL,actor_context jsonb NOT NULL,
 actor_id uuid NOT NULL REFERENCES users(id),command_id uuid NOT NULL UNIQUE REFERENCES commands(id),event_id uuid NOT NULL UNIQUE REFERENCES domain_events(id),
 observed_at timestamptz NOT NULL,recorded_at timestamptz NOT NULL,reason_ciphertext text NOT NULL,
 attachment_id uuid REFERENCES attachments(id),reference_ciphertext text,
 UNIQUE(case_id,version),CHECK(observed_at<=recorded_at),CHECK(NOT (attachment_id IS NOT NULL AND reference_ciphertext IS NOT NULL)),
 CHECK(kind NOT IN ('proposal','approve') OR (attachment_id IS NOT NULL OR reference_ciphertext IS NOT NULL)),
 CHECK((kind='approve')=(proposal_id IS NOT NULL)),CHECK((kind='proposal')=(original_value IS NOT NULL AND proposed_value IS NOT NULL AND identity_code IS NOT NULL AND basis_ciphertext IS NOT NULL))
);
ALTER TABLE restriction_cases ADD FOREIGN KEY(record_id) REFERENCES restriction_records(id) DEFERRABLE INITIALLY DEFERRED,
 ADD FOREIGN KEY(grant_record_id) REFERENCES restriction_records(id) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE restriction_supersessions (
 record_id uuid PRIMARY KEY REFERENCES restriction_records(id),case_id uuid NOT NULL UNIQUE REFERENCES restriction_cases(id),proposal_id uuid NOT NULL UNIQUE REFERENCES restriction_records(id),
 subject_id uuid NOT NULL REFERENCES subjects(id),source_kind text NOT NULL,source_id uuid NOT NULL,fact_key text NOT NULL,original_value text NOT NULL,proposed_value text NOT NULL,
 UNIQUE(source_kind,source_id,fact_key)
);
CREATE TABLE restriction_epoch_steps (record_id uuid NOT NULL REFERENCES restriction_records(id),subject_id uuid NOT NULL REFERENCES subjects(id),version bigint NOT NULL CHECK(version>0),PRIMARY KEY(record_id,subject_id),UNIQUE(subject_id,version));
CREATE TABLE restriction_epochs (subject_id uuid PRIMARY KEY REFERENCES subjects(id),version bigint NOT NULL CHECK(version>0),record_id uuid NOT NULL REFERENCES restriction_records(id));
CREATE TABLE restriction_impacts (id uuid PRIMARY KEY,record_id uuid NOT NULL REFERENCES restriction_records(id),subject_id uuid NOT NULL REFERENCES subjects(id),type text NOT NULL CHECK(type='completed_dependency'),target_id uuid NOT NULL,UNIQUE(record_id,subject_id,target_id));
CREATE TABLE restriction_stamps (kind text NOT NULL,record_id uuid NOT NULL,subject_id uuid NOT NULL REFERENCES subjects(id),version bigint NOT NULL CHECK(version>=0),PRIMARY KEY(kind,record_id));
CREATE TABLE restriction_notifications (id uuid PRIMARY KEY,outbox_id uuid NOT NULL UNIQUE REFERENCES notification_outbox(id),event_id uuid NOT NULL REFERENCES domain_events(id),case_id uuid NOT NULL REFERENCES restriction_cases(id),recipient_id uuid NOT NULL REFERENCES users(id),read_at timestamptz);
CREATE INDEX restriction_notice_recipient ON restriction_notifications(recipient_id,read_at,id);

CREATE VIEW effective_restrictions AS SELECT s.* FROM restriction_sources s WHERE s.supported AND NOT EXISTS
 (SELECT 1 FROM restriction_supersessions x WHERE x.source_kind=s.source_kind AND x.source_id=s.source_id AND x.fact_key=s.fact_key AND x.subject_id=s.subject_id AND x.original_value=s.original_value);
CREATE FUNCTION restriction_epoch(ref uuid) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT COALESCE((SELECT version FROM restriction_epochs WHERE subject_id=ref),0) $$;
CREATE FUNCTION restriction_receipt_blocked(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT EXISTS(SELECT 1 FROM effective_restrictions WHERE subject_id=ref AND source_kind='receipt') $$;
CREATE FUNCTION restriction_inspection_blocked(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT EXISTS(SELECT 1 FROM effective_restrictions WHERE subject_id=ref AND source_kind='inspection') $$;
CREATE FUNCTION restriction_any_inspection(refs uuid[]) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT EXISTS(SELECT 1 FROM effective_restrictions WHERE subject_id=ANY(refs) AND source_kind='inspection') $$;
CREATE FUNCTION restriction_dependent(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT EXISTS(SELECT 1 FROM restriction_impacts WHERE subject_id=ref) $$;
CREATE FUNCTION restriction_family(ref uuid) RETURNS TABLE(subject_id uuid) LANGUAGE sql STABLE AS $$
 WITH RECURSIVE edges AS (
 SELECT parent_id a,medium_id b FROM media_inventory_entries WHERE medium_id IS NOT NULL
 UNION SELECT batch_id,subject_id FROM batch_members UNION SELECT batch_id,subject_id FROM batch_outputs
 UNION SELECT parent_id,subject_id FROM batch_detachments
 ), links AS (SELECT a,b FROM edges UNION SELECT b,a FROM edges), family(id) AS (SELECT ref UNION SELECT l.b FROM links l JOIN family f ON l.a=f.id)
 SELECT id FROM family
$$;
CREATE FUNCTION restriction_actor_context(actor uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('user',u.id,'status',u.status,'session',u.session_version,'membership',to_jsonb(m),'place',CASE WHEN l.id IS NOT NULL THEN jsonb_build_array(l.id,l.version,l.active,l.review_status,l.kind) END,'operator',CASE WHEN o.id IS NOT NULL THEN jsonb_build_array(o.id,o.version,o.review_status,o.kind) END,
 'capabilities',(SELECT jsonb_agg(jsonb_build_array(category_id,subtype) ORDER BY category_id,subtype) FROM place_categories WHERE place_id=l.id))
 FROM users u JOIN memberships m ON m.user_id=u.id LEFT JOIN places l ON l.id=m.place_id LEFT JOIN organizations o ON o.id=m.organization_id WHERE u.id=actor
$$;
CREATE FUNCTION restriction_admin(actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT EXISTS(SELECT 1 FROM users u JOIN memberships m ON m.user_id=u.id WHERE u.id=actor AND u.status='active' AND m.active AND m.role='administrator') $$;
CREATE FUNCTION restriction_staff(actor uuid,ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM users u JOIN memberships m ON m.user_id=u.id JOIN places l ON l.id=m.place_id AND l.organization_id=m.organization_id JOIN organizations o ON o.id=l.organization_id
 WHERE u.id=actor AND u.status='active' AND m.active AND m.role='processor' AND l.active AND l.review_status='approved' AND l.kind IN ('facility','collection_point') AND o.review_status='approved' AND o.kind IN ('processor','both')
 AND (EXISTS(SELECT 1 FROM items i JOIN place_categories pc ON pc.place_id=l.id AND pc.category_id=i.category_id AND pc.subtype=COALESCE(i.subtype,'') WHERE i.subject_id=ref)
 OR EXISTS(SELECT 1 FROM dismantling_batches b JOIN parties p ON p.id=b.facility_party_id WHERE b.subject_id=ref AND p.place_id=l.id)))
$$;
CREATE FUNCTION restriction_investigator(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM restriction_cases c JOIN memberships m ON m.user_id=actor WHERE c.id=ref AND c.active AND c.assigned_to=actor AND c.grant_record_id IS NOT NULL
 AND c.assignment_context=restriction_actor_context(actor) AND restriction_staff(actor,c.subject_id)
 AND (EXISTS(SELECT 1 FROM custody_current cc JOIN parties p ON p.id=cc.party_id WHERE cc.subject_id=c.subject_id AND p.place_id=m.place_id)
 OR EXISTS(SELECT 1 FROM dismantling_batches b JOIN parties p ON p.id=b.facility_party_id WHERE b.subject_id=c.subject_id AND p.place_id=m.place_id)
 OR (c.pre_receipt AND c.source_kind='receipt' AND NOT EXISTS(SELECT 1 FROM first_receipts WHERE subject_id=c.subject_id))))
$$;
CREATE FUNCTION restriction_clarifier(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM restriction_cases c JOIN restriction_sources s ON s.source_kind=c.source_kind AND s.source_id=c.source_id AND s.fact_key=c.fact_key
 JOIN users u ON u.id=actor JOIN memberships m ON m.user_id=u.id JOIN requests r ON r.id=s.request_id JOIN organizations o ON o.id=m.organization_id
 WHERE c.id=ref AND c.active AND s.source_kind='receipt' AND s.source_actor_id=actor AND u.status='active' AND m.active AND o.review_status='approved'
 AND ((m.role='collector' AND r.mode='pickup' AND EXISTS(SELECT 1 FROM assignments a WHERE a.request_id=r.id AND a.collector_id=actor AND a.operator_id=m.organization_id AND a.membership_id=m.id))
 OR (m.role='processor' AND r.mode='drop_off' AND r.point_id=m.place_id AND EXISTS(SELECT 1 FROM places l WHERE l.id=m.place_id AND l.active AND l.review_status='approved' AND l.organization_id=o.id))))
$$;
CREATE FUNCTION restriction_dependency_context(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('epoch',restriction_epoch(ref),'sources',(SELECT COALESCE(jsonb_agg(jsonb_build_array(source_kind,source_id,fact_key,original_value,source_version) ORDER BY source_kind,source_id,fact_key),'[]') FROM restriction_sources WHERE subject_id=ref),
 'family',(SELECT jsonb_agg(jsonb_build_object('id',f.subject_id,'epoch',restriction_epoch(f.subject_id),'subject',(SELECT to_jsonb(s) FROM subjects s WHERE s.id=f.subject_id),'item',(SELECT jsonb_build_array(i.owner_user_id,i.processing_state,i.intake_class,i.category_id,i.subtype) FROM items i WHERE i.subject_id=f.subject_id),'custody',(SELECT to_jsonb(c) FROM custody_current c WHERE c.subject_id=f.subject_id),'assessment',(SELECT to_jsonb(a) FROM assessment_current a WHERE a.subject_id=f.subject_id),'inventory',(SELECT to_jsonb(i) FROM media_inventory_current i WHERE i.parent_id=f.subject_id),'assurance',(SELECT to_jsonb(a) FROM sanitization_current a WHERE a.subject_id=f.subject_id),'work',(SELECT to_jsonb(w) FROM refurbishment_current w WHERE w.subject_id=f.subject_id),'batch',(SELECT to_jsonb(b) FROM dismantling_batches b WHERE b.subject_id=f.subject_id),'downstream',(SELECT to_jsonb(d) FROM downstream_batches d WHERE d.batch_id=f.subject_id)) ORDER BY f.subject_id) FROM restriction_family(ref) f),
 'requests',(SELECT COALESCE(jsonb_agg(jsonb_build_array(r.id,r.version,r.review_id,r.review_state) ORDER BY r.id),'[]') FROM requests r WHERE EXISTS(SELECT 1 FROM request_items ri WHERE ri.request_id=r.id AND ri.item_id IN(SELECT subject_id FROM restriction_family(ref)))))
$$;
CREATE FUNCTION restriction_impact_preview(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('subjects',(SELECT count(*) FROM restriction_family(ref)),'requests',(SELECT count(DISTINCT request_id) FROM request_items WHERE active AND item_id IN(SELECT subject_id FROM restriction_family(ref))),
 'assessments',(SELECT count(*) FROM assessment_current WHERE subject_id IN(SELECT subject_id FROM restriction_family(ref))),
 'media_authorizations',(SELECT count(*) FROM media_authorization_current WHERE subject_id IN(SELECT subject_id FROM restriction_family(ref))),
 'pending_returns',(SELECT count(*) FROM reuse_authorizations WHERE state='pending' AND subject_id IN(SELECT subject_id FROM restriction_family(ref))),
 'committed_batches',(SELECT count(*) FROM batch_members WHERE subject_id IN(SELECT subject_id FROM restriction_family(ref))),
 'dispatched_outputs',(SELECT count(*) FROM downstream_records WHERE kind='dispatch' AND output_id IN(SELECT subject_id FROM restriction_family(ref))))
$$;
