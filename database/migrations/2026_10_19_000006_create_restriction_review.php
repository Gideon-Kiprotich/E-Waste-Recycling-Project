<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['receipt_discrepancies', 'assessments', 'downstream_records'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException('Restriction review upgrade requires the preceding workflow schema.');
            }
        }
        DB::unprepared(file_get_contents(database_path('sql/restriction_review.sql')));
        DB::unprepared(file_get_contents(database_path('sql/restriction_review_guards.sql')));
        $this->replaceEligibility();
        DB::unprepared(file_get_contents(database_path('sql/restriction_dependencies.sql')));
    }

    private function replaceEligibility(): void
    {
        // Patch only the known eligibility predicates; historical answer/rule checks remain unchanged.
        $patterns = [
            "/EXISTS\\s*\\(SELECT 1 FROM assessments WHERE subject_id=\\(m->>'subject_id'\\)::uuid AND hard_hold\\)/i" => "restriction_inspection_blocked((m->>'subject_id')::uuid)",
            '/EXISTS\s*\(SELECT 1 FROM assessments WHERE subject_id=([^\s)]+) AND hard_hold\)/i' => 'restriction_inspection_blocked($1)',
            '/EXISTS\s*\(SELECT 1 FROM assessments WHERE subject_id IN \(([^)]+)\) AND hard_hold\)/i' => 'restriction_any_inspection(ARRAY[$1])',
            '/EXISTS\s*\(SELECT 1 FROM assessments a WHERE a.hard_hold AND a.subject_id IN \((SELECT subject_id FROM batch_members[^;]+?)\)\)/i' => 'restriction_any_inspection(ARRAY($1))',
            '/EXISTS\s*\(SELECT 1 FROM receipt_discrepancies d JOIN receipt_outcomes (?:ro|o) ON (?:ro|o).id=d.outcome_id JOIN request_items ri ON ri.id=(?:ro|o).request_item_id WHERE ri.item_id=([^\s)]+) AND d.kind IN \(\x27mismatch\x27,\x27safety_hold\x27\)\)/i' => 'restriction_receipt_blocked($1)',
        ];
        $functions = DB::select("SELECT p.oid::regprocedure::text AS signature,pg_get_functiondef(p.oid) AS definition FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname=current_schema() AND p.prokind='f' AND (p.prosrc LIKE '%hard_hold%' OR p.prosrc LIKE '%receipt_discrepancies%') ORDER BY p.oid");
        $changed = 0;
        foreach ($functions as $function) {
            $definition = $function->definition;
            foreach ($patterns as $pattern => $replacement) {
                $definition = preg_replace($pattern, $replacement, $definition);
            }
            if ($definition !== $function->definition) {
                DB::unprepared($definition);
                $changed++;
            }
        }
        if ($changed < 10) {
            throw new RuntimeException('Restriction review upgrade blocked: unexpected eligibility definitions.');
        }
        // Refuse unknown retained eligibility predicates rather than leaving PHP/SQL inconsistent.
        $remaining = DB::select("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname=current_schema() AND p.prokind='f' AND p.prosrc ~* 'EXISTS[[:space:]]*\\(SELECT 1 FROM assessments[^;]*hard_hold' AND p.proname NOT LIKE 'restriction_%'");
        if ($remaining) {
            throw new RuntimeException('Restriction review upgrade blocked: an inspection eligibility predicate needs explicit review.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Restriction history requires a reviewed forward migration.');
    }
};
