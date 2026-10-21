<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/media_correction_domains.sql')));
        DB::statement('ALTER TABLE batch_records DROP CONSTRAINT batch_records_output_type_check');
        DB::statement("ALTER TABLE batch_records ADD CHECK(output_type IN ('material','component','residue','storage'))");
        $this->prefix('media_inventory_insert_guard()', "IF NEW.kind='review_binding' THEN PERFORM media_binding_record_guard(NEW); RETURN NEW; END IF;\nIF NEW.correction_review_id IS NOT NULL OR NEW.observer_id IS NOT NULL THEN RAISE EXCEPTION 'Only typed binding records carry review authority'; END IF;");
        $this->prefix('batch_record_insert_guard()', "IF NEW.correction_review_id IS NOT NULL THEN PERFORM media_batch_record_guard(NEW); RETURN NEW; END IF;\nIF NEW.output_type='storage' OR NEW.observer_id IS NOT NULL THEN RAISE EXCEPTION 'Canonical supplementary output requires typed review'; END IF;");
        $this->prefix('media_origin_integrity(uuid)', "IF EXISTS(SELECT 1 FROM media_origins WHERE subject_id=ref AND origin_kind='batch_discovery') THEN PERFORM media_new_origin_integrity(ref); RETURN; END IF;");
        $this->prefix('media_review_boundary(uuid)', "IF (SELECT kind FROM restriction_records WHERE id=ref) IN ('approve','fulfill') THEN PERFORM media_domain_effects_complete(ref); END IF;");
        $this->prefix('batch_final()', <<<'SQL'
IF TG_TABLE_NAME='batch_records' AND (to_jsonb(NEW)->>'correction_review_id') IS NOT NULL THEN
PERFORM batch_integrity((to_jsonb(NEW)->>'batch_id')::uuid);
IF (to_jsonb(NEW)->'context'->'access') IS DISTINCT FROM batch_access((to_jsonb(NEW)->>'observer_id')::uuid,(SELECT facility_party_id FROM dismantling_batches WHERE subject_id=(to_jsonb(NEW)->>'batch_id')::uuid)) THEN RAISE EXCEPTION 'Supplement observer control changed before COMMIT'; END IF; RETURN NULL; END IF;
SQL);
        $this->patch('restriction_source_generation(uuid,uuid)', "source_kind<>'dependent'", "NOT (source_kind='dependent' OR (source_kind='media_correction' AND fact_key='impact_continuity'))");
        $this->patch('restriction_family(uuid)', 'UNION SELECT parent_id,subject_id FROM batch_detachments', 'UNION SELECT parent_id,subject_id FROM batch_detachments UNION SELECT parent_id,medium_id FROM batch_media_discoveries UNION SELECT batch_id,medium_id FROM batch_media_discoveries');
        $this->patch('media_review_eligible(restriction_cases,restriction_records)', 'RETURN media_batch_onsite(c.subject_id) AND EXISTS', 'RETURN media_batch_onsite(c.subject_id) AND media_units_valid(c,p) AND EXISTS');
        // Keep original detachment validation, and add only the separately reviewed supplementary origin.
        $definition = $this->definition('batch_integrity(uuid)');
        $needle = 'NOT EXISTS(SELECT 1 FROM batch_detachments WHERE subject_id=o.subject_id AND batch_id=ref AND parent_id=o.source_item_id AND record_id=o.production_record_id)';
        $definition = str_replace($needle, '('.$needle.' AND NOT EXISTS(SELECT 1 FROM batch_media_discoveries discovered WHERE discovered.medium_id=o.subject_id AND discovered.batch_id=o.batch_id AND discovered.production_id=o.production_record_id))', $definition, $count);
        if ($count !== 1) {
            throw new RuntimeException('Supplement migration blocked: unexpected canonical production guard.');
        }
        DB::unprepared($definition);
    }

    private function definition(string $signature): string
    {
        return DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS definition', [$signature])->definition;
    }

    private function patch(string $signature, string $from, string $to): void
    {
        $definition = str_replace($from, $to, $this->definition($signature), $count);
        if ($count !== 1) {
            throw new RuntimeException('Media domain migration blocked: unexpected preceding definition.');
        }
        DB::unprepared($definition);
    }

    private function prefix(string $signature, string $body): void
    {
        $definition = preg_replace('/\bBEGIN\s*\n/', "BEGIN\n".$body."\n", $this->definition($signature), 1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Media domain migration blocked: unexpected preceding trigger.');
        }
        DB::unprepared($definition);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward media-domain migration.');
    }
};
