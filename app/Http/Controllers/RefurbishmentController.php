<?php

namespace App\Http\Controllers;

use App\Actions\RefurbishItems;
use App\Enums\Role;
use App\Support\Refurbishment\Context;
use App\Support\Refurbishment\Profiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RefurbishmentController extends Controller
{
    public function index(Request $request)
    {
        $rows = app(\App\Support\Assessments\Context::class)->rows($request->user())->whereIn('i.intake_class', ['whole_device', 'standalone_part'])->get();

        return response()->view('refurbishment.index', compact('rows'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Context $ctx, string $subject)
    {
        $row = $ctx->item($request->user(), $subject);
        $private = $request->user()->role() === Role::Processor;
        $history = DB::table('refurbishment_records')->where('subject_id', $subject)->orderByDesc('version')->get($private ? ['*'] : ['id', 'kind', 'result', 'version', 'observed_at', 'recorded_at']);
        $pending = $ctx->pending($subject);
        $manifest = $pending ? $ctx->manifest($pending->id) : collect();
        $blockers = $private ? $ctx->blockers($subject, $request->user()->id, true) : [];
        $stale = false;
        if ($pending) {
            $a = DB::table('refurbishment_records')->where('id', $pending->id)->firstOrFail();
            $stale = json_decode($a->context, true) !== $ctx->snapshot($subject, $a->actor_id);
        }
        $actions = $private ? array_intersect_key(Context::LABELS, array_flip(['work', 'test', 'fan_na', 'authorize', 'withdraw'])) : array_intersect_key(Context::LABELS, array_flip(['acknowledge', 'exception']));
        if (! $pending) {
            unset($actions['withdraw'], $actions['acknowledge'], $actions['exception']);
        }
        if ($row->subtype !== 'ict_cooling_fan') {
            unset($actions['fan_na']);
        }
        $mode = $request->query('action');
        $mode = is_string($mode) && isset($actions[$mode]) ? $mode : null;
        $questions = $private ? Profiles::questions($row) : [];
        $fieldIds = array_combine(['submission', 'outcome', 'configuration', 'received', 'actual_receipt', 'observed_at', 'evidence_kind', 'evidence', 'reference_issuer', 'reference_code', 'reference_attested', 'reason'], ['submission', 'outcome', 'configuration', 'received', 'actual_receipt', 'observed_at', 'evidence_kind', 'evidence', 'reference_issuer', 'reference_code', 'reference_attested', 'reason']);
        foreach ($questions as $code => $_) {
            $fieldIds['answers.'.$code] = 'answers_'.$code;
            $fieldIds['na_reasons.'.$code] = 'na_reasons_'.$code;
        }
        $version = $ctx->version($subject);
        $token = $ctx->token($request->user(), $subject);

        return response()->view('refurbishment.show', compact('row', 'private', 'history', 'pending', 'manifest', 'blockers', 'stale', 'actions', 'mode', 'questions', 'fieldIds', 'version', 'token'))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, RefurbishItems $action, string $subject, string $kind)
    {
        $request->validate(['evidence' => ['nullable', 'file']]);
        $action->record($request->user(), $subject, $kind, $request->except(['_token', 'evidence']), $request->file('evidence'));

        return redirect()->route('refurbishment.show', $subject)->with('status', $kind === 'acknowledge' ? 'Complete return acknowledged. Every manifest item is now in your custody; ownership is unchanged.' : Context::LABELS[$kind].' recorded. This action did not transfer custody.');
    }

    public function evidence(Request $request, Context $ctx, string $record)
    {
        $r = DB::table('refurbishment_records')->where('id', $record)->firstOrFail();
        $ctx->item($request->user(), $r->subject_id);
        $attachment = DB::table('attachments')->where('id', $r->attachment_id)->where('privacy_class', 'private')->firstOrFail();
        abort_unless(Storage::disk('directory_evidence')->exists($attachment->storage_key), 404);

        return Storage::disk('directory_evidence')->download($attachment->storage_key, 'private-work-evidence.'.($attachment->mime === 'application/pdf' ? 'pdf' : ($attachment->mime === 'image/png' ? 'png' : 'jpg')), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
