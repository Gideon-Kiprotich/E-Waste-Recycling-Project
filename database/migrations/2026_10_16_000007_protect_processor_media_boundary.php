<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('categories')->where('code', 'processor_media')->first();
        if (! $row || $row->question_set_version !== 'MEDIA-INVENTORY-1.0') {
            throw new RuntimeException('Processor media boundary upgrade blocked: unexpected category.');
        }
        $guard = DB::selectOne("SELECT pg_get_functiondef('protect_device_identity()'::regprocedure) AS body")->body;
        $guard = str_replace('id = NEW.category_id AND supported AND (', "id = NEW.category_id AND (supported OR code = 'processor_media') AND (", $guard, $count);
        if ($count !== 1) {
            throw new RuntimeException('Processor media boundary upgrade blocked: unexpected identity guard.');
        }
        DB::unprepared($guard);
        // Supported marks consumer/directory intake, not the processor-only provenance path.
        DB::table('categories')->where('id', $row->id)->update(['supported' => false]);
    }

    public function down(): void
    {
        throw new RuntimeException('Processor-only intake must remain restricted.');
    }
};
