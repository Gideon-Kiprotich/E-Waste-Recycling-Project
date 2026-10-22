CREATE TABLE downstream_review_effects (
 record_id uuid PRIMARY KEY REFERENCES restriction_records(id), case_id uuid NOT NULL REFERENCES restriction_cases(id), proposal_id uuid NOT NULL REFERENCES restriction_records(id),
 action text NOT NULL CHECK(action IN ('report_error','located','damage_error','damage_disposition','loss_error','loss_confirm','measurement','recipient','discovery_movement','completion_error','downstream_continuity','closure_review')),
 observer_id uuid NOT NULL REFERENCES users(id), context jsonb NOT NULL
);
CREATE TABLE downstream_fact_revisions (
 id uuid PRIMARY KEY, anchor_kind text NOT NULL CHECK(anchor_kind IN ('downstream','batch','discovery')),anchor_id uuid NOT NULL,fact text NOT NULL CHECK(fact IN ('kg','active','kind','condition','disposition','loss_status','net_kg','gross_kg','tare_kg','movement')),
 version bigint NOT NULL CHECK(version>0),predecessor_id uuid REFERENCES downstream_fact_revisions(id),value text NOT NULL,review_id uuid NOT NULL REFERENCES downstream_review_effects(record_id) DEFERRABLE INITIALLY DEFERRED,
 UNIQUE(anchor_kind,anchor_id,fact,version),UNIQUE(review_id,anchor_kind,anchor_id,fact)
);
CREATE TABLE downstream_recipient_records (
 id uuid PRIMARY KEY,shipment_id uuid NOT NULL REFERENCES downstream_shipments(id),version bigint NOT NULL CHECK(version>0),predecessor_id uuid REFERENCES downstream_recipient_records(id),
 kind text NOT NULL CHECK(kind IN ('proposed','accepted','cancelled')),review_id uuid NOT NULL REFERENCES downstream_review_effects(record_id) DEFERRABLE INITIALLY DEFERRED,
 receiver_actor_id uuid NOT NULL REFERENCES users(id),membership_id uuid NOT NULL REFERENCES memberships(id),scope_version bigint NOT NULL,profile_record_id uuid NOT NULL REFERENCES output_profile_records(id),
 actor_id uuid NOT NULL REFERENCES users(id),reason_ciphertext text NOT NULL,actor_context jsonb NOT NULL,receiver_context jsonb NOT NULL,
 command_id uuid NOT NULL REFERENCES commands(id),event_id uuid NOT NULL REFERENCES domain_events(id),observed_at timestamptz NOT NULL,recorded_at timestamptz NOT NULL,
 attachment_id uuid REFERENCES attachments(id),reference_ciphertext text,UNIQUE(shipment_id,version)
);
ALTER TABLE downstream_shipments ADD COLUMN discovery_id uuid REFERENCES batch_media_discoveries(id),ADD COLUMN movement_review_id uuid REFERENCES downstream_review_effects(record_id);
ALTER TABLE media_authorizations ADD COLUMN discovery_id uuid REFERENCES batch_media_discoveries(id);
ALTER TABLE downstream_shipments ADD CHECK((discovery_id IS NULL)=(movement_review_id IS NULL));
CREATE FUNCTION downstream_fact(k text,ref uuid,f text,original text) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT COALESCE((SELECT value FROM downstream_fact_revisions WHERE anchor_kind=k AND anchor_id=ref AND fact=f ORDER BY version DESC LIMIT 1),original)
$$;
CREATE VIEW downstream_effective_records AS
 SELECT (jsonb_populate_record(NULL::downstream_records,to_jsonb(r)||jsonb_build_object(
 'kg',CASE WHEN r.kg IS NOT NULL AND downstream_fact('downstream',r.id,'active','1')='0' THEN '0' ELSE downstream_fact('downstream',r.id,'kg',r.kg::text) END,
 'kind',downstream_fact('downstream',r.id,'kind',r.kind),'condition',downstream_fact('downstream',r.id,'condition',r.condition)))).* FROM downstream_records r;
CREATE VIEW downstream_effective_measurements AS
 SELECT (jsonb_populate_record(NULL::batch_records,to_jsonb(r)||jsonb_build_object(
 'net_kg',downstream_fact('batch',r.id,'net_kg',r.net_kg::text),'gross_kg',downstream_fact('batch',r.id,'gross_kg',r.gross_kg::text),'tare_kg',downstream_fact('batch',r.id,'tare_kg',r.tare_kg::text)))).* FROM batch_records r;
CREATE VIEW downstream_recipient_current AS SELECT DISTINCT ON(shipment_id) * FROM downstream_recipient_records ORDER BY shipment_id,version DESC;
CREATE VIEW downstream_effective_shipments AS
 SELECT (jsonb_populate_record(NULL::downstream_shipments,to_jsonb(h)||CASE WHEN r.id IS NULL THEN '{}'::jsonb ELSE jsonb_build_object(
 'receiver_actor_id',CASE WHEN r.kind='accepted' THEN r.receiver_actor_id END,'receiver_membership_id',r.membership_id,'receiver_scope_version',r.scope_version,'profile_record_id',r.profile_record_id) END||
 CASE WHEN o.type='storage' THEN jsonb_build_object('requested_kg',m.net_kg) ELSE '{}'::jsonb END)).*
 FROM downstream_shipments h JOIN batch_outputs o ON o.subject_id=h.output_id LEFT JOIN downstream_effective_measurements m ON m.id=o.measurement_record_id LEFT JOIN downstream_recipient_current r ON r.shipment_id=h.id;
CREATE FUNCTION downstream_accounting_context(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('facts',(SELECT COALESCE(jsonb_agg(to_jsonb(f) ORDER BY f.anchor_kind,f.anchor_id,f.fact,f.version),'[]'::jsonb) FROM downstream_fact_revisions f JOIN restriction_records approved ON approved.id=f.review_id JOIN restriction_cases c ON c.id=approved.case_id WHERE c.subject_id=ref),
 'recipients',(SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY r.shipment_id),'[]'::jsonb) FROM downstream_recipient_current r JOIN downstream_shipments h ON h.id=r.shipment_id WHERE h.batch_id=ref))
$$;
CREATE OR REPLACE FUNCTION downstream_stock(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('produced',m.net_kg,'dispatched',q.dispatched,'source_loss',q.loss,'reserved',q.reserved,'accounted',m.net_kg-q.dispatched-q.loss,'reservable',m.net_kg-q.dispatched-q.loss-q.reserved,
 'accepted',q.accepted,'completed',q.completed,'downstream_loss',q.downstream_loss,'refused',q.refused,'shortage',q.shortage)
 FROM batch_outputs o LEFT JOIN downstream_effective_measurements m ON m.id=o.measurement_record_id CROSS JOIN LATERAL(
 SELECT COALESCE(sum(kg) FILTER(WHERE kind='dispatch'),0) dispatched,COALESCE(sum(kg) FILTER(WHERE kind='source_loss'),0) loss,
 COALESCE(sum(CASE WHEN kind='reserve' THEN kg WHEN kind IN ('withdraw','dispatch') THEN -kg ELSE 0 END),0) reserved,
 COALESCE(sum(kg) FILTER(WHERE kind='accept'),0) accepted,COALESCE(sum(kg) FILTER(WHERE kind='complete'),0) completed,
 COALESCE(sum(kg) FILTER(WHERE kind='downstream_loss'),0) downstream_loss,COALESCE(sum(kg) FILTER(WHERE kind='refuse'),0) refused,COALESCE(sum(kg) FILTER(WHERE kind='shortage'),0) shortage
 FROM downstream_effective_records WHERE output_id=ref) q WHERE o.subject_id=ref
$$;
CREATE FUNCTION downstream_stock_map(ref uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT COALESCE(jsonb_object_agg(subject_id::text,downstream_stock(subject_id)),'{}'::jsonb) FROM batch_outputs WHERE batch_id=ref
$$;
CREATE FUNCTION downstream_loss_reviewed(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT downstream_fact('downstream',ref,'loss_status','pending')='confirmed' $$;
CREATE FUNCTION downstream_damage_outcome(ref uuid) RETURNS text LANGUAGE sql STABLE AS $$ SELECT downstream_fact('downstream',ref,'disposition',NULL) $$;
CREATE FUNCTION downstream_proof_current(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM downstream_review_effects e JOIN restriction_records r ON r.id=e.record_id JOIN restriction_records p ON p.id=e.proposal_id
 WHERE e.record_id=ref AND r.actor_context=restriction_actor_context(r.actor_id) AND p.actor_context=restriction_actor_context(p.actor_id) AND restriction_admin(r.actor_id)
 AND NOT EXISTS(SELECT 1 FROM attachments WHERE id IN(r.attachment_id,p.attachment_id) AND redacted_at IS NOT NULL)
 AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements_text(COALESCE(p.details->'party_findings','[]'::jsonb)) f JOIN restriction_records signature ON signature.id=f::uuid WHERE signature.actor_context IS DISTINCT FROM restriction_actor_context(signature.actor_id) OR EXISTS(SELECT 1 FROM attachments WHERE id=signature.attachment_id AND redacted_at IS NOT NULL)))
$$;
CREATE FUNCTION downstream_review_scope(actor uuid,ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT COALESCE(media_review_scope(actor,ref),false) OR EXISTS(SELECT 1 FROM downstream_effective_shipments h JOIN output_profile_records p ON p.id=h.profile_record_id WHERE h.batch_id=ref AND COALESCE((downstream_access(actor,p.facility_party_id)->>'eligible')::boolean,false))
$$;
CREATE FUNCTION downstream_case_access(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM restriction_cases c JOIN restriction_records assignment ON assignment.id=c.grant_record_id WHERE c.id=ref AND c.contract='P16' AND c.active AND downstream_review_scope(actor,c.subject_id)
 AND ((c.assigned_to=actor AND c.assignment_context=restriction_actor_context(actor)) OR (assignment.details->>'party_actor_id'=actor::text AND assignment.details->'party_context'=restriction_actor_context(actor))))
$$;
CREATE FUNCTION downstream_preparatory_proof(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT false $$;
CREATE FUNCTION downstream_evidence_blocked(ref uuid,preparatory boolean) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT media_batch_unresolved(ref) OR EXISTS(SELECT 1 FROM downstream_effective_records r WHERE r.batch_id=ref AND
 ((r.kind IN ('refuse','shortage','dispute','source_loss','downstream_loss') AND r.kg>0 AND NOT (r.kind IN ('source_loss','downstream_loss') AND downstream_loss_reviewed(r.id)))
 OR (r.kind='accept' AND r.kg>0 AND r.condition='damaged' AND downstream_damage_outcome(r.id) IS NULL)
 OR (r.kind='measurement_dispute' AND downstream_fact('downstream',r.id,'active','1')<>'0')))
 OR EXISTS(SELECT 1 FROM downstream_fact_revisions v JOIN downstream_review_effects e ON e.record_id=v.review_id JOIN restriction_cases c ON c.id=e.case_id
 WHERE c.subject_id=ref AND NOT EXISTS(SELECT 1 FROM downstream_fact_revisions newer WHERE newer.anchor_kind=v.anchor_kind AND newer.anchor_id=v.anchor_id AND newer.fact=v.fact AND newer.version>v.version) AND NOT downstream_proof_current(e.record_id) AND NOT (preparatory AND downstream_preparatory_proof(e.record_id)))
 OR EXISTS(SELECT 1 FROM downstream_exceptions e WHERE e.batch_id=ref AND e.type='post_closure_assurance_contradiction' AND NOT EXISTS(SELECT 1 FROM downstream_review_effects d JOIN restriction_cases c ON c.id=d.case_id WHERE c.subject_id=ref AND d.action='closure_review' AND downstream_proof_current(d.record_id) AND d.context->'assurance'=(SELECT COALESCE(jsonb_agg(jsonb_build_array(o.subject_id,s.record_id,s.version) ORDER BY o.subject_id),'[]'::jsonb) FROM batch_outputs o LEFT JOIN sanitization_current s ON s.subject_id=o.subject_id WHERE o.batch_id=ref AND o.type='storage')))
$$;

CREATE FUNCTION downstream_evidence_blocked(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT downstream_evidence_blocked(ref,false) $$;
CREATE OR REPLACE FUNCTION downstream_blocked(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT restriction_dependent(ref) OR downstream_evidence_blocked(ref) $$;
