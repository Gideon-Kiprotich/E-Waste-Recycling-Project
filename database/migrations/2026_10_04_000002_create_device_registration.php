<?php

use App\Support\Devices\Questions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->text('label');
            $table->boolean('supported');
            $table->text('question_set_version');
        });
        foreach (Questions::CATEGORIES as $code => $label) {
            DB::table('categories')->insert(['id' => Str::uuid()->toString(), 'code' => $code, 'label' => $label, 'supported' => true, 'question_set_version' => Questions::VERSION]);
        }
        Schema::create('subjects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('public_code', 60)->unique();
            $table->text('kind');
            $table->bigInteger('version')->default(1);
            $table->timestampTz('created_at');
        });
        Schema::create('items', function (Blueprint $table): void {
            $table->foreignUuid('subject_id')->primary()->constrained('subjects')->restrictOnDelete();
            $table->foreignUuid('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('category_id')->constrained('categories')->restrictOnDelete();
            $table->text('subtype')->nullable();
            $table->text('model')->nullable();
            $table->text('identifier_ciphertext')->nullable();
            $table->text('identifier_hmac')->nullable();
            $table->text('identifier_key_version')->nullable();
            $table->text('processing_state')->default('registered');
            $table->text('data_status');
            $table->index(['owner_user_id', 'processing_state']);
        });
        Schema::create('item_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('items', 'subject_id')->restrictOnDelete();
            $table->text('source');
            $table->foreignUuid('observer_id')->constrained('users')->restrictOnDelete();
            $table->jsonb('answers');
            $table->text('question_version');
            $table->bigInteger('revision');
            $table->timestampTz('observed_at');
            $table->unique(['item_id', 'revision']);
        });
        Schema::create('attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('storage_key')->unique();
            $table->text('mime');
            $table->bigInteger('size_bytes');
            $table->text('checksum');
            $table->foreignUuid('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->text('privacy_class');
            $table->timestampTz('redacted_at')->nullable();
        });
        Schema::create('commands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('action');
            $table->uuid('idempotency_key');
            $table->text('payload_hash');
            $table->text('status');
            $table->jsonb('safe_response');
            $table->unique(['actor_id', 'action', 'idempotency_key']);
        });
        Schema::create('domain_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->text('aggregate_kind');
            $table->uuid('aggregate_id');
            $table->bigInteger('aggregate_version');
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('type');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');
            $table->jsonb('safe_payload');
            $table->foreignUuid('command_id')->constrained('commands')->restrictOnDelete();
            $table->unique(['aggregate_kind', 'aggregate_id', 'aggregate_version']);
            $table->index(['subject_id', 'recorded_at', 'id']);
        });
        Schema::create('evidence_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('attachment_id')->constrained('attachments')->restrictOnDelete();
            $table->foreignUuid('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignUuid('event_id')->constrained('domain_events')->restrictOnDelete();
        });
        Schema::create('parties', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('kind');
            $table->foreignUuid('user_id')->unique()->constrained('users')->restrictOnDelete();
        });
        Schema::create('custody_current', function (Blueprint $table): void {
            $table->foreignUuid('subject_id')->primary()->constrained('subjects')->restrictOnDelete();
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('last_event_id')->constrained('domain_events')->restrictOnDelete();
            $table->bigInteger('version');
        });
        Schema::create('notification_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('domain_events')->restrictOnDelete();
            $table->foreignUuid('recipient_id')->constrained('users')->restrictOnDelete();
            $table->text('channel');
            $table->integer('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->text('state');
            $table->unique(['event_id', 'recipient_id', 'channel']);
        });
        DB::statement("ALTER TABLE subjects ADD CHECK (version >= 1), ADD CHECK (kind = 'item')");
        DB::statement("ALTER TABLE items ADD CHECK (data_status IN ('YES','NO','UNKNOWN')), ADD CHECK (processing_state IN ('registered','received','assessed','released','dismantled','closed')), ADD CHECK (subtype IS NULL OR subtype IN ('router','keyboard')), ADD CHECK ((identifier_ciphertext IS NULL AND identifier_hmac IS NULL AND identifier_key_version IS NULL) OR (identifier_ciphertext IS NOT NULL AND identifier_hmac IS NOT NULL AND identifier_key_version IS NOT NULL))");
        DB::statement('CREATE INDEX items_identifier_hmac_index ON items(identifier_hmac) WHERE identifier_hmac IS NOT NULL');
        DB::statement("ALTER TABLE item_observations ADD CHECK (revision >= 1), ADD CHECK (source = 'self_report'), ADD CHECK (jsonb_typeof(answers) = 'object')");
        DB::statement("ALTER TABLE attachments ADD CHECK (size_bytes > 0 AND size_bytes <= 5242880), ADD CHECK (mime IN ('image/jpeg','image/png')), ADD CHECK (privacy_class = 'private')");
        DB::statement("ALTER TABLE parties ADD CHECK (kind = 'user')");
        DB::statement('ALTER TABLE custody_current ADD CHECK (version >= 1)');
        DB::statement("ALTER TABLE commands ADD CHECK (status = 'completed')");
        DB::statement("ALTER TABLE notification_outbox ADD CHECK (channel = 'in_app'), ADD CHECK (state IN ('pending','delivered')), ADD CHECK (attempts >= 0)");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_device_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Committed device history cannot be overwritten';
END;
$$;
CREATE TRIGGER retain_device_events BEFORE UPDATE OR DELETE ON domain_events FOR EACH ROW EXECUTE FUNCTION protect_device_history();
CREATE TRIGGER retain_device_observations BEFORE UPDATE OR DELETE ON item_observations FOR EACH ROW EXECUTE FUNCTION protect_device_history();
CREATE FUNCTION protect_device_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME = 'subjects' THEN
        IF TG_OP = 'DELETE' OR ROW(NEW.id, NEW.public_code, NEW.kind, NEW.created_at) IS DISTINCT FROM ROW(OLD.id, OLD.public_code, OLD.kind, OLD.created_at) THEN
            RAISE EXCEPTION 'Device identity is permanent';
        END IF;
    ELSE
        IF TG_OP = 'DELETE' OR (TG_OP = 'UPDATE' AND ROW(NEW.subject_id, NEW.owner_user_id, NEW.category_id, NEW.subtype, NEW.model, NEW.identifier_ciphertext, NEW.identifier_hmac, NEW.identifier_key_version) IS DISTINCT FROM ROW(OLD.subject_id, OLD.owner_user_id, OLD.category_id, OLD.subtype, OLD.model, OLD.identifier_ciphertext, OLD.identifier_hmac, OLD.identifier_key_version)) THEN
            RAISE EXCEPTION 'Device identity fields require a reviewed correction';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM categories WHERE id = NEW.category_id AND supported AND ((code = 'peripheral' AND NEW.subtype IN ('router','keyboard')) OR (code <> 'peripheral' AND NEW.subtype IS NULL))) THEN
            RAISE EXCEPTION 'Unsupported device category or subtype';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER permanent_subject_identity BEFORE UPDATE OR DELETE ON subjects FOR EACH ROW EXECUTE FUNCTION protect_device_identity();
CREATE TRIGGER permanent_item_identity BEFORE INSERT OR UPDATE OR DELETE ON items FOR EACH ROW EXECUTE FUNCTION protect_device_identity();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Permanent device records require a reviewed forward migration; rollback is disabled.');
    }
};
