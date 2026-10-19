<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE dismantling_batches, batch_outputs, custody_current, media_authorizations IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        if (Schema::hasTable('downstream_records') || ! Schema::hasTable('batch_detachments')) {
            throw new RuntimeException('Unexpected downstream upgrade state; preserve records and inspect privately.');
        }
        foreach (DB::table('dismantling_batches')->pluck('subject_id') as $ref) {
            DB::select('SELECT batch_integrity(?)', [$ref]);
        }
        DB::unprepared(file_get_contents(__DIR__.'/../sql/downstream_movements.sql'));
        DB::statement('ALTER TABLE media_authorizations ADD COLUMN downstream_receipt_id uuid REFERENCES downstream_records(id)');
        $this->check('media_authorizations', 'authorization_context_version = ANY', 'authorization_context_version IN (2,3,4)');
        $this->check('media_authorizations', 'batch_id IS NULL', '(authorization_context_version=2 AND batch_id IS NULL AND downstream_receipt_id IS NULL) OR (authorization_context_version=3 AND batch_id IS NOT NULL AND downstream_receipt_id IS NULL) OR (authorization_context_version=4 AND batch_id IS NOT NULL AND downstream_receipt_id IS NOT NULL)');
        $this->check('sanitization_records', 'authorization_context_version = ANY', 'version>=1 AND source_subject_version>=1 AND assessment_version>=2 AND ((authorization_context_version=1 AND authorization_id IS NULL AND custody_version>=2) OR (authorization_context_version IN (2,3,4) AND authorization_id IS NOT NULL AND custody_version>=1))');
        DB::unprepared(file_get_contents(__DIR__.'/../sql/downstream_assurance.sql'));
        $this->patch('media_custody_guard()', 'ref:=NEW.subject_id;', "ref:=NEW.subject_id; IF TG_TABLE_NAME='custody_current' THEN IF downstream_receipt_matches(ref,NEW.party_id,NEW.last_event_id,NEW.version) THEN RETURN NEW; END IF; END IF;");
        $this->patch('media_origin_integrity(uuid)', 'OR reuse_source_matches(ref,r.facility_party_id,o.event_id,1,1))', 'OR reuse_source_matches(ref,r.facility_party_id,o.event_id,1,1) OR batch_source_matches(ref,r.facility_party_id,o.event_id,1,1))');
        $this->patch('media_origin_integrity(uuid)', 'subject_id=o.parent_id AND party_id=cc.party_id', 'subject_id=o.parent_id AND party_id=r.facility_party_id');
        $this->patch('handover_subject_step(uuid,jsonb,jsonb)', 'OR EXISTS (SELECT 1 FROM inspection_steps', "OR EXISTS(SELECT 1 FROM downstream_records r JOIN downstream_shipments h ON h.id=r.shipment_id WHERE r.output_id=ref AND r.kind='accept' AND r.canonical AND h.source_subject_version=(before_row->>'version')::bigint AND h.source_subject_version+1=(after_row->>'version')::bigint) OR EXISTS (SELECT 1 FROM inspection_steps");
        $this->patch('handover_receipt_projection(uuid,uuid,jsonb,jsonb)', 'OR EXISTS (SELECT 1 FROM inspection_steps', "OR EXISTS(SELECT 1 FROM downstream_records r JOIN downstream_shipments h ON h.id=r.shipment_id WHERE r.output_id=ref AND r.kind='accept' AND r.canonical AND h.source_subject_version+1=(actual->>'version')::bigint) OR EXISTS (SELECT 1 FROM inspection_steps");
        $this->patch('sanitization_insert_guard()', 'IF NEW.authorization_context_version<>3', 'IF NEW.authorization_context_version NOT IN (3,4)');
        foreach (['downstream_records', 'output_profile_records', 'downstream_shipments'] as $table) {
            DB::statement('CREATE TRIGGER immutable_'.$table.' BEFORE UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION protect_device_history()');
        }
        foreach (['downstream_batches', 'downstream_records', 'downstream_shipments', 'batch_records', 'batch_outputs', 'custody_current'] as $table) {
            DB::statement('CREATE CONSTRAINT TRIGGER downstream_final_'.$table.' AFTER INSERT OR UPDATE ON '.$table.' DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION downstream_final()');
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION downstream_profile_final() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ref uuid;
BEGIN
 ref:=CASE WHEN TG_TABLE_NAME='output_profiles' THEN NEW.id ELSE NEW.profile_id END;
 IF NOT EXISTS(SELECT 1 FROM output_profiles p JOIN output_profile_records r ON r.id=p.record_id WHERE p.id=ref AND r.profile_id=p.id AND r.version=p.version AND p.version=(SELECT count(*) FROM output_profile_records WHERE profile_id=p.id)) THEN RAISE EXCEPTION 'Profile projection must match complete immutable review chain'; END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER output_profile_final AFTER INSERT OR UPDATE ON output_profiles DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION downstream_profile_final();
CREATE CONSTRAINT TRIGGER output_profile_record_final AFTER INSERT ON output_profile_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION downstream_profile_final();
SQL);
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function patch(string $function, string $search, string $replace, int $expected = 1): void
    {
        $body = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$function])->body;
        $body = str_replace($search, $replace, $body, $count);
        if ($count !== $expected) {
            throw new RuntimeException('Downstream upgrade blocked by unexpected protection: '.$function);
        }
        DB::unprepared($body);
    }

    private function check(string $table, string $needle, string $replace): void
    {
        $matches = collect(DB::select("SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid=?::regclass AND contype='c'", [$table]))->filter(fn ($c) => str_contains($c->definition, $needle));
        if ($matches->count() !== 1) {
            throw new RuntimeException('Unexpected '.$table.' constraint');
        }
        DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT "'.$matches->first()->conname.'", ADD CHECK ('.$replace.')');
    }

    public function down(): void
    {
        throw new RuntimeException('Movement/custody history is permanent; use reviewed forward correction.');
    }
};
