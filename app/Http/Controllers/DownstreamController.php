<?php

namespace App\Http\Controllers;

use App\Actions\AuthorizeBatchMedia;
use App\Actions\ManageOutputProfiles;
use App\Actions\MoveBatchOutputs;
use App\Support\Downstream\Context;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DownstreamController
{
    public function index(Request $request, Context $ctx)
    {
        $party = app(\App\Support\Batches\Context::class)->party($request->user());
        $batches = DB::table('dismantling_batches as b')->join('subjects as s', 's.id', '=', 'b.subject_id')->where('b.facility_party_id', $party)->where('b.state', 'processing')->orderBy('s.public_code')->paginate(12, ['b.*', 's.public_code'], 'batches_page');
        $incoming = DB::table('downstream_shipments as h')->join('output_profile_records as p', 'p.id', '=', 'h.profile_record_id')->join('subjects as s', 's.id', '=', 'h.output_id')->where('h.receiver_actor_id', $request->user()->id)->where('h.receiver_scope_version', $request->user()->membership->version)->where('p.facility_party_id', $party)->orderBy('h.id')->paginate(12, ['h.*', 's.public_code'], 'incoming_page');

        return $this->view('downstream.index', compact('batches', 'incoming'));
    }

    public function show(Request $request, Context $ctx, string $batch)
    {
        $b = $ctx->source($request->user(), $batch);
        $outputs = DB::table('batch_outputs as o')->join('subjects as s', 's.id', '=', 'o.subject_id')->where('o.batch_id', $batch)->orderBy('s.public_code')->get(['o.*', 's.public_code']);
        $stocks = $outputs->mapWithKeys(fn ($o) => [$o->subject_id => $ctx->stock($o->subject_id)]);
        $shipments = DB::table('downstream_shipments as h')->join('subjects as s', 's.id', '=', 'h.output_id')->where('h.batch_id', $batch)->orderBy('h.id')->paginate(12, ['h.*', 's.public_code']);
        $history = DB::table('downstream_records')->where('batch_id', $batch)->orderByDesc('version')->paginate(12, ['*'], 'history_page');
        $ready = DB::selectOne("SELECT downstream_ready(?,?) AS ready,downstream_closable(?) AS closable,downstream_blocked(?) AS blocked,downstream_closed(?) AS closed, EXISTS(SELECT 1 FROM downstream_records WHERE batch_id=? AND kind='dispatch') AS dispatched", [$batch, $request->user()->id, $batch, $batch, $batch, $batch]);
        $profiles = $ctx->profiles()->where('r.approved', true)->where('r.facility_party_id', '<>', $b->facility_party_id)->where('l.active', true)->where('l.review_status', 'approved')->where('o.review_status', 'approved')->whereIn('o.kind', ['processor', 'both'])->get();
        $sourcePlace = DB::table('parties')->where('id', $b->facility_party_id)->value('place_id');
        $recipients = $ctx->recipients()->where('facility_id', '<>', $sourcePlace)->values();
        $mode = $this->mode($request, ['draft', 'source_loss', 'measurement_dispute', 'close']);
        $version = $ctx->version($batch);
        $token = $ctx->token($batch, $request->user());
        $fieldIds = $this->fields();

        return $this->view('downstream.batch', compact('b', 'outputs', 'stocks', 'shipments', 'history', 'ready', 'profiles', 'recipients', 'mode', 'version', 'token', 'fieldIds'));
    }

    public function shipment(Request $request, Context $ctx, string $shipment)
    {
        $h = $ctx->shipment($request->user(), $shipment);
        $b = DB::table('dismantling_batches')->where('subject_id', $h->batch_id)->firstOrFail();
        $source = app(\App\Support\Batches\Context::class)->party($request->user()) === $b->facility_party_id;
        $output = DB::table('batch_outputs as o')->join('subjects as s', 's.id', '=', 'o.subject_id')->where('o.subject_id', $h->output_id)->firstOrFail(['o.*', 's.public_code']);
        $profile = DB::table('output_profile_records as p')->join('parties as a', 'a.id', '=', 'p.facility_party_id')->join('places as l', 'l.id', '=', 'a.place_id')->where('p.id', $h->profile_record_id)->first(['p.*', 'l.name']);
        $profile->display_name = DB::table('users')->where('id', $h->receiver_actor_id)->value('display_name');
        $records = DB::table('downstream_records')->where('shipment_id', $h->id)->orderByDesc('version')->get();
        $quantities = json_decode(DB::selectOne('SELECT downstream_shipment_quantities(?) AS data', [$h->id])->data, true, flags: JSON_THROW_ON_ERROR);
        foreach ($records as $r) {
            $r->remaining_kg = in_array($r->kind, ['dispatch', 'accept'], true) ? DB::selectOne('SELECT downstream_remaining(?) AS kg', [$r->id])->kg : null;
        }
        $reserved = DB::selectOne("SELECT downstream_reserved(?) AS kg,(downstream_profile_ready(?) AND batch_balance(?)->>'status'='reconciled_on_site') AS eligible,(downstream_preparation_ready(?) AND downstream_ready(?,?)) AS preparation_eligible", [$h->id, $h->id, $h->batch_id, $h->id, $h->batch_id, $request->user()->id]);
        $mode = $this->mode($request, $source ? ['reserve', 'withdraw', 'dispatch'] : ['accept', 'refuse', 'shortage', 'dispute', 'complete', 'downstream_loss', 'measurement_dispute', 'authorize']);
        $version = $ctx->version($h->batch_id);
        $token = $ctx->token($h->batch_id, $request->user(), $h->id);
        $fieldIds = $this->fields();
        $authorizationToken = ! $source && $output->type === 'storage' && $records->contains('kind', 'accept') ? app(AuthorizeBatchMedia::class)->token($request->user(), $h->batch_id, $h->output_id) : null;
        $authorizationVersion = $authorizationToken ? (int) DB::table('media_authorization_current')->where('subject_id', $h->output_id)->value('version') : 0;

        return $this->view('downstream.shipment', compact('h', 'b', 'source', 'output', 'profile', 'records', 'reserved', 'mode', 'version', 'token', 'fieldIds', 'authorizationToken', 'authorizationVersion', 'quantities'));
    }

    public function store(Request $request, MoveBatchOutputs $action, string $batch, string $kind)
    {
        $request->validate(['evidence' => ['nullable', 'file']]);
        $id = $action->record($request->user(), $batch, $kind, $request->except(['_token', 'evidence']), $request->file('evidence'));
        $r = DB::table('downstream_records')->where('id', $id)->firstOrFail();

        return ($r->shipment_id ? redirect()->route('downstream.shipment', $r->shipment_id) : redirect()->route('downstream.show', $batch))->with('status', Context::LABELS[$kind].' recorded. Review quantities and pending obligations; this is an accountable assertion.');
    }

    public function authorize(Request $request, AuthorizeBatchMedia $action, string $batch, string $medium)
    {
        $request->validate(['evidence' => ['nullable', 'file']]);
        $action->record($request->user(), $batch, $medium, $request->except(['_token', 'evidence']), $request->file('evidence'));

        return redirect()->route('sanitization.show', $medium)->with('status', 'Received-drive context authorized. Explicit applicability review is required; no new treatment success was inferred.');
    }

    public function profiles(Request $request, Context $ctx, ManageOutputProfiles $action, ?string $profile = null)
    {
        $profiles = $ctx->profiles()->paginate(12);
        $id = $profile ?? Str::uuid()->toString();
        $current = $profile ? $ctx->profiles()->where('p.id', $profile)->firstOrFail() : null;
        $facilities = DB::table('places')->where('kind', 'facility')->orderBy('name')->get(['id', 'name']);
        $token = $action->token($id);
        $fieldIds = $this->fields();

        return $this->view('downstream.profiles', compact('profiles', 'id', 'current', 'facilities', 'token', 'fieldIds'));
    }

    public function profileStore(Request $request, ManageOutputProfiles $action, string $profile)
    {
        $request->validate(['evidence' => ['nullable', 'file']]);
        $action->record($request->user(), $profile, $request->except(['_token', 'evidence']), $request->file('evidence'));

        return redirect()->route('admin.output-profiles.edit', $profile)->with('status', 'Output acceptance review recorded. Earlier shipment reviews may be stale; prepare a fresh shipment rather than replaying a completed command.');
    }

    public function evidence(Request $request, Context $ctx, string $record)
    {
        $r = DB::table('downstream_records')->where('id', $record)->firstOrFail();
        $r->shipment_id ? $ctx->shipment($request->user(), $r->shipment_id) : $ctx->source($request->user(), $r->batch_id);
        $file = DB::table('attachments')->where('id', $r->attachment_id)->where('privacy_class', 'private')->firstOrFail();
        abort_unless(Storage::disk('directory_evidence')->exists($file->storage_key), 404);

        return Storage::disk('directory_evidence')->download($file->storage_key, 'private-movement-evidence.'.($file->mime === 'application/pdf' ? 'pdf' : ($file->mime === 'image/png' ? 'png' : 'jpg')), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function mode(Request $request, array $allowed): ?string
    {
        $mode = $request->query('action');
        abort_unless($mode === null || (is_string($mode) && in_array($mode, $allowed, true)), 404);

        return $mode;
    }

    private function fields(): array
    {
        $keys = ['output_id', 'profile_record_id', 'receiver_actor_id', 'kg', 'hazards', 'related_record_id', 'condition', 'identity_code', 'outcome', 'issuer', 'attested', 'facility_id', 'output_type', 'material_key', 'approved', 'observed_at', 'reason', 'evidence', 'evidence_kind', 'reference_issuer', 'reference_code', 'reference_attested'];

        return array_combine($keys, $keys);
    }

    private function view(string $name, array $data)
    {
        return response()->view($name, $data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
