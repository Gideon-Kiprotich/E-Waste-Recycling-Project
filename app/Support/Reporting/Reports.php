<?php

namespace App\Support\Reporting;

use App\Enums\Role;
use App\Models\User;
use App\Support\Assessments\Context;
use App\Support\Batches\Mass;
use App\Support\Collection\Review;
use Illuminate\Support\Facades\DB;

class Reports
{
    public const DEFINITION = 'C17-1.0';

    public function snapshot(User $actor, array $input, ?string $sessionFingerprint = null): array
    {
        return DB::transaction(function () use ($actor, $input, $sessionFingerprint) {
            Review::lock();
            $actor = User::with('membership')->findOrFail($actor->id);
            $filters = Filters::normalize($actor, $input);
            $rows = match ($filters['kind']) {
                'collections' => $this->collections($actor, $filters),
                'facility' => $this->facility($filters),
                'batches' => app(BatchReport::class)->rows($filters),
                'incoming' => app(BatchReport::class)->incoming($actor, $filters),
                'manifest' => app(Manifest::class)->rows($actor),
            };
            $summary = $this->summary($filters['kind'], $rows);
            if ($filters['kind'] === 'facility') {
                $summary['Unclassifiable admission evidence (excluded)'] = $this->cohort('reporting_admission_issues', $filters)->distinct()->count('subject_id');
            }
            $authority = $this->authority($actor);
            $revision = hash('sha256', json_encode([$filters, self::DEFINITION, $authority, $sessionFingerprint, $rows, $summary, $this->sourceRevision()], JSON_THROW_ON_ERROR));

            return ['definition' => self::DEFINITION, 'filters' => $filters, 'revision' => $revision, 'authority' => $authority, 'generated_at' => now()->utc()->toIso8601String(), 'rows' => $rows, 'summary' => $summary, 'live' => $this->live($actor)];
        });
    }

    public function authority(User $actor): string
    {
        $actor->refresh()->load('membership');
        abort_unless($actor->canSignIn(), 403);
        $context = DB::selectOne('SELECT restriction_actor_context(?)::text AS context', [$actor->id])->context;

        return hash('sha256', $context);
    }

    private function sourceRevision(): array
    {
        // Conservative invalidation includes complete version vectors, not a maximum version.
        $result = [];
        foreach (['subjects', 'users', 'memberships', 'organizations', 'places', 'requests', 'assignments', 'custody_current', 'assessment_current', 'sanitization_current', 'refurbishment_current', 'reuse_authorizations', 'dismantling_batches', 'batch_outputs', 'downstream_batches', 'downstream_fact_revisions', 'downstream_recipient_records', 'restriction_cases', 'restriction_epochs', 'restriction_impact_reviews', 'route_approvals', 'route_consumptions', 'route_finishes', 'attachments'] as $table) {
            $result[$table] = DB::selectOne('SELECT md5(COALESCE(string_agg(row_data,\'\' ORDER BY row_data),\'\')) AS digest FROM (SELECT to_jsonb(t)::text row_data FROM '.$table.' t) q')->digest;
        }

        return $result;
    }

    private function cohort(string $view, array $filters)
    {
        [$from, $to] = Filters::bounds($filters);
        $q = DB::table($view)->where('observed_at', '>=', $from)->where('observed_at', '<', $to);
        if ($filters['category'] !== '') {
            $q->where('category', $filters['category']);
        }
        if ($filters['subtype'] !== '') {
            $q->where('subtype', $filters['subtype']);
        }
        if ($filters['facility'] !== '') {
            $q->where('place_id', $filters['facility']);
        }

        return $q;
    }

    private function collections(User $actor, array $filters): array
    {
        $q = $this->cohort('reporting_collections', $filters);
        if ($actor->role() === Role::Consumer) {
            $q->where('owner_user_id', $actor->id);
        }

        return $q->orderBy('subject_id')->get()->map(fn ($r) => [
            'row_kind' => 'collection', 'id' => $r->subject_id, 'public_code' => $r->public_code, 'intake_class' => $r->intake_class, 'category' => $r->category, 'subtype' => $r->subtype ?? '',
            'observed_at' => $r->observed_at, 'facility' => $r->place_id ?? 'No receiving place at first receipt', 'estimated_kg' => $r->estimated_kg,
            'original_reference' => $r->event_id, 'effective_reference' => $r->request_item_id,
            'status' => DB::selectOne('SELECT reporting_subject_exception(?) AS blocked', [$r->subject_id])->blocked ? 'Current exception; actual receipt retained' : 'Actual first receipt recorded',
        ])->all();
    }

    private function facility(array $filters): array
    {
        return $this->cohort('reporting_facility_admissions', $filters)->orderBy('subject_id')->get()->map(function ($r) {
            $state = DB::selectOne('SELECT reporting_subject_exception(?) AS exception,reporting_reuse_historical(?) AS historical,reporting_reuse_qualified(?) AS qualified', [$r->subject_id, $r->subject_id, $r->subject_id]);
            $status = $state->exception ? 'current_exception' : ($state->qualified ? 'qualified_reuse_return' : (DB::table('batch_members')->where('subject_id', $r->subject_id)->exists() ? 'batch_linked_outcome' : 'other_pending'));

            // Safe aggregate follow-up contains no destination actors, evidence or batch kilograms.
            return ['row_kind' => 'facility_admission', 'id' => $r->subject_id, 'public_code' => $r->public_code, 'intake_class' => $r->intake_class, 'category' => $r->category, 'subtype' => $r->subtype ?? '', 'observed_at' => $r->observed_at, 'facility' => $r->place_id, 'original_reference' => $r->event_id, 'status' => $status, 'historical_acknowledged' => (int) $state->historical, 'qualified' => (int) $state->qualified];
        })->all();
    }

    public static function grams(?string $kg): ?int
    {
        return $kg === null ? null : Mass::milli($kg, 'kg');
    }

    public static function kg(int $grams): string
    {
        return ($grams < 0 ? '-' : '').Mass::decimal(abs($grams));
    }

    public function summary(string $kind, array $rows): array
    {
        if ($kind === 'collections') {
            $known = array_filter($rows, fn ($r) => $r['estimated_kg'] !== null);

            return ['R01 first received subjects' => count($rows), 'Whole devices' => count(array_filter($rows, fn ($r) => $r['intake_class'] === 'whole_device')), 'Standalone parts' => count(array_filter($rows, fn ($r) => $r['intake_class'] === 'standalone_part')), 'R04 estimated kg' => self::kg(array_sum(array_map(fn ($r) => self::grams($r['estimated_kg']), $known))), 'Estimate coverage' => count($known).'/'.count($rows), 'Unclassifiable estimate ancestry' => count($rows) - count($known)];
        }
        if ($kind === 'facility') {
            $qualified = array_sum(array_column($rows, 'qualified'));
            $summary = ['R02 first-facility subjects' => count($rows), 'R03 current qualified reuse returns' => $qualified, 'Historical acknowledged returns (overlaps current buckets)' => array_sum(array_column($rows, 'historical_acknowledged')), 'Current reuse-return rate' => count($rows) ? number_format($qualified * 100 / count($rows), 2).'%' : 'Not applicable (0 received)', 'Incomplete current outcomes' => count($rows) - $qualified];
            foreach (['qualified_reuse_return', 'batch_linked_outcome', 'other_pending', 'current_exception'] as $status) {
                $summary[$status] = count(array_filter($rows, fn ($r) => $r['status'] === $status));
            }

            return $summary;
        }
        if ($kind === 'batches') {
            return app(BatchReport::class)->summary($rows);
        }

        return [$kind === 'manifest' ? 'Current unconsumed personally assigned stops' : 'Personally authorized incoming slices (non-additive)' => count($rows)];
    }

    private function live(User $actor): array
    {
        return match ($actor->role()) {
            Role::Consumer => ['Outstanding requests' => DB::table('requests as r')->where('consumer_id', $actor->id)->whereExists(fn ($q) => $q->from('request_items')->whereColumn('request_id', 'r.id')->where('active', true)->where('outcome', 'outstanding'))->count()],
            Role::Collector => app(Manifest::class)->work($actor),
            Role::Processor => $this->processorWork($actor),
            Role::Administrator => ['Active restriction cases' => DB::table('restriction_cases')->where('active', true)->count()],
        };
    }

    private function processorWork(User $actor): array
    {
        $rows = app(Context::class)->rows($actor)->whereIn('i.processing_state', ['received', 'assessed', 'inventoried'])->get();
        $ids = $rows->pluck('subject_id')->unique();
        $counts = DB::table('items')->whereIn('subject_id', $ids)->selectRaw('intake_class,count(*) as count')->groupBy('intake_class')->pluck('count', 'intake_class');

        return ['Consumer-origin physical subjects' => (int) ($counts['whole_device'] ?? 0) + (int) ($counts['standalone_part'] ?? 0), 'Processor-origin physical subjects' => (int) ($counts['processor_derived'] ?? 0)];
    }
}
