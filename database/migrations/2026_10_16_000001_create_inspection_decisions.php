<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        if (DB::table('items')->whereNotIn('processing_state', ['registered', 'received'])->exists()) {
            throw new RuntimeException('Inspection upgrade blocked: unexpected processing state; inspect privately. No history is inferred.');
        }
        foreach (DB::table('first_receipts')->pluck('subject_id') as $id) {
            DB::select('SELECT handover_chain_check(?)', [$id]);
        }
        $guard = DB::selectOne("SELECT pg_get_functiondef('receipt_integrity()'::regprocedure) AS definition")->definition;
        if (! str_contains($guard, "i.processing_state<>'received'") || ! str_contains($guard, 'handover_chain_check(ref)')) {
            throw new RuntimeException('Inspection upgrade blocked: unexpected receipt guard.');
        }
        Schema::create('rule_sets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->text('version_code')->unique();
            $t->text('schema_version');
            $t->jsonb('definition');
            $t->text('checksum');
            $t->timestampTz('frozen_at')->useCurrent();
        });
        $sets = json_decode(file_get_contents(__DIR__.'/../data/inspection-rules-1.0.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($sets as $code => $definition) {
            $json = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            DB::table('rule_sets')->insert(['id' => $code === 'ASSESS-1.0' ? 'd72c6a51-d584-46a0-9ef3-baa9e2041501' : 'd72c6a51-d584-46a0-9ef3-baa9e2041502', 'version_code' => $code, 'schema_version' => $definition['schema_version'], 'definition' => $json, 'checksum' => hash('sha256', $json)]);
        }
        Schema::create('assessments', function (Blueprint $t): void {
            $this->operationColumns($t);
            $t->foreignUuid('rule_set_id')->constrained('rule_sets')->restrictOnDelete();
            $t->text('schema_version');
            $t->text('rule_checksum');
            $t->text('recommendation');
            $t->jsonb('result');
            $t->boolean('hard_hold');
            $t->text('data_status');
            $t->text('evidence_kind');
            $t->foreignUuid('attachment_id')->nullable()->constrained('attachments')->restrictOnDelete();
            $t->text('reference_ciphertext')->nullable();
            $t->unique(['subject_id', 'version']);
        });
        Schema::create('assessment_answers', function (Blueprint $t): void {
            $t->foreignUuid('assessment_id')->constrained('assessments')->restrictOnDelete();
            $t->text('question_code');
            $t->text('value');
            $t->text('na_reason_ciphertext')->nullable();
            $t->primary(['assessment_id', 'question_code']);
        });
        Schema::create('decisions', function (Blueprint $t): void {
            $this->operationColumns($t);
            $t->foreignUuid('assessment_id')->constrained('assessments')->restrictOnDelete();
            $t->text('pathway');
            $t->boolean('override');
            $t->unique(['subject_id', 'version']);
        });
        Schema::create('assessment_current', function (Blueprint $t): void {
            $t->foreignUuid('subject_id')->primary()->constrained('subjects')->restrictOnDelete();
            $t->bigInteger('version');
            $t->foreignUuid('assessment_id')->constrained('assessments')->restrictOnDelete();
            $t->foreignUuid('decision_id')->nullable()->constrained('decisions')->restrictOnDelete();
        });
        Schema::create('assessment_notifications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('outbox_id')->unique()->constrained('notification_outbox')->restrictOnDelete();
            $t->foreignUuid('event_id')->constrained('domain_events')->restrictOnDelete();
            $t->foreignUuid('recipient_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('read_at')->nullable();
        });
        DB::statement("ALTER TABLE assessments ADD CHECK (recommendation IN ('refurbish','recycle','hold')), ADD CHECK (data_status IN ('YES','NO','UNKNOWN')), ADD CHECK ((evidence_kind='attachment' AND attachment_id IS NOT NULL AND reference_ciphertext IS NULL) OR (evidence_kind='operator_reference' AND attachment_id IS NULL AND reference_ciphertext IS NOT NULL))");
        DB::statement("ALTER TABLE decisions ADD CHECK (pathway IN ('refurbish','recycle','hold'))");
        foreach (['assessments', 'decisions'] as $table) {
            DB::statement('ALTER TABLE '.$table.' ADD CHECK (version>=1 AND source_subject_version>=1 AND subject_version=source_subject_version+1 AND custody_version>=2)');
        }
        DB::statement('ALTER TABLE assessment_current ADD CHECK (version>=1)');
        foreach (['rule_sets', 'assessments', 'assessment_answers', 'decisions'] as $table) {
            DB::statement('CREATE TRIGGER retain_'.$table.' BEFORE UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION protect_device_history()');
        }
        DB::unprepared(file_get_contents(__DIR__.'/../sql/inspection_decisions.sql'));
        $guard = str_replace("i.processing_state<>'received'", "i.processing_state NOT IN ('received','assessed')", $guard);
        $guard = str_replace('PERFORM handover_chain_check(ref);', 'PERFORM handover_chain_check(ref); PERFORM inspection_integrity(ref);', $guard);
        DB::unprepared($guard);
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function operationColumns(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('subject_id')->constrained('subjects')->restrictOnDelete();
        $t->bigInteger('version');
        $t->bigInteger('source_subject_version');
        $t->bigInteger('subject_version');
        $t->foreignUuid('facility_party_id')->constrained('parties')->restrictOnDelete();
        $t->foreignUuid('custody_event_id')->constrained('domain_events')->restrictOnDelete();
        $t->bigInteger('custody_version');
        $t->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
        $t->foreignUuid('command_id')->unique()->constrained('commands')->restrictOnDelete();
        $t->foreignUuid('event_id')->unique()->constrained('domain_events')->restrictOnDelete();
        $t->timestampTz('observed_at');
        $t->timestampTz('recorded_at')->useCurrent();
        $t->text('reason_ciphertext');
    }

    public function down(): void
    {
        throw new RuntimeException('Inspection history is permanent; use a reviewed forward migration.');
    }
};
