<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        if (DB::table('sanitization_policies')->count() !== 1 || ! DB::table('sanitization_policies')->where('id', 'a3d71835-fce3-4ad7-ae2f-cda4507b1001')->where('version_code', 'MEDIA-ASSURANCE-1.0')->exists()) {
            throw new RuntimeException('Unexpected sanitization policy; inspect privately before upgrading.');
        }
        DB::statement("ALTER TABLE sanitization_policies ADD CHECK (id='a3d71835-fce3-4ad7-ae2f-cda4507b1001' AND version_code='MEDIA-ASSURANCE-1.0')");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION sanitization_evidence_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.attachment_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM attachments a WHERE a.id=NEW.attachment_id AND a.uploaded_by=NEW.actor_id AND a.privacy_class='private')
 THEN RAISE EXCEPTION 'Sanitization evidence must be private and attributable to the actual recording actor'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER validate_sanitization_evidence BEFORE INSERT ON sanitization_records FOR EACH ROW EXECUTE FUNCTION sanitization_evidence_guard();
SQL);
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function down(): void
    {
        throw new RuntimeException('Sanitization evidence protections require a reviewed forward migration.');
    }
};
