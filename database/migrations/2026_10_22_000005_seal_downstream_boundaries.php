<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // G is signed evidence, not another original obligation that may renew itself.
        foreach (['downstream_impact_fulfilled(uuid)', 'downstream_continuity(uuid)'] as $signature) {
            $body = $this->body($signature);
            $body = str_replace('downstream_proof_current(r.id)', 'downstream_original_proof_current(r.id)', $body, $count);
            if ($count !== 1) {
                throw new RuntimeException('Unexpected downstream signature boundary; inspect without rewriting history.');
            }
            DB::unprepared($body);
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION downstream_proof_current(ref uuid) RETURNS boolean LANGUAGE plpgsql STABLE AS $$
DECLARE review_action text;
BEGIN
 IF downstream_original_proof_current(ref) THEN RETURN true; END IF;
 SELECT effect.action INTO review_action FROM downstream_review_effects effect
 JOIN restriction_records review ON review.id=effect.record_id JOIN restriction_cases c ON c.id=review.case_id
 WHERE effect.record_id=ref AND review.kind='approve' AND c.contract='P16';
 IF NOT FOUND OR review_action IN ('downstream_continuity','closure_review') THEN RETURN false; END IF;
 RETURN EXISTS(SELECT 1 FROM restriction_impacts WHERE record_id=ref)
 AND NOT EXISTS(SELECT 1 FROM restriction_impacts impact WHERE impact.record_id=ref AND NOT downstream_impact_fulfilled(impact.id));
END; $$;
CREATE OR REPLACE FUNCTION downstream_named_destination_signed(p restriction_records,source uuid,required_fact text) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT COALESCE((SELECT
 (h.receiver_actor_id=p.actor_id AND downstream_destination_actor(p.actor_id,source) AND p.details->'facts'->>required_fact='YES')
 OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(p.details->'party_findings') cited JOIN restriction_records signature ON signature.id=cited::uuid WHERE signature.actor_id=h.receiver_actor_id AND downstream_destination_actor(signature.actor_id,source) AND signature.details->'facts'->>required_fact='YES')
 FROM downstream_records r JOIN downstream_effective_shipments h ON h.id=r.shipment_id WHERE r.id=source),false)
$$;
SQL);
        $body = preg_replace('/\bBEGIN\s*\n/', <<<'SQL'
BEGIN
 IF EXISTS(SELECT 1 FROM batch_outputs WHERE subject_id=NEW.output_id AND type='storage')
 AND NEW.requested_kg IS DISTINCT FROM (downstream_stock(NEW.output_id)->>'produced')::numeric THEN
 RAISE EXCEPTION USING ERRCODE='P1600',MESSAGE='A new canonical shipment must request the measured whole unit. An effective mass revision cannot normalize a fractional draft request.';
 END IF;

SQL, $this->body('downstream_header_guard()'), 1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected canonical draft boundary; inspect before migrating.');
        }
        DB::unprepared($body);
    }

    private function body(string $signature): string
    {
        return DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$signature])->body;
    }

    public function down(): void
    {
        throw new RuntimeException('Retain sealed downstream authority and history; use forward migrations.');
    }
};
