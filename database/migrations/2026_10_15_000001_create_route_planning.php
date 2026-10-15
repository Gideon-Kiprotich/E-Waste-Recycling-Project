<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('assignments')->whereNotIn('state', ['offered', 'accepted', 'declined', 'withdrawn', 'replaced', 'invalidated'])->exists()) {
            throw new RuntimeException('Unexpected assignment state; inspect privately before upgrading.');
        }
        Schema::create('vehicles', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('collector_id')->constrained('users')->restrictOnDelete();
            $t->string('code', 60)->unique();
            $t->decimal('capacity_kg', 12, 3);
            $t->integer('max_duration_seconds');
            $t->boolean('available');
            $t->boolean('synthetic');
            $t->bigInteger('version')->default(1);
        });
        DB::statement('ALTER TABLE vehicles ADD CHECK (capacity_kg>0 AND max_duration_seconds>0 AND version>=1)');
        Schema::create('route_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('operator_id')->constrained('organizations')->restrictOnDelete();
            $t->foreignUuid('depot_id')->constrained('places')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->text('snapshot_ciphertext');
            $t->string('snapshot_hash', 64);
            $t->text('matrix_source');
            $t->text('algorithm_version');
            $t->jsonb('results');
            $t->decimal('local_seconds', 12, 6);
        });
        DB::statement("ALTER TABLE route_runs ADD CHECK (matrix_source='HAVERSINE-SIMULATION-1.0'), ADD CHECK (local_seconds>=0 AND jsonb_typeof(results)='object')");
        Schema::create('route_dependencies', function (Blueprint $t): void {
            $t->foreignUuid('run_id')->constrained('route_runs')->restrictOnDelete();
            $t->text('kind');
            $t->uuid('record_id');
            $t->primary(['run_id', 'kind', 'record_id']);
            $t->index(['kind', 'record_id']);
        });
        Schema::create('routes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('run_id')->constrained('route_runs')->restrictOnDelete();
            $t->foreignUuid('vehicle_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('collector_id')->constrained('users')->restrictOnDelete();
            $t->text('method');
            $t->unique(['run_id', 'method', 'vehicle_id']);
            $t->unique(['run_id', 'method', 'collector_id']);
        });
        DB::statement("ALTER TABLE routes ADD CHECK (method IN ('baseline','proposed'))");
        Schema::create('route_stops', function (Blueprint $t): void {
            $t->foreignUuid('route_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('assignment_id')->constrained()->restrictOnDelete();
            $t->integer('position');
            $t->bigInteger('request_version');
            $t->decimal('estimated_kg', 12, 3);
            $t->primary(['route_id', 'position']);
            $t->unique(['route_id', 'assignment_id']);
        });
        DB::statement('ALTER TABLE route_stops ADD CHECK (position>0 AND request_version>0 AND estimated_kg>0)');
        Schema::create('route_approvals', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('run_id')->unique()->constrained('route_runs')->restrictOnDelete();
            $t->foreignUuid('approved_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at');
            $t->text('state');
            $t->bigInteger('version');
        });
        DB::statement("ALTER TABLE route_approvals ADD CHECK (state IN ('approved','stale','superseded','withdrawn') AND version>=1)");
        Schema::create('route_claims', function (Blueprint $t): void {
            $t->foreignUuid('approval_id')->constrained('route_approvals')->restrictOnDelete();
            $t->text('kind');
            $t->uuid('record_id');
            $t->primary(['kind', 'record_id']);
        });
        DB::statement("ALTER TABLE route_claims ADD CHECK (kind IN ('assignment','vehicle','collector'))");
        Schema::create('route_approval_history', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('approval_id')->constrained('route_approvals')->restrictOnDelete();
            $t->bigInteger('version');
            $t->text('state');
            $t->text('cause');
            $t->timestampTz('recorded_at');
            $t->unique(['approval_id', 'version']);
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION route_approval_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Route approval history is retained'; END IF;
    IF TG_OP='INSERT' THEN
        IF NEW.state<>'approved' OR NEW.version<>1 THEN RAISE EXCEPTION 'Invalid initial approval'; END IF;
    ELSE
        IF OLD.state<>'approved' OR NEW.state NOT IN ('stale','superseded','withdrawn') OR NEW.version<>OLD.version+1
        OR (to_jsonb(NEW)-ARRAY['state','version']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','version']) THEN RAISE EXCEPTION 'Invalid approval transition'; END IF;
        DELETE FROM route_claims WHERE approval_id=NEW.id;
    END IF;
    INSERT INTO route_approval_history VALUES (gen_random_uuid(),NEW.id,NEW.version,NEW.state,COALESCE(NULLIF(current_setting('ewaste.route_cause',true),''),'explicit_command'),CURRENT_TIMESTAMP);
    RETURN NEW;
END; $$;
CREATE TRIGGER route_approval_transition AFTER INSERT OR UPDATE OR DELETE ON route_approvals FOR EACH ROW EXECUTE FUNCTION route_approval_guard();
CREATE FUNCTION invalidate_route_dependency() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid;
BEGIN
    IF TG_OP='UPDATE' AND NEW IS NOT DISTINCT FROM OLD THEN RETURN NEW; END IF;
    IF TG_TABLE_NAME='place_categories' THEN ref:=COALESCE(NEW.place_id,OLD.place_id);
    ELSE ref:=COALESCE((to_jsonb(NEW)->>'id')::uuid,(to_jsonb(OLD)->>'id')::uuid); END IF;
    PERFORM set_config('ewaste.route_cause','dependency_changed:'||TG_TABLE_NAME,true);
    UPDATE route_approvals a SET state='stale',version=version+1 WHERE a.state='approved' AND EXISTS (
        SELECT 1 FROM route_dependencies d WHERE d.run_id=a.run_id AND d.kind=CASE WHEN TG_TABLE_NAME='place_categories' THEN 'places' ELSE TG_TABLE_NAME END AND d.record_id=ref
    );
    PERFORM set_config('ewaste.route_cause','',true);
    RETURN COALESCE(NEW,OLD);
END; $$;
CREATE FUNCTION guard_route_claim() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='INSERT' THEN
        IF NOT EXISTS (SELECT 1 FROM route_approvals WHERE id=NEW.approval_id AND state='approved') THEN RAISE EXCEPTION 'Inactive route claim'; END IF;
        IF NEW.kind='assignment' AND NOT EXISTS (
            SELECT 1 FROM route_approvals a JOIN routes r ON r.run_id=a.run_id AND r.method='proposed' JOIN route_stops s ON s.route_id=r.id JOIN assignments j ON j.id=s.assignment_id
            WHERE a.id=NEW.approval_id AND j.id=NEW.record_id AND j.active AND j.state='accepted'
        ) THEN RAISE EXCEPTION 'Invalid assignment claim'; END IF;
        RETURN NEW;
    END IF;
    IF TG_OP='UPDATE' OR EXISTS (SELECT 1 FROM route_approvals WHERE id=OLD.approval_id AND state='approved') THEN RAISE EXCEPTION 'Active claims require an approval transition'; END IF;
    RETURN OLD;
END; $$;
CREATE TRIGGER route_claim_guard BEFORE INSERT OR UPDATE OR DELETE ON route_claims FOR EACH ROW EXECUTE FUNCTION guard_route_claim();
CREATE FUNCTION guard_vehicle_revision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Vehicle history is retained'; END IF;
    IF NEW.id<>OLD.id OR NEW.organization_id<>OLD.organization_id OR NEW.code<>OLD.code OR NEW.synthetic<>OLD.synthetic OR NEW.version<>OLD.version+1 THEN RAISE EXCEPTION 'Invalid vehicle revision'; END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER vehicle_revision BEFORE UPDATE OR DELETE ON vehicles FOR EACH ROW EXECUTE FUNCTION guard_vehicle_revision();
CREATE FUNCTION route_claim_set_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE approval uuid; run uuid; state text;
BEGIN
    IF TG_TABLE_NAME='route_approvals' THEN approval:=COALESCE(NEW.id,OLD.id); ELSE approval:=COALESCE(NEW.approval_id,OLD.approval_id); END IF;
    SELECT a.run_id,a.state INTO run,state FROM route_approvals a WHERE a.id=approval;
    IF state='approved' THEN
        IF EXISTS (
            (SELECT 'assignment' AS kind,s.assignment_id AS ref FROM routes r JOIN route_stops s ON s.route_id=r.id WHERE r.run_id=run AND r.method='proposed'
             UNION SELECT 'vehicle',r.vehicle_id FROM routes r WHERE r.run_id=run AND r.method='proposed'
             UNION SELECT 'collector',r.collector_id FROM routes r WHERE r.run_id=run AND r.method='proposed')
            EXCEPT SELECT c.kind,c.record_id FROM route_claims c WHERE c.approval_id=approval
        ) OR EXISTS (
            SELECT c.kind,c.record_id FROM route_claims c WHERE c.approval_id=approval EXCEPT
            (SELECT 'assignment',s.assignment_id FROM routes r JOIN route_stops s ON s.route_id=r.id WHERE r.run_id=run AND r.method='proposed'
             UNION SELECT 'vehicle',r.vehicle_id FROM routes r WHERE r.run_id=run AND r.method='proposed'
             UNION SELECT 'collector',r.collector_id FROM routes r WHERE r.run_id=run AND r.method='proposed')
        ) OR NOT EXISTS (SELECT 1 FROM routes WHERE run_id=run AND method='proposed') THEN RAISE EXCEPTION 'Incomplete or inconsistent approval claims'; END IF;
    ELSIF EXISTS (SELECT 1 FROM route_claims WHERE approval_id=approval) THEN RAISE EXCEPTION 'Closed approval retains claims'; END IF;
    RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER complete_route_approval AFTER INSERT OR UPDATE ON route_approvals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION route_claim_set_guard();
CREATE CONSTRAINT TRIGGER complete_route_claims AFTER INSERT OR UPDATE OR DELETE ON route_claims DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION route_claim_set_guard();
CREATE FUNCTION route_projection_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME='routes' THEN
        IF NOT EXISTS (SELECT 1 FROM route_runs run CROSS JOIN LATERAL jsonb_array_elements(run.results->NEW.method->'routes') j WHERE run.id=NEW.run_id AND j->>'vehicle_id'=NEW.vehicle_id::text AND j->>'collector_id'=NEW.collector_id::text) THEN RAISE EXCEPTION 'Route differs from frozen result'; END IF;
    ELSE
        IF NOT EXISTS (SELECT 1 FROM routes r JOIN route_runs run ON run.id=r.run_id JOIN assignments a ON a.id=NEW.assignment_id CROSS JOIN LATERAL jsonb_array_elements(run.results->r.method->'routes') j
        WHERE r.id=NEW.route_id AND a.collector_id=r.collector_id AND j->>'vehicle_id'=r.vehicle_id::text AND j->'stops'->>(NEW.position-1)=NEW.assignment_id::text) THEN RAISE EXCEPTION 'Stop differs from frozen result or Collector'; END IF;
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER route_projection BEFORE INSERT ON routes FOR EACH ROW EXECUTE FUNCTION route_projection_guard();
CREATE TRIGGER stop_projection BEFORE INSERT ON route_stops FOR EACH ROW EXECUTE FUNCTION route_projection_guard();
SQL);
        foreach (['route_runs', 'route_dependencies', 'routes', 'route_stops', 'route_approval_history'] as $table) {
            DB::statement('CREATE TRIGGER retain_'.$table.' BEFORE UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION protect_device_history()');
        }
        foreach (['requests', 'assignments', 'organizations', 'places', 'memberships', 'users', 'vehicles', 'service_areas', 'request_private_details', 'request_items', 'subjects'] as $table) {
            DB::statement('CREATE TRIGGER routing_dependency AFTER UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION invalidate_route_dependency()');
        }
        DB::statement('CREATE TRIGGER routing_dependency AFTER INSERT OR UPDATE OR DELETE ON place_categories FOR EACH ROW EXECUTE FUNCTION invalidate_route_dependency()');
    }

    public function down(): void
    {
        throw new RuntimeException('Route history is retained; use a reviewed forward migration.');
    }
};
