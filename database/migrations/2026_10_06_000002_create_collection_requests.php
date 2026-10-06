<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('storage_assets') || ! Schema::hasColumn('items', 'intake_class') || Schema::hasTable('requests')) {
            throw new RuntimeException('Collection migration blocked: unexpected schema.');
        }
        $guards = DB::selectOne("SELECT count(*) AS total FROM pg_trigger WHERE tgrelid IN ('items'::regclass, 'domain_events'::regclass) AND tgname IN ('permanent_item_identity', 'retain_device_events') AND tgenabled = 'O'");
        if ((int) $guards->total !== 2) {
            throw new RuntimeException('Collection migration blocked: existing protections are not enabled.');
        }
        Schema::create('organizations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->text('code')->unique();
            $t->text('name');
            $t->text('kind');
            $t->text('review_status');
            $t->boolean('synthetic');
            $t->timestampTz('reviewed_at')->nullable();
            $t->text('review_reason')->nullable();
        });
        Schema::create('places', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->text('code')->unique();
            $t->text('kind');
            $t->text('name');
            $t->decimal('latitude', 9, 6);
            $t->decimal('longitude', 9, 6);
            $t->jsonb('hours');
            $t->boolean('active');
            $t->text('review_status');
            $t->boolean('synthetic');
        });
        Schema::create('place_categories', function (Blueprint $t): void {
            $t->foreignUuid('place_id')->constrained('places')->restrictOnDelete();
            $t->foreignUuid('category_id')->constrained('categories')->restrictOnDelete();
            // Empty subtype is an explicit whole-device capability, never a wildcard for parts.
            $t->text('subtype')->default('');
            $t->primary(['place_id', 'category_id', 'subtype']);
        });
        Schema::create('service_areas', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->text('version_code')->unique();
            $t->jsonb('geometry');
            $t->text('provenance');
            $t->boolean('synthetic');
            $t->boolean('active');
        });
        Schema::create('request_private_details', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->text('encrypted_contact');
            $t->text('encrypted_address')->nullable();
            $t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 9, 6)->nullable();
        });
        Schema::create('requests', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->text('public_code')->unique();
            $t->foreignUuid('consumer_id')->constrained('users')->restrictOnDelete();
            $t->text('mode');
            $t->foreignUuid('point_id')->nullable()->constrained('places')->restrictOnDelete();
            $t->foreignUuid('private_details_id')->unique()->constrained('request_private_details')->restrictOnDelete();
            $t->foreignUuid('service_area_id')->constrained('service_areas')->restrictOnDelete();
            $t->date('preferred_date');
            $t->text('state')->default('SUBMITTED');
            $t->bigInteger('version')->default(1);
            $t->timestampTz('created_at');
            $t->index(['consumer_id', 'created_at', 'id']);
        });
        Schema::create('request_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('request_id')->constrained('requests')->restrictOnDelete();
            $t->foreignUuid('item_id')->constrained('items', 'subject_id')->restrictOnDelete();
            $t->text('outcome')->default('outstanding');
            $t->boolean('active')->default(true);
            $t->decimal('estimated_kg', 12, 3);
            $t->date('preferred_date');
            $t->foreignUuid('initial_observation_id')->constrained('item_observations')->restrictOnDelete();
            $t->foreignUuid('current_observation_id')->constrained('item_observations')->restrictOnDelete();
            $t->bigInteger('initial_subject_version');
            $t->bigInteger('current_subject_version');
            $t->text('eligibility_version');
            $t->jsonb('initial_review_reasons');
            $t->jsonb('review_reasons');
            $t->unique(['request_id', 'item_id']);
        });
        DB::statement('ALTER TABLE domain_events ALTER COLUMN subject_id DROP NOT NULL');
        DB::statement("ALTER TABLE organizations ADD CHECK (kind IN ('collector','processor','both')), ADD CHECK (review_status IN ('pending','approved','rejected')), ADD CHECK (review_status <> 'approved' OR (reviewed_at IS NOT NULL AND review_reason IS NOT NULL))");
        DB::statement("ALTER TABLE places ADD CHECK (kind = 'collection_point'), ADD CHECK (review_status IN ('pending','approved','rejected')), ADD CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)");
        DB::statement("ALTER TABLE service_areas ADD CHECK (jsonb_typeof(geometry) = 'object')");
        DB::statement('ALTER TABLE request_private_details ADD CHECK ((latitude IS NULL AND longitude IS NULL) OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))');
        DB::statement("ALTER TABLE requests ADD CHECK (mode IN ('pickup','drop_off')), ADD CHECK ((mode = 'pickup' AND point_id IS NULL) OR (mode = 'drop_off' AND point_id IS NOT NULL)), ADD CHECK (version >= 1), ADD CHECK (state IN ('SUBMITTED','CANCELLED','PARTIALLY_COMPLETED','COMPLETED'))");
        DB::statement("ALTER TABLE request_items ADD CHECK (estimated_kg > 0), ADD CHECK (outcome IN ('outstanding','cancelled','collected')), ADD CHECK (active = (outcome = 'outstanding')), ADD CHECK (initial_subject_version > 0 AND current_subject_version >= initial_subject_version), ADD CHECK (jsonb_typeof(review_reasons) = 'array' AND jsonb_typeof(initial_review_reasons) = 'array')");
        DB::statement('CREATE UNIQUE INDEX request_items_active_item_unique ON request_items(item_id) WHERE active');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_request_item() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Request item history must be retained'; END IF;
    IF TG_OP = 'UPDATE' AND (ROW(NEW.id,NEW.request_id,NEW.item_id,NEW.estimated_kg,NEW.initial_observation_id,NEW.initial_subject_version,NEW.initial_review_reasons,NEW.eligibility_version) IS DISTINCT FROM ROW(OLD.id,OLD.request_id,OLD.item_id,OLD.estimated_kg,OLD.initial_observation_id,OLD.initial_subject_version,OLD.initial_review_reasons,OLD.eligibility_version) OR (OLD.outcome <> 'outstanding' AND NEW IS DISTINCT FROM OLD)) THEN
        RAISE EXCEPTION 'Request identity, initial snapshot and final outcomes are permanent';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM requests r JOIN items i ON i.owner_user_id = r.consumer_id WHERE r.id = NEW.request_id AND i.subject_id = NEW.item_id) OR
       NOT EXISTS (SELECT 1 FROM item_observations WHERE id = NEW.initial_observation_id AND item_id = NEW.item_id AND revision = NEW.initial_subject_version) OR
       NOT EXISTS (SELECT 1 FROM item_observations WHERE id = NEW.current_observation_id AND item_id = NEW.item_id AND revision = NEW.current_subject_version) THEN
        RAISE EXCEPTION 'Request ownership or observation snapshot mismatch';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER retain_request_item BEFORE INSERT OR UPDATE OR DELETE ON request_items FOR EACH ROW EXECUTE FUNCTION protect_request_item();
CREATE FUNCTION protect_request_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' OR ROW(NEW.id,NEW.public_code,NEW.consumer_id,NEW.mode,NEW.point_id,NEW.private_details_id,NEW.service_area_id,NEW.preferred_date,NEW.created_at) IS DISTINCT FROM ROW(OLD.id,OLD.public_code,OLD.consumer_id,OLD.mode,OLD.point_id,OLD.private_details_id,OLD.service_area_id,OLD.preferred_date,OLD.created_at) THEN
        RAISE EXCEPTION 'Request identity and submitted preference are permanent';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER permanent_request_identity BEFORE UPDATE OR DELETE ON requests FOR EACH ROW EXECUTE FUNCTION protect_request_identity();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Request history requires a reviewed forward migration; rollback is disabled.');
    }
};
