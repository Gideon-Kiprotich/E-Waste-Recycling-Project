<?php

namespace Tests\Support;

use App\Actions\ReviewRestrictions;
use App\Support\Restrictions\MediaReview;
use Illuminate\Support\Facades\DB;

trait PriorMediaCorrectionFixtures
{
    private function typedWork(array $w, string $type, ?string $source = null): array
    {
        $w['subject'] = str_starts_with($type, 'discovery') ? $w['batch'] : $w['ids'][0];
        $w['investigator'] = $w['receiver'];
        $w['source'] = DB::table('restriction_sources')->where('source_kind', 'media_correction')->where('subject_id', $w['subject'])->where('fact_key', $type)->when($source, fn ($q) => $q->where('source_id', $source))->firstOrFail();

        return $w;
    }

    private function typedProposal(array $w, string $case, array $details = []): string
    {
        $f = $this->restrictionFields($w, 'proposal', $case);
        $f['checks'] = array_fill_keys(array_keys(MediaReview::checks($w['source']->fact_key)), 'YES');
        $f['details'] = $details;

        return app(ReviewRestrictions::class)->record($w['investigator'], $case, 'proposal', $f);
    }

    private function approveTyped(array $w, array $details = []): string
    {
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->typedProposal($w, $case, $details);
        $this->handoverBoundary();
        $this->restrictionRecord($w, $case, 'approve');

        return $case;
    }

    private function continuities(array $w): array
    {
        $cases = [];
        foreach (DB::table('restriction_impacts')->whereIn('subject_id', array_column(DB::select('SELECT subject_id FROM restriction_family(?)', [$w['ids'][0]]), 'subject_id'))->pluck('id') as $impact) {
            $source = DB::table('restriction_sources')->where('source_kind', 'media_correction')->where('source_id', $impact)->where('fact_key', 'impact_continuity')->firstOrFail();
            $e = array_replace($w, ['subject' => $source->subject_id, 'source' => $source, 'investigator' => $w['receiver']]);
            $cases[] = [$e, $this->approveTyped($e)];
        }

        return $cases;
    }

    private function fulfillContinuities(array $cases): void
    {
        foreach ($cases as [$e,$case]) {
            $this->typedProposal($e, $case);
            $this->handoverBoundary();
            $p = DB::table('restriction_records')->where('case_id', $case)->where('kind', 'proposal')->orderByDesc('version')->value('id');
            $this->restrictionRecord($e, $case, 'fulfill', ['proposal_id' => $p]);
        }
    }
}
