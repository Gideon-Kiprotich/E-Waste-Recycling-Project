<?php

namespace App\Http\Controllers;

use App\Actions\ReviewRestrictions;
use App\Enums\Role;
use App\Support\Collection\Review;
use App\Support\Restrictions\Context;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RestrictionController extends Controller
{
    public function index(Request $request, Context $ctx)
    {
        $actor = $request->user();
        $admin = $actor->role() === Role::Administrator;
        $consumer = $actor->role() === Role::Consumer;
        $sources = $admin ? DB::table('restriction_sources as r')->join('subjects as s', 's.id', '=', 'r.subject_id')->where('r.currently_blocking', true)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('restriction_supersessions as x')->whereColumn('x.source_kind', 'r.source_kind')->whereColumn('x.source_id', 'r.source_id')->whereColumn('x.fact_key', 'r.fact_key'))->orderBy('r.source_time')->orderBy('r.source_id')->orderBy('r.fact_key')->paginate(12, ['r.source_kind', 'r.source_id', 'r.fact_key', 'r.supported', 's.public_code'], 'sources_page') : null;
        $cases = DB::table('restriction_cases as c')->join('subjects as s', 's.id', '=', 'c.subject_id')
            ->when(! $admin && ! $consumer, fn ($q) => $q->whereRaw('restriction_investigator(c.id,?) OR restriction_clarifier(c.id,?)', [$actor->id, $actor->id]))
            ->when($consumer, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('items as i')->whereColumn('i.subject_id', 'c.subject_id')->where('i.owner_user_id', $actor->id)))
            ->orderByDesc('c.created_at')->orderBy('c.id')->paginate(12, ['c.id', 'c.subject_id', 'c.state', 'c.active', 's.public_code'], 'cases_page');

        return $this->view('restrictions.index', compact('admin', 'consumer', 'sources', 'cases'));
    }

    public function source(Request $request, Context $ctx, string $kind, string $source, string $fact)
    {
        $source = $ctx->source($kind, $source, $fact);
        $case = null;
        $token = $ctx->token($request->user(), $source);
        $epoch = $ctx->epoch($source->subject_id);
        $impact = $ctx->impact($source->subject_id);
        $existing = DB::table('restriction_cases')->where('source_kind', $kind)->where('source_id', $source->source_id)->where('fact_key', $fact)->where('active', true)->value('id');
        $sourceAvailable = $source->currently_blocking && ! DB::table('restriction_supersessions')->where('source_kind', $kind)->where('source_id', $source->source_id)->where('fact_key', $fact)->exists();

        return $this->view('restrictions.source', compact('source', 'case', 'token', 'epoch', 'impact', 'existing', 'sourceAvailable') + ['fieldIds' => $this->fields()]);
    }

    public function show(Request $request, Context $ctx, string $case)
    {
        $c = $ctx->case($request->user(), $case);
        $consumer = $request->user()->role() === Role::Consumer;
        if ($consumer) {
            return $this->view('restrictions.owned', compact('c'));
        }
        $source = $ctx->source($c->source_kind, $c->source_id, $c->fact_key);
        $admin = $request->user()->role() === Role::Administrator;
        $investigator = $ctx->investigator($request->user(), $case);
        $clarifier = (bool) DB::selectOne('SELECT restriction_clarifier(?,?) AS allowed', [$case, $request->user()->id])->allowed;
        $history = DB::table('restriction_records as r')->join('users as u', 'u.id', '=', 'r.actor_id')->where('r.case_id', $case)->orderByDesc('r.version')->paginate(10, ['r.*', 'u.display_name as actor_name']);
        $token = $ctx->token($request->user(), $source, $c);
        $epoch = $ctx->epoch($c->subject_id);
        $impact = $ctx->impact($c->subject_id);
        $candidates = $admin && $c->active ? $ctx->candidates($c->subject_id, $c->supported) : collect();
        $proposals = $admin && $c->active ? DB::table('restriction_records')->where('case_id', $case)->where('kind', 'proposal')->orderByDesc('version')->get() : collect();
        $knownValue = $ctx->proposedValue($c->fact_key);
        $mode = $request->query('action', 'history');
        $allowed = $admin ? ['assign', 'revoke', 'note', 'continue', 'close', ...($c->supported ? ['approve'] : [])] : [...($investigator ? ['note', ...($c->supported ? ['proposal'] : [])] : []), ...($clarifier ? ['clarify'] : [])];
        $mode = is_string($mode) && in_array($mode, $allowed, true) && $c->active ? $mode : 'history';
        $allowedEvidence = json_decode(DB::selectOne('SELECT to_json(allowed_evidence) AS ids FROM restriction_cases WHERE id=?', [$case])->ids, true);

        return $this->view('restrictions.show', compact('c', 'source', 'admin', 'investigator', 'clarifier', 'history', 'token', 'epoch', 'impact', 'candidates', 'proposals', 'knownValue', 'mode', 'allowed', 'allowedEvidence') + ['fieldIds' => $this->fields()]);
    }

    public function open(Request $request, ReviewRestrictions $action, string $kind, string $source, string $fact)
    {
        $request->validate(['evidence' => ['nullable', 'file']]);
        $case = $action->open($request->user(), $kind, $source, $fact, $request->except(['_token', 'evidence']), $request->file('evidence'));

        return redirect()->route('restrictions.show', $case)->with('status', 'Investigation opened; the underlying restriction remains unchanged.');
    }

    public function store(Request $request, ReviewRestrictions $action, string $case, string $kind)
    {
        $request->validate(['evidence' => ['nullable', 'file']]);
        $action->record($request->user(), $case, $kind, $request->except(['_token', 'evidence']), $request->file('evidence'));
        $destination = $request->user()->role() === Role::Administrator ? redirect()->route('restrictions.show', $case) : redirect()->route('restrictions.index');

        return $destination->with('status', match ($kind) {
            'approve' => 'The specific recorded fact was superseded. Fresh review and prerequisites are required; no physical outcome was recorded.', 'close' => 'Investigation closed without changing the restriction.', default => 'Investigation updated. The underlying restriction is unchanged.'
        });
    }

    public function evidence(Request $request, Context $ctx, string $case, string $attachment)
    {
        $c = $ctx->case($request->user(), $case);
        abort_if($request->user()->role() === Role::Consumer, 404);
        $source = $ctx->source($c->source_kind, $c->source_id, $c->fact_key);
        $admin = $request->user()->role() === Role::Administrator;
        $fromCase = DB::table('restriction_records')->where('case_id', $case)->where('attachment_id', $attachment)->when(! $admin, fn ($q) => $q->where('actor_id', $request->user()->id))->exists();
        $sourceAllowed = $source->source_attachment_id === $attachment && ($admin || ($ctx->investigator($request->user(), $case) && DB::table('restriction_cases')->where('id', $case)->whereRaw('?::uuid=ANY(allowed_evidence)', [$attachment])->exists()));
        abort_unless($fromCase || $sourceAllowed, 404);
        $file = DB::table('attachments')->where('id', $attachment)->whereNull('redacted_at')->firstOrFail();
        abort_unless(Storage::disk('directory_evidence')->exists($file->storage_key), 404);

        return Storage::disk('directory_evidence')->download($file->storage_key, 'case-evidence.'.($file->mime === 'application/pdf' ? 'pdf' : 'png'), ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }

    public function notices(Request $request)
    {
        $rows = DB::table('restriction_notifications')->where('recipient_id', $request->user()->id)->orderByDesc('id')->paginate(12);

        return $this->view('restrictions.notices', compact('rows'));
    }

    public function read(Request $request, Context $ctx, string $notice)
    {
        DB::transaction(function () use ($request, $ctx, $notice) {
            Review::lock();
            $ctx->actor($request->user());
            DB::table('restriction_notifications')->where('id', $notice)->where('recipient_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            DB::table('restriction_notifications')->where('id', $notice)->whereNull('read_at')->update(['read_at' => now()]);
        });

        return redirect()->route('restrictions.notices')->with('status', 'Notice marked read.');
    }

    private function fields(): array
    {
        $fields = array_combine($keys = ['submission', 'reason', 'observed_at', 'evidence', 'evidence_kind', 'reference_issuer', 'reference_code', 'reference_attested', 'assigned_to', 'pre_receipt', 'allowed_evidence', 'proposal_id', 'original_value', 'proposed_value', 'identity_code', 'basis'], $keys);
        $fields['allowed_evidence.0'] = 'allowed_evidence';
        foreach (Context::CHECKS as $key => $label) {
            $fields['checks.'.$key] = 'check-'.$key;
        }

        return $fields;
    }

    private function view(string $name, array $data)
    {
        return response()->view($name, $data)->header('Cache-Control', 'private, no-store');
    }
}
