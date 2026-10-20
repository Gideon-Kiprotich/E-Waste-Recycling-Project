<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $initial = file_get_contents(database_path('sql/restriction_review.sql'));
        $query = trim(substr($initial, strlen('CREATE VIEW restriction_sources AS'), strpos($initial, 'CREATE TABLE restriction_cases') - strlen('CREATE VIEW restriction_sources AS')));
        $query = rtrim($query, ";\r\n ");
        $query = str_replace("WHERE hi.active AND hi.state='disputed' AND oc.outcome", 'WHERE oc.outcome', $query, $handoverCount);
        $query = str_replace("SELECT 'inventory',e.id,e.parent_id,e.state,e.state,false,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid\n FROM media_inventory_entries e JOIN media_inventory_records r ON r.id=e.record_id WHERE e.state IN ('unresolved','disputed')", "SELECT 'inventory',r.id,r.parent_id,r.entry_state,r.entry_state,false,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid\n FROM media_inventory_records r WHERE r.entry_state IN ('unresolved','disputed')", $query, $inventoryCount);
        if ($handoverCount !== 1 || $inventoryCount !== 1) {
            throw new RuntimeException('Restriction source upgrade blocked: unexpected source registry.');
        }
        $query .= <<<'SQL'

 UNION ALL SELECT 'dependent',i.id,i.subject_id,i.type,i.type,false,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid FROM restriction_impacts i JOIN restriction_records r ON r.id=i.record_id
 UNION ALL SELECT 'recipient_scope',r.id,r.batch_id,'recipient_scope_changed','recipient_scope_changed',false,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid
 FROM downstream_records r JOIN downstream_shipments h ON h.id=r.shipment_id JOIN memberships m ON m.id=h.receiver_membership_id JOIN users u ON u.id=h.receiver_actor_id
 WHERE r.kind='dispatch' AND (NOT m.active OR m.version<>h.receiver_scope_version OR u.status<>'active')
 UNION ALL SELECT 'assurance_dependency',r.id,e.batch_id,e.type,e.type,false,r.event_id,r.version,r.actor_id,r.observed_at,r.attachment_id,NULL::uuid
 FROM downstream_exceptions e JOIN sanitization_records r ON r.id=e.id WHERE e.type='post_closure_assurance_contradiction'
SQL;
        DB::unprepared("CREATE OR REPLACE VIEW restriction_sources AS SELECT b.*, CASE WHEN b.source_kind='handover' THEN EXISTS(SELECT 1 FROM handover_outcomes oc JOIN handover_items hi ON hi.id=oc.handover_item_id WHERE oc.id=b.source_id AND hi.active AND hi.state='disputed') WHEN b.source_kind='inventory' THEN EXISTS(SELECT 1 FROM media_inventory_entries e WHERE e.record_id=b.source_id AND e.state IN ('unresolved','disputed')) ELSE true END AS currently_blocking FROM (".$query.') b');
        $this->patch('restriction_case_guard()', 's.source_id IS NULL OR NEW.subject_id', 's.source_id IS NULL OR NOT s.currently_blocking OR NEW.subject_id');
        $this->patch('restriction_record_effects()', "type IN ('reuse_return_acknowledged','item_dismantled','batch_committed','downstream_accepted')", "type IN ('reuse_returned','dismantled_in_batch','media_detached_in_batch','downstream_accepted','refurbishment_work','sanitization_attempt')");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION restriction_approval_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c restriction_cases;ref uuid;
BEGIN
 IF NEW.kind<>'approve' THEN RETURN NULL; END IF;
 SELECT * INTO c FROM restriction_cases WHERE id=NEW.case_id;
 IF EXISTS(SELECT 1 FROM requests r JOIN request_items ri ON ri.request_id=r.id WHERE ri.active AND ri.item_id IN(SELECT subject_id FROM restriction_family(c.subject_id)) AND r.review_state<>'stale')
 OR EXISTS(SELECT 1 FROM assignments a JOIN request_items ri ON ri.request_id=a.request_id WHERE a.active AND ri.active AND ri.item_id IN(SELECT subject_id FROM restriction_family(c.subject_id)))
 THEN RAISE EXCEPTION 'Supported correction requires explicit fresh review and pending assignment invalidation'; END IF;
 IF NOT EXISTS(SELECT 1 FROM notification_outbox WHERE event_id=NEW.event_id AND recipient_id=NEW.actor_id AND channel='in_app') THEN RAISE EXCEPTION 'Case review requires committed scoped notification outbox'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER restriction_approval_complete AFTER INSERT ON restriction_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restriction_approval_boundary();
SQL);
    }

    private function patch(string $signature, string $from, string $to): void
    {
        $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS definition', [$signature])->definition;
        $definition = str_replace($from, $to, $definition, $count);
        if ($count !== 1) {
            throw new RuntimeException('Restriction source upgrade blocked: unexpected dependency guard.');
        }
        DB::unprepared($definition);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward restriction migration.');
    }
};
