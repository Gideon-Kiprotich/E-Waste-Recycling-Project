<?php

namespace App\Support\Assignments;

use App\Models\Item;
use App\Support\Collection\Directory;
use App\Support\Collection\Review;
use App\Support\Collection\ReviewFeedback;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AllocationGuide
{
    public function inspect(object $request): array
    {
        $ids = DB::table('request_items')->where('request_id', $request->id)->where('active', true)->pluck('item_id');
        $items = Item::with('category')->whereIn('subject_id', $ids)->get();
        $required = $items->map(fn ($item) => $item->subtypeLabel() ?? $item->category->label)->unique()->values()->all();
        $places = DB::table('places as p')->join('organizations as o', 'o.id', '=', 'p.organization_id')->orderBy('p.name')->orderBy('p.id')->get(['p.id', 'p.name', 'p.kind', 'p.version', 'p.active', 'p.review_status', 'p.synthetic', 'p.organization_id', 'o.name as operator_name', 'o.kind as operator_kind', 'o.review_status as operator_status']);
        $compatible = $places->filter(fn ($p) => $p->active && $p->review_status === 'approved' && $p->operator_status === 'approved' && $items->isNotEmpty() && app(Directory::class)->compatible($p, $items));
        $operatorIds = $compatible->filter(fn ($p) => in_array($p->operator_kind, ['collector', 'both'], true))->pluck('organization_id');
        $members = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->leftJoin('organizations as o', 'o.id', '=', 'm.organization_id')->where('m.role', 'collector')->orderBy('u.display_name')->orderBy('m.id')->get(['m.id', 'm.version', 'm.user_id', 'm.active', 'm.organization_id', 'u.status', 'u.display_name', 'o.name as operator_name', 'o.kind as operator_kind', 'o.review_status as operator_status', 'o.synthetic']);
        $excluded = [];
        $eligibleMembers = $members->filter(function ($m) use ($operatorIds, &$excluded): bool {
            $reason = match (true) {
                ! $m->active || $m->status !== 'active' => 'Account or membership inactive; resolve Collector access before allocation.',
                ! $m->organization_id => 'No operator link; link this existing Collector through Manage Collector access.',
                $m->operator_status !== 'approved' || ! in_array($m->operator_kind, ['collector', 'both'], true) => 'Linked operator is not an approved Collector operator or Collector and processor.',
                ! $operatorIds->contains($m->organization_id) => 'Linked operator has no active, reviewed place accepting every required category and subtype.',
                default => null,
            };
            if ($reason) {
                $excluded[] = ['id' => $m->user_id, 'name' => $m->display_name ?: 'Unnamed Collector', 'operator' => $m->operator_name, 'reason' => $reason];
            }

            return $reason === null;
        });
        $receiving = $compatible->filter(fn ($p) => in_array($p->kind, ['facility', 'collection_point'], true) && in_array($p->operator_kind, ['processor', 'both'], true));
        $receivingCandidates = $places->filter(fn ($p) => in_array($p->kind, ['facility', 'collection_point'], true) && in_array($p->operator_kind, ['processor', 'both'], true));
        $receivingOperators = DB::table('organizations')->whereIn('kind', ['processor', 'both'])->orderBy('name')->get(['id', 'name', 'kind', 'review_status']);
        $collectingOperators = DB::table('organizations')->whereIn('kind', ['collector', 'both'])->orderBy('name')->get(['id', 'name', 'review_status']);
        $receivingMissing = match (true) {
            ! $receivingOperators->contains(fn ($o) => $o->review_status === 'approved') => 'No approved receiving operator exists. Approve a Processor operator or Collector and processor first, with appropriate private evidence, then add its receiving place.',
            $receivingCandidates->isEmpty() => 'A reviewed receiving operator exists, but it has no receiving facility or collection point. Use Add point or facility; a depot cannot receive this job.',
            ! $receivingCandidates->contains(fn ($p) => $p->active && $p->review_status === 'approved' && $p->operator_status === 'approved') => 'Receiving places exist but are inactive, pending, suspended or attached to an unapproved operator. Check Review state and Selection availability in Operators and places.',
            default => 'Reviewed receiving places exist, but none accepts every required category and subtype. Check Explicit acceptance capabilities; whole-device support does not include loose drives or fans.',
        };
        $snapshot = app(Review::class)->assess($request);
        $state = app(Review::class)->state($request);

        return [
            'required' => $required,
            'reasons' => ReviewFeedback::reasons($snapshot['blockers']),
            'state' => $state,
            'memberOptions' => $eligibleMembers->mapWithKeys(fn ($m) => [$m->id.':'.$m->version => ($m->display_name ?: 'Unnamed Collector').' · '.$m->operator_name.' · accepts all required items'.($m->synthetic ? ' · Synthetic' : '')])->all(),
            'placeOptions' => $receiving->mapWithKeys(fn ($p) => [$p->id.':'.$p->version => $p->name.' · '.$p->operator_name.' · '.str_replace('_', ' ', $p->kind).' · accepts all required items'.($p->synthetic ? ' · Synthetic' : '')])->all(),
            'collectors' => $eligibleMembers->map(fn ($m) => ['id' => $m->user_id, 'name' => $m->display_name ?: 'Unnamed Collector', 'operator' => $m->operator_name])->values()->all(),
            'collectingOperators' => $collectingOperators->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'reason' => $o->review_status !== 'approved' ? 'Operator needs approval.' : ($operatorIds->contains($o->id) ? 'Reviewed place accepts every required item.' : 'No active, reviewed place accepts every required category/subtype.')])->all(),
            'receivingOperators' => $receivingOperators->all(),
            'excludedCollectors' => $excluded,
            'collectorMissing' => $members->isEmpty() ? 'No Collector accounts exist. Create a Collector account and link it to an approved collecting operator.' : 'No eligible scoped Collector supports this job. Resolve the account, operator link or exact capabilities listed below; creating another account alone will not fix operator eligibility.',
            'receivingMissing' => $receivingMissing,
            'receivingPlaces' => $this->receivingContext($receivingCandidates, $items),
            'canOffer' => $request->mode === 'pickup' && $state === 'ready' && ! $snapshot['blockers'] && $eligibleMembers->isNotEmpty() && $receiving->isNotEmpty(),
        ];
    }

    private function receivingContext(Collection $places, Collection $items): array
    {
        return $places->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'operator' => $p->operator_name, 'reason' => match (true) {
            $p->operator_status !== 'approved' => 'Operator needs approval.',
            ! $p->active || $p->review_status !== 'approved' => 'Place needs active availability and project review.',
            ! app(Directory::class)->compatible($p, $items) => 'Missing acceptance for one or more required categories/subtypes.',
            default => 'Compatible with every required item.',
        }])->all();
    }
}
