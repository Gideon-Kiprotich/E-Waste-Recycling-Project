<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('requests') || Schema::hasTable('request_reviews') || Schema::hasColumn('places', 'version')) {
            throw new RuntimeException('Directory review migration blocked: unexpected schema.');
        }
        $guards = DB::selectOne("SELECT count(*) AS total FROM pg_trigger WHERE tgname IN ('retain_device_events','retain_request_item','permanent_request_identity','explicit_place_capability','permanent_service_area_version') AND tgenabled='O' AND tgrelid IN ('domain_events'::regclass,'request_items'::regclass,'requests'::regclass,'place_categories'::regclass,'service_areas'::regclass)");
        if ((int) $guards->total !== 5 || DB::table('organizations')->whereNotIn('review_status', ['pending', 'approved', 'rejected'])->exists() || DB::table('places')->where('kind', '<>', 'collection_point')->exists()) {
            throw new RuntimeException('Directory review migration blocked: unexpected data or protections.');
        }
        foreach (['organizations', 'places'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->bigInteger('version')->default(1);
                $t->text('encrypted_contact')->nullable();
                $t->foreignUuid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            });
        }
        Schema::table('places', function (Blueprint $t): void {
            $t->timestampTz('reviewed_at')->nullable();
            $t->text('review_reason')->nullable();
        });
        foreach ([['organizations', 'review_status', "review_status IN ('pending','approved','rejected','suspended')"], ['places', 'review_status', "review_status IN ('pending','approved','rejected','suspended')"], ['places', 'kind', "kind IN ('collection_point','facility','depot')"], ['attachments', 'mime', "mime IN ('image/jpeg','image/png','application/pdf')"]] as [$table, $needle, $replacement]) {
            $checks = DB::select("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid=?::regclass AND contype='c'", [$table]);
            $matching = array_filter($checks, fn ($c) => str_contains($c->definition, $needle) && ! str_contains($c->definition, 'reviewed_at'));
            if (count($matching) !== 1) {
                throw new RuntimeException('Directory review migration blocked: unexpected check definition.');
            }
            $name = array_values($matching)[0]->conname;
            DB::statement('ALTER TABLE "'.$table.'" DROP CONSTRAINT "'.str_replace('"', '""', $name).'"');
            DB::statement('ALTER TABLE "'.$table.'" ADD CHECK ('.$replacement.')');
        }
        foreach (['organizations', 'places'] as $table) {
            DB::statement('ALTER TABLE '.$table.' ADD CHECK (version >= 1)');
        }
        Schema::create('directory_revisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->text('record_kind');
            $t->uuid('record_id');
            $t->bigInteger('version');
            $t->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $t->text('reason_ciphertext');
            $t->text('snapshot_ciphertext');
            $t->foreignUuid('event_id')->constrained('domain_events')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['record_kind', 'record_id', 'version']);
        });
        Schema::create('request_reviews', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('request_id')->constrained('requests')->restrictOnDelete();
            $t->bigInteger('evaluated_version');
            $t->text('decision');
            $t->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $t->text('reason_ciphertext');
            $t->jsonb('snapshot');
            $t->foreignUuid('event_id')->constrained('domain_events')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->index(['request_id', 'created_at', 'id']);
        });
        Schema::table('requests', function (Blueprint $t): void {
            $t->text('review_state')->default('pending');
            $t->foreignUuid('review_id')->nullable()->constrained('request_reviews')->restrictOnDelete();
            $t->index(['review_state', 'created_at', 'id']);
        });
        DB::statement('ALTER TABLE evidence_links ALTER COLUMN subject_id DROP NOT NULL');
        Schema::table('evidence_links', function (Blueprint $t): void {
            $t->text('record_kind')->nullable();
            $t->uuid('record_id')->nullable();
            $t->index(['record_kind', 'record_id']);
        });
        DB::statement("ALTER TABLE evidence_links ADD CHECK ((subject_id IS NOT NULL AND record_kind IS NULL AND record_id IS NULL) OR (subject_id IS NULL AND record_kind IS NOT NULL AND record_kind IN ('organization','place','request_review') AND record_id IS NOT NULL))");
        DB::statement("ALTER TABLE directory_revisions ADD CHECK (record_kind IN ('organization','place')), ADD CHECK (version > 0)");
        DB::statement("ALTER TABLE request_reviews ADD CHECK (decision IN ('ready','hold')), ADD CHECK (evaluated_version > 0), ADD CHECK (jsonb_typeof(snapshot)='object' AND jsonb_exists(snapshot,'blockers') AND jsonb_typeof(snapshot->'blockers')='array'), ADD CHECK (decision <> 'ready' OR snapshot->'blockers' = '[]'::jsonb)");
        DB::statement("ALTER TABLE requests ADD CHECK (review_state IN ('pending','ready','hold','stale')), ADD CHECK (review_state NOT IN ('ready','hold') OR review_id IS NOT NULL)");
        DB::unprepared(<<<'SQL'
CREATE TRIGGER retain_directory_revisions BEFORE UPDATE OR DELETE ON directory_revisions FOR EACH ROW EXECUTE FUNCTION protect_device_history();
CREATE TRIGGER retain_request_reviews BEFORE UPDATE OR DELETE ON request_reviews FOR EACH ROW EXECUTE FUNCTION protect_device_history();
CREATE FUNCTION protect_directory_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' OR ROW(NEW.id,NEW.code,NEW.synthetic) IS DISTINCT FROM ROW(OLD.id,OLD.code,OLD.synthetic) THEN RAISE EXCEPTION 'Directory identity and synthetic markers are permanent'; END IF;
    IF TG_TABLE_NAME='places' THEN
        IF NEW.organization_id IS DISTINCT FROM OLD.organization_id THEN RAISE EXCEPTION 'Place organization is permanent'; END IF;
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER permanent_organization_identity BEFORE UPDATE OR DELETE ON organizations FOR EACH ROW EXECUTE FUNCTION protect_directory_identity();
CREATE TRIGGER permanent_place_identity BEFORE UPDATE OR DELETE ON places FOR EACH ROW EXECUTE FUNCTION protect_directory_identity();
CREATE FUNCTION protect_review_projection() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.version <> OLD.version AND NEW.review_id IS NOT DISTINCT FROM OLD.review_id AND OLD.review_state <> 'pending' THEN NEW.review_state := 'stale'; END IF;
    IF NEW.review_state IN ('ready','hold') AND NOT EXISTS (SELECT 1 FROM request_reviews WHERE id=NEW.review_id AND request_id=NEW.id AND decision=NEW.review_state AND evaluated_version=NEW.version-1) THEN RAISE EXCEPTION 'Review projection requires its matching evaluated version'; END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER current_request_review BEFORE UPDATE ON requests FOR EACH ROW EXECUTE FUNCTION protect_review_projection();
CREATE FUNCTION directory_revision_target() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (NEW.record_kind='organization' AND NOT EXISTS (SELECT 1 FROM organizations WHERE id=NEW.record_id AND version=NEW.version)) OR (NEW.record_kind='place' AND NOT EXISTS (SELECT 1 FROM places WHERE id=NEW.record_id AND version=NEW.version)) THEN RAISE EXCEPTION 'Directory revision target/version mismatch'; END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER valid_directory_revision BEFORE INSERT ON directory_revisions FOR EACH ROW EXECUTE FUNCTION directory_revision_target();
CREATE FUNCTION valid_management_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.record_kind='organization' AND NOT EXISTS (SELECT 1 FROM organizations o JOIN domain_events e ON e.aggregate_id=o.id AND e.aggregate_kind='organization' WHERE o.id=NEW.record_id AND e.id=NEW.event_id) THEN RAISE EXCEPTION 'Evidence organization target mismatch'; END IF;
    IF NEW.record_kind='place' AND NOT EXISTS (SELECT 1 FROM places p JOIN domain_events e ON e.aggregate_id=p.id AND e.aggregate_kind='place' WHERE p.id=NEW.record_id AND e.id=NEW.event_id) THEN RAISE EXCEPTION 'Evidence place target mismatch'; END IF;
    IF NEW.record_kind='request_review' AND NOT EXISTS (SELECT 1 FROM request_reviews WHERE id=NEW.record_id AND event_id=NEW.event_id) THEN RAISE EXCEPTION 'Evidence review target mismatch'; END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER management_evidence_target BEFORE INSERT ON evidence_links FOR EACH ROW EXECUTE FUNCTION valid_management_evidence();
CREATE TRIGGER retain_evidence_links BEFORE UPDATE OR DELETE ON evidence_links FOR EACH ROW EXECUTE FUNCTION protect_device_history();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Directory and review history requires a reviewed forward migration.');
    }
};
