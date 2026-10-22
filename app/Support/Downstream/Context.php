<?php

namespace App\Support\Downstream;

use App\Models\User;
use App\Support\Collection\AdminMutation;
use Illuminate\Support\Facades\DB;

class Context
{
    public const LABELS = ['draft' => 'Prepare shipment draft', 'reserve' => 'Reserve stock', 'withdraw' => 'Withdraw remaining reservation', 'dispatch' => 'Record actual dispatch', 'accept' => 'Acknowledge accepted quantity', 'refuse' => 'Record refused quantity', 'shortage' => 'Record missing quantity', 'dispute' => 'Record quantity dispute', 'complete' => 'Record completion evidence', 'source_loss' => 'Record source-stock loss', 'downstream_loss' => 'Record downstream loss', 'measurement_dispute' => 'Report measurement or later evidence dispute', 'close' => 'Close accounted batch'];

    public static function correctionsAvailable(): bool
    {
        return (bool) DB::selectOne("SELECT to_regprocedure('downstream_corrections_ready()') IS NOT NULL AS ready")->ready;
    }

    public static function records(): string
    {
        return DB::getSchemaBuilder()->hasTable('downstream_fact_revisions') ? 'downstream_effective_records' : 'downstream_records';
    }

    public static function shipments(): string
    {
        return DB::getSchemaBuilder()->hasTable('downstream_recipient_records') ? 'downstream_effective_shipments' : 'downstream_shipments';
    }

    public function source(User $actor, string $batch): object
    {
        return app(\App\Support\Batches\Context::class)->batch($actor, $batch);
    }

    public function shipment(User $actor, string $shipment): object
    {
        $h = DB::table(self::shipments())->where('id', $shipment)->firstOrFail();
        $party = app(\App\Support\Batches\Context::class)->party($actor);
        $source = DB::table('dismantling_batches')->where('subject_id', $h->batch_id)->value('facility_party_id');
        $destination = DB::table('output_profile_records')->where('id', $h->profile_record_id)->value('facility_party_id');
        $pending = DB::getSchemaBuilder()->hasTable('downstream_recipient_records')
            ? DB::table('downstream_recipient_current')->where('shipment_id', $shipment)->where('kind', 'proposed')->where('receiver_actor_id', $actor->id)->whereRaw('receiver_context=restriction_actor_context(?)', [$actor->id])->first() : null;
        abort_unless($party === $source || (($h->receiver_actor_id === $actor->id || $pending) && $party === $destination && (int) $actor->membership->version === (int) $h->receiver_scope_version), 404);

        return $h;
    }

    public function snapshot(string $batch, User $actor, ?string $shipment = null): array
    {
        return json_decode(DB::selectOne('SELECT downstream_context(?,?,?) AS context', [$batch, $actor->id, $shipment])->context, true, flags: JSON_THROW_ON_ERROR);
    }

    public function token(string $batch, User $actor, ?string $shipment = null): string
    {
        return AdminMutation::hash(['batch' => $batch, 'shipment' => $shipment, 'context' => $this->snapshot($batch, $actor, $shipment)]);
    }

    public function version(string $batch): int
    {
        return (int) DB::table('downstream_batches')->where('batch_id', $batch)->value('version');
    }

    public function stock(string $output): array
    {
        return collect(DB::select('SELECT key,value FROM jsonb_each_text(downstream_stock(?))', [$output]))->pluck('value', 'key')->all();
    }

    public function profiles()
    {
        return DB::table('output_profiles as p')->join('output_profile_records as r', 'r.id', '=', 'p.record_id')->join('parties as party', 'party.id', '=', 'r.facility_party_id')->join('places as l', 'l.id', '=', 'party.place_id')->join('organizations as o', 'o.id', '=', 'l.organization_id')->select('r.*', 'l.name as facility_name', 'l.id as facility_id', 'o.name as operator_name')->orderBy('l.name');
    }

    public function recipients()
    {
        return DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('places as l', 'l.id', '=', 'm.place_id')->join('organizations as o', 'o.id', '=', 'm.organization_id')->whereColumn('l.organization_id', 'o.id')->where('m.active', true)->where('m.role', 'processor')->where('u.status', 'active')->where('l.kind', 'facility')->where('l.active', true)->where('l.review_status', 'approved')->where('o.review_status', 'approved')->orderBy('l.name')->get(['u.id', 'u.display_name', 'l.id as facility_id', 'l.name as facility_name']);
    }
}
