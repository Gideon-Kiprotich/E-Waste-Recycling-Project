<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('items as i')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('custody_current as c')->whereColumn('c.subject_id', 'i.subject_id'))->exists()) {
            throw new RuntimeException('Custody retention upgrade blocked: missing current custody. Inspect privately; no history is inferred.');
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION retain_current_custody() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Current custody is retained; use an acknowledged transition'; END IF;
 IF NEW.subject_id IS DISTINCT FROM OLD.subject_id THEN RAISE EXCEPTION 'Custody subject identity is permanent'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER permanent_current_custody BEFORE UPDATE OR DELETE ON custody_current FOR EACH ROW EXECUTE FUNCTION retain_current_custody();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward custody migration.');
    }
};
