<?php

namespace App\Http\Controllers\Assignments;

use App\Actions\AllocateCollector;
use App\Http\Controllers\Controller;
use App\Support\Assignments\AllocationGuide;
use App\Support\Assignments\Eligibility;
use App\Support\Collection\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AllocationController extends Controller
{
    public function index()
    {
        $requests = DB::table('requests')->where('mode', 'pickup')->whereExists(fn ($q) => $q->selectRaw('1')->from('request_items')->whereColumn('request_id', 'requests.id')->where('active', true))->orderBy('created_at')->orderBy('id')->paginate(12);

        return response()->view('assignments.queue', compact('requests'))->header('Cache-Control', 'private, no-store');
    }

    public function show(string $collection)
    {
        $record = DB::table('requests')->where('id', $collection)->where('mode', 'pickup')->firstOrFail();
        $reviewState = app(Review::class)->state($record);
        $active = DB::table('assignments')->where('request_id', $collection)->where('active', true)->first();
        $current = $active && app(Eligibility::class)->current($active);
        $guide = app(AllocationGuide::class)->inspect($record);
        $memberOptions = $guide['memberOptions'];
        $placeOptions = $guide['placeOptions'];
        $history = DB::table('assignments as a')->join('domain_events as e', 'e.aggregate_id', '=', 'a.id')->leftJoin('assignment_revisions as v', 'v.event_id', '=', 'e.id')->where('a.request_id', $collection)->where('e.aggregate_kind', 'assignment')->orderByDesc('e.recorded_at')->orderByDesc('e.id')->paginate(12, ['e.type', 'e.recorded_at', 'e.aggregate_version', 'a.id', 'v.reason_ciphertext']);

        return response()->view('assignments.allocate', compact('record', 'reviewState', 'active', 'current', 'memberOptions', 'placeOptions', 'history', 'guide'))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, AllocateCollector $action, string $collection)
    {
        $action->allocate($request->user(), $collection, $request->except('_token'));

        return redirect()->route('admin.assignments.show', $collection)->with('status', 'Assignment action recorded. Reservations and custody are unchanged. No appointment or dispatch is confirmed.');
    }

    public function work(Request $request)
    {
        $member = $request->user()->membership;
        $operator = DB::table('organizations')->where('id', $member->organization_id)->first();
        abort_unless($operator?->review_status === 'approved' && in_array($operator->kind, ['collector', 'both'], true), 403, 'Current reviewed operator access is required.');
        $assignments = DB::table('assignments')->where('collector_id', $request->user()->id)->where('membership_id', $member->id)->where('operator_id', $member->organization_id)->orderByDesc('created_at')->orderByDesc('id')->paginate(12);

        return response()->view('assignments.work', compact('assignments'))->header('Cache-Control', 'private, no-store');
    }

    public function job(Request $request, string $assignment)
    {
        $member = $request->user()->membership;
        $operator = DB::table('organizations')->where('id', $member->organization_id)->first();
        abort_unless($operator?->review_status === 'approved' && in_array($operator->kind, ['collector', 'both'], true), 403, 'Current reviewed operator access is required.');
        $record = DB::table('assignments')->where('id', $assignment)->where('collector_id', $request->user()->id)->where('membership_id', $member->id)->where('operator_id', $member->organization_id)->firstOrFail();
        $org = DB::table('organizations')->where('id', $record->operator_id)->first();
        $current = $org?->review_status === 'approved' && in_array($org->kind, ['collector', 'both'], true) && app(Eligibility::class)->current($record);
        $collection = DB::table('requests')->where('id', $record->request_id)->firstOrFail();
        $facility = DB::table('places')->where('id', $record->facility_id)->firstOrFail();
        $rows = collect();
        $contact = $address = $latitude = $longitude = null;
        if ($current) {
            $rows = DB::table('request_items as r')->join('items as i', 'i.subject_id', '=', 'r.item_id')->join('subjects as s', 's.id', '=', 'i.subject_id')->join('categories as c', 'c.id', '=', 'i.category_id')->where('r.request_id', $collection->id)->where('r.active', true)->orderBy('r.item_id')->get(['s.public_code', 'c.label', 'i.subtype', 'i.data_status', 'r.estimated_kg', 'r.preferred_date', 'r.review_reasons']);
            if ($record->state === 'accepted') {
                $private = DB::table('request_private_details')->where('id', $collection->private_details_id)->firstOrFail();
                $contact = Crypt::decryptString($private->encrypted_contact);
                $address = Crypt::decryptString($private->encrypted_address);
                $latitude = $private->latitude;
                $longitude = $private->longitude;
            }
        }
        $area = DB::table('service_areas')->where('id', $collection->service_area_id)->first();

        return response()->view('assignments.job', compact('record', 'collection', 'facility', 'rows', 'current', 'contact', 'address', 'latitude', 'longitude', 'area'))->header('Cache-Control', 'private, no-store');
    }

    public function respond(Request $request, AllocateCollector $action, string $assignment)
    {
        $action->respond($request->user(), $assignment, $request->except('_token'));

        return redirect()->route('collector.assignments.show', $assignment)->with('status', 'Your response was recorded. Acceptance is not scheduling or collection.');
    }
}
