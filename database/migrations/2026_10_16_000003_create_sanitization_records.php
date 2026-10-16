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
        if (DB::table('items')->whereNotIn('processing_state', ['registered', 'received', 'assessed'])->exists()
            || DB::table('storage_assets as a')->join('items as i', 'i.subject_id', '=', 'a.subject_id')->join('categories as c', 'c.id', '=', 'i.category_id')->where(fn ($q) => $q->where('c.code', '<>', 'standalone_part')->orWhereColumn('a.media_kind', '<>', 'i.subtype')->orWhere('i.data_status', '<>', 'YES'))->exists()) {
            throw new RuntimeException('Sanitization upgrade blocked: unexpected media/state. Inspect privately; no treatment or identity is inferred.');
        }
        foreach (DB::table('first_receipts')->pluck('subject_id') as $id) {
            DB::select('SELECT handover_chain_check(?)', [$id]);
            DB::select('SELECT inspection_integrity(?)', [$id]);
        }
        Schema::create('sanitization_policies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->text('version_code')->unique();
            $t->jsonb('definition');
            $t->text('checksum');
        });
        // Read a frozen migration resource, never mutable application categories or policy helpers.
        $definition = json_decode(file_get_contents(__DIR__.'/../data/media-assurance-1.0.json'), true, flags: JSON_THROW_ON_ERROR);
        $json = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        DB::table('sanitization_policies')->insert(['id' => 'a3d71835-fce3-4ad7-ae2f-cda4507b1001', 'version_code' => 'MEDIA-ASSURANCE-1.0', 'definition' => $json, 'checksum' => hash('sha256', $json)]);
        Schema::create('sanitization_records', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('subject_id')->references('subject_id')->on('storage_assets')->restrictOnDelete();
            $t->bigInteger('version');
            $t->uuid('predecessor_id')->nullable();
            $t->uuid('attempt_id');
            $t->uuid('previous_attempt_id')->nullable();
            $t->text('kind');
            $t->text('outcome');
            $t->text('technique');
            $t->foreignUuid('policy_id')->constrained('sanitization_policies')->restrictOnDelete();
            $t->bigInteger('source_subject_version');
            $t->bigInteger('assessment_version');
            $t->foreignUuid('assessment_id')->constrained('assessments')->restrictOnDelete();
            $t->foreignUuid('decision_id')->constrained('decisions')->restrictOnDelete();
            $t->foreignUuid('facility_party_id')->constrained('parties')->restrictOnDelete();
            $t->bigInteger('facility_version');
            $t->bigInteger('operator_version');
            $t->foreignUuid('custody_event_id')->constrained('domain_events')->restrictOnDelete();
            $t->bigInteger('custody_version');
            $t->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('membership_id')->constrained('memberships')->restrictOnDelete();
            $t->bigInteger('membership_version');
            $t->bigInteger('actor_session_version');
            $t->foreignUuid('command_id')->unique()->constrained('commands')->restrictOnDelete();
            $t->foreignUuid('event_id')->unique()->constrained('domain_events')->restrictOnDelete();
            $t->timestampTz('observed_at');
            $t->timestampTz('recorded_at');
            $t->text('reason_ciphertext');
            foreach (['executor', 'tool', 'tool_version', 'scope', 'procedure'] as $field) {
                $t->text($field.'_ciphertext')->nullable();
            }
            $t->jsonb('checks');
            $t->text('evidence_kind')->nullable();
            $t->foreignUuid('attachment_id')->nullable()->constrained('attachments')->restrictOnDelete();
            $t->text('reference_ciphertext')->nullable();
            $t->unique(['subject_id', 'version']);
            $t->index(['subject_id', 'kind', 'version']);
        });
        Schema::table('sanitization_records', function (Blueprint $t): void {
            foreach (['predecessor_id', 'attempt_id', 'previous_attempt_id'] as $column) {
                $t->foreign($column)->references('id')->on('sanitization_records')->restrictOnDelete();
            }
        });
        Schema::create('sanitization_current', function (Blueprint $t): void {
            $t->foreignUuid('subject_id')->primary()->references('subject_id')->on('storage_assets')->restrictOnDelete();
            $t->bigInteger('version');
            $t->foreignUuid('record_id')->unique()->constrained('sanitization_records')->restrictOnDelete();
            $t->foreignUuid('attempt_id')->constrained('sanitization_records')->restrictOnDelete();
            $t->boolean('destruction_reported');
        });
        DB::statement("ALTER TABLE sanitization_records ADD CHECK (version>=1 AND source_subject_version>=1 AND assessment_version>=2 AND custody_version>=2), ADD CHECK ((kind='attempt' AND outcome IN ('success','failed','incomplete','unverifiable')) OR (kind='verification' AND outcome IN ('satisfactory','unsatisfactory','unverifiable')) OR (kind IN ('validation','revalidation') AND outcome IN ('accepted','rejected'))), ADD CHECK ((evidence_kind IS NULL AND attachment_id IS NULL AND reference_ciphertext IS NULL) OR (evidence_kind='attachment' AND attachment_id IS NOT NULL AND reference_ciphertext IS NULL) OR (evidence_kind='operator_reference' AND attachment_id IS NULL AND reference_ciphertext IS NOT NULL))");
        foreach (['sanitization_policies', 'sanitization_records'] as $table) {
            DB::statement('CREATE TRIGGER retain_'.$table.' BEFORE UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION protect_device_history()');
        }
        DB::unprepared(file_get_contents(__DIR__.'/../sql/sanitization_records.sql'));
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function down(): void
    {
        throw new RuntimeException('Sanitization assurance history is permanent; use a reviewed forward migration.');
    }
};
