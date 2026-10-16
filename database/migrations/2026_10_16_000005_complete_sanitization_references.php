<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        if (DB::table('domain_events')->where('aggregate_kind', 'sanitization')->whereNull('subject_id')->exists()
            || DB::table('commands as c')->whereRaw("c.action ~ '^sanitization_(attempt|verification|validation|revalidation):'")->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sanitization_records as r')->whereColumn('r.command_id', 'c.id'))->exists()) {
            throw new RuntimeException('Unbacked sanitization command/event found. Inspect privately; no history is repaired automatically.');
        }
        foreach (DB::table('sanitization_current')->pluck('subject_id') as $subject) {
            DB::select('SELECT sanitization_integrity(?)', [$subject]);
        }
        DB::statement("ALTER TABLE domain_events ADD CONSTRAINT sanitization_event_subject_required CHECK (aggregate_kind<>'sanitization' OR subject_id IS NOT NULL)");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION sanitization_command_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid;
BEGIN
 SELECT subject_id INTO ref FROM sanitization_records WHERE command_id=NEW.id;
 IF ref IS NULL THEN RAISE EXCEPTION 'Sanitization command requires its immutable operation record'; END IF;
 PERFORM sanitization_integrity(ref);
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER sanitization_command_complete AFTER INSERT ON commands DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.action ~ '^sanitization_(attempt|verification|validation|revalidation):') EXECUTE FUNCTION sanitization_command_final();
SQL);
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function down(): void
    {
        throw new RuntimeException('Sanitization reciprocal references require a reviewed forward migration.');
    }
};
