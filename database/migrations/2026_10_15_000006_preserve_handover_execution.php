<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(__DIR__.'/../sql/handover_execution.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward custody migration.');
    }
};
