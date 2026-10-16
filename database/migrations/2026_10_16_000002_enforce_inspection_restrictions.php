<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION inspection_hard_restriction(answers jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
 SELECT answers->>'safety_concern' IN ('YES','UNKNOWN')
 OR COALESCE(answers->>'media_identified' IN ('NO','UNKNOWN'),false)
 OR COALESCE(answers->>'fan_voltage' IN ('OUTSIDE','UNKNOWN'),false) OR COALESCE(answers->>'fan_size' IN ('OUTSIDE','UNKNOWN'),false)
 OR COALESCE(answers->>'fan_application' IN ('OTHER','UNKNOWN'),false) OR COALESCE(answers->>'fan_electronics' IN ('ADDITIONAL','UNKNOWN'),false)
 OR COALESCE(answers->>'charging_risk' IN ('YES','UNKNOWN'),false) OR COALESCE(answers->>'battery_risk' IN ('YES','UNKNOWN'),false) OR COALESCE(answers->>'thermal_issue' IN ('YES','UNKNOWN'),false)
$$;
SQL);
        $definition = DB::selectOne("SELECT pg_get_functiondef('inspection_integrity(uuid)'::regprocedure) AS definition")->definition;
        $updated = preg_replace('/hard:=answers.*?;\n IF a\.hard_hold/s', "hard:=inspection_hard_restriction(answers);\n IF a.hard_hold", $definition, 1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Inspection safety upgrade blocked: unexpected projection guard.');
        }
        DB::unprepared($updated);
        foreach (DB::table('assessment_current')->pluck('subject_id') as $id) {
            DB::select('SELECT inspection_integrity(?)', [$id]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward inspection migration.');
    }
};
