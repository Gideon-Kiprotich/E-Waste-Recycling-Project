<?php

namespace App\Http\Controllers;

use App\Actions\AuthorizeBatchMedia;
use App\Actions\DismantleBatches;
use App\Enums\Role;
use App\Support\Batches\Context;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BatchController extends Controller
{
    public function index(Request $request, Context $ctx)
    {
        $party = $ctx->party($request->user());
        $rows = DB::table('dismantling_batches as b')->join('subjects as s', 's.id', '=', 'b.subject_id')->where('b.facility_party_id', $party)->orderBy('s.created_at')->get(['b.*', 's.public_code']);

        return $this->view('batches.index', compact('rows'));
    }

    public function create(Request $request, Context $ctx)
    {
        $ctx->party($request->user());
        $id = Str::uuid()->toString();
        $rows = app(\App\Support\Assessments\Context::class)->rows($request->user())->get();
        $version = 0;
        $token = $ctx->token($request->user(), $id);
        $fieldIds = $this->fields();

        return $this->view('batches.create', compact('id', 'rows', 'version', 'token', 'fieldIds', 'ctx'));
    }

    public function show(Request $request, Context $ctx, string $batch)
    {
        $b = $ctx->batch($request->user(), $batch);
        $private = $request->user()->role() === Role::Processor;
        $balance = $ctx->balance($batch);
        $members = DB::table('batch_members as m')->join('items as i', 'i.subject_id', '=', 'm.subject_id')->join('subjects as s', 's.id', '=', 'm.subject_id')->where('m.batch_id', $batch);
        if (! $private) {
            $members->where('i.owner_user_id', $request->user()->id);
            $balance = array_intersect_key($balance, array_flip(['status', 'recycling_complete']));
        }
        $members = $members->get(['m.subject_id', 's.public_code']);
        $inputs = $private ? $ctx->inputs($b) : [];
        $selected = $private ? DB::table('subjects')->whereIn('id', $inputs)->orderBy('id')->get() : collect();
        $media = $private ? DB::table('media_inventory_entries as e')->join('subjects as s', 's.id', '=', 'e.medium_id')->whereIn('e.parent_id', $inputs)->orderBy('e.medium_id')->get(['e.*', 's.public_code']) : collect();
        $outputs = $private ? DB::table('batch_outputs as o')->join('subjects as s', 's.id', '=', 'o.subject_id')->leftJoin('batch_records as r', 'r.id', '=', 'o.measurement_record_id')->where('o.batch_id', $batch)->orderBy('s.public_code')->get(['o.*', 's.public_code', 'r.net_kg', 'r.gross_kg', 'r.tare_kg']) : collect();
        $history = $private ? DB::table('batch_records')->where('batch_id', $batch)->orderByDesc('version')->get() : collect();
        $actions = $private ? ($b->state === 'draft' ? ['draft' => Context::LABELS['draft'], 'commit' => Context::LABELS['commit']] : array_diff_key(Context::LABELS, array_flip(['draft', 'commit']))) : [];
        $downstreamClosed = Schema::hasTable('downstream_records') && DB::table('downstream_records')->where('batch_id', $batch)->where('kind', 'close')->exists();
        if ($downstreamClosed) {
            $actions = array_intersect_key($actions, ['discrepancy' => true]);
        }
        $requested = $request->query('action');
        $mode = is_string($requested) && isset($actions[$requested]) ? $requested : null;
        $target = null;
        $measurement = null;
        $authorization = null;
        if ($mode === 'measurement') {
            $candidate = $request->query('target');
            abort_unless($candidate === null || (is_string($candidate) && ($candidate === $batch || $outputs->contains('subject_id', $candidate))), 404);
            $target = $candidate ?? $batch;
            $measurement = DB::table('batch_records')->where('id', $target === $batch ? $b->input_measurement_id : $outputs->firstWhere('subject_id', $target)->measurement_record_id)->first();
        }
        if ($private && ! $downstreamClosed && $request->query('action') === 'authorize') {
            $candidate = $request->query('medium');
            abort_unless(is_string($candidate) && $outputs->contains(fn ($o) => $o->subject_id === $candidate && $o->type === 'storage'), 404);
            $mode = 'authorize';
            $target = $candidate;
            $authorization = DB::table('media_authorization_current')->where('subject_id', $target)->first();
        }
        $version = $mode === 'authorize' ? ($authorization?->version ?? 0) : $b->version;
        $token = $private ? ($mode === 'authorize' ? app(AuthorizeBatchMedia::class)->token($request->user(), $batch, $target) : $ctx->token($request->user(), $batch)) : null;
        $fieldIds = $this->fields();
        $blockers = $private && $b->state === 'draft' ? collect($inputs)->mapWithKeys(fn ($s) => [$s => $ctx->blockers($request->user(), $s)]) : collect();

        return $this->view('batches.show', compact('b', 'private', 'balance', 'members', 'selected', 'media', 'outputs', 'history', 'actions', 'mode', 'target', 'measurement', 'version', 'token', 'fieldIds', 'blockers', 'downstreamClosed'));
    }

    public function store(Request $request, DismantleBatches $action, string $batch, string $kind)
    {
        $request->validate(['evidence' => ['nullable', 'file']]);
        $id = $action->record($request->user(), $batch, $kind, $request->except(['_token', 'evidence']), $request->file('evidence'));
        $batch = DB::table('batch_records')->where('id', $id)->value('batch_id');

        return redirect()->route('batches.show', $batch)->with('status', Context::LABELS[$kind].' recorded. Review current accounting; no dispatch or completed recycling was recorded.');
    }

    public function authorize(Request $request, AuthorizeBatchMedia $action, string $batch, string $medium)
    {
        $request->validate(['evidence' => ['nullable', 'file']]);
        $action->record($request->user(), $batch, $medium, $request->except(['_token', 'evidence']), $request->file('evidence'));

        return redirect()->route('sanitization.show', $medium)->with('status', 'Batch-controlled treatment authorized. Review current applicability; no new physical treatment or successful sanitization was inferred.');
    }

    public function lookup(Request $request, Context $ctx)
    {
        $fields = $request->validate(['code' => ['required', 'string', 'regex:/\AEWC-[A-F0-9]{32}\z/']]);
        $subject = DB::table('subjects')->where('public_code', $fields['code'])->value('id');
        $batch = DB::table('dismantling_batches')->where('subject_id', $subject)->value('subject_id') ?? DB::table('batch_outputs')->where('subject_id', $subject)->value('batch_id');
        abort_unless($batch, 404);
        $ctx->batch($request->user(), $batch);

        return redirect()->route('batches.show', $batch);
    }

    public function label(Request $request, Context $ctx, string $subject)
    {
        $batch = DB::table('dismantling_batches')->where('subject_id', $subject)->value('subject_id') ?? DB::table('batch_outputs')->where('subject_id', $subject)->value('batch_id');
        abort_unless($batch, 404);
        $ctx->batch($request->user(), $batch);
        $row = DB::table('subjects')->where('id', $subject)->firstOrFail();
        if ($request->boolean('qr')) {
            $result = (new PngWriter)->write(new QrCode(data: route('batches.lookup', ['code' => $row->public_code]), size: 360, margin: 24));

            return response($result->getString(), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
        }

        return $this->view('batches.label', compact('row', 'batch'));
    }

    public function evidence(Request $request, Context $ctx, string $record)
    {
        $r = DB::table('batch_records')->where('id', $record)->firstOrFail();
        $ctx->batch($request->user(), $r->batch_id);
        $file = DB::table('attachments')->where('id', $r->attachment_id)->where('privacy_class', 'private')->firstOrFail();
        abort_unless(Storage::disk('directory_evidence')->exists($file->storage_key), 404);

        return Storage::disk('directory_evidence')->download($file->storage_key, 'private-batch-evidence.'.($file->mime === 'application/pdf' ? 'pdf' : ($file->mime === 'image/png' ? 'png' : 'jpg')), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function fields(): array
    {
        $names = ['inputs', 'detached', 'target_id', 'previous_measurement_id', 'measure_mode', 'unit', 'net_kg', 'gross_kg', 'tare_kg', 'measurement_source', 'output_type', 'material_code', 'source_item_id', 'observed_at', 'reason', 'evidence', 'evidence_kind', 'reference_issuer', 'reference_code', 'reference_attested'];
        $map = array_combine($names, $names);
        foreach (['actual_dismantling', 'all_media_detached', 'identity_safety', 'actual_output', 'all_outputs_accounted', 'unexpected_media', 'identity_confirmed', 'safe_media', 'method_scope_known', 'no_unresolved_restriction'] as $key) {
            $map['checks.'.$key] = 'checks_'.$key;
        }

        return $map;
    }

    private function view(string $name, array $data)
    {
        return response()->view($name, $data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
