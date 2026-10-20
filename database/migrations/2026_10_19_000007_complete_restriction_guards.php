<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['restriction_final()' => ['NEW.action LIKE' => "to_jsonb(NEW)->>'action' LIKE", "NEW.aggregate_kind='restriction'" => "to_jsonb(NEW)->>'aggregate_kind'='restriction'"], 'restriction_dependency_final()' => ["TG_TABLE_NAME='restriction_records' AND NEW.kind='approve'" => "TG_TABLE_NAME='restriction_records' AND to_jsonb(NEW)->>'kind'='approve'"]] as $signature => $replacements) {
            $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS definition', [$signature])->definition;
            foreach ($replacements as $from => $to) {
                $definition = str_replace($from, $to, $definition, $count);
                if ($count !== 1) {
                    throw new RuntimeException('Restriction upgrade blocked: unexpected shared trigger definition.');
                }
            }
            DB::unprepared($definition);
        }
        DB::unprepared(file_get_contents(database_path('sql/restriction_review_integrity.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward restriction migration.');
    }
};
