<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Restoring scope must not revive an old continuity/fulfillment signature.
        foreach (['media_impact_fulfilled(uuid)', 'media_recovery_allowed(uuid,text)'] as $signature) {
            $body = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS body', [$signature])->body;
            $from = 'AND restriction_admin(r.actor_id) AND';
            $to = 'AND restriction_admin(r.actor_id) AND r.actor_context=restriction_actor_context(r.actor_id) AND EXISTS(SELECT 1 FROM restriction_records technical_signature WHERE technical_signature.id=e.proposal_id AND technical_signature.actor_context=restriction_actor_context(technical_signature.actor_id)) AND';
            $body = str_replace($from, $to, $body, $count);
            if ($count !== 1) {
                throw new RuntimeException('Unexpected obligation signature boundary; inspect without rewriting history.');
            }
            DB::unprepared($body);
        }
        $body = DB::selectOne("SELECT pg_get_functiondef('media_validate_record(restriction_records)'::regprocedure) AS body")->body;
        $from = "WHEN n.observed_at>clock_timestamp() OR n.observed_at<date_trunc('minute',GREATEST(c.created_at,source.source_time)) OR n.recorded_at<n.observed_at THEN 'Observation time is outside the investigation/source period. Recheck the UTC time and reload the form.'";
        $to = "WHEN n.observed_at>clock_timestamp() THEN 'Observation time is in the future relative to the server. Recheck the UTC time.' WHEN n.kind<>'open' AND n.observed_at<date_trunc('minute',c.created_at) THEN 'Observation time precedes this investigation. Recheck the UTC time.' WHEN n.observed_at<date_trunc('minute',source.source_time) THEN 'Observation time precedes the original source. Recheck the UTC time.' WHEN n.recorded_at<n.observed_at THEN 'Recording time precedes the stated observation. Recheck the UTC time.'";
        $body = str_replace($from, $to, $body, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected observation-period boundary; inspect before migrating.');
        }
        $body = str_replace('GREATEST(c.created_at,source.source_time)', "GREATEST(CASE WHEN n.kind='open' THEN source.source_time ELSE c.created_at END,source.source_time)", $body, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected opening observation boundary; inspect before migrating.');
        }
        DB::unprepared($body);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward correction migration.');
    }
};
