CREATE FUNCTION restriction_epoch_step_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.version<>restriction_epoch(NEW.subject_id)+1 OR NOT EXISTS(SELECT 1 FROM restriction_records r JOIN restriction_cases c ON c.id=r.case_id
 WHERE r.id=NEW.record_id AND r.kind='approve' AND r.xmin::text::bigint=(pg_current_xact_id()::text::bigint % 4294967296)
 AND NEW.subject_id IN(SELECT subject_id FROM restriction_family(c.subject_id))) THEN RAISE EXCEPTION 'Epoch step requires the newly approved affected fact'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER restriction_epoch_step_valid BEFORE INSERT ON restriction_epoch_steps FOR EACH ROW EXECUTE FUNCTION restriction_epoch_step_guard();
CREATE FUNCTION restriction_notice_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR (TG_OP='UPDATE' AND ((to_jsonb(NEW)-'read_at')<>(to_jsonb(OLD)-'read_at') OR OLD.read_at IS NOT NULL OR NEW.read_at IS NULL))
 OR (TG_OP='INSERT' AND NOT EXISTS(SELECT 1 FROM notification_outbox n JOIN restriction_records r ON r.event_id=n.event_id WHERE n.id=NEW.outbox_id AND n.recipient_id=NEW.recipient_id AND n.event_id=NEW.event_id AND r.case_id=NEW.case_id AND n.channel='in_app'))
 THEN RAISE EXCEPTION 'Restriction notices retain their committed recipient and event'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER restriction_notice_valid BEFORE INSERT OR UPDATE OR DELETE ON restriction_notifications FOR EACH ROW EXECUTE FUNCTION restriction_notice_guard();
