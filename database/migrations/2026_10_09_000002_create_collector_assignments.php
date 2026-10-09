<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('memberships')->whereNotIn('role', ['consumer', 'collector', 'processor', 'administrator'])->exists()) {
            throw new RuntimeException('Unexpected membership state; inspect privately before upgrading.');
        }
        Schema::table('memberships', function (Blueprint $table): void {
            $table->foreignUuid('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('version')->default(1);
        });
        DB::statement("ALTER TABLE memberships ADD CONSTRAINT collector_scope_check CHECK (organization_id IS NULL OR role='collector'), ADD CHECK (version>=1)");
        Schema::create('assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('requests')->restrictOnDelete();
            $table->foreignUuid('collector_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('membership_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('operator_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('facility_id')->constrained('places')->restrictOnDelete();
            $table->foreignUuid('review_id')->constrained('request_reviews')->restrictOnDelete();
            $table->bigInteger('request_version');
            $table->jsonb('eligibility_snapshot');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->text('state');
            $table->boolean('active');
            $table->timestampTz('accepted_at')->nullable();
            $table->bigInteger('version')->default(1);
            $table->index(['collector_id', 'active']);
        });
        DB::statement('CREATE UNIQUE INDEX assignments_active_request_unique ON assignments(request_id) WHERE active');
        DB::statement("ALTER TABLE assignments ADD CHECK (version>=1 AND request_version>=1), ADD CHECK (state IN ('offered','accepted','declined','withdrawn','replaced','invalidated')), ADD CHECK (active=(state IN ('offered','accepted'))), ADD CHECK ((state='accepted' AND accepted_at IS NOT NULL) OR state IN ('replaced','withdrawn','invalidated') OR (state IN ('offered','declined') AND accepted_at IS NULL)), ADD CHECK (jsonb_typeof(eligibility_snapshot)='object')");
        Schema::create('assignment_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('assignment_id')->constrained()->restrictOnDelete();
            $table->bigInteger('version');
            $table->foreignUuid('event_id')->unique()->constrained('domain_events')->restrictOnDelete();
            $table->text('reason_ciphertext')->nullable();
            $table->unique(['assignment_id', 'version']);
        });
        Schema::create('assignment_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('outbox_id')->unique()->constrained('notification_outbox')->restrictOnDelete();
            $table->foreignUuid('event_id')->constrained('domain_events')->restrictOnDelete();
            $table->foreignUuid('recipient_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('assignment_id')->constrained()->restrictOnDelete();
            $table->timestampTz('read_at')->nullable();
            $table->index(['recipient_id', 'read_at']);
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_assignment() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Assignment history is retained'; END IF;
    IF TG_OP='INSERT' THEN
        IF NEW.state<>'offered' OR NEW.version<>1 OR NOT EXISTS (
            SELECT 1 FROM memberships m JOIN users u ON u.id=m.user_id JOIN organizations o ON o.id=m.organization_id
            WHERE m.id=NEW.membership_id AND m.user_id=NEW.collector_id AND m.organization_id=NEW.operator_id AND m.role='collector' AND m.active AND u.status='active' AND o.review_status='approved' AND o.kind IN ('collector','both')
        ) OR NOT EXISTS (SELECT 1 FROM requests r JOIN request_reviews v ON v.id=r.review_id WHERE r.id=NEW.request_id AND r.mode='pickup' AND r.version=NEW.request_version AND r.review_id=NEW.review_id AND r.review_state='ready' AND v.request_id=r.id)
        OR NOT EXISTS (SELECT 1 FROM places p JOIN organizations o ON o.id=p.organization_id WHERE p.id=NEW.facility_id AND p.active AND p.review_status='approved' AND p.kind IN ('facility','collection_point') AND o.review_status='approved' AND o.kind IN ('processor','both')) THEN
            RAISE EXCEPTION 'Invalid assignment references or offer state';
        END IF;
    ELSE
        IF (to_jsonb(NEW)-ARRAY['state','active','version','accepted_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','active','version','accepted_at']) OR NEW.version<>OLD.version+1
        OR NOT ((OLD.state='offered' AND NEW.state IN ('accepted','declined','withdrawn','replaced','invalidated')) OR (OLD.state='accepted' AND NEW.state IN ('withdrawn','replaced','invalidated')))
        OR (OLD.accepted_at IS NOT NULL AND NEW.accepted_at IS DISTINCT FROM OLD.accepted_at) THEN RAISE EXCEPTION 'Invalid assignment transition or identity change'; END IF;
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER permanent_assignment BEFORE INSERT OR UPDATE OR DELETE ON assignments FOR EACH ROW EXECUTE FUNCTION protect_assignment();
CREATE TRIGGER retain_assignment_revisions BEFORE UPDATE OR DELETE ON assignment_revisions FOR EACH ROW EXECUTE FUNCTION protect_device_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_notifications');
        Schema::dropIfExists('assignment_revisions');
        Schema::dropIfExists('assignments');
        DB::statement('DROP FUNCTION IF EXISTS protect_assignment()');
        DB::statement('ALTER TABLE memberships DROP CONSTRAINT collector_scope_check');
        Schema::table('memberships', fn (Blueprint $table) => $table->dropConstrainedForeignId('organization_id'));
        Schema::table('memberships', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
