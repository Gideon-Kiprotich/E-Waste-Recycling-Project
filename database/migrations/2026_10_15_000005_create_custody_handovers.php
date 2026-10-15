<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('first_receipts') || Schema::hasTable('handovers')) {
            throw new RuntimeException('Handover upgrade blocked: unexpected schema.');
        }
        $guard = DB::selectOne("SELECT pg_get_functiondef('receipt_integrity()'::regprocedure) AS definition")->definition;
        if (! str_contains($guard, 'c.party_id<>f.recipient_party_id') || ! str_contains($guard, "to_jsonb(NEW)->>'outcome'")) {
            throw new RuntimeException('Handover upgrade blocked: unexpected receipt protection.');
        }
        // Force existing deferred guards before replacing their projection boundary.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        if (DB::table('memberships as m')->join('places as p', 'p.id', '=', 'm.place_id')->where('m.role', 'processor')->where(fn ($q) => $q->whereColumn('p.organization_id', '<>', 'm.organization_id')->orWhere('p.kind', '<>', 'collection_point'))->exists()) {
            throw new RuntimeException('Handover upgrade blocked: unexpected existing Processor scope.');
        }
        foreach (DB::table('first_receipts')->pluck('subject_id') as $id) {
            $f = DB::table('first_receipts')->where('subject_id', $id)->first();
            $c = DB::table('custody_current')->where('subject_id', $id)->first();
            if (! $c || $c->party_id !== $f->recipient_party_id || $c->last_event_id !== $f->event_id || $c->version !== $f->custody_version || DB::table('subjects')->where('id', $id)->value('version') !== $f->subject_version) {
                throw new RuntimeException('Handover upgrade blocked: unexpected existing custody. Inspect privately.');
            }
        }
        Schema::create('handovers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('sender_party_id')->constrained('parties')->restrictOnDelete();
            $t->foreignUuid('receiver_party_id')->constrained('parties')->restrictOnDelete();
            foreach (['sender_actor_id', 'receiver_actor_id'] as $field) {
                $t->foreignUuid($field)->constrained('users')->restrictOnDelete();
            }
            foreach (['sender_membership_id', 'receiver_membership_id'] as $field) {
                $t->foreignUuid($field)->constrained('memberships')->restrictOnDelete();
            }
            $t->bigInteger('sender_scope_version');
            $t->bigInteger('receiver_scope_version');
            $t->bigInteger('destination_version');
            $t->bigInteger('operator_version');
            $t->text('state')->default('pending');
            $t->bigInteger('version')->default(1);
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['receiver_actor_id', 'created_at']);
            $t->index(['sender_party_id', 'created_at']);
        });
        DB::statement("ALTER TABLE handovers ADD CHECK (sender_party_id<>receiver_party_id AND sender_actor_id<>receiver_actor_id AND version>=1 AND sender_scope_version>=1 AND receiver_scope_version>=1 AND destination_version>=1 AND operator_version>=1), ADD CHECK (state IN ('pending','partial','accepted','disputed','closed'))");
        Schema::create('handover_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('handover_id')->constrained('handovers')->restrictOnDelete();
            $t->foreignUuid('subject_id')->constrained('subjects')->restrictOnDelete();
            $t->foreignUuid('source_event_id')->constrained('domain_events')->restrictOnDelete();
            $t->bigInteger('source_custody_version');
            $t->bigInteger('source_subject_version');
            $t->text('state')->default('pending');
            $t->boolean('active')->default(true);
            $t->unique(['handover_id', 'subject_id']);
        });
        DB::statement("ALTER TABLE handover_items ADD CHECK (source_custody_version>=2 AND source_subject_version>=2), ADD CHECK (state IN ('pending','accepted','disputed','withdrawn','resolved') AND active=(state IN ('pending','disputed')))");
        DB::statement('CREATE UNIQUE INDEX handover_subject_active_unique ON handover_items(subject_id) WHERE active');
        Schema::create('handover_operations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('handover_id')->constrained('handovers')->restrictOnDelete();
            $t->bigInteger('version');
            $t->text('kind');
            $t->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('command_id')->unique()->constrained('commands')->restrictOnDelete();
            $t->foreignUuid('event_id')->unique()->constrained('domain_events')->restrictOnDelete();
            $t->timestampTz('observed_at');
            $t->timestampTz('recorded_at');
            $t->text('evidence_kind');
            $t->text('reference_ciphertext')->nullable();
            $t->foreignUuid('attachment_id')->nullable()->constrained('attachments')->restrictOnDelete();
            $t->unique(['handover_id', 'version']);
        });
        DB::statement("ALTER TABLE handover_operations ADD CHECK (kind IN ('initiated','acknowledged','withdrawn','resolved') AND version>=1 AND observed_at<=recorded_at), ADD CHECK ((evidence_kind='attachment' AND attachment_id IS NOT NULL AND reference_ciphertext IS NULL) OR (evidence_kind='operator_reference' AND attachment_id IS NULL AND reference_ciphertext IS NOT NULL))");
        Schema::create('handover_outcomes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('operation_id')->constrained('handover_operations')->restrictOnDelete();
            $t->foreignUuid('handover_item_id')->constrained('handover_items')->restrictOnDelete();
            $t->text('outcome');
            $t->text('reason_ciphertext');
            $t->boolean('attested');
            $t->unique(['operation_id', 'handover_item_id']);
        });
        DB::statement("ALTER TABLE handover_outcomes ADD CHECK (outcome IN ('pending','accepted','missing','damaged','mismatch','refused','withdrawn','closed_no_transfer','review_continues')), ADD CHECK (attested=(outcome IN ('accepted','closed_no_transfer')))");
        Schema::create('handover_receipts', function (Blueprint $t): void {
            $t->foreignUuid('outcome_id')->primary()->constrained('handover_outcomes')->restrictOnDelete();
            $t->foreignUuid('handover_item_id')->unique()->constrained('handover_items')->restrictOnDelete();
            $t->foreignUuid('event_id')->unique()->constrained('domain_events')->restrictOnDelete();
            $t->foreignUuid('subject_id')->constrained('subjects')->restrictOnDelete();
            $t->bigInteger('custody_version');
            $t->bigInteger('subject_version');
            $t->unique(['subject_id', 'custody_version']);
        });
        Schema::create('handover_notifications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('outbox_id')->unique()->constrained('notification_outbox')->restrictOnDelete();
            $t->foreignUuid('operation_id')->constrained('handover_operations')->restrictOnDelete();
            $t->foreignUuid('recipient_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('read_at')->nullable();
        });
        DB::unprepared(file_get_contents(__DIR__.'/../sql/custody_handovers.sql'));
        $guard = str_replace(' OR c.party_id<>f.recipient_party_id OR c.last_event_id<>f.event_id OR c.version<>f.custody_version', '', $guard);
        $guard = str_replace(' OR NOT EXISTS (SELECT 1 FROM subjects WHERE id=ref AND version=f.subject_version)', '', $guard);
        $guard = str_replace("THEN RAISE EXCEPTION 'Inconsistent physical first receipt'; END IF;", "THEN RAISE EXCEPTION 'Inconsistent physical first receipt'; END IF;\n PERFORM handover_chain_check(ref);", $guard);
        DB::unprepared($guard);
        foreach (['handover_operations', 'handover_outcomes', 'handover_receipts'] as $table) {
            DB::statement('CREATE TRIGGER retain_'.$table.' BEFORE UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION protect_device_history()');
        }
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function down(): void
    {
        throw new RuntimeException('Custody handovers are permanent; use a reviewed forward migration.');
    }
};
