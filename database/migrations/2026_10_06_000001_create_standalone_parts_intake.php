<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE categories, subjects, items IN SHARE ROW EXCLUSIVE MODE');
        $subtypeConstraint = $this->preflight();

        $category = DB::table('categories')->where('code', 'standalone_part')->first();
        if ($category) {
            // A fresh install's historical migration imports the live category list.
            DB::table('categories')->where('id', $category->id)->update(['question_set_version' => 'PARTS-SELF-REPORT-1.0']);
        } else {
            DB::table('categories')->insert(['id' => Str::uuid()->toString(), 'code' => 'standalone_part', 'label' => 'Standalone ICT part', 'supported' => true, 'question_set_version' => 'PARTS-SELF-REPORT-1.0']);
        }
        Schema::table('items', function (Blueprint $table): void {
            $table->text('intake_class')->default('whole_device');
        });
        DB::statement('ALTER TABLE items DROP CONSTRAINT "'.str_replace('"', '""', $subtypeConstraint).'"');
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_subtype_check CHECK (subtype IS NULL OR subtype IN ('router','keyboard','hdd','ssd','ict_cooling_fan')), ADD CONSTRAINT items_intake_class_check CHECK (intake_class IN ('whole_device','standalone_part'))");
        Schema::create('storage_assets', function (Blueprint $table): void {
            $table->foreignUuid('subject_id')->primary()->constrained('subjects')->restrictOnDelete();
            $table->text('media_kind');
        });
        DB::statement("ALTER TABLE storage_assets ADD CONSTRAINT storage_assets_media_kind_check CHECK (media_kind IN ('hdd','ssd'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_device_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME = 'subjects' THEN
        IF TG_OP = 'DELETE' OR ROW(NEW.id, NEW.public_code, NEW.kind, NEW.created_at) IS DISTINCT FROM ROW(OLD.id, OLD.public_code, OLD.kind, OLD.created_at) THEN
            RAISE EXCEPTION 'Device identity is permanent';
        END IF;
    ELSE
        IF TG_OP = 'DELETE' OR (TG_OP = 'UPDATE' AND ROW(NEW.subject_id, NEW.owner_user_id, NEW.category_id, NEW.subtype, NEW.model, NEW.identifier_ciphertext, NEW.identifier_hmac, NEW.identifier_key_version, NEW.intake_class) IS DISTINCT FROM ROW(OLD.subject_id, OLD.owner_user_id, OLD.category_id, OLD.subtype, OLD.model, OLD.identifier_ciphertext, OLD.identifier_hmac, OLD.identifier_key_version, OLD.intake_class)) THEN
            RAISE EXCEPTION 'Device identity fields require a reviewed correction';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM categories WHERE id = NEW.category_id AND supported AND (
            (code = 'peripheral' AND NEW.subtype IN ('router','keyboard') AND NEW.intake_class = 'whole_device') OR
            (code = 'standalone_part' AND NEW.subtype IN ('hdd','ssd','ict_cooling_fan') AND NEW.intake_class = 'standalone_part') OR
            (code IN ('laptop','desktop','phone','flat_monitor','printer') AND NEW.subtype IS NULL AND NEW.intake_class = 'whole_device')
        )) THEN
            RAISE EXCEPTION 'Unsupported device category, subtype or intake classification' USING ERRCODE = '23514';
        END IF;
        IF NEW.intake_class = 'standalone_part' AND NEW.subtype IN ('hdd','ssd') AND NEW.data_status <> 'YES' THEN
            RAISE EXCEPTION 'Storage media must remain data-bearing' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE FUNCTION protect_storage_facet() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' OR ROW(NEW.subject_id, NEW.media_kind) IS DISTINCT FROM ROW(OLD.subject_id, OLD.media_kind) THEN
        RAISE EXCEPTION 'Storage facet identity requires a reviewed correction' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER permanent_storage_facet BEFORE UPDATE OR DELETE ON storage_assets FOR EACH ROW EXECUTE FUNCTION protect_storage_facet();
CREATE FUNCTION verify_item_storage_facet() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    physical_id uuid;
    item_class text;
    item_subtype text;
    media text;
BEGIN
    physical_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.subject_id ELSE NEW.subject_id END;
    SELECT intake_class, subtype INTO item_class, item_subtype FROM items WHERE subject_id = physical_id;
    SELECT media_kind INTO media FROM storage_assets WHERE subject_id = physical_id;
    IF item_class = 'standalone_part' AND item_subtype IN ('hdd','ssd') THEN
        IF media IS NULL OR media <> item_subtype THEN
            RAISE EXCEPTION 'Drive requires a matching storage facet on its own identity' USING ERRCODE = '23514';
        END IF;
    ELSIF media IS NOT NULL THEN
        RAISE EXCEPTION 'Storage facet requires a matching standalone drive item' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER complete_item_storage AFTER INSERT OR UPDATE ON items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_item_storage_facet();
CREATE CONSTRAINT TRIGGER complete_storage_item AFTER INSERT OR UPDATE OR DELETE ON storage_assets DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_item_storage_facet();
SQL);
    }

    private function preflight(): string
    {
        if (Schema::hasColumn('items', 'intake_class') || Schema::hasTable('storage_assets')) {
            throw new RuntimeException('Parts migration blocked: unexpected existing schema; no changes made.');
        }
        $expected = ['laptop' => 'Laptop', 'desktop' => 'Desktop', 'phone' => 'Mobile phone', 'flat_monitor' => 'Flat-panel monitor', 'printer' => 'Printer', 'peripheral' => 'Small ICT peripheral'];
        $rows = DB::table('categories')->get()->keyBy('code');
        if (count($rows) !== 6 && count($rows) !== 7) {
            throw new RuntimeException('Parts migration blocked: unexpected category configuration; no changes made.');
        }
        foreach ($expected as $code => $label) {
            $row = $rows->get($code);
            if (! $row || $row->label !== $label || ! $row->supported || $row->question_set_version !== 'SELF-REPORT-1.0') {
                throw new RuntimeException('Parts migration blocked: unexpected category configuration; no changes made.');
            }
        }
        if ($rows->has('standalone_part')) {
            $row = $rows->get('standalone_part');
            if ($row->label !== 'Standalone ICT part' || ! $row->supported || $row->question_set_version !== 'SELF-REPORT-1.0' || DB::table('items')->where('category_id', $row->id)->exists()) {
                throw new RuntimeException('Parts migration blocked: unexpected fresh-install category; no changes made.');
            }
        } elseif (count($rows) !== 6) {
            throw new RuntimeException('Parts migration blocked: unexpected category configuration; no changes made.');
        }
        $unexpected = DB::selectOne("SELECT count(*) AS total FROM items JOIN categories ON categories.id = items.category_id WHERE ((categories.code = 'peripheral' AND items.subtype IN ('router','keyboard')) OR (categories.code IN ('laptop','desktop','phone','flat_monitor','printer') AND items.subtype IS NULL)) IS NOT TRUE");
        if ((int) $unexpected->total > 0) {
            throw new RuntimeException('Parts migration blocked: unexpected existing item classification; no changes made.');
        }
        $guards = ['protect_device_history' => '2026_10_04_000002_create_device_registration.php', 'protect_device_identity' => '2026_10_04_000002_create_device_registration.php', 'protect_device_identifier_kind' => '2026_10_05_000001_enforce_unique_device_identifiers.php'];
        foreach ($guards as $function => $file) {
            preg_match('/CREATE FUNCTION '.preg_quote($function, '/').'\(\).*?AS \$\$\n(.*?)\n\$\$;/s', file_get_contents(database_path('migrations/'.$file)), $match);
            $actual = DB::selectOne('SELECT prosrc FROM pg_proc WHERE oid = to_regprocedure(?)', [$function.'()']);
            if (! isset($match[1]) || ! $actual || trim($actual->prosrc) !== trim($match[1])) {
                throw new RuntimeException('Parts migration blocked: unexpected identity/history protection; no changes made.');
            }
        }
        $triggers = DB::select("SELECT tgname, tgenabled FROM pg_trigger WHERE NOT tgisinternal AND tgrelid IN ('items'::regclass, 'subjects'::regclass, 'item_observations'::regclass, 'domain_events'::regclass)");
        $names = array_column($triggers, 'tgname');
        sort($names);
        $expectedTriggers = ['permanent_identifier_kind', 'permanent_item_identity', 'permanent_subject_identity', 'retain_device_events', 'retain_device_observations'];
        if ($names !== $expectedTriggers || array_filter($triggers, fn ($trigger) => $trigger->tgenabled !== 'O')) {
            throw new RuntimeException('Parts migration blocked: unexpected triggers; no changes made.');
        }
        $index = DB::selectOne("SELECT indisunique, indisvalid, pg_get_expr(indpred, indrelid) AS predicate FROM pg_index WHERE indexrelid = to_regclass('items_identifier_hmac_unique')");
        if (! $index || ! $index->indisunique || ! $index->indisvalid || $index->predicate !== '(identifier_hmac IS NOT NULL)') {
            throw new RuntimeException('Parts migration blocked: unexpected identifier index; no changes made.');
        }
        $checks = DB::select("SELECT conname, pg_get_expr(conbin, conrelid) AS expression FROM pg_constraint WHERE conrelid = 'items'::regclass AND contype = 'c' AND conkey = ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid='items'::regclass AND attname='subtype')]::smallint[]");
        if (count($checks) !== 1 || preg_replace('/\s+/', '', $checks[0]->expression) !== "((subtypeISNULL)OR(subtype=ANY(ARRAY['router'::text,'keyboard'::text])))") {
            throw new RuntimeException('Parts migration blocked: unexpected subtype constraint; no changes made.');
        }

        return $checks[0]->conname;
    }

    public function down(): void
    {
        throw new RuntimeException('Permanent parts identities require a reviewed forward migration; rollback is disabled.');
    }
};
