<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('receipt_visits') || DB::table('receipt_visits')->exists()) {
            throw new RuntimeException('Receipt boundary upgrade requires the expected empty new receipt ledger; inspect unexpected physical history privately.');
        }
        DB::unprepared(file_get_contents(__DIR__.'/../sql/physical_receipt_boundaries.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Receipt boundary protections require a reviewed forward migration.');
    }
};
