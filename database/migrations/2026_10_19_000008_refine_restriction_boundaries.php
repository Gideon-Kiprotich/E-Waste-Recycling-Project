<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->patch('restriction_record_guard()', 'JOIN parties p ON p.id=cc.party_id WHERE m.user_id=NEW.assigned_to AND m.place_id=p.place_id', 'JOIN parties scope_party ON scope_party.id=cc.party_id WHERE m.user_id=NEW.assigned_to AND m.place_id=scope_party.place_id');
        // Savepoints have subtransaction xmins; trigger provenance works at both root and nested boundaries.
        $this->patch('restriction_stamp_guard()', 'xmin::text::bigint=(pg_current_xact_id()::text::bigint %% 4294967296)', 'pg_trigger_depth()>1');
        $this->patch('restriction_epoch_step_guard()', 'r.xmin::text::bigint=(pg_current_xact_id()::text::bigint % 4294967296)', 'pg_trigger_depth()>1');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION restriction_receipt_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid;
BEGIN
 SELECT item_id INTO ref FROM request_items WHERE id=NEW.request_item_id;
 IF NEW.outcome='received' AND (restriction_receipt_blocked(ref) OR restriction_dependent(ref)) THEN RAISE EXCEPTION USING ERRCODE='P1400',MESSAGE='The effective receipt restriction still blocks actual receipt. Complete supported review and fresh prerequisites first.'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER restriction_receipt_valid BEFORE INSERT ON receipt_outcomes FOR EACH ROW EXECUTE FUNCTION restriction_receipt_boundary();
SQL);
    }

    private function patch(string $signature, string $from, string $to): void
    {
        $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS definition', [$signature])->definition;
        $definition = str_replace($from, $to, $definition, $count);
        if ($count !== 1) {
            throw new RuntimeException('Restriction boundary upgrade blocked: unexpected preceding definition.');
        }
        DB::unprepared($definition);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward restriction migration.');
    }
};
