<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('directory_revisions') || Schema::hasColumn('organizations', 'brs_number') || Schema::hasColumn('places', 'site_address_ciphertext')) {
            throw new RuntimeException('Directory duplicate migration blocked: unexpected schema.');
        }
        $guards = DB::selectOne("SELECT count(*) AS total FROM pg_trigger WHERE tgname IN ('permanent_organization_identity','permanent_place_identity') AND tgrelid IN ('organizations'::regclass,'places'::regclass) AND tgenabled='O'");
        if ((int) $guards->total !== 2) {
            throw new RuntimeException('Directory duplicate migration blocked: identity protections are not enabled.');
        }
        DB::statement('LOCK TABLE organizations, places IN SHARE ROW EXCLUSIVE MODE');
        $key = "lower(btrim(regexp_replace(normalize(name, NFC),'[[:space:]]+', ' ', 'g')))";
        foreach (['organizations' => $key, 'places' => 'organization_id,'.$key.',latitude,longitude'] as $table => $columns) {
            $groups = DB::select("SELECT array_agg(id ORDER BY id) AS references,count(*) AS count FROM $table GROUP BY $columns HAVING count(*)>1");
            if ($groups) {
                throw new RuntimeException('Directory duplicate migration blocked: '.$table.' conflicts (opaque references/counts only) '.json_encode($groups, JSON_THROW_ON_ERROR));
            }
            if (DB::table($table)->whereRaw($key." = ''")->exists()) {
                throw new RuntimeException('Directory duplicate migration blocked: blank normalized names. Review affected records privately.');
            }
        }
        Schema::table('organizations', function (Blueprint $t): void {
            $t->text('brs_number')->nullable();
            $t->foreignUuid('identifier_verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('identifier_verified_at')->nullable();
        });
        Schema::table('places', fn (Blueprint $t) => $t->text('site_address_ciphertext')->nullable());
        DB::unprepared(<<<'SQL'
CREATE FUNCTION directory_name_key(value text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$ SELECT lower(btrim(regexp_replace(normalize(value, NFC),'[[:space:]]+', ' ', 'g'))) $$;
CREATE UNIQUE INDEX organizations_name_identity ON organizations (directory_name_key(name));
CREATE UNIQUE INDEX organizations_brs_identity ON organizations (brs_number) WHERE brs_number IS NOT NULL;
CREATE UNIQUE INDEX places_site_identity ON places (organization_id,directory_name_key(name),latitude,longitude);
ALTER TABLE organizations ADD CONSTRAINT organization_name_present CHECK (directory_name_key(name) <> ''), ADD CONSTRAINT verified_brs_identity CHECK ((brs_number IS NULL AND identifier_verified_by IS NULL AND identifier_verified_at IS NULL) OR (brs_number IS NOT NULL AND brs_number ~ '^[A-Z0-9/-]{1,64}$' AND brs_number ~ '[A-Z0-9]' AND identifier_verified_by IS NOT NULL AND identifier_verified_at IS NOT NULL));
ALTER TABLE places ADD CONSTRAINT place_name_present CHECK (directory_name_key(name) <> '');
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Directory identities require a reviewed forward migration.');
    }
};
