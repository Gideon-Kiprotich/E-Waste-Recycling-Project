<?php

namespace Tests\Support;

use App\Actions\InspectItems;
use App\Actions\RecordSanitization;
use App\Support\Sanitization\Context;
use App\Support\Sanitization\Policy;
use Illuminate\Support\Facades\DB;

trait SanitizationFixtures
{
    use InspectionFixtures;

    protected function mediaWork(string $media = 'ssd'): array
    {
        $w = $this->inspectionWork('standalone_part', $media);
        $s = $w['ids'][0];
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s, $media.'-healthy'));
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();

        return $w;
    }

    protected function mediaFields(array $w, string $kind = 'attempt', ?string $outcome = null): array
    {
        $s = $w['ids'][0];
        $actor = $w['receiver']->fresh();
        $row = app(\App\Support\Assessments\Context::class)->authorized($actor, $s);
        $f = $this->handoverEvidence() + ['expected_version' => DB::table('sanitization_current')->where('subject_id', $s)->value('version') ?? 0, 'expected_context' => app(Context::class)->token($actor, $row), 'reason' => 'Private synthetic result and review method; retrospective entry explained', 'outcome' => $outcome ?? ($kind === 'attempt' ? 'success' : ($kind === 'verification' ? 'satisfactory' : 'accepted'))];
        if ($kind === 'attempt') {
            $f += ['technique' => DB::table('storage_assets')->where('subject_id', $s)->value('media_kind') === 'hdd' ? 'hdd_sanitize_overwrite' : 'ssd_sanitize_block_erase', 'executor' => 'Synthetic external operator', 'tool' => 'Synthetic tool/equipment identity', 'tool_version' => 'test-1.0', 'scope' => 'Synthetic full medium scope; no real hardware', 'procedure' => 'Synthetic manufacturer capability, coverage and media-specific criterion evidence'];
        } else {
            $f['checks'] = array_fill_keys(array_keys(Policy::checks($kind)), 'YES');
        }

        return $f;
    }

    protected function mediaRecord(array $w, string $kind = 'attempt', ?array $fields = null): string
    {
        $id = app(RecordSanitization::class)->record($w['receiver'], $w['ids'][0], $kind, $fields ?? $this->mediaFields($w, $kind));
        $this->handoverBoundary();

        return $id;
    }

    protected function mediaSatisfied(array $w, ?array $attempt = null): void
    {
        $this->mediaRecord($w, 'attempt', $attempt);
        $this->mediaRecord($w, 'verification');
        $this->mediaRecord($w, 'validation');
    }

    protected function mediaPreservation(): array
    {
        $result = [];
        foreach (['users', 'memberships', 'subjects', 'items', 'storage_assets', 'custody_current', 'first_receipts', 'handover_receipts', 'assessments', 'assessment_answers', 'decisions', 'assessment_current', 'request_items', 'requests', 'route_claims', 'route_approvals', 'route_consumptions', 'notification_outbox'] as $table) {
            $result[$table] = DB::table($table)->get()->toJson();
        }

        return $result;
    }
}
