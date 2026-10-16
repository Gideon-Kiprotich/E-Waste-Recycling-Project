<?php

namespace App\Http\Controllers;

use App\Actions\InspectItems;
use App\Enums\Role;
use App\Support\Assessments\Context;
use App\Support\Assessments\Schemas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AssessmentController extends Controller
{
    public function index(Request $request, Context $ctx)
    {
        $actor = $request->user();
        $place = $ctx->scope($actor);
        $rows = $ctx->rows($actor)->where('i.intake_class', '<>', 'processor_derived')->get();

        return $this->view('assessments.index', compact('rows', 'place', 'ctx'));
    }

    public function show(Request $request, Context $ctx, string $subject)
    {
        $private = $request->user()->role() === Role::Processor;
        if ($private) {
            $row = $ctx->authorized($request->user(), $subject);
        } else {
            abort_unless($request->user()->role() === Role::Consumer, 403);
            $row = DB::table('items as i')->join('subjects as s', 's.id', '=', 'i.subject_id')->where('i.subject_id', $subject)->where('i.owner_user_id', $request->user()->id)->firstOrFail(['i.subject_id', 's.public_code']);
        }
        $history = DB::table('inspection_steps as op')->join('users as u', 'u.id', '=', 'op.actor_id')->where('op.subject_id', $subject)->orderByDesc('op.version')->select($private ? ['op.*', 'u.display_name'] : ['op.id', 'op.version', 'op.event_type', 'op.observed_at'])->get();
        $current = DB::table('assessment_current')->where('subject_id', $subject)->first();
        $assessments = $private ? DB::table('assessments')->where('subject_id', $subject)->get()->keyBy('id') : collect();
        $decisions = DB::table('decisions')->where('subject_id', $subject)->when(! $private, fn ($q) => $q->select('id', 'assessment_id', 'pathway'))->get()->keyBy('id');
        $schema = $private ? Schemas::definition($row->category, $row->subtype) : [];
        $blockers = $private ? $ctx->blockers($row) : [];
        $restricted = $private && $ctx->restricted($subject);
        $answers = $private && $current ? DB::table('assessment_answers')->whereIn('assessment_id', $assessments->keys())->get()->groupBy('assessment_id') : collect();
        $mode = in_array($request->query('action'), ['inspect', 'decide'], true) ? $request->query('action') : 'history';
        $fields = ['observed_at' => 'observed_at', 'reason' => 'reason', 'evidence' => 'evidence', 'evidence_kind' => 'evidence_kind', 'reference_issuer' => 'reference_issuer', 'reference_code' => 'reference_code', 'reference_attested' => 'reference_attested', 'pathway' => 'pathway'];
        foreach ($schema['questions'] ?? [] as $code => $q) {
            $fields['answers.'.$code] = 'answer-'.$code;
            $fields['na_reasons.'.$code] = 'na-'.$code;
        }

        return $this->view('assessments.show', compact('private', 'row', 'history', 'current', 'assessments', 'decisions', 'schema', 'blockers', 'restricted', 'answers', 'mode') + ['fieldIds' => $fields]);
    }

    public function store(Request $request, InspectItems $action, string $subject)
    {
        $file = $request->file('evidence');
        if (is_array($file)) {
            throw ValidationException::withMessages(['evidence' => 'Choose one private attachment.']);
        }
        $action->inspect($request->user(), $subject, $request->except('_token', 'evidence'), $file);

        return redirect()->route('assessments.show', $subject)->with('status', 'Inspection recorded or original submission retrieved. Check the current inspection below; a separate decision is required for each new inspection.');
    }

    public function decide(Request $request, InspectItems $action, string $subject)
    {
        // Reject even null/array protected file fields rather than silently dropping them.
        $action->decide($request->user(), $subject, $request->except('_token'));

        return redirect()->route('assessments.show', $subject)->with('status', 'Pathway decision recorded or original submission retrieved. Check its current/historical label; no work, data clearance or release was recorded.');
    }

    public function evidence(Request $request, Context $ctx, string $assessment)
    {
        $a = DB::table('assessments')->where('id', $assessment)->firstOrFail();
        $ctx->authorized($request->user(), $a->subject_id);
        $file = DB::table('attachments')->where('id', $a->attachment_id)->whereNull('redacted_at')->firstOrFail();
        abort_unless(Storage::disk('directory_evidence')->exists($file->storage_key), 404);

        return Storage::disk('directory_evidence')->download($file->storage_key, 'inspection-evidence.'.($file->mime === 'application/pdf' ? 'pdf' : 'png'), ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }

    public function notices(Request $request)
    {
        $rows = DB::table('assessment_notifications as n')->join('inspection_steps as s', 's.event_id', '=', 'n.event_id')->where('n.recipient_id', $request->user()->id)->orderByDesc('s.observed_at')->get(['n.id', 'n.read_at', 's.subject_id']);

        return $this->view('assessments.notices', compact('rows'));
    }

    public function read(Request $request, string $notice)
    {
        $n = DB::table('assessment_notifications')->where('id', $notice)->where('recipient_id', $request->user()->id)->firstOrFail();
        if (! $n->read_at) {
            DB::table('assessment_notifications')->where('id', $n->id)->update(['read_at' => now()]);
        }

        return redirect()->route('assessments.notices')->with('status', 'Notice marked read.');
    }

    private function view(string $name, array $data)
    {
        return response()->view($name, $data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
