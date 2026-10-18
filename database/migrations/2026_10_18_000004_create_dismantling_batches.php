<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE subjects, items, custody_current, media_inventory_entries IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        if (Schema::hasTable('dismantling_batches') || ! Schema::hasTable('reuse_return_items') || DB::table('items')->whereIn('processing_state', ['dismantled', 'closed'])->exists() || DB::table('subjects')->where('kind', '<>', 'item')->exists()) {
            throw new RuntimeException('Batch upgrade blocked by unexpected physical/schema state; preserve data and inspect privately.');
        }
        foreach (DB::table('first_receipts')->pluck('subject_id') as $id) {
            DB::select('SELECT handover_chain_check(?)', [$id]);
            DB::select('SELECT inspection_integrity(?)', [$id]);
        }
        foreach (DB::table('media_origins')->pluck('subject_id') as $id) {
            DB::select('SELECT media_origin_integrity(?)', [$id]);
        }
        DB::unprepared(file_get_contents(__DIR__.'/../sql/dismantling_batches.sql'));
        $this->replaceCheck('subjects', "kind = 'item'", "kind IN ('item','batch','output')");
        $this->patch('handover_chain_check(uuid)', 'AND NOT reuse_source_matches(ref,party,ev,cv,sv) THEN', 'AND NOT reuse_source_matches(ref,party,ev,cv,sv) AND NOT batch_source_matches(ref,party,ev,cv,sv) THEN');
        $this->patch('inspection_integrity(uuid)', "processing_state='assessed'", "(processing_state='assessed' OR (processing_state='dismantled' AND EXISTS(SELECT 1 FROM batch_members WHERE subject_id=ref)))", 2);
        $this->patch('receipt_integrity()', "i.processing_state NOT IN ('received','assessed')", "(i.processing_state NOT IN ('received','assessed') AND NOT (i.processing_state='dismantled' AND EXISTS(SELECT 1 FROM batch_members WHERE subject_id=ref)))");
        $this->patch('media_origin_integrity(uuid)', 'OR NOT (EXISTS(SELECT 1 FROM subjects WHERE id=ref AND version=1) OR reuse_source_matches(ref,r.facility_party_id,o.event_id,1,1))', 'OR NOT (EXISTS(SELECT 1 FROM subjects WHERE id=ref AND version=1) OR reuse_source_matches(ref,r.facility_party_id,o.event_id,1,1) OR batch_source_matches(ref,r.facility_party_id,o.event_id,1,1))');
        $this->patch('handover_subject_step(uuid,jsonb,jsonb)', 'OR EXISTS (SELECT 1 FROM inspection_steps', "OR EXISTS(SELECT 1 FROM batch_members bm WHERE bm.subject_id=ref AND bm.source_subject_version=(before_row->>'version')::bigint AND bm.source_subject_version+1=(after_row->>'version')::bigint) OR EXISTS(SELECT 1 FROM batch_detachments bd WHERE bd.subject_id=ref AND bd.source_subject_version=(before_row->>'version')::bigint AND bd.source_subject_version+1=(after_row->>'version')::bigint) OR EXISTS (SELECT 1 FROM inspection_steps");
        $this->patch('handover_receipt_projection(uuid,uuid,jsonb,jsonb)', 'OR EXISTS (SELECT 1 FROM inspection_steps', "OR EXISTS(SELECT 1 FROM batch_members WHERE subject_id=ref AND source_subject_version+1=(actual->>'version')::bigint) OR EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=ref AND source_subject_version+1=(actual->>'version')::bigint) OR EXISTS (SELECT 1 FROM inspection_steps");
        $this->replaceCheck('sanitization_records', 'authorization_context_version = 2', 'version>=1 AND source_subject_version>=1 AND assessment_version>=2 AND ((authorization_context_version=1 AND authorization_id IS NULL AND custody_version>=2) OR (authorization_context_version IN (2,3) AND authorization_id IS NOT NULL AND custody_version>=1))');
        DB::unprepared(file_get_contents(__DIR__.'/../sql/batch_assurance.sql'));
        $this->patch('sanitization_insert_guard()', "IF EXISTS(SELECT 1 FROM items WHERE subject_id=NEW.subject_id AND intake_class='processor_derived') THEN", "IF EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=NEW.subject_id) THEN\n IF NEW.authorization_context_version<>3 OR NEW.authorization_id IS DISTINCT FROM (SELECT record_id FROM media_authorization_current WHERE subject_id=NEW.subject_id) THEN RAISE EXCEPTION 'Detached media needs explicit current batch authorization'; END IF;\n SELECT ac0.* INTO ac FROM assessment_current ac0 JOIN batch_detachments bd ON bd.parent_id=ac0.subject_id WHERE bd.subject_id=NEW.subject_id;\n ELSIF EXISTS(SELECT 1 FROM items WHERE subject_id=NEW.subject_id AND intake_class='processor_derived') THEN");
        foreach (['dismantling_batches', 'batch_records', 'batch_members', 'batch_detachments', 'batch_outputs', 'subjects', 'items', 'custody_current', 'domain_events', 'commands'] as $table) {
            $operations = in_array($table, ['batch_records', 'batch_members', 'batch_detachments', 'domain_events', 'commands'], true) ? 'INSERT' : 'INSERT OR UPDATE';
            DB::statement('CREATE CONSTRAINT TRIGGER batch_final_'.$table.' AFTER '.$operations.' ON '.$table.' DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION batch_final()');
        }
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function patch(string $function, string $search, string $replacement, int $expected = 1): void
    {
        $body = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$function])->body;
        $body = str_replace($search, $replacement, $body, $count);
        if ($count !== $expected) {
            throw new RuntimeException('Batch upgrade blocked: unexpected '.$function.' protection.');
        }
        DB::unprepared($body);
    }

    private function replaceCheck(string $table, string $needle, string $replacement): void
    {
        $matches = collect(DB::select("SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid=?::regclass AND contype='c'", [$table]))->filter(fn ($c) => str_contains($c->definition, $needle));
        if ($matches->count() !== 1) {
            throw new RuntimeException('Batch upgrade blocked: unexpected '.$table.' constraint.');
        }
        DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT "'.$matches->first()->conname.'", ADD CHECK ('.$replacement.')');
    }

    public function down(): void
    {
        throw new RuntimeException('Dismantling lineage and measurements are permanent; use reviewed forward correction.');
    }
};
