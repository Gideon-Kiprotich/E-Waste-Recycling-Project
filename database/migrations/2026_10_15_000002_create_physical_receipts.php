<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function replaceCheck(string $table, string $needle, string $definition): void
    {
        $checks = DB::select("SELECT conname,pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid=?::regclass AND contype='c'", [$table]);
        $matches = array_values(array_filter($checks, fn ($c) => str_contains($c->def, $needle) && ($needle !== 'state = ANY' || (! str_contains($c->def, 'active') && ! str_contains($c->def, 'accepted_at')))));
        if (count($matches) !== 1) {
            throw new RuntimeException('Receipt upgrade blocked: unexpected check definition.');
        }
        DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT "'.$matches[0]->conname.'"');
        DB::statement('ALTER TABLE '.$table.' ADD CHECK ('.$definition.')');
    }

    public function up(): void
    {
        if (! Schema::hasTable('route_claims') || Schema::hasTable('receipt_visits') || DB::table('items')->where('processing_state', '<>', 'registered')->exists() || DB::table('request_items')->where('outcome', 'collected')->exists() || DB::table('memberships')->where('role', '<>', 'collector')->whereNotNull('organization_id')->exists()) {
            throw new RuntimeException('Receipt upgrade blocked: unexpected schema or physical history; inspect privately.');
        }
        if (DB::table('items as i')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('custody_current as c')->join('parties as p', 'p.id', '=', 'c.party_id')->whereColumn('c.subject_id', 'i.subject_id')->where('p.kind', 'user')->whereColumn('p.user_id', 'i.owner_user_id'))->exists()) {
            throw new RuntimeException('Receipt upgrade blocked: unexpected existing custody; inspect privately.');
        }
        $guards = DB::selectOne("SELECT count(*) AS n FROM pg_trigger WHERE tgenabled='O' AND NOT tgisinternal AND (tgrelid,tgname) IN (('route_claims'::regclass,'route_claim_guard'),('assignments'::regclass,'permanent_assignment'),('request_items'::regclass,'retain_request_item'),('domain_events'::regclass,'retain_device_events'))");
        if ((int) $guards->n !== 4) {
            throw new RuntimeException('Receipt upgrade blocked: existing protections missing.');
        }
        Schema::table('memberships', fn (Blueprint $t) => $t->foreignUuid('place_id')->nullable()->constrained('places')->restrictOnDelete());
        DB::statement('ALTER TABLE memberships DROP CONSTRAINT collector_scope_check');
        DB::statement("ALTER TABLE memberships ADD CHECK ((role='collector' AND place_id IS NULL) OR (role='processor' AND ((place_id IS NULL AND organization_id IS NULL) OR (place_id IS NOT NULL AND organization_id IS NOT NULL))) OR (role NOT IN ('collector','processor') AND place_id IS NULL AND organization_id IS NULL))");
        DB::statement('ALTER TABLE parties ALTER COLUMN user_id DROP NOT NULL');
        Schema::table('parties', fn (Blueprint $t) => $t->foreignUuid('place_id')->nullable()->unique()->constrained('places')->restrictOnDelete());
        $this->replaceCheck('parties', 'kind', "(kind='user' AND user_id IS NOT NULL AND place_id IS NULL) OR (kind='place' AND user_id IS NULL AND place_id IS NOT NULL)");
        $this->replaceCheck('assignments', 'state = ANY', "state IN ('offered','accepted','declined','withdrawn','replaced','invalidated','completed','remainder_review')");
        $this->replaceCheck('assignments', 'accepted_at', "(state IN ('accepted','completed','remainder_review') AND accepted_at IS NOT NULL) OR state IN ('replaced','withdrawn','invalidated') OR (state IN ('offered','declined') AND accepted_at IS NULL)");
        $this->replaceCheck('route_approvals', 'state', "state IN ('approved','stale','superseded','withdrawn','finished') AND version>=1");
        Schema::create('receipt_visits', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('request_id')->constrained('requests')->restrictOnDelete();
            $t->foreignUuid('assignment_id')->nullable()->constrained('assignments')->restrictOnDelete();
            $t->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('recipient_party_id')->constrained('parties')->restrictOnDelete();
            $t->foreignUuid('command_id')->unique()->constrained('commands')->restrictOnDelete();
            $t->foreignUuid('event_id')->unique()->constrained('domain_events')->restrictOnDelete();
            $t->bigInteger('request_version');
            $t->timestampTz('observed_at');
            $t->timestampTz('recorded_at');
            $t->text('evidence_kind');
            $t->text('reference_ciphertext')->nullable();
            $t->foreignUuid('attachment_id')->nullable()->constrained('attachments')->restrictOnDelete();
            $t->index(['request_id', 'recorded_at']);
        });
        DB::statement("ALTER TABLE receipt_visits ADD CHECK (request_version>1 AND observed_at<=recorded_at), ADD CHECK ((evidence_kind='attachment' AND attachment_id IS NOT NULL AND reference_ciphertext IS NULL) OR (evidence_kind='operator_reference' AND reference_ciphertext IS NOT NULL AND attachment_id IS NULL))");
        Schema::create('receipt_outcomes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('visit_id')->constrained('receipt_visits')->restrictOnDelete();
            $t->foreignUuid('request_item_id')->constrained('request_items')->restrictOnDelete();
            $t->text('outcome');
            $t->text('observation_ciphertext');
            $t->boolean('attested');
            $t->unique(['visit_id', 'request_item_id']);
        });
        DB::statement("ALTER TABLE receipt_outcomes ADD CHECK (outcome IN ('received','absent','refused','mismatch','safety_hold')), ADD CHECK (attested=(outcome='received'))");
        Schema::create('first_receipts', function (Blueprint $t): void {
            $t->foreignUuid('subject_id')->primary()->constrained('subjects')->restrictOnDelete();
            $t->foreignUuid('outcome_id')->unique()->constrained('receipt_outcomes')->restrictOnDelete();
            $t->foreignUuid('event_id')->unique()->constrained('domain_events')->restrictOnDelete();
            $t->foreignUuid('from_party_id')->constrained('parties')->restrictOnDelete();
            $t->foreignUuid('recipient_party_id')->constrained('parties')->restrictOnDelete();
            $t->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $t->bigInteger('subject_version');
            $t->bigInteger('custody_version');
        });
        Schema::create('receipt_discrepancies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('request_id')->constrained('requests')->restrictOnDelete();
            $t->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('outcome_id')->nullable()->constrained('receipt_outcomes')->restrictOnDelete();
            $t->text('kind');
            $t->timestampTz('recorded_at');
        });
        DB::statement("ALTER TABLE receipt_discrepancies ADD CHECK (kind IN ('context_mismatch','late_conflict','mismatch','safety_hold'))");
        Schema::create('route_consumptions', function (Blueprint $t): void {
            $t->foreignUuid('route_id')->constrained('routes')->restrictOnDelete();
            $t->foreignUuid('assignment_id')->constrained('assignments')->restrictOnDelete();
            $t->foreignUuid('visit_id')->unique()->constrained('receipt_visits')->restrictOnDelete();
            $t->primary(['route_id', 'assignment_id']);
            $t->foreign(['route_id', 'assignment_id'])->references(['route_id', 'assignment_id'])->on('route_stops')->restrictOnDelete();
        });
        Schema::create('receipt_route_changes', function (Blueprint $t): void {
            $t->foreignUuid('visit_id')->constrained('receipt_visits')->restrictOnDelete();
            $t->text('kind');
            $t->uuid('record_id');
            $t->jsonb('before_row');
            $t->jsonb('after_row');
            $t->primary(['visit_id', 'kind', 'record_id']);
        });
        Schema::create('route_finishes', function (Blueprint $t): void {
            $t->foreignUuid('route_id')->primary()->constrained('routes')->restrictOnDelete();
            $t->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('command_id')->unique()->constrained('commands')->restrictOnDelete();
            $t->timestampTz('recorded_at');
            $t->jsonb('unfinished');
            $t->text('reason_ciphertext');
        });
        Schema::create('receipt_notifications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('outbox_id')->unique()->constrained('notification_outbox')->restrictOnDelete();
            $t->foreignUuid('visit_id')->constrained('receipt_visits')->restrictOnDelete();
            $t->foreignUuid('recipient_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('read_at')->nullable();
        });
        DB::unprepared(file_get_contents(__DIR__.'/../sql/physical_receipts.sql'));
        foreach (['receipt_visits', 'receipt_outcomes', 'first_receipts', 'receipt_discrepancies', 'route_consumptions', 'receipt_route_changes', 'route_finishes'] as $table) {
            DB::statement('CREATE TRIGGER retain_'.$table.' BEFORE UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION protect_device_history()');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Physical receipt history is permanent; use a reviewed forward migration.');
    }
};
