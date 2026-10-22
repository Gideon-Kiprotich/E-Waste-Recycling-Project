<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->check('media_authorizations', 'authorization_context_version = ANY', 'authorization_context_version IN (2,3,4,5,6)');
        $this->check('media_authorizations', 'batch_id IS NULL', '(authorization_context_version=2 AND batch_id IS NULL AND downstream_receipt_id IS NULL) OR (authorization_context_version IN (3,5) AND batch_id IS NOT NULL AND downstream_receipt_id IS NULL) OR (authorization_context_version IN (4,6) AND batch_id IS NOT NULL AND downstream_receipt_id IS NOT NULL)');
        $this->check('sanitization_records', 'authorization_context_version = ANY', 'version>=1 AND source_subject_version>=1 AND assessment_version>=2 AND ((authorization_context_version=1 AND authorization_id IS NULL AND custody_version>=2) OR (authorization_context_version IN (2,3,4,5,6) AND authorization_id IS NOT NULL AND custody_version>=1))');
        DB::statement('ALTER TABLE media_authorizations ADD CHECK((authorization_context_version=6)=(discovery_id IS NOT NULL))');
        DB::unprepared(file_get_contents(database_path('sql/downstream_discovery.sql')));
        DB::unprepared(<<<'SQL'
ALTER FUNCTION downstream_receipt_matches(uuid,uuid,uuid,bigint) RENAME TO downstream_receipt_matches_detached;
CREATE FUNCTION downstream_receipt_matches(ref uuid,party uuid,ev uuid,cv bigint) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT downstream_receipt_matches_detached(ref,party,ev,cv) OR downstream_discovery_receipt(ref,party,ev,cv) $$;
SQL);
        $this->prefix('downstream_header_guard()', "IF NEW.discovery_id IS NOT NULL THEN PERFORM downstream_discovery_header(NEW); RETURN NEW; END IF; IF NEW.movement_review_id IS NOT NULL THEN RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='Movement review requires its exact discovery provenance.'; END IF;");
        $this->patch('downstream_record_guard()', 'EXISTS(SELECT 1 FROM batch_media_discoveries WHERE medium_id=NEW.output_id)', 'EXISTS(SELECT 1 FROM batch_media_discoveries WHERE medium_id=NEW.output_id) AND NOT downstream_discovery_movable(NEW.output_id,NEW.actor_id)');
        $this->prefix('media_custody_guard()', "IF TG_TABLE_NAME='custody_current' AND EXISTS(SELECT 1 FROM batch_media_discoveries WHERE medium_id=NEW.subject_id) AND downstream_discovery_receipt(NEW.subject_id,(to_jsonb(NEW)->>'party_id')::uuid,(to_jsonb(NEW)->>'last_event_id')::uuid,(to_jsonb(NEW)->>'version')::bigint) THEN RETURN NEW; END IF;");
        $this->patch('discovery_authorization_applicable(media_authorizations)', 'media_batch_onsite(b.subject_id)', 'downstream_discovery_on_site(d.medium_id)');
        $this->patch('media_authorization_applicable_before_restriction(media_authorizations)', 'CASE WHEN a.authorization_context_version=5', 'CASE WHEN a.authorization_context_version=6 THEN downstream_discovery_authorization(a) WHEN a.authorization_context_version=5');
        $this->patch('sanitization_ready_before_restriction(uuid,uuid)', 'THEN EXISTS(SELECT 1 FROM media_authorization_current cur JOIN media_authorizations a ON a.id=cur.record_id WHERE cur.subject_id=ref AND a.authorization_context_version=5', 'THEN EXISTS(SELECT 1 FROM media_authorization_current cur JOIN media_authorizations a ON a.id=cur.record_id WHERE cur.subject_id=ref AND a.authorization_context_version=6 AND a.actor_id=actor AND downstream_discovery_authorization(a)) OR EXISTS(SELECT 1 FROM media_authorization_current cur JOIN media_authorizations a ON a.id=cur.record_id WHERE cur.subject_id=ref AND a.authorization_context_version=5');
        $this->patch('sanitization_dependencies_before_restriction(sanitization_records)', 'CASE WHEN r.authorization_context_version=5', 'CASE WHEN r.authorization_context_version=6 THEN downstream_discovery_dependencies(r) WHEN r.authorization_context_version=5');
        $this->patch('sanitization_insert_guard()', 'NEW.authorization_context_version<>5', 'NEW.authorization_context_version NOT IN (5,6)');
        $this->patch('downstream_authorization_applicable(media_authorizations)', 'receipt.actor_id=a.actor_id', 'h.receiver_actor_id=a.actor_id');
        $this->patch('downstream_authorization_applicable(media_authorizations)', 'AND NOT downstream_blocked(d.batch_id)', "AND NOT downstream_evidence_blocked(d.batch_id,true) AND media_recovery_allowed(a.subject_id,'authorization')");
        // A recorded incident cannot become sanitization authority or controlled storage.
        DB::unprepared(<<<'SQL'
ALTER FUNCTION media_sanitization_ready(uuid,uuid,text) RENAME TO media_sanitization_ready_before_incidents;
CREATE FUNCTION media_sanitization_ready(ref uuid,actor uuid,operation text) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT NOT downstream_media_uncontrolled(ref) AND media_sanitization_ready_before_incidents(ref,actor,operation) $$;
ALTER FUNCTION sanitization_dependencies(sanitization_records) RENAME TO sanitization_dependencies_before_incidents;
CREATE FUNCTION sanitization_dependencies(r sanitization_records) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT NOT downstream_media_uncontrolled(r.subject_id) AND sanitization_dependencies_before_incidents(r) $$;
SQL);
        $this->patch('handover_chain_check(uuid)', 'AND NOT batch_source_matches(ref,party,ev,cv,sv) THEN', 'AND NOT batch_source_matches(ref,party,ev,cv,sv) AND NOT downstream_discovery_chain(ref,party,ev,cv,sv) THEN');
        $this->patch('receipt_integrity()', 'IF NOT FOUND THEN', "IF NOT FOUND THEN IF EXISTS(SELECT 1 FROM media_origins discovered_origin WHERE discovered_origin.subject_id=ref AND discovered_origin.origin_kind='batch_discovery' AND downstream_discovery_received(ref)) THEN PERFORM media_new_origin_integrity(ref); RETURN NULL; END IF;");
        $this->patch('media_new_origin_integrity(uuid)', 'cc.last_event_id IS DISTINCT FROM o.event_id', '(cc.last_event_id IS DISTINCT FROM o.event_id AND NOT downstream_discovery_received(ref))');
        $this->patch('media_new_origin_integrity(uuid)', 'cc.version<>1', '(cc.version<>1 AND NOT downstream_discovery_received(ref))');
        $this->patch('media_new_origin_integrity(uuid)', 'NOT EXISTS(SELECT 1 FROM subjects WHERE id=ref AND version=1)', '(NOT EXISTS(SELECT 1 FROM subjects WHERE id=ref AND version=1) AND NOT downstream_discovery_received(ref))');
        $this->patch('media_discovery_integrity(uuid)', 'cc.party_id IS DISTINCT FROM (SELECT facility_party_id FROM dismantling_batches WHERE subject_id=d.batch_id)', '(cc.party_id IS DISTINCT FROM (SELECT facility_party_id FROM dismantling_batches WHERE subject_id=d.batch_id) AND NOT downstream_discovery_received(d.medium_id))');
        $this->patch('media_discovery_integrity(uuid)', "NOT d.is_new AND cc.last_event_id IS DISTINCT FROM (u->>'existing_custody_event')::uuid", "NOT d.is_new AND cc.last_event_id IS DISTINCT FROM (u->>'existing_custody_event')::uuid AND NOT downstream_discovery_received(d.medium_id)");
        DB::unprepared("CREATE FUNCTION downstream_corrections_ready() RETURNS boolean LANGUAGE sql IMMUTABLE AS 'SELECT true'");
    }

    private function check(string $table, string $fragment, string $replacement): void
    {
        $matches = array_values(array_filter(DB::select('SELECT conname,pg_get_constraintdef(oid) AS body FROM pg_constraint WHERE conrelid=?::regclass AND contype=\'c\'', [$table]), fn ($row) => str_contains($row->body, $fragment) && ! ($table === 'media_authorizations' && str_contains($fragment, 'ANY') && str_contains($row->body, 'batch_id'))));
        if (count($matches) !== 1) {
            throw new RuntimeException('Unexpected discovery recipient constraint; inspect before migrating.');
        }
        DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT "'.$matches[0]->conname.'"');
        DB::statement('ALTER TABLE '.$table.' ADD CHECK ('.$replacement.')');
    }

    private function patch(string $signature, string $from, string $to): void
    {
        $body = str_replace($from, $to, DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$signature])->body, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected discovery recipient boundary: '.$signature);
        }
        DB::unprepared($body);
    }

    private function prefix(string $signature, string $body): void
    {
        if ($body === '') {
            return;
        }
        $definition = preg_replace('/\bBEGIN\s*\n/', "BEGIN\n".$body."\n", DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$signature])->body, 1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected discovery recipient guard: '.$signature);
        }
        DB::unprepared($definition);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain real discovery provenance and receipts; use forward migrations.');
    }
};
