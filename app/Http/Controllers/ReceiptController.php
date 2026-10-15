<?php

namespace App\Http\Controllers;

use App\Actions\ManagePointStaff;
use App\Actions\RecordVisit;
use App\Enums\Role;
use App\Support\Assignments\Input;
use App\Support\Collection\Review;
use App\Support\Receipts\Context;
use App\Support\Routing\Execution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReceiptController extends Controller
{
    public function access()
    {
        $staff = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->leftJoin('places as p', 'p.id', '=', 'm.place_id')->where('m.role', 'processor')->orderBy('u.display_name')->get(['m.*', 'u.display_name', 'u.status', 'p.name as point_name']);
        $points = DB::table('places as p')->join('organizations as o', 'o.id', '=', 'p.organization_id')->whereIn('p.kind', ['collection_point', 'facility'])->where('p.active', true)->where('p.review_status', 'approved')->where('o.review_status', 'approved')->whereIn('o.kind', ['processor', 'both'])->orderBy('p.name')->get(['p.id', 'p.version', 'p.name', 'o.name as operator_name']);

        return $this->view('receipts.access', compact('staff', 'points'));
    }

    public function saveAccess(Request $request, ManagePointStaff $action, string $staff)
    {
        $action->save($request->user(), $staff, $request->except('_token'));

        return redirect()->route('admin.point-staff')->with('status', 'Place scope saved. Actual changes revoked affected sessions; sign in again before intake. Recheck Request review after any directory changes.');
    }

    public function inbox(Request $request)
    {
        $actor = $request->user()->load('membership');
        $p = DB::table('places')->where('id', $actor->membership->place_id)->where('organization_id', $actor->membership->organization_id)->where('active', true)->where('review_status', 'approved')->first();
        $org = $p ? DB::table('organizations')->where('id', $p->organization_id)->first() : null;
        $eligible = $p && $p->kind === 'collection_point' && $org?->review_status === 'approved' && in_array($org->kind, ['processor', 'both'], true);
        $requests = $eligible ? DB::table('requests')->where('mode', 'drop_off')->where('point_id', $p->id)->whereExists(fn ($q) => $q->selectRaw('1')->from('request_items')->whereColumn('request_id', 'requests.id')->where('active', true))->orderBy('created_at')->get(['id', 'public_code', 'review_state']) : collect();

        return $this->view('receipts.inbox', compact('p', 'requests', 'eligible'));
    }

    public function form(Request $request, Context $context, string $collection)
    {
        $actor = $request->user()->load('membership');
        $work = $context->current($actor, $collection);

        return $this->view('receipts.form', $work);
    }

    public function lookup(Request $request, Context $context, string $collection)
    {
        $fields = Input::validate($request->except('_token'), ['code' => ['required', 'string', 'regex:/\AEWC-[A-F0-9]{32}\z/']]);
        $work = $context->current($request->user()->load('membership'), $collection);
        $row = $work['rows']->firstWhere('public_code', $fields['code']);
        if (! $row) {
            DB::transaction(function () use ($request, $collection) {
                Review::lock();
                DB::table('receipt_discrepancies')->insert(['id' => Str::uuid()->toString(), 'request_id' => $collection, 'actor_id' => $request->user()->id, 'kind' => 'context_mismatch', 'recorded_at' => now()]);
            });

            return back()->withErrors(['code' => 'This label is not an outstanding item in this authorized visit. Recheck the label. No other device details are available.']);
        }

        return redirect()->route('receipts.form', $collection)->with('selected_item', $row->id)->with('status', 'Label selected. Compare category, subtype and model with the physical item. Selection alone records no receipt.')->with('status_type', 'info');
    }

    public function store(Request $request, RecordVisit $action, string $collection)
    {
        $file = $request->file('evidence');
        if (is_array($file)) {
            throw ValidationException::withMessages(['evidence' => 'Choose one private attachment, not multiple files.']);
        }
        $visit = $action->record($request->user(), $collection, $request->except('_token', 'evidence'), $file);

        return redirect()->route('receipts.result', $visit)->with('status', 'Visit recorded. Only explicitly acknowledged received items changed custody. The remainder requires fresh review.');
    }

    public function result(Request $request, Context $context, string $visit)
    {
        $record = DB::table('receipt_visits')->where('id', $visit)->firstOrFail();
        $r = DB::table('requests')->where('id', $record->request_id)->firstOrFail();
        $actor = $request->user()->load('membership');
        $private = false;
        if ($actor->role() === Role::Consumer) {
            abort_unless($r->consumer_id === $actor->id, 404);
        } elseif ($actor->role() === Role::Administrator) {/* Safe oversight only; no private evidence or observations. */
        } else {
            $context->authorized($actor, $r->id);
            abort_unless($record->actor_id === $actor->id, 404);
            $private = true;
        }
        $outcomes = DB::table('receipt_outcomes as o')->join('request_items as ri', 'ri.id', '=', 'o.request_item_id')->join('subjects as s', 's.id', '=', 'ri.item_id')->where('o.visit_id', $visit)->get(['s.public_code', 'o.outcome', ...($private ? ['o.observation_ciphertext'] : [])]);
        $reference = $private && $record->reference_ciphertext ? json_decode(Crypt::decryptString($record->reference_ciphertext), true) : null;

        return $this->view('receipts.result', compact('record', 'r', 'outcomes', 'private', 'reference'));
    }

    public function evidence(Request $request, Context $context, string $visit)
    {
        $v = DB::table('receipt_visits')->where('id', $visit)->where('actor_id', $request->user()->id)->firstOrFail();
        $context->authorized($request->user()->load('membership'), $v->request_id);
        $a = DB::table('attachments')->where('id', $v->attachment_id)->whereNull('redacted_at')->firstOrFail();
        abort_unless(Storage::disk('directory_evidence')->exists($a->storage_key), 404);

        return Storage::disk('directory_evidence')->download($a->storage_key, 'receipt-evidence.'.($a->mime === 'application/pdf' ? 'pdf' : 'png'), ['Content-Type' => $a->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }

    public function finish(Request $request, Execution $execution, string $route)
    {
        $fields = $request->except('_token');
        $fields['unfinished'] ??= [];
        $execution->finish($request->user(), $route, $fields);

        return redirect()->route('collector.routes.index')->with('status', 'Tour work finished and route claims released. Unfinished stops remain uncollected; reservations and accepted assignments remain for Administrator replanning. No depot arrival or delivery was recorded.');
    }

    public function notices(Request $request)
    {
        $notices = DB::table('receipt_notifications as n')->join('receipt_visits as v', 'v.id', '=', 'n.visit_id')->join('requests as r', 'r.id', '=', 'v.request_id')->where('n.recipient_id', $request->user()->id)->orderByDesc('v.recorded_at')->get(['n.id', 'n.read_at', 'v.id as visit_id', 'v.recorded_at', 'r.public_code']);

        return $this->view('receipts.notices', compact('notices'));
    }

    public function read(Request $request, string $notice)
    {
        DB::transaction(function () use ($request, $notice) {
            Review::lock();
            $row = DB::table('receipt_notifications')->where('id', $notice)->where('recipient_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            if (! $row->read_at) {
                DB::table('receipt_notifications')->where('id', $notice)->update(['read_at' => now()]);
            }
        });

        return redirect()->route('receipts.notices')->with('status', 'Notice marked read.');
    }

    private function view(string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
