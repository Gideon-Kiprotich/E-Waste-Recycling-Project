<?php

namespace App\Support\Sanitization;

use App\Models\User;
use App\Support\Collection\AdminMutation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Context
{
    public function blockers(object $row, ?User $actor = null): array
    {
        if (Schema::hasTable('batch_detachments') && DB::table('batch_detachments')->where('subject_id', $row->subject_id)->exists()) {
            return DB::selectOne('SELECT sanitization_ready(?,?) AS ready', [$row->subject_id, $actor?->id ?? auth()->id()])->ready
                ? [] : ['Detached media requires current batch-controlled authorization. Open Dismantling / Batches; retained restrictions and unexpected media cannot be cleared here.'];
        }
        if ($row->category === 'processor_media') {
            if ($row->subtype === 'other_nonvolatile') {
                return ['Treatment support unavailable. This medium is inventory-only; no HDD/SSD method eligibility is implied.'];
            }

            return DB::selectOne('SELECT sanitization_ready(?,?) AS ready', [$row->subject_id, $actor?->id ?? auth()->id() ?? '00000000-0000-0000-0000-000000000000'])->ready
                ? [] : ['Current parent-based treatment authorization is required. Open the parent Media inventory, attest current facts and Authorize media treatment; unresolved restrictions cannot be cleared here.'];
        }
        if ($row->category !== 'standalone_part') {
            return ['Whole-device treatment is recorded per identified medium. Open Media inventory to review coverage; direct parent attempts are not supported.'];
        }
        if (! in_array($row->subtype, ['hdd', 'ssd'], true) || ! DB::table('storage_assets')->where('subject_id', $row->subject_id)->where('media_kind', $row->subtype)->exists()) {
            return ['Only existing canonical standalone HDD/SSD storage facets are supported. Fans are outside this media workflow.'];
        }
        $inspection = app(\App\Support\Assessments\Context::class);
        $blockers = $inspection->blockers($row);
        if ($inspection->restricted($row->subject_id)) {
            $blockers[] = 'An inspection hard restriction remains unresolved. Treatment records cannot clear it; the reviewed correction workflow is not implemented.';
        }
        $decision = DB::table('assessment_current as ac')->join('decisions as d', 'd.id', '=', 'ac.decision_id')->where('ac.subject_id', $row->subject_id)->whereColumn('d.assessment_id', 'ac.assessment_id')->first(['d.pathway']);
        if (! $decision || $decision->pathway === 'hold') {
            $blockers[] = 'A current non-HOLD human pathway decision is required by project policy. Open Assessments / Inspection and record a fresh decision for the latest inspection.';
        }

        return $blockers;
    }

    public function snapshot(User $actor, object $row): array
    {
        $detachment = Schema::hasTable('batch_detachments') ? DB::table('batch_detachments')->where('subject_id', $row->subject_id)->first() : null;
        $authorization = ($detachment || $row->category === 'processor_media') ? DB::table('media_authorization_current as mc')->join('media_authorizations as a', 'a.id', '=', 'mc.record_id')->where('mc.subject_id', $row->subject_id)->select('a.*')->first() : null;
        $ac = DB::table('assessment_current')->where('subject_id', $detachment?->parent_id ?? $authorization?->parent_id ?? $row->subject_id)->first();
        $place = DB::table('parties as p')->join('places as l', 'l.id', '=', 'p.place_id')->join('organizations as o', 'o.id', '=', 'l.organization_id')->where('p.id', $row->party_id)->first(['l.version as facility_version', 'o.version as operator_version']);

        return ['placement_record_id' => DB::table('media_inventory_entries')->where('medium_id', $row->subject_id)->value('record_id'), 'authorization_context_version' => $detachment ? 3 : ($row->category === 'processor_media' ? 2 : 1), 'authorization_id' => $authorization?->id, 'source_subject_version' => (int) $row->subject_version, 'assessment_version' => (int) ($ac?->version ?? 0), 'assessment_id' => $ac?->assessment_id, 'decision_id' => $ac?->decision_id, 'facility_party_id' => $row->party_id, 'facility_version' => (int) $place->facility_version, 'operator_version' => (int) $place->operator_version, 'custody_event_id' => $row->last_event_id, 'custody_version' => (int) $row->custody_version, 'membership_id' => $actor->membership->id, 'membership_version' => (int) $actor->membership->version, 'actor_session_version' => (int) $actor->session_version];
    }

    public function token(User $actor, object $row): string
    {
        return AdminMutation::hash($this->snapshot($actor, $row));
    }

    public function current(string $subject): ?object
    {
        return DB::table('sanitization_current as c')->join('sanitization_records as r', 'r.id', '=', 'c.record_id')->where('c.subject_id', $subject)->select('r.*', 'c.destruction_reported')->selectRaw('sanitization_dependencies(r) AS applicable')->first();
    }

    public function state(string $subject): string
    {
        return DB::selectOne('SELECT sanitization_state(?) AS state', [$subject])->state;
    }

    public static function labels(): array
    {
        return ['required' => 'Data treatment required', 'pending_verification' => 'Reported success — verification pending', 'failed' => 'Failed — new attempt required', 'incomplete' => 'Incomplete — new attempt required', 'unverifiable' => 'Unverifiable — new attempt required', 'unsatisfactory' => 'Verification unsatisfactory — new attempt required', 'pending_validation' => 'Execution verified — adequacy validation pending', 'rejected' => 'Validation rejected — new attempt required', 'satisfied' => 'Data treatment satisfied (attested)', 'review_required' => 'Applicability review required — no current clearance'];
    }

    public function actions(?object $current): array
    {
        $actions = ['attempt' => 'Record external attempt'];
        if (! $current) {
            return $actions;
        }
        if (($current->kind === 'attempt' && $current->outcome === 'success') || ($current->kind === 'verification' && $current->outcome === 'satisfactory' && ! $current->applicable)) {
            $actions['verification'] = 'Record verification';
        }
        if ($current->kind === 'verification' && $current->outcome === 'satisfactory' && $current->applicable) {
            $actions['validation'] = 'Record validation';
        }
        if (in_array($current->kind, ['validation', 'revalidation'], true) && $current->outcome === 'accepted' && ! $current->applicable) {
            $actions['revalidation'] = 'Revalidate applicability';
        }

        return $actions;
    }
}
