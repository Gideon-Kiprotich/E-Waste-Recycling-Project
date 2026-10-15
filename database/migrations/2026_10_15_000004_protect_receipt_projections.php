<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $guard = DB::selectOne("SELECT count(*) AS n FROM pg_trigger WHERE tgrelid='items'::regclass AND tgname='item_receipt_consistency' AND tgenabled='O' AND tgdeferrable");
        if ((int) $guard->n !== 1) {
            throw new RuntimeException('Receipt projection upgrade blocked: expected deferred protection missing.');
        }
        DB::unprepared(file_get_contents(__DIR__.'/../sql/physical_receipt_projection_guard.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Receipt projections require a reviewed forward migration.');
    }
};
