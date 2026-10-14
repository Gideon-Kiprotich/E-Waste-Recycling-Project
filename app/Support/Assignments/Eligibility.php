<?php

namespace App\Support\Assignments;

use App\Models\Item;
use App\Support\Collection\Directory;
use App\Support\Collection\Review;
use App\Support\Collection\ReviewFeedback;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Eligibility
{
    public function snapshot(object $request, string $membershipId, string $facilityId, bool $lock = false): array
    {
        $query = DB::table('memberships')->where('id', $membershipId);
        $member = ($lock ? $query->lockForUpdate() : $query)->first();
        $userQuery = DB::table('users')->where('id', $member?->user_id);
        $user = ($lock ? $userQuery->lockForUpdate() : $userQuery)->first();
        $orgQuery = DB::table('organizations')->where('id', $member?->organization_id);
        $org = ($lock ? $orgQuery->lockForUpdate() : $orgQuery)->first();
        if (! $member || $member->role !== 'collector' || ! $member->active || $user?->status !== 'active' || $org?->review_status !== 'approved' || ! in_array($org->kind, ['collector', 'both'], true)) {
            throw ValidationException::withMessages(['membership_ref' => 'Choose an active Collector linked to a reviewed collector operator.']);
        }
        $review = app(Review::class);
        if ($request->mode !== 'pickup') {
            throw ValidationException::withMessages(['eligibility' => 'Only pickup requests enter allocation. Drop-off keeps its existing point workflow.']);
        }
        $blockers = $review->assess($request)['blockers'];
        if ($blockers) {
            throw ValidationException::withMessages(['eligibility' => ReviewFeedback::readyError($blockers)]);
        }
        if ($review->state($request) !== 'ready') {
            throw ValidationException::withMessages(['eligibility' => 'A current Ready review is required. Finish directory and Collector access setup, then open Request review and record Ready again. Setup changes can invalidate an earlier review.']);
        }
        $rowsQuery = DB::table('request_items')->where('request_id', $request->id)->where('active', true)->orderBy('item_id');
        $rows = ($lock ? $rowsQuery->lockForUpdate() : $rowsQuery)->get();
        $items = Item::with('category', 'subject', 'observations')->whereIn('subject_id', $rows->pluck('item_id'))->orderBy('subject_id')->get();
        if ($rows->isEmpty() || $items->count() !== $rows->count() || $rows->contains(fn ($r) => $r->outcome !== 'outstanding') || $items->contains(function ($item) use ($rows) {
            $row = $rows->firstWhere('item_id', $item->subject_id);
            $observation = $item->observations->sortByDesc('revision')->first();

            return (int) $row->current_subject_version !== (int) $item->subject->version || $row->current_observation_id !== $observation?->id;
        })) {
            throw ValidationException::withMessages(['eligibility' => 'Outstanding reservations or observations have changed. Fresh review is required.']);
        }
        $placesQuery = DB::table('places')->where('organization_id', $org->id)->where('active', true)->where('review_status', 'approved')->orderBy('id');
        $places = ($lock ? $placesQuery->lockForUpdate() : $placesQuery)->get();
        if (! $places->contains(fn ($p) => app(Directory::class)->compatible($p, $items))) {
            throw ValidationException::withMessages(['membership_ref' => 'This operator does not explicitly support every outstanding category and subtype.']);
        }
        $placeQuery = DB::table('places')->where('id', $facilityId);
        $place = ($lock ? $placeQuery->lockForUpdate() : $placeQuery)->first();
        $receivingQuery = DB::table('organizations')->where('id', $place?->organization_id);
        $receiving = ($lock ? $receivingQuery->lockForUpdate() : $receivingQuery)->first();
        if (! $place || ! $place->active || $place->review_status !== 'approved' || ! in_array($place->kind, ['facility', 'collection_point'], true) || $receiving?->review_status !== 'approved' || ! in_array($receiving->kind, ['processor', 'both'], true) || ! app(Directory::class)->compatible($place, $items)) {
            throw ValidationException::withMessages(['facility_ref' => 'Choose a reviewed receiving facility or collection point that explicitly accepts every outstanding subtype. Depots are excluded.']);
        }

        return ['review_id' => $request->review_id, 'request_version' => (int) $request->version, 'review_snapshot' => $review->assess($request), 'membership' => ['id' => $member->id, 'version' => (int) $member->version, 'user_id' => $member->user_id, 'organization_id' => $org->id], 'operator_version' => (int) $org->version, 'facility' => ['id' => $place->id, 'version' => (int) $place->version, 'organization_id' => $receiving->id, 'organization_version' => (int) $receiving->version]];
    }

    public function current(object $assignment): bool
    {
        if (! $assignment->active) {
            return false;
        }
        $request = DB::table('requests')->where('id', $assignment->request_id)->first();
        if (! $request) {
            return false;
        }
        try {
            return $this->snapshot($request, $assignment->membership_id, $assignment->facility_id) == json_decode($assignment->eligibility_snapshot, true, flags: JSON_THROW_ON_ERROR);
        } catch (ValidationException) {
            return false;
        }
    }
}
