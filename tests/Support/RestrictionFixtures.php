<?php

namespace Tests\Support;

use App\Actions\InspectItems;
use App\Actions\RecordVisit;
use App\Actions\ReviewRestrictions;
use App\Models\User;
use App\Support\Restrictions\Context;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait RestrictionFixtures
{
    use BatchFixtures;

    protected function restrictionWork(string $kind = 'receipt', string $fact = 'mismatch', bool $attachment = false): array
    {
        if ($kind === 'receipt') {
            [$admin,$owner,$collector,$point] = $this->allocationSetup();
            $facility = $this->facility($point);
            $investigator = $this->pointStaff($admin, $facility);
            $observer = $this->pointStaff($admin, $point);
            $subject = $this->device($owner, 'standalone_part', 'ssd')->subject_id;
            $request = $this->readyRequest($admin, $owner, [$subject], 'drop_off');
            $visitFields = $this->visitFields($request, [$fact]);
            if ($attachment) {
                $visitFields['evidence_kind'] = 'attachment';
                unset($visitFields['reference_issuer'], $visitFields['reference_code'], $visitFields['reference_attested']);
            }
            app(RecordVisit::class)->record($observer, $request, $visitFields, $attachment ? UploadedFile::fake()->image('private.png') : null);
        } else {
            $w = $this->inspectionWork();
            $admin = $w['admin'];
            $owner = $w['owner'];
            $investigator = $w['receiver'];
            $observer = $w['receiver'];
            $subject = $w['ids'][0];
            $request = $w['request'];
            $fields = $this->inspectionFields($subject);
            $fields['answers'][$fact] = 'YES';
            app(InspectItems::class)->inspect($investigator, $subject, $fields);
            app(InspectItems::class)->decide($investigator, $subject, $this->decisionFields($subject, 'hold'));
        }
        $this->handoverBoundary();
        $source = DB::table('restriction_sources')->where('subject_id', $subject)->where('source_kind', $kind)->where('fact_key', $fact)->firstOrFail();

        return compact('admin', 'owner', 'investigator', 'observer', 'subject', 'request', 'source');
    }

    protected function restrictionFields(array $w, string $kind, ?string $case = null, ?User $actor = null): array
    {
        $actor ??= in_array($kind, ['proposal', 'note'], true) ? $w['investigator'] : $w['admin'];
        $ctx = app(Context::class);
        $c = $case ? $ctx->case($actor, $case) : null;
        $source = $ctx->source($w['source']->source_kind, $w['source']->source_id, $w['source']->fact_key);
        $fields = ['idempotency_key' => Str::uuid()->toString(), 'expected_version' => $c?->version ?? 0, 'expected_source_version' => $source->source_version, 'expected_restriction_version' => $ctx->epoch($w['subject']), 'expected_context' => $ctx->token($actor, $source, $c), 'observed_at' => now('UTC')->format('Y-m-d\TH:i'), 'reason' => 'Private synthetic investigation reason', 'evidence_kind' => 'operator_reference', 'reference_issuer' => 'Synthetic observation log', 'reference_code' => 'SYN-REVIEW-001', 'reference_attested' => '1'];
        if ($kind === 'assign') {
            $fields += ['assigned_to' => $w['investigator']->id, 'pre_receipt' => $source->source_kind === 'receipt' ? '1' : '0'];
        } elseif ($kind === 'proposal') {
            $fields += ['original_value' => $source->original_value, 'proposed_value' => $ctx->proposedValue($source->fact_key), 'identity_code' => $source->public_code, 'basis' => 'Synthetic same-physical-subject observation and contemporaneous evidence', 'checks' => array_fill_keys(array_keys(Context::CHECKS), 'YES')];
        } elseif ($kind === 'approve') {
            $fields['proposal_id'] = DB::table('restriction_records')->where('case_id', $case)->where('kind', 'proposal')->orderByDesc('version')->value('id');
        }

        return $fields;
    }

    protected function openRestriction(array $w): string
    {
        $s = $w['source'];
        $id = app(ReviewRestrictions::class)->open($w['admin'], $s->source_kind, $s->source_id, $s->fact_key, $this->restrictionFields($w, 'open'));
        $this->handoverBoundary();

        return $id;
    }

    protected function restrictionRecord(array $w, string $case, string $kind, array $overrides = [], ?User $actor = null): string
    {
        $actor ??= $kind === 'proposal' ? $w['investigator'] : $w['admin'];
        $id = app(ReviewRestrictions::class)->record($actor, $case, $kind, array_replace($this->restrictionFields($w, $kind, $case, $actor), $overrides));
        $this->handoverBoundary();

        return $id;
    }
}
