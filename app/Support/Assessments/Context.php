<?php

namespace App\Support\Assessments;

use App\Enums\Role;
use App\Models\User;
use App\Support\Restrictions\Effective;
use Illuminate\Support\Facades\DB;

class Context
{
    public function scope(User $actor): ?object
    {
        $actor->load('membership');
        if (! $actor->canSignIn() || $actor->role() !== Role::Processor) {
            return null;
        }

        return DB::table('places as p')->join('organizations as o', 'o.id', '=', 'p.organization_id')->where('p.id', $actor->membership->place_id)->where('o.id', $actor->membership->organization_id)->where('p.kind', 'facility')->where('p.active', true)->where('p.review_status', 'approved')->where('o.review_status', 'approved')->whereIn('o.kind', ['processor', 'both'])->select('p.*')->first();
    }

    public function rows(User $actor)
    {
        $place = $this->scope($actor);

        return DB::table('items as i')->join('subjects as s', 's.id', '=', 'i.subject_id')->join('categories as cat', 'cat.id', '=', 'i.category_id')->join('custody_current as cc', 'cc.subject_id', '=', 'i.subject_id')->join('parties as p', 'p.id', '=', 'cc.party_id')->where('p.place_id', $place?->id ?? '00000000-0000-0000-0000-000000000000')->select('i.subject_id', 'i.model', 'i.subtype', 'i.owner_user_id', 'i.processing_state', 'i.category_id', 'cat.code as category', 'cat.label as category_label', 's.public_code', 's.version as subject_version', 'cc.party_id', 'cc.last_event_id', 'cc.version as custody_version')->orderBy('s.public_code');
    }

    public function authorized(User $actor, string $id): object
    {
        return $this->rows($actor)->where('i.subject_id', $id)->firstOrFail();
    }

    public function blockers(object $row): array
    {
        $result = [];
        if (! in_array($row->processing_state, ['received', 'assessed'], true) || ! DB::table('handover_receipts as r')->join('handover_items as hi', 'hi.id', '=', 'r.handover_item_id')->join('handovers as h', 'h.id', '=', 'hi.handover_id')->join('domain_events as e', 'e.id', '=', 'r.event_id')->where('r.subject_id', $row->subject_id)->where('r.event_id', $row->last_event_id)->where('h.receiver_party_id', $row->party_id)->where('hi.state', 'accepted')->where('e.safe_payload->destination_kind', 'facility')->exists()) {
            $result[] = 'An accepted facility handover and current facility custody are required. Open Custody handovers; initiation or scanning alone is insufficient.';
        }
        $place = DB::table('parties')->where('id', $row->party_id)->value('place_id');
        if (! DB::table('place_categories')->where('place_id', $place)->where('category_id', $row->category_id)->where('subtype', $row->subtype ?? '')->exists()) {
            $result[] = 'This facility lacks the exact category/subtype capability. Ask an Administrator to review Operators and places; compatibility is rechecked on submission.';
        }
        if (DB::table('handover_items')->where('subject_id', $row->subject_id)->where('active', true)->exists()) {
            $result[] = 'A pending or disputed handover still claims this item. Resolve it through Custody handovers before routine inspection.';
        }
        if (Effective::receiptKinds($row->subject_id)) {
            $result[] = 'An unresolved physical safety/mismatch restriction blocks this item. Open Operational restriction review for supported recording-error investigation; inspection cannot clear it.';
        }

        return $result;
    }

    public function restricted(string $id): bool
    {
        return Effective::inspectionBlocked($id);
    }
}
