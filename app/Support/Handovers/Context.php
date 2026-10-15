<?php

namespace App\Support\Handovers;

use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\AdminMutation;
use Illuminate\Support\Facades\DB;

class Context
{
    public function actor(User $actor): User
    {
        return $actor->load('membership')->role() === Role::Administrator
            ? AdminMutation::actor($actor)
            : app(\App\Support\Receipts\Context::class)->actor($actor);
    }

    public function lockScopes(array $userIds): void
    {
        DB::table('users')->whereIn('id', $userIds)->orderBy('id')->lockForUpdate()->get();
        $members = DB::table('memberships')->whereIn('user_id', $userIds)->orderBy('id')->lockForUpdate()->get();
        DB::table('organizations')->whereIn('id', $members->pluck('organization_id')->filter())->orderBy('id')->lockForUpdate()->get();
        DB::table('places')->whereIn('id', $members->pluck('place_id')->filter())->orderBy('id')->lockForUpdate()->get();
        DB::table('place_categories')->whereIn('place_id', $members->pluck('place_id')->filter())->orderBy('place_id')->orderBy('category_id')->orderBy('subtype')->lockForUpdate()->get();
    }

    public function party(User $actor): ?string
    {
        $m = $actor->membership;
        $org = DB::table('organizations')->where('id', $m?->organization_id)->first();
        if (! $actor->canSignIn() || $org?->review_status !== 'approved') {
            return null;
        }
        if ($actor->role() === Role::Collector && in_array($org->kind, ['collector', 'both'], true)) {
            return DB::table('parties')->where('kind', 'user')->where('user_id', $actor->id)->value('id');
        }
        if ($actor->role() === Role::Processor && in_array($org->kind, ['processor', 'both'], true)) {
            $point = DB::table('places')->where('id', $m->place_id)->where('organization_id', $m->organization_id)->where('kind', 'collection_point')->where('active', true)->where('review_status', 'approved')->first();

            return $point ? DB::table('parties')->where('place_id', $point->id)->value('id') : null;
        }

        return null;
    }

    public function live(object $h, string $side): bool
    {
        return (bool) DB::selectOne('SELECT handover_scope(h,?) AS ok FROM handovers h WHERE id=?', [$side, $h->id])?->ok;
    }

    public function access(User $actor, object $h): bool
    {
        return $actor->role() === Role::Administrator
            || ($actor->id === $h->sender_actor_id && $this->live($h, 'sender'))
            || ($actor->id === $h->receiver_actor_id && $this->live($h, 'receiver'));
    }

    public function compatible(object $h): bool
    {
        $place = DB::table('parties')->where('id', $h->receiver_party_id)->value('place_id');

        return ! DB::table('handover_items as hi')->join('items as i', 'i.subject_id', '=', 'hi.subject_id')->where('hi.handover_id', $h->id)->where('hi.state', 'pending')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('place_categories as pc')->where('pc.place_id', $place)->whereColumn('pc.category_id', 'i.category_id')->whereRaw("pc.subtype=COALESCE(i.subtype,'')"))->exists();
    }

    public function visible(User $actor)
    {
        $query = DB::table('handovers as h')->select('h.*');
        if ($actor->role() === Role::Administrator) {
            return $query;
        }
        if ($actor->role() === Role::Consumer) {
            return $query->whereExists(fn ($q) => $q->selectRaw('1')->from('handover_items as hi')->join('items as i', 'i.subject_id', '=', 'hi.subject_id')->whereColumn('hi.handover_id', 'h.id')->where('i.owner_user_id', $actor->id));
        }

        return $query->where(fn ($q) => $q->where(fn ($s) => $s->where('h.sender_actor_id', $actor->id)->whereRaw("handover_scope(h,'sender')"))->orWhere(fn ($s) => $s->where('h.receiver_actor_id', $actor->id)->whereRaw("handover_scope(h,'receiver')")));
    }

    public function rows(string $id, ?string $ownerId = null)
    {
        return DB::table('handover_items as hi')->join('subjects as s', 's.id', '=', 'hi.subject_id')->join('items as i', 'i.subject_id', '=', 'hi.subject_id')->join('categories as c', 'c.id', '=', 'i.category_id')->where('hi.handover_id', $id)->when($ownerId !== null, fn ($q) => $q->where('i.owner_user_id', $ownerId))->orderBy('hi.subject_id')->get(['hi.*', 's.public_code', 'i.model', 'i.subtype', 'i.owner_user_id', 'c.label as category_label']);
    }

    public function held(User $actor)
    {
        return DB::table('custody_current as cc')->join('items as i', 'i.subject_id', '=', 'cc.subject_id')->join('subjects as s', 's.id', '=', 'cc.subject_id')->join('categories as c', 'c.id', '=', 'i.category_id')->join('first_receipts as f', 'f.subject_id', '=', 'cc.subject_id')->where('cc.party_id', $this->party($actor) ?? '00000000-0000-0000-0000-000000000000')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('handover_items as hi')->whereColumn('hi.subject_id', 'cc.subject_id')->where('hi.active', true))->orderBy('s.public_code')->get(['cc.subject_id', 's.version', 's.public_code', 'i.model', 'i.subtype', 'c.label as category_label']);
    }

    public function recipients(User $actor)
    {
        return DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('places as p', 'p.id', '=', 'm.place_id')->join('organizations as o', 'o.id', '=', 'm.organization_id')->whereColumn('p.organization_id', 'o.id')->where('m.role', 'processor')->where('m.active', true)->where('u.status', 'active')->where('u.id', '<>', $actor->id)->whereIn('p.kind', $actor->role() === Role::Collector ? ['collection_point', 'facility'] : ['facility'])->where('p.active', true)->where('p.review_status', 'approved')->where('o.review_status', 'approved')->whereIn('o.kind', ['processor', 'both'])->orderBy('p.name')->orderBy('u.display_name')->get(['u.id', 'u.display_name', 'm.version', 'p.name', 'p.kind', 'p.version as destination_version', 'o.name as operator_name', 'o.version as operator_version']);
    }
}
