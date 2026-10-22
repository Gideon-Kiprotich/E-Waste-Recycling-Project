CREATE FUNCTION downstream_reconciliation_current(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT NOT EXISTS(SELECT 1 FROM downstream_review_effects e JOIN restriction_records r ON r.id=e.record_id JOIN restriction_cases c ON c.id=e.case_id WHERE c.subject_id=ref AND e.action='measurement'
 AND NOT EXISTS(SELECT 1 FROM batch_records a WHERE a.batch_id=ref AND a.kind='accounted' AND a.recorded_at>=r.recorded_at)
 AND NOT EXISTS(SELECT 1 FROM downstream_review_effects renewed JOIN restriction_records review ON review.id=renewed.record_id JOIN restriction_cases rc ON rc.id=renewed.case_id WHERE rc.subject_id=ref AND renewed.action='closure_review' AND review.kind='fulfill' AND review.recorded_at>=r.recorded_at AND downstream_proof_current(renewed.record_id)))
$$;
CREATE FUNCTION downstream_media_uncontrolled(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT EXISTS(SELECT 1 FROM downstream_effective_records WHERE output_id=ref AND kind IN ('source_loss','downstream_loss') AND kg>0) $$;
CREATE FUNCTION downstream_outcomes_ready(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT COALESCE((batch_balance_before_downstream_corrections(ref)->>'unmeasured')::int=0,false) AND NOT COALESCE((batch_balance_before_downstream_corrections(ref)->>'excess_variance')::boolean,true) AND NOT media_batch_unresolved(ref)
 AND EXISTS(SELECT 1 FROM batch_outputs WHERE batch_id=ref)
 AND NOT EXISTS(SELECT 1 FROM batch_outputs o WHERE o.batch_id=ref AND (o.measurement_record_id IS NULL OR (downstream_stock(o.subject_id)->>'accounted')::numeric<>0 OR (downstream_stock(o.subject_id)->>'reserved')::numeric<>0 OR (o.type='storage' AND (downstream_media_uncontrolled(o.subject_id) OR sanitization_state(o.subject_id)<>'satisfied' OR EXISTS(SELECT 1 FROM sanitization_current WHERE subject_id=o.subject_id AND destruction_reported)))))
 AND NOT EXISTS(SELECT 1 FROM downstream_effective_records r WHERE r.batch_id=ref AND ((r.kind IN ('dispatch','accept') AND downstream_remaining(r.id)<>0) OR (r.kind IN ('refuse','shortage','dispute') AND r.kg>0) OR (r.kind IN ('source_loss','downstream_loss') AND r.kg>0 AND NOT downstream_loss_reviewed(r.id)) OR (r.kind='measurement_dispute' AND downstream_fact('downstream',r.id,'active','1')<>'0') OR (r.kind='accept' AND r.condition='damaged' AND downstream_damage_outcome(r.id) IS NULL)))
$$;
CREATE OR REPLACE FUNCTION downstream_closable(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT downstream_outcomes_ready(ref) AND downstream_reconciliation_current(ref) AND NOT downstream_blocked(ref) $$;
CREATE OR REPLACE FUNCTION downstream_status(ref uuid) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN downstream_closed(ref) THEN CASE WHEN downstream_blocked(ref) OR NOT downstream_outcomes_ready(ref) THEN 'closed_later_evidence_exception' WHEN EXISTS(SELECT 1 FROM downstream_review_effects e JOIN restriction_cases c ON c.id=e.case_id WHERE c.subject_id=ref AND e.context->>'historical_closure' IS NOT NULL) THEN 'closed_reviewed_accounting' WHEN EXISTS(SELECT 1 FROM downstream_effective_records WHERE batch_id=ref AND kind IN ('source_loss','downstream_loss') AND kg>0) THEN 'closed_recorded_outcomes_with_loss' ELSE 'closed_recorded_outcomes' END
 WHEN downstream_blocked(ref) THEN 'blocked_downstream_exception' WHEN EXISTS(SELECT 1 FROM downstream_records WHERE batch_id=ref AND kind='dispatch') THEN 'awaiting_downstream_outcomes' ELSE 'processing_on_site' END
$$;
CREATE FUNCTION downstream_impact_fulfilled(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM restriction_impact_reviews v JOIN restriction_records r ON r.id=v.record_id JOIN restriction_cases c ON c.id=r.case_id JOIN downstream_review_effects e ON e.record_id=r.id
 WHERE v.impact_id=ref AND v.stage='fulfilled' AND c.contract='P16' AND c.fact_key='downstream_downstream_continuity' AND v.epoch=restriction_epoch(c.subject_id) AND v.context=downstream_obligation_context(c.subject_id,c.source_id) AND downstream_proof_current(r.id))
$$;
ALTER FUNCTION downstream_proof_current(uuid) RENAME TO downstream_original_proof_current;
CREATE FUNCTION downstream_proof_current(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT downstream_original_proof_current(ref) OR (
 EXISTS(SELECT 1 FROM restriction_impacts WHERE record_id=ref) AND
 NOT EXISTS(SELECT 1 FROM restriction_impacts WHERE record_id=ref AND NOT downstream_impact_fulfilled(id)))
$$;
CREATE FUNCTION downstream_continuity(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM restriction_impact_reviews v JOIN restriction_records r ON r.id=v.record_id JOIN restriction_cases c ON c.id=r.case_id JOIN downstream_review_effects e ON e.record_id=r.id
 WHERE v.impact_id=ref AND v.stage='continuity' AND c.contract='P16' AND c.active AND c.state='revalidation_pending' AND v.epoch=restriction_epoch(c.subject_id) AND downstream_proof_current(r.id))
$$;
CREATE OR REPLACE FUNCTION downstream_preparatory_proof(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT downstream_proof_current(ref) OR (EXISTS(SELECT 1 FROM restriction_impacts WHERE record_id=ref) AND NOT EXISTS(SELECT 1 FROM restriction_impacts WHERE record_id=ref AND NOT downstream_impact_fulfilled(id) AND NOT downstream_continuity(id)))
$$;
CREATE FUNCTION downstream_accounting_allowed(ref uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT batch_control_ready_before_restriction(ref,actor) AND NOT EXISTS(SELECT 1 FROM restriction_impacts i WHERE i.subject_id IN(SELECT subject_id FROM restriction_family(ref)) AND NOT media_impact_fulfilled(i.id) AND NOT downstream_continuity(i.id)) $$;
CREATE FUNCTION downstream_ack_allowed(ref uuid,claim uuid,actor uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT NOT restriction_dependent(ref) OR (NOT EXISTS(SELECT 1 FROM restriction_impacts i WHERE i.subject_id IN(SELECT subject_id FROM restriction_family(ref)) AND NOT media_impact_fulfilled(i.id) AND NOT downstream_continuity(i.id))
 AND EXISTS(SELECT 1 FROM downstream_review_effects e JOIN restriction_records review ON review.id=e.record_id JOIN restriction_cases c ON c.id=e.case_id JOIN downstream_records source ON source.id=c.source_id JOIN downstream_effective_shipments h ON h.id=source.shipment_id WHERE c.subject_id=ref AND e.action IN ('report_error','located','recipient') AND downstream_proof_current(e.record_id) AND h.receiver_actor_id=actor AND ((e.action='recipient' AND EXISTS(SELECT 1 FROM downstream_records d WHERE d.id=claim AND d.shipment_id=h.id AND d.kind='dispatch')) OR source.related_record_id=claim)))
$$;
CREATE FUNCTION downstream_recipient_authority(ref uuid) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT EXISTS(SELECT 1 FROM downstream_recipient_current r JOIN output_profile_records pr ON pr.id=r.profile_record_id WHERE r.shipment_id=ref AND r.kind='accepted' AND r.receiver_context=restriction_actor_context(r.receiver_actor_id) AND downstream_proof_current(r.review_id) AND COALESCE((downstream_access(r.receiver_actor_id,pr.facility_party_id)->>'eligible')::boolean,false)) $$;
CREATE FUNCTION downstream_recipient_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prev downstream_recipient_records;review restriction_records;proposal restriction_records;c restriction_cases;h downstream_shipments;pr output_profile_records;original output_profile_records;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Recipient responsibility history is append-only.'; END IF;
 SELECT * INTO h FROM downstream_shipments WHERE id=NEW.shipment_id FOR UPDATE;SELECT * INTO prev FROM downstream_recipient_records WHERE shipment_id=h.id ORDER BY version DESC LIMIT 1;
 SELECT * INTO review FROM restriction_records WHERE id=NEW.review_id;SELECT * INTO proposal FROM restriction_records WHERE id=review.proposal_id;SELECT * INTO c FROM restriction_cases WHERE id=review.case_id;SELECT * INTO pr FROM output_profile_records WHERE id=NEW.profile_record_id;SELECT * INTO original FROM output_profile_records WHERE id=h.profile_record_id;
 IF NEW.version<>COALESCE(prev.version,0)+1 OR NEW.predecessor_id IS DISTINCT FROM prev.id OR c.fact_key IS DISTINCT FROM 'downstream_recipient' OR c.source_id IS DISTINCT FROM h.created_record_id OR pr.profile_id IS DISTINCT FROM original.profile_id OR NEW.receiver_actor_id::text IS DISTINCT FROM proposal.details->>'receiver_actor_id' OR NEW.profile_record_id::text IS DISTINCT FROM proposal.details->>'profile_record_id' OR NEW.receiver_context IS DISTINCT FROM proposal.details->'receiver_context' OR NEW.observed_at>clock_timestamp() OR NEW.observed_at<review.observed_at OR NEW.recorded_at<NEW.observed_at OR NEW.actor_context IS DISTINCT FROM restriction_actor_context(NEW.actor_id)
 OR (NEW.kind<>'cancelled' AND NOT EXISTS(SELECT 1 FROM memberships m WHERE m.id=NEW.membership_id AND m.user_id=NEW.receiver_actor_id AND m.version=NEW.scope_version)) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Recipient generation, approved destination/profile or scope changed.'; END IF;
 IF NEW.reason_ciphertext IS NULL OR (NEW.attachment_id IS NULL)=(NEW.reference_ciphertext IS NULL) OR (NEW.attachment_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM attachments WHERE id=NEW.attachment_id AND uploaded_by=NEW.actor_id AND privacy_class='private' AND redacted_at IS NULL)) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Current private evidence and a recorded reason are required for responsibility.'; END IF;
 IF NEW.kind='proposed' THEN
 IF NEW.attachment_id IS DISTINCT FROM review.attachment_id OR NEW.reference_ciphertext IS DISTINCT FROM review.reference_ciphertext OR NEW.reason_ciphertext IS DISTINCT FROM review.reason_ciphertext OR NEW.observed_at IS DISTINCT FROM review.observed_at OR NEW.actor_id<>review.actor_id OR NEW.command_id<>review.command_id OR NEW.event_id<>review.event_id OR NEW.receiver_context IS DISTINCT FROM restriction_actor_context(NEW.receiver_actor_id) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Proposed recipient needs the exact independent review.'; END IF;
 ELSE
 IF prev.kind IS DISTINCT FROM 'proposed' OR NEW.review_id<>prev.review_id OR (NEW.kind<>'cancelled' AND NOT downstream_proof_current(NEW.review_id)) OR NOT EXISTS(SELECT 1 FROM commands cmd JOIN domain_events ev ON ev.command_id=cmd.id WHERE cmd.id=NEW.command_id AND cmd.actor_id=NEW.actor_id AND cmd.status='completed' AND cmd.action='downstream_recipient_'||NEW.kind||':'||h.id::text AND cmd.safe_response->>'record_id'=NEW.id::text AND ev.id=NEW.event_id AND ev.actor_id=NEW.actor_id AND ev.aggregate_kind='downstream_recipient' AND ev.aggregate_id=h.id AND ev.aggregate_version=NEW.version AND ev.type='downstream_recipient_'||NEW.kind AND ev.occurred_at=NEW.observed_at AND ev.recorded_at=NEW.recorded_at) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Responsibility acceptance/cancellation needs its own current command and event.'; END IF;
 IF NEW.kind='accepted' AND (NEW.actor_id<>NEW.receiver_actor_id OR NEW.receiver_context IS DISTINCT FROM restriction_actor_context(NEW.actor_id) OR NOT COALESCE((downstream_access(NEW.actor_id,pr.facility_party_id)->>'eligible')::boolean,false)) OR NEW.kind='cancelled' AND NOT restriction_admin(NEW.actor_id) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Only the named current successor can accept responsibility; no physical acknowledgment is inferred.'; END IF;
 END IF;RETURN NEW;
END; $$;
CREATE TRIGGER downstream_recipient_valid BEFORE INSERT OR UPDATE OR DELETE ON downstream_recipient_records FOR EACH ROW EXECUTE FUNCTION downstream_recipient_guard();
CREATE FUNCTION downstream_recipient_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE current downstream_recipient_records;
BEGIN
 SELECT * INTO current FROM downstream_recipient_current WHERE shipment_id=NEW.shipment_id;
 IF current.id=NEW.id AND (NEW.actor_context IS DISTINCT FROM restriction_actor_context(NEW.actor_id) OR (NEW.kind<>'cancelled' AND NEW.receiver_context IS DISTINCT FROM restriction_actor_context(NEW.receiver_actor_id)) OR (NEW.kind<>'cancelled' AND NOT downstream_proof_current(NEW.review_id))) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Recipient signatures changed before COMMIT.'; END IF;
 PERFORM downstream_integrity((SELECT batch_id FROM downstream_shipments WHERE id=NEW.shipment_id));RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER downstream_recipient_complete AFTER INSERT ON downstream_recipient_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION downstream_recipient_final();
CREATE FUNCTION downstream_revision_integrity(ref uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE m downstream_effective_measurements;o batch_outputs;r downstream_effective_records;h downstream_shipments;
BEGIN
 FOR m IN SELECT * FROM downstream_effective_measurements WHERE batch_id=ref AND net_kg IS NOT NULL LOOP
 IF m.net_kg<=0 OR m.gross_kg IS NOT NULL AND (m.tare_kg IS NULL OR m.gross_kg-m.tare_kg<>m.net_kg OR m.tare_kg<0) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Effective measurement must retain positive consistent gross/tare/net kg.'; END IF;
 END LOOP;
 FOR r IN SELECT * FROM downstream_effective_records WHERE batch_id=ref LOOP
 IF r.kind IN ('dispatch','accept') AND r.kg<=0 THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Review cannot negate an actual dispatch or acknowledgment.'; END IF;
 IF r.canonical AND r.kg>0 AND r.kg IS DISTINCT FROM (downstream_stock(r.output_id)->>'produced')::numeric THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Canonical kg revisions must preserve the entire single-unit allocation vector.'; END IF;
 IF r.kind='complete' THEN
 SELECT * INTO o FROM batch_outputs WHERE subject_id=r.output_id;
 IF o.type='storage' AND r.outcome<>'controlled_storage' OR o.type='residue' AND r.outcome<>'disposed' THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Output disposition must retain its supported policy.'; END IF;
 END IF;
 END LOOP;
 IF EXISTS(SELECT 1 FROM downstream_effective_records WHERE batch_id=ref AND kind='accept' AND canonical AND kg>0 GROUP BY output_id HAVING count(*)>1) THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='One canonical drive cannot acquire another physical receipt.'; END IF;
END; $$;
