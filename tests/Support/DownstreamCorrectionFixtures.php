<?php

namespace Tests\Support;

use App\Actions\ReviewRestrictions;
use App\Support\Restrictions\Context;
use App\Support\Restrictions\DownstreamReview;
use Illuminate\Support\Facades\DB;

trait DownstreamCorrectionFixtures
{
    use DownstreamFixtures, RestrictionFixtures;

    protected function correction(array $w, string $source, string $action, array $revisions, bool $destination = true, array $facts = [], array $extra = [], bool $both = false, bool $approve = true): string
    {
        $w['subject'] = $w['batch'];
        $w['investigator'] = $destination ? $w['recipient'] : $w['receiver'];
        $w['source'] = app(Context::class)->source('downstream_correction', $source, 'downstream_'.$action);
        $service = app(ReviewRestrictions::class);
        $case = $service->open($w['admin'], 'downstream_correction', $source, 'downstream_'.$action, $this->restrictionFields($w, 'open'));
        $this->handoverBoundary();
        $assignment = $this->restrictionFields($w, 'assign', $case);
        if ($both) {
            $assignment['details'] = ['party_actor_id' => ($destination ? $w['receiver'] : $w['recipient'])->id];
        }
        $service->record($w['admin'], $case, 'assign', $assignment);
        $this->handoverBoundary();
        $fields = $this->restrictionFields($w, 'proposal', $case);
        $fields['checks'] = array_fill_keys(array_keys(DownstreamReview::CHECKS), 'YES');
        $fields['details'] = $extra + ['revisions' => $revisions, 'party_findings' => [], 'facts' => $facts + ['identity_known' => 'YES', 'supported_facts' => 'YES']];
        if ($both) {
            $party = $destination ? $w['receiver'] : $w['recipient'];
            $linked = $this->restrictionFields($w, 'proposal', $case, $party);
            $linked['checks'] = $fields['checks'];
            $linked['details'] = $fields['details'];
            if (! $destination) {
                $linked['details']['revisions'] = array_values(array_filter($revisions, fn ($row) => $row['anchor_kind'] === 'downstream' && DB::selectOne('SELECT downstream_destination_actor(?,?) AS ok', [$party->id, $row['anchor_id']])->ok));
            }
            if ($destination) {
                $linked['details']['revisions'] = array_values(array_filter($revisions, fn ($row) => $row['anchor_kind'] !== 'downstream' || DB::table('downstream_records')->where('id', $row['anchor_id'])->whereIn('kind', ['reserve', 'withdraw', 'dispatch', 'source_loss'])->exists()));
            }
            $service->record($party, $case, 'clarify', $linked);
            $this->handoverBoundary();
            $details = $fields['details'];
            $details['party_findings'] = [DB::table('restriction_cases')->where('id', $case)->value('record_id')];
            $fields = $this->restrictionFields($w, 'proposal', $case);
            $fields['checks'] = array_fill_keys(array_keys(DownstreamReview::CHECKS), 'YES');
            $fields['details'] = $details;
        }
        $service->record($w['investigator'], $case, 'proposal', $fields);
        $this->handoverBoundary();
        if ($approve) {
            $service->record($w['admin'], $case, 'approve', $this->restrictionFields($w, 'approve', $case));
            $this->handoverBoundary();
        }

        return $case;
    }

    protected function fact(string $anchor, string $id, string $fact, string $value): array
    {
        $previous = DB::table('downstream_fact_revisions')->where('anchor_kind', $anchor)->where('anchor_id', $id)->where('fact', $fact)->orderByDesc('version')->first();

        return ['anchor_kind' => $anchor, 'anchor_id' => $id, 'fact' => $fact, 'value' => $value, 'expected_version' => $previous?->version ?? 0, 'predecessor_id' => $previous?->id];
    }

    protected function downstreamContinuities(array $w, bool $fulfill = true): array
    {
        $cases = [];
        foreach (DB::table('restriction_impacts')->whereRaw('NOT media_impact_fulfilled(id)')->get() as $impact) {
            $paired = (bool) DB::selectOne('SELECT downstream_review_scope(?,?) AS allowed', [$w['recipient']->id, $w['batch']])->allowed;
            $case = $this->correction($w, $impact->id, 'downstream_continuity', [], false, both: $paired);
            $cases[] = [$case, $impact->id];
        }
        if ($fulfill) {
            $this->fulfillDownstream($w, $cases);
        }

        return $cases;
    }

    protected function fulfillDownstream(array $w, array $cases): void
    {
        foreach ($cases as [$case, $impact]) {
            $w['subject'] = $w['batch'];
            $w['investigator'] = $w['receiver'];
            $w['source'] = app(Context::class)->source('downstream_correction', $impact, 'downstream_downstream_continuity');
            $service = app(ReviewRestrictions::class);
            $fields = $this->restrictionFields($w, 'proposal', $case);
            $fields['checks'] = array_fill_keys(array_keys(DownstreamReview::CHECKS), 'YES');
            $fields['details'] = ['revisions' => [], 'party_findings' => [], 'facts' => ['identity_known' => 'YES', 'supported_facts' => 'YES']];
            if (DB::selectOne('SELECT downstream_case_access(?,?) AS allowed', [$case, $w['recipient']->id])->allowed) {
                $linked = $this->restrictionFields($w, 'proposal', $case, $w['recipient']);
                $linked['checks'] = $fields['checks'];
                $linked['details'] = $fields['details'];
                $service->record($w['recipient'], $case, 'clarify', $linked);
                $this->handoverBoundary();
                $fields = $this->restrictionFields($w, 'proposal', $case);
                $fields['checks'] = $linked['checks'];
                $fields['details'] = $linked['details'];
                $fields['details']['party_findings'] = [DB::table('restriction_cases')->where('id', $case)->value('record_id')];
            }
            $service->record($w['receiver'], $case, 'proposal', $fields);
            $this->handoverBoundary();
            $service->record($w['admin'], $case, 'fulfill', $this->restrictionFields($w, 'approve', $case));
            $this->handoverBoundary();
        }
    }
}
