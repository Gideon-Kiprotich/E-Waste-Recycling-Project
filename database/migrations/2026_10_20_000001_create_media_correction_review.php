<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE media_inventory_entries, restriction_cases IN SHARE ROW EXCLUSIVE MODE');
        if (DB::selectOne('SELECT count(*) AS n FROM (SELECT medium_id FROM media_inventory_entries WHERE medium_id IS NOT NULL GROUP BY medium_id HAVING count(*)>1) x')->n) {
            throw new RuntimeException('Media correction preflight blocked: conflicting canonical claims; no automatic repair.');
        }
        DB::unprepared(file_get_contents(database_path('sql/media_corrections.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Reviewed media history requires a forward migration.');
    }
};
