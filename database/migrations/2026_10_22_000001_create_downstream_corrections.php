<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/downstream_corrections.sql')));
        // Initial effective values must retain every existing physical/accounting fact.
        foreach (['downstream_records' => 'downstream_effective_records', 'downstream_shipments' => 'downstream_effective_shipments', 'batch_records' => 'downstream_effective_measurements'] as $original => $view) {
            if (DB::selectOne("SELECT count(*) AS n FROM ((SELECT * FROM {$original} EXCEPT SELECT * FROM {$view}) UNION ALL (SELECT * FROM {$view} EXCEPT SELECT * FROM {$original})) delta")->n) {
                throw new RuntimeException('Effective accounting preflight differs from original history; inspect without rewriting records.');
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retain reviewed downstream history; use forward corrections.');
    }
};
