<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE items, subjects, storage_assets IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        if (Schema::hasTable('media_inventory_records') || DB::table('categories')->where('code', 'processor_media')->exists()
            || DB::table('items')->whereNotIn('intake_class', ['whole_device', 'standalone_part'])->exists()) {
            throw new RuntimeException('Media inventory upgrade blocked: unexpected prior inventory or intake state; inspect privately.');
        }
        foreach (DB::table('first_receipts')->pluck('subject_id') as $id) {
            DB::select('SELECT handover_chain_check(?)', [$id]);
            DB::select('SELECT inspection_integrity(?)', [$id]);
        }
        // Preserve historical guards/resources; extend only the explicit derived-media branch.
        $identity = DB::selectOne("SELECT pg_get_functiondef('protect_device_identity()'::regprocedure) AS body")->body;
        $identity = str_replace("(code = 'peripheral'", "(code = 'processor_media' AND NEW.subtype IN ('hdd','ssd','other_nonvolatile') AND NEW.intake_class = 'processor_derived') OR\n            (code = 'peripheral'", $identity, $count);
        if ($count !== 1) {
            throw new RuntimeException('Media upgrade blocked: unexpected identity guard.');
        }
        $identity = str_replace("NEW.intake_class = 'standalone_part' AND NEW.subtype IN ('hdd','ssd')", "(NEW.intake_class = 'processor_derived' OR (NEW.intake_class = 'standalone_part' AND NEW.subtype IN ('hdd','ssd')))", $identity);
        DB::unprepared($identity);
        DB::statement("ALTER TABLE items DROP CONSTRAINT items_intake_class_check, ADD CONSTRAINT items_intake_class_check CHECK (intake_class IN ('whole_device','standalone_part','processor_derived'))");
        DB::statement("ALTER TABLE items DROP CONSTRAINT items_subtype_check, ADD CONSTRAINT items_subtype_check CHECK (subtype IS NULL OR subtype IN ('router','keyboard','hdd','ssd','ict_cooling_fan','other_nonvolatile'))");
        $this->replaceCheck('items', 'processing_state =', "processing_state IN ('registered','received','assessed','released','dismantled','closed','inventoried')");
        DB::statement("ALTER TABLE storage_assets DROP CONSTRAINT storage_assets_media_kind_check, ADD CONSTRAINT storage_assets_media_kind_check CHECK (media_kind IN ('hdd','ssd','other_nonvolatile'))");
        $facet = DB::selectOne("SELECT pg_get_functiondef('verify_item_storage_facet()'::regprocedure) AS body")->body;
        $facet = str_replace("item_class = 'standalone_part' AND item_subtype IN ('hdd','ssd')", "item_class = 'processor_derived' OR (item_class = 'standalone_part' AND item_subtype IN ('hdd','ssd'))", $facet, $count);
        if ($count !== 1) {
            throw new RuntimeException('Media upgrade blocked: unexpected facet guard.');
        }
        DB::unprepared($facet);
        DB::statement("INSERT INTO categories(id,code,label,supported,question_set_version) VALUES ('bb8300f1-100b-4000-8000-000000000001','processor_media','Facility-identified storage medium',true,'MEDIA-INVENTORY-1.0')");
        DB::unprepared(file_get_contents(__DIR__.'/../sql/media_inventory.sql'));
        DB::statement('ALTER TABLE sanitization_records ADD COLUMN placement_record_id uuid REFERENCES media_inventory_records(id) ON DELETE RESTRICT, ADD COLUMN authorization_context_version integer NOT NULL DEFAULT 1, ADD COLUMN authorization_id uuid REFERENCES media_authorizations(id) ON DELETE RESTRICT');
        $this->replaceCheck('sanitization_records', 'custody_version >=', 'version>=1 AND source_subject_version>=1 AND assessment_version>=2 AND ((authorization_context_version=1 AND authorization_id IS NULL AND custody_version>=2) OR (authorization_context_version=2 AND authorization_id IS NOT NULL AND custody_version>=1))');
        DB::unprepared(file_get_contents(__DIR__.'/../sql/media_inventory_assurance.sql'));
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function replaceCheck(string $table, string $needle, string $replacement): void
    {
        $rows = DB::select("SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid=?::regclass AND contype='c'", [$table]);
        $matches = array_filter($rows, fn ($r) => str_contains($r->definition, $needle));
        if (count($matches) !== 1) {
            throw new RuntimeException('Media upgrade blocked: unexpected constraint definition.');
        }
        $name = str_replace('"', '""', array_values($matches)[0]->conname);
        DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT "'.$name.'", ADD CHECK ('.$replacement.')');
    }

    public function down(): void
    {
        throw new RuntimeException('Media identity and inventory history are permanent; use a reviewed forward correction.');
    }
};
