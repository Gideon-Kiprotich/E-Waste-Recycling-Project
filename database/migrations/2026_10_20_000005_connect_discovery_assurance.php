<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->check('media_authorizations', 'authorization_context_version = ANY', 'authorization_context_version IN (2,3,4,5)');
        $this->check('media_authorizations', 'batch_id IS NULL', '(authorization_context_version=2 AND batch_id IS NULL AND downstream_receipt_id IS NULL) OR (authorization_context_version IN (3,5) AND batch_id IS NOT NULL AND downstream_receipt_id IS NULL) OR (authorization_context_version=4 AND batch_id IS NOT NULL AND downstream_receipt_id IS NOT NULL)');
        $this->check('sanitization_records', 'authorization_context_version = ANY', 'version>=1 AND source_subject_version>=1 AND assessment_version>=2 AND ((authorization_context_version=1 AND authorization_id IS NULL AND custody_version>=2) OR (authorization_context_version IN (2,3,4,5) AND authorization_id IS NOT NULL AND custody_version>=1))');
        DB::unprepared(file_get_contents(database_path('sql/media_discovery_assurance.sql')));
        $this->patch('media_authorization_applicable_before_restriction(media_authorizations)', 'CASE WHEN a.authorization_context_version=4', 'CASE WHEN a.authorization_context_version=5 THEN discovery_authorization_applicable(a) WHEN a.authorization_context_version=4');
        $this->patch('sanitization_ready_before_restriction(uuid,uuid)', 'CASE WHEN EXISTS(SELECT 1 FROM downstream_records', 'CASE WHEN EXISTS(SELECT 1 FROM batch_media_discoveries WHERE medium_id=ref) THEN EXISTS(SELECT 1 FROM media_authorization_current cur JOIN media_authorizations a ON a.id=cur.record_id WHERE cur.subject_id=ref AND a.authorization_context_version=5 AND discovery_authorization_applicable(a) AND media_review_scope(actor,ref)) WHEN EXISTS(SELECT 1 FROM downstream_records');
        $this->patch('sanitization_dependencies_before_restriction(sanitization_records)', 'CASE WHEN r.authorization_context_version=4', 'CASE WHEN r.authorization_context_version=5 THEN discovery_sanitization_dependencies(r) WHEN r.authorization_context_version=4');
        $this->patch('sanitization_insert_guard()', 'IF EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=NEW.subject_id) THEN', "IF EXISTS(SELECT 1 FROM batch_media_discoveries WHERE medium_id=NEW.subject_id) THEN\n IF NEW.authorization_context_version<>5 OR NEW.authorization_id IS DISTINCT FROM (SELECT record_id FROM media_authorization_current WHERE subject_id=NEW.subject_id) THEN RAISE EXCEPTION 'Discovery media requires explicit context 5 authorization'; END IF;\n SELECT ac0.* INTO ac FROM assessment_current ac0 JOIN batch_media_discoveries d ON d.parent_id=ac0.subject_id WHERE d.medium_id=NEW.subject_id;\n ELSIF EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=NEW.subject_id) THEN");
        // Discovery supplements provide on-site accountability only, including recognized existing identities.
        $this->patch('downstream_record_guard()', "BEGIN\n", "BEGIN\n IF NEW.kind IN ('reserve','dispatch') AND EXISTS(SELECT 1 FROM batch_media_discoveries WHERE medium_id=NEW.output_id) THEN RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='Discovery-origin downstream movement requires the deferred Phase 16 contract.'; END IF;\n");
    }

    private function check(string $table, string $fragment, string $replacement): void
    {
        $found = DB::select("SELECT conname,pg_get_constraintdef(oid) AS body FROM pg_constraint WHERE conrelid=?::regclass AND contype='c'", [$table]);
        $matches = array_values(array_filter($found, fn ($c) => str_contains($c->body, $fragment)));
        if (count($matches) !== 1) {
            throw new RuntimeException('Unexpected discovery assurance constraint; inspect before migrating.');
        }
        DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT "'.$matches[0]->conname.'"');
        DB::statement('ALTER TABLE '.$table.' ADD CHECK ('.$replacement.')');
    }

    private function patch(string $signature, string $from, string $to): void
    {
        $body = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$signature])->body;
        $body = str_replace($from, $to, $body, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected discovery authorization contract; no migration repair attempted.');
        }
        DB::unprepared($body);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward correction migration.');
    }
};
