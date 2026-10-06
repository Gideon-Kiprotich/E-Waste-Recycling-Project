<?php

namespace App\Support\Collection;

use App\Enums\Role;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Review
{
    public const LABELS = ['pending' => 'Awaiting review', 'ready' => 'Reviewed for later allocation', 'hold' => 'On hold — review required', 'stale' => 'Changed — fresh review required'];

    public static function lock(): void
    {
        // One transaction gate prevents directory/request/observation lock inversions in this bounded workflow.
        DB::select('SELECT pg_advisory_xact_lock(481204)');
    }

    public function assess(object $request): array
    {
        $rows = DB::table('request_items')->where('request_id', $request->id)->where('active', true)->orderBy('item_id')->get();
        $items = Item::with('category', 'subject')->whereIn('subject_id', $rows->pluck('item_id'))->orderBy('subject_id')->get();
        $blockers = [];
        $observations = [];
        if ($rows->isEmpty()) {
            $blockers[] = 'no_outstanding_items';
        }
        if ($items->count() !== $rows->count()) {
            $blockers[] = 'item_unavailable';
        }
        foreach ($items as $item) {
            if ($item->owner_user_id !== $request->consumer_id) {
                $blockers[] = 'ownership_changed';
            }
            try {
                $snapshot = app(Eligibility::class)->inspect($item, false);
                $observations[$item->subject_id] = $snapshot;
                $blockers = array_merge($blockers, $snapshot['review_reasons']);
            } catch (ValidationException) {
                $blockers[] = 'item_ineligible';
            }
        }
        $owner = User::with('membership')->find($request->consumer_id);
        if (! $owner?->canSignIn() || $owner->role() !== Role::Consumer) {
            $blockers[] = 'owner_unavailable';
        }
        if ($rows->contains(fn ($row) => $row->preferred_date < now('Africa/Nairobi')->toDateString())) {
            $blockers[] = 'date_preference_elapsed';
        }
        $private = DB::table('request_private_details')->where('id', $request->private_details_id)->first();
        try {
            if ($request->mode === 'drop_off') {
                $point = app(Directory::class)->point($request->point_id, $items);
                $lat = (float) $point->latitude;
                $lon = (float) $point->longitude;
            } else {
                $lat = (float) $private->latitude;
                $lon = (float) $private->longitude;
            }
            app(Directory::class)->area($request->service_area_id, $lat, $lon);
        } catch (ValidationException) {
            $blockers[] = 'location_or_compatibility_changed';
        }
        $operators = DB::table('organizations')->where('review_status', 'approved')->orderBy('id')->get();
        $places = DB::table('places')->where('active', true)->where('review_status', 'approved')->whereIn('organization_id', $operators->pluck('id'))->orderBy('id')->get();
        if ($request->mode === 'pickup') {
            if (! $operators->contains(fn ($org) => in_array($org->kind, ['collector', 'both'], true) && $places->contains(fn ($place) => $place->organization_id === $org->id && app(Directory::class)->compatible($place, $items)))) {
                $blockers[] = 'no_reviewed_collector';
            }
            if (! $places->contains(fn ($place) => in_array($place->kind, ['facility', 'collection_point'], true) && in_array($operators->firstWhere('id', $place->organization_id)->kind, ['processor', 'both'], true) && app(Directory::class)->compatible($place, $items))) {
                $blockers[] = 'no_compatible_receiving_place';
            }
        }
        $area = DB::table('service_areas')->where('id', $request->service_area_id)->first();

        return ['rule_version' => Eligibility::VERSION, 'blockers' => array_values(array_unique($blockers)), 'observations' => $observations,
            'directory' => $operators->map(fn ($org) => ['id' => $org->id, 'version' => $org->version])->all(),
            'places' => $places->map(fn ($place) => ['id' => $place->id, 'version' => $place->version, 'capabilities' => DB::table('place_categories')->where('place_id', $place->id)->orderBy('category_id')->orderBy('subtype')->get(['category_id', 'subtype'])->map(fn ($capability) => (array) $capability)->all()])->all(),
            'area' => $area ? ['id' => $area->id, 'version_code' => $area->version_code, 'active' => $area->active] : null];
    }

    public function state(object $request): string
    {
        if (! in_array($request->review_state, ['ready', 'hold'], true)) {
            return $request->review_state;
        }
        $review = DB::table('request_reviews')->where('id', $request->review_id)->first();

        return $review && json_decode($review->snapshot, true) == $this->assess($request) ? $request->review_state : 'stale';
    }
}
