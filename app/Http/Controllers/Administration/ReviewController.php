<?php

namespace App\Http\Controllers\Administration;

use App\Actions\ReviewRequest;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Support\Collection\Review;
use App\Support\Collection\ReviewFeedback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    public function index()
    {
        $requests = DB::table('requests')->whereExists(fn ($q) => $q->selectRaw('1')->from('request_items')->whereColumn('request_id', 'requests.id')->where('active', true))->orderBy('created_at')->orderBy('id')->paginate(12);

        return response()->view('administration.review-index', compact('requests'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Review $review, string $collection)
    {
        $record = DB::table('requests')->where('id', $collection)->firstOrFail();
        $snapshot = $review->assess($record);
        $reviewState = $review->state($record);
        $reviewReasons = ReviewFeedback::reasons($snapshot['blockers']);
        $rows = DB::table('request_items')->where('request_id', $collection)->orderBy('item_id')->get();
        $items = Item::with('subject', 'category', 'observations')->whereIn('subject_id', $rows->pluck('item_id'))->get()->keyBy('subject_id');
        $private = DB::table('request_private_details')->where('id', $record->private_details_id)->firstOrFail();
        $contact = Crypt::decryptString($private->encrypted_contact);
        $address = $private->encrypted_address ? Crypt::decryptString($private->encrypted_address) : null;
        $history = DB::table('domain_events')->where('aggregate_kind', 'request')->where('aggregate_id', $collection)->orderByDesc('aggregate_version')->paginate(10);
        $reviews = DB::table('request_reviews')->where('request_id', $collection)->orderByDesc('created_at')->orderByDesc('id')->paginate(10, ['*'], 'reviews_page');
        $evidence = DB::table('evidence_links')->join('attachments', 'attachments.id', '=', 'evidence_links.attachment_id')->where('record_kind', 'request_review')->whereIn('record_id', $reviews->pluck('id'))->whereNull('redacted_at')->get(['attachments.id', 'attachments.mime', 'evidence_links.record_id'])->groupBy('record_id');

        return response()->view('administration.review-show', compact('record', 'snapshot', 'reviewState', 'reviewReasons', 'rows', 'items', 'private', 'contact', 'address', 'history', 'reviews', 'evidence'))->header('Cache-Control', 'private, no-store');
    }

    public function decide(Request $request, ReviewRequest $action, string $collection)
    {
        $action->decide($request->user(), $collection, $request->except('_token', 'evidence'), DirectoryController::upload($request));

        return redirect()->route('admin.reviews.show', $collection)->with('status', 'Review recorded. This is not an assignment, appointment or physical collection.')->with('status_type', 'success');
    }
}
