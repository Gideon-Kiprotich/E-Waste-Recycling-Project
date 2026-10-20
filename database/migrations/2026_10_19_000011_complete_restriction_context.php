<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $definition = DB::selectOne("SELECT pg_get_functiondef('restriction_dependency_context(uuid)'::regprocedure) AS definition")->definition;
        $insert = <<<'SQL'
'media_authorization',(SELECT to_jsonb(a) FROM media_authorization_current a WHERE a.subject_id=f.subject_id),
'handovers',(SELECT COALESCE(jsonb_agg(jsonb_build_array(h.id,h.state,h.source_event_id,h.source_custody_version) ORDER BY h.id),'[]') FROM handover_items h WHERE h.subject_id=f.subject_id AND h.active),
'place_context',(SELECT jsonb_build_array(l.id,l.version,l.kind,l.active,l.review_status,o.id,o.version,o.review_status,(SELECT jsonb_agg(jsonb_build_array(pc.category_id,pc.subtype) ORDER BY pc.category_id,pc.subtype) FROM place_categories pc WHERE pc.place_id=l.id)) FROM custody_current cc JOIN parties p ON p.id=cc.party_id JOIN places l ON l.id=p.place_id JOIN organizations o ON o.id=l.organization_id WHERE cc.subject_id=f.subject_id),
'assurance',
SQL;
        $definition = str_replace("'assurance',", $insert, $definition, $count);
        if ($count !== 1) {
            throw new RuntimeException('Restriction context upgrade blocked: unexpected dependency snapshot.');
        }
        DB::unprepared($definition);
        $definition = DB::selectOne("SELECT pg_get_functiondef('restriction_dependency_context(uuid)'::regprocedure) AS definition")->definition;
        $insert = <<<'SQL'
'assignments',(SELECT COALESCE(jsonb_agg(jsonb_build_array(a.id,a.version,a.state,a.collector_id,a.membership_id) ORDER BY a.id),'[]') FROM assignments a WHERE a.active AND EXISTS(SELECT 1 FROM request_items ri WHERE ri.request_id=a.request_id AND ri.item_id IN(SELECT subject_id FROM restriction_family(ref)))),
'route_claims',(SELECT COALESCE(jsonb_agg(jsonb_build_array(rc.approval_id,rc.kind,rc.record_id) ORDER BY rc.approval_id,rc.kind,rc.record_id),'[]') FROM route_claims rc WHERE rc.kind='assignment' AND rc.record_id IN(SELECT a.id FROM assignments a JOIN request_items ri ON ri.request_id=a.request_id WHERE ri.item_id IN(SELECT subject_id FROM restriction_family(ref)))),
'requests',
SQL;
        $definition = str_replace("'requests',", $insert, $definition, $count);
        if ($count !== 1) {
            throw new RuntimeException('Restriction context upgrade blocked: unexpected request snapshot.');
        }
        DB::unprepared($definition);
        // Before-insert candidates have no stamp yet; retained rows must still match their captured epoch.
        foreach ([
            'media_record_applicable(media_inventory_records)' => ["restriction_record_current('media_inventory_records',r.id,r.parent_id)", "(NOT EXISTS(SELECT 1 FROM media_inventory_records stored WHERE stored.id=r.id) OR restriction_record_current('media_inventory_records',r.id,r.parent_id))"],
            'media_authorization_applicable(media_authorizations)' => ["restriction_record_current('media_authorizations',a.id,a.subject_id)", "(NOT EXISTS(SELECT 1 FROM media_authorizations stored WHERE stored.id=a.id) OR restriction_record_current('media_authorizations',a.id,a.subject_id))"],
        ] as $signature => [$from, $to]) {
            $body = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS definition', [$signature])->definition;
            $body = str_replace($from, $to, $body, $count);
            if ($count !== 1) {
                throw new RuntimeException('Restriction context upgrade blocked: unexpected authority boundary.');
            }
            DB::unprepared($body);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward restriction migration.');
    }
};
