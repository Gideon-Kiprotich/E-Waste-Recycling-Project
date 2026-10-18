<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE items, subjects, custody_current IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        if (Schema::hasTable('refurbishment_records') || ! Schema::hasTable('media_origins') || DB::table('items')->whereNotIn('processing_state', ['registered', 'received', 'assessed', 'inventoried'])->exists()) {
            throw new RuntimeException('Reuse upgrade blocked: unexpected schema or physical state; inspect privately.');
        }
        foreach (DB::table('first_receipts')->pluck('subject_id') as $id) {
            DB::select('SELECT handover_chain_check(?)', [$id]);
            DB::select('SELECT inspection_integrity(?)', [$id]);
        }
        foreach (DB::table('media_origins')->pluck('subject_id') as $id) {
            DB::select('SELECT media_origin_integrity(?)', [$id]);
        }
        DB::unprepared(file_get_contents(__DIR__.'/../sql/refurbishment.sql'));
        $definition = json_decode(file_get_contents(__DIR__.'/../data/reuse-tests-1.0.json'), true, flags: JSON_THROW_ON_ERROR);
        $json = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        DB::table('refurbishment_profiles')->insert(['version' => $definition['version'], 'definition' => $json, 'checksum' => hash('sha256', $json)]);
        // Validate the historical chain against the frozen source, then its explicit terminal return.
        $this->patch('handover_chain_check(uuid)', 'IF NOT EXISTS (SELECT 1 FROM custody_current c JOIN subjects s ON s.id=c.subject_id WHERE c.subject_id=ref AND c.party_id=party AND c.last_event_id=ev AND c.version=cv AND s.version=sv) THEN', 'IF NOT EXISTS (SELECT 1 FROM custody_current c JOIN subjects s ON s.id=c.subject_id WHERE c.subject_id=ref AND c.party_id=party AND c.last_event_id=ev AND c.version=cv AND s.version=sv) AND NOT reuse_source_matches(ref,party,ev,cv,sv) THEN');
        $this->patch('inspection_integrity(uuid)', "processing_state='assessed')", "(processing_state='assessed' OR (processing_state='released' AND EXISTS(SELECT 1 FROM reuse_return_items WHERE subject_id=ref))))", 2);
        $this->patch('receipt_integrity()', "i.processing_state NOT IN ('received','assessed')", "(i.processing_state NOT IN ('received','assessed') AND NOT (i.processing_state='released' AND EXISTS(SELECT 1 FROM reuse_return_items WHERE subject_id=ref)))");
        $this->patch('media_custody_guard()', 'ref:=NEW.subject_id;', "ref:=NEW.subject_id; IF TG_TABLE_NAME='custody_current' THEN IF reuse_target_matches(ref,NEW.party_id,NEW.last_event_id,NEW.version) THEN RETURN NEW; END IF; END IF;");
        $this->patch('media_origin_integrity(uuid)', "i.processing_state<>'inventoried'", "(i.processing_state<>'inventoried' AND NOT (i.processing_state='released' AND EXISTS(SELECT 1 FROM reuse_return_items WHERE subject_id=ref)))");
        $this->patch('media_origin_integrity(uuid)', 'OR cc.party_id IS DISTINCT FROM r.facility_party_id OR cc.last_event_id IS DISTINCT FROM o.event_id OR cc.version<>1', 'OR NOT ((cc.party_id IS NOT DISTINCT FROM r.facility_party_id AND cc.last_event_id IS NOT DISTINCT FROM o.event_id AND cc.version=1) OR reuse_source_matches(ref,r.facility_party_id,o.event_id,1,1))');
        $this->patch('media_origin_integrity(uuid)', 'OR NOT EXISTS(SELECT 1 FROM subjects WHERE id=ref AND version=1)', 'OR NOT (EXISTS(SELECT 1 FROM subjects WHERE id=ref AND version=1) OR reuse_source_matches(ref,r.facility_party_id,o.event_id,1,1))');
        $this->patch('media_origin_integrity(uuid)', 'OR NOT EXISTS(SELECT 1 FROM custody_current WHERE subject_id=o.parent_id AND party_id=cc.party_id AND last_event_id=r.custody_event_id AND version=r.custody_version)', 'OR NOT (EXISTS(SELECT 1 FROM custody_current WHERE subject_id=o.parent_id AND party_id=cc.party_id AND last_event_id=r.custody_event_id AND version=r.custody_version) OR EXISTS(SELECT 1 FROM reuse_return_items ri JOIN reuse_manifest m USING(authorization_id,subject_id) JOIN reuse_return_items child ON child.authorization_id=ri.authorization_id WHERE ri.subject_id=o.parent_id AND child.subject_id=ref AND m.party_id=r.facility_party_id AND m.event_id=r.custody_event_id AND m.custody_version=r.custody_version))');
        // Earlier consumed route/receipt snapshots accept only a proved, ledger-backed version step.
        $this->patch('handover_subject_step(uuid,jsonb,jsonb)', 'OR EXISTS (SELECT 1 FROM inspection_steps', "OR EXISTS(SELECT 1 FROM reuse_return_items a JOIN reuse_manifest m USING(authorization_id,subject_id) WHERE a.subject_id=ref AND m.subject_version=(before_row->>'version')::bigint AND a.subject_version=(after_row->>'version')::bigint) OR EXISTS (SELECT 1 FROM inspection_steps");
        $this->patch('handover_receipt_projection(uuid,uuid,jsonb,jsonb)', 'OR EXISTS (SELECT 1 FROM inspection_steps', "OR EXISTS(SELECT 1 FROM reuse_return_items WHERE subject_id=ref AND subject_version=(actual->>'version')::bigint) OR EXISTS (SELECT 1 FROM inspection_steps");
        foreach (['refurbishment_records', 'refurbishment_current', 'reuse_authorizations', 'reuse_manifest', 'reuse_return_items', 'subjects', 'items', 'custody_current', 'domain_events', 'commands'] as $table) {
            $operation = in_array($table, ['refurbishment_records', 'reuse_manifest', 'reuse_return_items', 'domain_events', 'commands'], true) ? 'INSERT' : 'INSERT OR UPDATE';
            DB::statement('CREATE CONSTRAINT TRIGGER reuse_final_'.$table.' AFTER '.$operation.' ON '.$table.' DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION refurbishment_final()');
        }
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function patch(string $function, string $search, string $replacement, int $expected = 1): void
    {
        $body = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$function])->body;
        $body = str_replace($search, $replacement, $body, $count);
        if ($count !== $expected) {
            throw new RuntimeException('Reuse upgrade blocked: unexpected '.$function.' guard; no protection disabled.');
        }
        DB::unprepared($body);
    }

    public function down(): void
    {
        throw new RuntimeException('Acknowledged reuse and work history are permanent; use a reviewed forward correction.');
    }
};
