<?php

namespace App\Http\Controllers;

use App\Actions\CustodyHandovers;
use App\Enums\Role;
use App\Support\Assignments\Input;
use App\Support\Collection\Review;
use App\Support\Handovers\Context;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class HandoverController extends Controller
{
    public function index(Request $request, Context $ctx)
    {
        $actor = $request->user()->load('membership');
        $held = $ctx->held($actor);
        $canInitiate = $held->isNotEmpty() && $ctx->party($actor) !== null;
        $recipients = $canInitiate ? $ctx->recipients($actor) : collect();
        $handovers = $ctx->visible($actor)->orderByDesc('h.created_at')->get();

        return $this->view('handovers.index', compact('held', 'recipients', 'handovers', 'canInitiate'));
    }

    public function create(Request $request, Context $ctx, string $recipient)
    {
        $actor = $request->user()->load('membership');
        abort_unless($ctx->party($actor), 403, 'An active collecting scope and confirmed custody are required.');
        $receiver = $ctx->recipients($actor)->firstWhere('id', $recipient);
        abort_unless($receiver, 404);
        $held = $ctx->held($actor);
        $m = DB::table('memberships')->where('user_id', $recipient)->firstOrFail();
        $capabilities = DB::table('place_categories as pc')->join('categories as c', 'c.id', '=', 'pc.category_id')->where('pc.place_id', $m->place_id)->get(['c.label', 'pc.subtype']);

        return $this->view('handovers.create', compact('receiver', 'held', 'capabilities'));
    }

    public function store(Request $request, CustodyHandovers $action)
    {
        $id = $action->initiate($request->user(), $request->except('_token', 'evidence'), $this->file($request));

        return redirect()->route('handovers.show', $id)->with('status', 'Handover initiated. Custody remains with the sender until individual recipient acknowledgment.');
    }

    public function show(Request $request, Context $ctx, string $handover)
    {
        $actor = $request->user()->load('membership');
        $h = $ctx->visible($actor)->where('h.id', $handover)->firstOrFail();
        $private = $ctx->access($actor, $h);
        $rows = $ctx->rows($h->id, $actor->role() === Role::Consumer ? $actor->id : null);
        if (! $private) {
            abort_unless($actor->role() === Role::Consumer, 404);
            $rows = $rows->where('owner_user_id', $actor->id);
            abort_unless($rows->isNotEmpty(), 404);
        }
        $operations = DB::table('handover_operations')->where('handover_id', $h->id)->orderBy('version')->get();
        $outcomes = DB::table('handover_outcomes')->whereIn('operation_id', $operations->pluck('id'))->whereIn('handover_item_id', $rows->pluck('id'))->get();
        if (! $private) {
            $operations = $operations->whereIn('id', $outcomes->pluck('operation_id'));
        }
        $canAck = $private && $actor->id === $h->receiver_actor_id && $ctx->live($h, 'receiver') && $ctx->live($h, 'sender') && $ctx->compatible($h);
        $canWithdraw = $private && ($actor->role() === Role::Administrator || ($actor->id === $h->sender_actor_id && $ctx->live($h, 'sender')));
        $canResolve = $actor->role() === Role::Administrator;
        $acceptedTypes = DB::table('handover_receipts as a')->join('domain_events as e', 'e.id', '=', 'a.event_id')->whereIn('a.handover_item_id', $rows->pluck('id'))->get(['a.handover_item_id', 'e.safe_payload'])->mapWithKeys(fn ($r) => [$r->handover_item_id => json_decode($r->safe_payload, true)['destination_kind'] ?? null]);
        $destination = DB::table('parties as p')->join('places as l', 'l.id', '=', 'p.place_id')->where('p.id', $h->receiver_party_id)->first(['l.name', 'l.kind']);

        return $this->view('handovers.show', compact('h', 'rows', 'private', 'operations', 'outcomes', 'canAck', 'canWithdraw', 'canResolve', 'destination', 'acceptedTypes'));
    }

    public function change(Request $request, CustodyHandovers $action, string $handover, string $kind)
    {
        $id = $action->change($request->user(), $handover, $kind, $request->except('_token', 'evidence'), $this->file($request));

        return redirect()->route('handovers.show', $id)->with('status', 'Handover outcomes recorded. Only actual recipient acceptance changes custody; disputed physical whereabouts remain unresolved unless explicitly reviewed.');
    }

    public function lookup(Request $request, Context $ctx, string $handover)
    {
        $f = Input::validate($request->except('_token'), ['code' => ['required', 'string', 'regex:/\AEWC-[A-F0-9]{32}\z/']]);
        $h = DB::table('handovers')->where('id', $handover)->firstOrFail();
        abort_unless($ctx->access($request->user()->load('membership'), $h), 404);
        $row = $ctx->rows($h->id)->firstWhere('public_code', $f['code']);
        if (! $row) {
            return redirect()->route('handovers.show', $h->id)->withErrors(['code' => 'This label does not match this authorized manifest. Recheck the physical label; no other record is disclosed.']);
        }

        return redirect()->route('handovers.show', $h->id)->with('selected_item', $row->id)->with('status', 'Label selected. Compare the physical item before choosing an outcome; scanning records no acknowledgment.')->with('status_type', 'info');
    }

    public function evidence(Request $request, Context $ctx, string $operation)
    {
        $op = DB::table('handover_operations')->where('id', $operation)->firstOrFail();
        $h = DB::table('handovers')->where('id', $op->handover_id)->firstOrFail();
        abort_unless($ctx->access($request->user()->load('membership'), $h), 404);
        $a = DB::table('attachments')->where('id', $op->attachment_id)->whereNull('redacted_at')->firstOrFail();
        abort_unless(Storage::disk('directory_evidence')->exists($a->storage_key), 404);

        return Storage::disk('directory_evidence')->download($a->storage_key, 'handover-evidence.'.($a->mime === 'application/pdf' ? 'pdf' : 'png'), ['Content-Type' => $a->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }

    public function notices(Request $request)
    {
        $notices = DB::table('handover_notifications as n')->join('handover_operations as o', 'o.id', '=', 'n.operation_id')->where('n.recipient_id', $request->user()->id)->orderByDesc('o.recorded_at')->get(['n.id', 'n.read_at', 'o.handover_id', 'o.kind', 'o.recorded_at']);

        return $this->view('handovers.notices', compact('notices'));
    }

    public function read(Request $request, string $notice)
    {
        DB::transaction(function () use ($request, $notice) {
            Review::lock();
            $row = DB::table('handover_notifications')->where('id', $notice)->where('recipient_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            if (! $row->read_at) {
                DB::table('handover_notifications')->where('id', $row->id)->update(['read_at' => now()]);
            }
        });

        return redirect()->route('handovers.notices')->with('status', 'Notice marked read.');
    }

    private function file(Request $request)
    {
        $file = $request->file('evidence');
        if (is_array($file)) {
            throw ValidationException::withMessages(['evidence' => 'Choose one private attachment.']);
        }

        return $file;
    }

    private function view(string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
