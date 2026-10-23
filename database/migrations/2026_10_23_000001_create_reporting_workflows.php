<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::selectOne('SELECT count(*) AS count FROM (SELECT outbox_id FROM (SELECT outbox_id FROM assignment_notifications UNION ALL SELECT outbox_id FROM receipt_notifications UNION ALL SELECT outbox_id FROM handover_notifications UNION ALL SELECT outbox_id FROM assessment_notifications UNION ALL SELECT outbox_id FROM restriction_notifications) n GROUP BY outbox_id HAVING count(*)>1) duplicate_families')->count;
        if ($duplicates) {
            throw new RuntimeException('Reporting cutover blocked: notice family ownership requires review. No survivor was selected.');
        }
        DB::unprepared(file_get_contents(__DIR__.'/../sql/reporting.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Reporting history requires a forward migration; do not erase inbox or export audits.');
    }
};
