<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('output_profile_records')->exists() || DB::table('downstream_records')->exists()) {
            throw new RuntimeException('Pre-use downstream controls require inspected empty ledgers; preserve unexpected records.');
        }
        DB::statement('ALTER TABLE output_profile_records ADD COLUMN context jsonb NOT NULL');
        DB::unprepared(file_get_contents(__DIR__.'/../sql/downstream_controls.sql'));
        $this->patch('receipt_integrity()', 'IF NOT FOUND THEN', "IF NOT FOUND THEN\n IF EXISTS(SELECT 1 FROM batch_detachments d JOIN items i ON i.subject_id=d.subject_id WHERE d.subject_id=ref AND i.intake_class='processor_derived') THEN PERFORM media_origin_integrity(ref); RETURN NULL; END IF;");
        $this->patch('downstream_profile_guard()', 'IF NEW.version<>', 'IF NEW.context IS DISTINCT FROM output_profile_context(NEW.actor_id,NEW.facility_party_id) OR NEW.version<>');
        $this->patch('downstream_profile_final()', 'RETURN NULL;', "IF EXISTS(SELECT 1 FROM output_profiles p JOIN output_profile_records r ON r.id=p.record_id WHERE p.id=ref AND r.context IS DISTINCT FROM output_profile_context(r.actor_id,r.facility_party_id)) THEN RAISE EXCEPTION 'Acceptance review dependencies changed before commit'; END IF; RETURN NULL;");
    }

    private function patch(string $function, string $search, string $replace): void
    {
        $body = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$function])->body;
        $body = str_replace($search, $replace, $body, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected downstream dependency protection: '.$function);
        }
        DB::unprepared($body);
    }

    public function down(): void
    {
        throw new RuntimeException('Downstream dependency protections require forward correction.');
    }
};
