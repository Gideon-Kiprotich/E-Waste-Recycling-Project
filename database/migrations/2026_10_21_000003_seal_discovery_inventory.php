<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE media_inventory_records, batch_media_discoveries IN SHARE ROW EXCLUSIVE MODE');
        if (DB::selectOne('SELECT count(*) n FROM media_current_bindings binding JOIN batch_media_discoveries discovery ON discovery.medium_id=binding.medium_id')->n) {
            throw new RuntimeException('Discovery inventory guard blocked by an existing association; preserve and investigate without choosing a replacement history.');
        }
        $body = DB::selectOne("SELECT pg_get_functiondef('media_inventory_insert_guard()'::regprocedure) AS body")->body;
        $prefix = <<<'SQL'
BEGIN
 IF NEW.kind IN ('identify','link') AND EXISTS(SELECT 1 FROM batch_media_discoveries WHERE medium_id=NEW.medium_id) THEN
 RAISE EXCEPTION USING ERRCODE='P1500',MESSAGE='On-site discovery output cannot acquire another parent association. Replacement and re-parenting are outside this contract.';
 END IF;

SQL;
        $body = str_replace("BEGIN\n", $prefix, $body, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected inventory insertion boundary; inspect before migrating.');
        }
        DB::unprepared($body);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward correction migration.');
    }
};
