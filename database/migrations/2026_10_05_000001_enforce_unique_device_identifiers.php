<?php

use App\Support\Devices\Identifiers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL runs this migration transactionally. Block writers before the preflight.
        DB::statement('LOCK TABLE items IN SHARE ROW EXCLUSIVE MODE');
        $duplicates = DB::selectOne('SELECT count(*) AS groups, coalesce(sum(total), 0) AS records FROM (SELECT count(*) AS total FROM items WHERE identifier_hmac IS NOT NULL GROUP BY identifier_hmac HAVING count(*) > 1) conflicts');
        if ((int) $duplicates->groups > 0) {
            throw new RuntimeException("Identifier migration blocked: {$duplicates->groups} duplicate comparison group(s), {$duplicates->records} record(s). A reviewed correction is required; no identifiers or records were changed.");
        }

        Schema::table('items', function (Blueprint $table): void {
            $table->text('identifier_kind')->nullable();
        });
        // The old free-text field did not distinguish serials from IMEIs; do not guess.
        DB::table('items')->whereNotNull('identifier_hmac')->update(['identifier_kind' => 'legacy_unspecified']);
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_identifier_kind_check CHECK ((identifier_hmac IS NULL AND identifier_kind IS NULL) OR (identifier_hmac IS NOT NULL AND identifier_kind IS NOT NULL AND identifier_kind IN ('serial', 'imei', 'legacy_unspecified')))");
        DB::statement('CREATE UNIQUE INDEX '.Identifiers::UNIQUE_INDEX.' ON items(identifier_hmac) WHERE identifier_hmac IS NOT NULL');
        DB::statement('DROP INDEX items_identifier_hmac_index');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_device_identifier_kind() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (TG_OP = 'UPDATE' AND NEW.identifier_kind IS DISTINCT FROM OLD.identifier_kind)
        OR (TG_OP = 'INSERT' AND NEW.identifier_kind = 'legacy_unspecified') THEN
        RAISE EXCEPTION 'Identifier type requires a reviewed correction';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER permanent_identifier_kind BEFORE INSERT OR UPDATE ON items FOR EACH ROW EXECUTE FUNCTION protect_device_identifier_kind();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Permanent identifier records require a reviewed forward migration; rollback is disabled.');
    }
};
