<?php

namespace App\Http\Controllers;

use App\Actions\RecordSanitization;
use App\Enums\Role;
use App\Support\Sanitization\Context;
use App\Support\Sanitization\Policy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SanitizationController extends Controller
{
    public function index(Request $request, \App\Support\Assessments\Context $inspection, Context $ctx)
    {
        $place = $inspection->scope($request->user());
        $rows = $inspection->rows($request->user())->get();

        return $this->view('sanitization.index', compact('rows', 'place', 'ctx'));
    }

    public function show(Request $request, Context $ctx, string $subject)
    {
        $private = $request->user()->role() === Role::Processor;
        if ($private) {
            $row = app(\App\Support\Assessments\Context::class)->authorized($request->user(), $subject);
        } else {
            abort_unless($request->user()->role() === Role::Consumer, 403);
            $row = DB::table('items as i')->join('subjects as s', 's.id', '=', 'i.subject_id')->join('categories as c', 'c.id', '=', 'i.category_id')->where('i.subject_id', $subject)->where('i.owner_user_id', $request->user()->id)->firstOrFail(['i.subject_id', 'i.subtype', 's.public_code', 'c.code as category']);
        }
        $supported = $row->category === 'standalone_part' && in_array($row->subtype, ['hdd', 'ssd'], true);
        $current = $ctx->current($subject);
        $state = $supported ? $ctx->state($subject) : null;
        $history = DB::table('sanitization_records as r')->join('users as u', 'u.id', '=', 'r.actor_id')->where('r.subject_id', $subject)->orderByDesc('r.version')->select($private ? ['r.*', 'u.display_name'] : ['r.id', 'r.version', 'r.kind', 'r.outcome', 'r.observed_at'])->get();
        $blockers = $private ? $ctx->blockers($row) : [];
        $actions = $private && ! $blockers ? $ctx->actions($current) : [];
        $requestedAction = $request->query('action');
        $mode = is_string($requestedAction) && isset($actions[$requestedAction]) ? $requestedAction : null;
        $contextToken = $private ? $ctx->token($request->user(), $row) : null;
        $policy = Policy::definition();
        $fieldIds = array_combine(['observed_at', 'outcome', 'technique', 'reason', 'executor', 'scope', 'procedure', 'tool', 'tool_version', 'evidence_kind', 'evidence', 'reference_issuer', 'reference_code', 'reference_attested'], ['observed_at', 'outcome', 'technique', 'reason', 'executor', 'scope', 'procedure', 'tool', 'tool_version', 'evidence_kind', 'evidence', 'reference_issuer', 'reference_code', 'reference_attested']);
        foreach ($mode ? Policy::checks($mode) : [] as $code => $label) {
            $fieldIds['checks.'.$code] = 'checks_'.$code;
        }

        return $this->view('sanitization.show', compact('private', 'row', 'supported', 'current', 'state', 'history', 'blockers', 'actions', 'mode', 'contextToken', 'policy', 'fieldIds'));
    }

    public function store(Request $request, RecordSanitization $action, string $subject, string $kind)
    {
        $request->validate(['evidence' => ['nullable', 'file']]);
        $action->record($request->user(), $subject, $kind, $request->except(['_token', 'evidence']), $request->file('evidence'));

        return redirect()->route('sanitization.show', $subject)->with('status', match ($kind) {
            'attempt' => 'External attempt recorded. Verification and adequacy validation are separate; no release or custody change.',
            'verification' => 'Execution verification recorded. Review the current gate and next action.',
            'validation' => 'Adequacy validation recorded. This does not establish reuse suitability or release.',
            default => 'Applicability review recorded. No new physical treatment was claimed.',
        });
    }

    public function evidence(Request $request, string $record)
    {
        $r = DB::table('sanitization_records')->where('id', $record)->firstOrFail();
        app(\App\Support\Assessments\Context::class)->authorized($request->user(), $r->subject_id);
        $file = DB::table('attachments')->where('id', $r->attachment_id)->firstOrFail();
        abort_unless(Storage::disk('directory_evidence')->exists($file->storage_key), 404);

        return Storage::disk('directory_evidence')->download($file->storage_key, 'private-media-evidence.'.($file->mime === 'application/pdf' ? 'pdf' : ($file->mime === 'image/png' ? 'png' : 'jpg')), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function view(string $name, array $data)
    {
        return response()->view($name, $data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
