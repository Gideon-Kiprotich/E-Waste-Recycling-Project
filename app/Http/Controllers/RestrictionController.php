<?php

namespace App\Http\Controllers;

use App\Actions\AcceptDownstreamResponsibility;
use App\Actions\ReviewRestrictions;
use App\Enums\Role;
use App\Support\Collection\Review;
use App\Support\Restrictions\Context;
use App\Support\Restrictions\DownstreamReview;
use App\Support\Restrictions\MediaReview;
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
        $sources = $admin ? DB::table('restriction_sources as r')->join('subjects as s', 's.id', '=', 'r.subject_id')->where('r.currently_blocking', true)->when(! \App\Support\Downstream\Context::correctionsAvailable(), fn ($q) => $q->where('r.source_kind', '<>', 'downstream_correction'))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('restriction_supersessions as x')->whereColumn('x.source_kind', 'r.source_kind')->whereColumn('x.source_id', 'r.source_id')->whereColumn('x.fact_key', 'r.fact_key'))->orderBy('r.source_time')->orderBy('r.source_id')->orderBy('r.fact_key')->paginate(12, ['r.source_kind', 'r.source_id', 'r.fact_key', 'r.supported', 's.public_code'], 'sources_page') : null;
        $cases = DB::table('restriction_cases as c')->join('subjects as s', 's.id', '=', 'c.subject_id')
            ->when(! $admin && ! $consumer, fn ($q) => $q->whereRaw('restriction_investigator(c.id,?) OR restriction_clarifier(c.id,?)', [$actor->id, $actor->id]))
            ->when($consumer, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('items as i')->whereColumn('i.subject_id', 'c.subject_id')->where('i.owner_user_id', $actor->id)))
            ->orderByDesc('c.created_at')->orderBy('c.id')->paginate(12, ['c.id', 'c.subject_id', 'c.state', 'c.active', 's.public_code'], 'cases_page');

        return $this->view('restrictions.index', compact('admin', 'consumer', 'sources', 'cases'));
    }

    public function source(Request $request, Context $ctx, string $kind, string $source, string $fact)
    {
        $source = $ctx->source($kind, $source, $fact);
        $typedBlockers = MediaReview::blockers($source);
        $case = null;
        $token = $ctx->token($request->user(), $source);
        $epoch = $ctx->epoch($source->subject_id);
        $impact = $ctx->impact($source->subject_id);
        $existingQuery = DB::table('restriction_cases')->where('active', true);
        if (DB::getSchemaBuilder()->hasTable('media_review_effects') && in_array($kind, ['media_correction', 'inventory', 'work', 'batch', 'dependent'], true)) {
            $existingQuery->whereRaw('media_source_scope(source_kind,source_id)=media_source_scope(?,?)', [$kind, $source->source_id]);
        } else {
            $existingQuery->where('source_kind', $kind)->where('source_id', $source->source_id)->where('fact_key', $fact);
        }
        $existing = $existingQuery->value('id');
        $sourceAvailable = $source->currently_blocking && ! DB::table('restriction_supersessions')->where('source_kind', $kind)->where('source_id', $source->source_id)->where('fact_key', $fact)->exists();
        if ($kind === 'media_correction' && $fact === 'impact_continuity' && DB::selectOne('SELECT media_impact_fulfilled(?) AS current', [$source->source_id])->current) {
            $sourceAvailable = false;
        }

        return $this->view('restrictions.source', compact('source', 'case', 'token', 'epoch', 'impact', 'existing', 'sourceAvailable', 'typedBlockers') + ['fieldIds' => $this->fields()]);
    }

    public function show(Request $request, Context $ctx, string $case)
    {
        $c = $ctx->case($request->user(), $case);
        $consumer = $request->user()->role() === Role::Consumer;
        if ($consumer) {
            $ownerForm = $c->active && ($c->contract ?? 'C14') === 'P15' && $c->fact_key === 'return_report';
            $source = $ownerForm ? $ctx->source($c->source_kind, $c->source_id, $c->fact_key) : null;
            $token = $ownerForm ? $ctx->token($request->user(), $source, $c) : null;
            $epoch = $ctx->epoch($c->subject_id);
            $ownerManifest = $ownerForm ? DB::table('reuse_manifest as m')->join('refurbishment_records as r', 'r.authorization_id', '=', 'm.authorization_id')->join('subjects as s', 's.id', '=', 'm.subject_id')->where('r.id', $c->source_id)->orderBy('m.subject_id')->get(['m.subject_id', 's.public_code']) : collect();

            return $this->view('restrictions.owned', compact('c', 'ownerForm', 'source', 'token', 'epoch', 'ownerManifest') + ['fieldIds' => $this->fields()]);
        }
        $source = $ctx->source($c->source_kind, $c->source_id, $c->fact_key);
        $typedBlockers = MediaReview::blockers($source);
        $admin = $request->user()->role() === Role::Administrator;
        $investigator = $ctx->investigator($request->user(), $case);
        $clarifier = (bool) DB::selectOne('SELECT restriction_clarifier(?,?) AS allowed', [$case, $request->user()->id])->allowed;
        $history = DB::table('restriction_records as r')->join('users as u', 'u.id', '=', 'r.actor_id')->where('r.case_id', $case)->orderByDesc('r.version')->paginate(10, ['r.*', 'u.display_name as actor_name']);
        $token = $ctx->token($request->user(), $source, $c);
        $epoch = $ctx->epoch($c->subject_id);
        $impact = $ctx->impact($c->subject_id);
        $candidates = $admin && $c->active ? (($c->contract ?? 'C14') === 'P16' ? DownstreamReview::candidates($c->subject_id) : $ctx->candidates($c->subject_id, $c->supported, ($c->contract ?? 'C14') === 'P15')) : collect();
        $proposals = $admin && $c->active ? DB::table('restriction_records')->where('case_id', $case)->where('kind', 'proposal')->orderByDesc('version')->get() : collect();
        $knownValue = $ctx->proposedValue($c->fact_key);
        $mode = $request->query('action', 'history');
        $allowed = $admin ? ['assign', 'revoke', 'note', 'continue', 'close', ...($c->supported ? ['approve'] : [])] : [...($investigator ? ['note', ...($c->supported ? ['proposal'] : [])] : []), ...($clarifier ? ['clarify'] : [])];
        if ($admin && ($c->contract ?? 'C14') === 'P15' && $c->fact_key === 'impact_continuity' && $c->state === 'revalidation_pending') {
            $allowed[] = 'fulfill';
        }
        if (($c->contract ?? 'C14') === 'P16') {
            if ($investigator) {
                $allowed[] = 'clarify';
            }
            if ($admin && in_array($c->fact_key, ['downstream_downstream_continuity', 'downstream_closure_review'], true) && $c->state === 'revalidation_pending') {
                $allowed[] = 'fulfill';
            }
        }
        $mode = is_string($mode) && in_array($mode, $allowed, true) && $c->active ? $mode : 'history';
        $retainedUnits = [];
        if (($c->contract ?? 'C14') === 'P15' && $c->fact_key === 'discovery_supplement') {
            $details = DB::table('restriction_records')->where('case_id', $case)->where('kind', 'proposal')->orderByDesc('version')->value('details');
            foreach (json_decode($details ?? '{}', true)['units'] ?? [] as $unit) {
                $retainedUnits[] = array_intersect_key($unit, array_flip(['id', 'parent_id', 'media_kind']));
            }
        }
        $unitCount = max(1, min(10, (int) $request->query('units', 1)));
        if ($retainedUnits) {
            $unitCount = count($retainedUnits);
        }
        $parents = ($c->contract ?? 'C14') === 'P15' && str_starts_with($c->fact_key, 'discovery') ? DB::table('batch_members as m')->join('subjects as s', 's.id', '=', 'm.subject_id')->where('m.batch_id', $c->subject_id)->get(['m.subject_id', 's.public_code']) : collect();
        $batchOutputs = $parents->isNotEmpty() ? DB::table('batch_outputs as o')->join('subjects as s', 's.id', '=', 'o.subject_id')->where('o.batch_id', $c->subject_id)->get(['o.subject_id', 'o.type', 's.public_code']) : collect();
        $allowedEvidence = json_decode(DB::selectOne('SELECT to_json(allowed_evidence) AS ids FROM restriction_cases WHERE id=?', [$case])->ids, true);

        $recipientProposal = $admin && ($c->contract ?? 'C14') === 'P16' && $c->fact_key === 'downstream_recipient' ? DB::table('downstream_recipient_current as generation')->join('downstream_review_effects as proof', 'proof.record_id', '=', 'generation.review_id')->where('proof.case_id', $c->id)->where('generation.kind', 'proposed')->first(['generation.*']) : null;
        $recipientCancelToken = $recipientProposal ? app(AcceptDownstreamResponsibility::class)->token($request->user(), $recipientProposal->shipment_id) : null;
        $downstreamForm = ($c->contract ?? 'C14') === 'P16' ? DownstreamReview::formData($c, $request->user(), is_string($request->query('finding')) ? $request->query('finding') : null) : [];

        return $this->view('restrictions.show', compact('c', 'source', 'admin', 'investigator', 'clarifier', 'history', 'token', 'epoch', 'impact', 'candidates', 'proposals', 'knownValue', 'mode', 'allowed', 'allowedEvidence', 'unitCount', 'retainedUnits', 'parents', 'batchOutputs', 'typedBlockers', 'downstreamForm', 'recipientProposal', 'recipientCancelToken') + ['fieldIds' => $this->fields()]);
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
            'fulfill' => 'The exact obligation was verified against current evidence; independent gates remain.', 'approve' => 'The exact review effects were recorded. Accounting, treatment and dependent obligations may still be pending. Fresh review and prerequisites are required; no physical outcome was recorded.', 'close' => 'Investigation closed without changing the restriction.', default => 'Investigation updated. The underlying restriction is unchanged.'
        });
    }

    public function ownerClarification(Request $request, ReviewRestrictions $action, Context $ctx, string $case)
    {
        $c = $ctx->case($request->user(), $case);
        abort_unless(($c->contract ?? 'C14') === 'P15' && $c->fact_key === 'return_report', 404);

        return $this->store($request, $action, $case, 'owner_clarify');
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

        foreach ($rows as $notice) {
            $notice->shipment_id = DB::getSchemaBuilder()->hasTable('downstream_recipient_records') ? DB::table('downstream_recipient_current as generation')->join('downstream_review_effects as proof', 'proof.record_id', '=', 'generation.review_id')->where('proof.case_id', $notice->case_id)->where('generation.kind', 'proposed')->where('generation.receiver_actor_id', $request->user()->id)->whereRaw('generation.receiver_context=restriction_actor_context(?)', [$request->user()->id])->value('generation.shipment_id') : null;
        }

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
        foreach (['details', 'details.count', 'details.units', 'details.manifest', 'details.no_handover'] as $name) {
            $fields[$name] = 'submission';
        }
        $fields['details.no_handover'] = 'details_no_handover';
        $fields['details.manifest'] = 'owner-manifest';
        for ($index = 0; $index < 10; $index++) {
            foreach (['id', 'parent_id', 'existing_code', 'media_kind', 'identifier', 'model', 'mode', 'source_output_id'] as $key) {
                $name = 'details.units.'.$index.'.'.$key;
                $fields[$name] = str_replace('.', '_', $name);
            }
            foreach (['measurement', 'remaining'] as $measurement) {
                foreach (['mode', 'net', 'gross', 'tare', 'source'] as $key) {
                    $name = 'details.units.'.$index.'.'.$measurement.'.'.$key;
                    $fields[$name] = str_replace('.', '_', $name);
                }
            }
        }
        foreach (['party_actor_id', 'receiver_actor_id', 'profile_record_id', 'party_findings', ...DownstreamReview::FACTS] as $key) {
            $fields['details.'.$key] = 'details_'.str_replace('.', '_', $key);
            $fields['details.facts.'.$key] = 'details_facts_'.$key;
        }
        for ($index = 0; $index < 50; $index++) {
            foreach (['anchor_kind', 'anchor_id', 'fact', 'value', 'expected_version', 'predecessor_id'] as $key) {
                $fields['details.revisions.'.$index.'.'.$key] = 'details_revisions_'.$index.'_'.$key;
            }
        }
        foreach ((Context::CHECKS + DownstreamReview::CHECKS + MediaReview::DISCOVERY_CHECKS + MediaReview::IMPACT_CHECKS) as $key => $label) {
            $fields['checks.'.$key] = 'check-'.$key;
        }

        return $fields;
    }

    private function view(string $name, array $data)
    {
        return response()->view($name, $data)->header('Cache-Control', 'private, no-store');
    }
}
