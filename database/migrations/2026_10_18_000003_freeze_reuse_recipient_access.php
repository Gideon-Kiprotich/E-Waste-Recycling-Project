<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        if (DB::table('refurbishment_records')->exists() || DB::table('reuse_return_items')->exists()) {
            throw new RuntimeException('Recipient access upgrade requires inspected pre-use state; preserve existing history and stop for review.');
        }
        $body = DB::selectOne("SELECT pg_get_functiondef('refurbishment_context(uuid,uuid,uuid)'::regprocedure) AS body")->body;
        if (! str_contains($body, 'refurbishment_configuration(ref)') || ! str_contains($body, "'staff'")) {
            throw new RuntimeException('Unexpected reuse authorization context.');
        }
        DB::unprepared(file_get_contents(__DIR__.'/../sql/reuse_recipient_context.sql'));
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function down(): void
    {
        throw new RuntimeException('Recipient security-version protections must remain.');
    }
};
