<?php

namespace App\Support\Reporting;

use App\Models\User;
use App\Support\Downstream\Context;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BatchReport
{
    public function rows(array $filters): array
    {
        [$from, $to] = Filters::bounds($filters);
        $q = DB::table('dismantling_batches as b')->join('batch_records as committed', 'committed.id', '=', 'b.commit_record_id')->join('subjects as s', 's.id', '=', 'b.subject_id')->join('parties as p', 'p.id', '=', 'b.facility_party_id')->where('committed.observed_at', '>=', $from)->where('committed.observed_at', '<', $to)->orderBy('b.subject_id');
        if ($filters['facility'] !== '') {
            $q->where('p.place_id', $filters['facility']);
        }
        $rows = [];
        foreach ($q->get(['b.*', 's.public_code', 'committed.observed_at', 'p.place_id']) as $b) {
            $matches = DB::table('batch_members as m')->join('items as i', 'i.subject_id', '=', 'm.subject_id')->join('categories as c', 'c.id', '=', 'i.category_id')->where('m.batch_id', $b->subject_id)->get(['c.code', 'i.subtype']);
            $selected = $matches->filter(fn ($r) => ($filters['category'] === '' || $r->code === $filters['category']) && ($filters['subtype'] === '' || $r->subtype === $filters['subtype']));
            if ($selected->count() !== $matches->count()) {
                if ($selected->isNotEmpty()) {
                    $rows[] = ['row_kind' => 'excluded_mixed_category', 'id' => $b->subject_id, 'public_code' => $b->public_code, 'status' => 'Excluded mixed-category batch; no proportional mass'];
                }

                continue;
            }
            $input = DB::table('downstream_effective_measurements')->where('id', $b->input_measurement_id)->first(['id', 'net_kg']);
            $original = DB::table('batch_records')->where('id', $b->input_measurement_id)->value('net_kg');
            $outputs = $this->outputs($b->subject_id);
            $unknown = count(array_filter($outputs, fn ($r) => $r['produced_kg'] === null));
            $produced = array_sum(array_map(fn ($r) => Reports::grams($r['produced_kg']), $outputs));
            $status = DB::selectOne('SELECT downstream_status(?) AS status,downstream_closed(?) AS closed', [$b->subject_id, $b->subject_id]);
            $inputGrams = Reports::grams($input?->net_kg);
            $balance = json_decode(DB::selectOne('SELECT batch_balance(?) AS balance', [$b->subject_id])->balance, true, flags: JSON_THROW_ON_ERROR);
            $blockers = [];
            if ($unknown || ! count($outputs)) {
                $blockers[] = 'Pending measurement/accounting';
            }
            if ($unknown === 0 && count($outputs) > 0 && ($balance['excess_variance'] ?? false)) {
                $blockers[] = 'Excess variance';
            }
            if (DB::selectOne('SELECT downstream_blocked(?) AS blocked', [$b->subject_id])->blocked) {
                $blockers[] = 'Current evidence or obligation exception';
            }
            $recordedStatus = $status->status;
            if ($status->closed && array_sum(array_map(fn ($r) => Reports::grams($r['disputed_recovered_kg']) + Reports::grams($r['disputed_disposed_kg']) + Reports::grams($r['disputed_controlled_storage_kg']), $outputs)) > 0) {
                $status->status = 'closed_later_evidence_exception';
            }
            $closure = DB::table('downstream_records')->where('batch_id', $b->subject_id)->where('kind', 'close')->first(['id', 'observed_at']);
            $latestReview = DB::table('downstream_review_effects as effect')->join('restriction_records as reviewed', 'reviewed.id', '=', 'effect.record_id')->join('restriction_cases as c', 'c.id', '=', 'effect.case_id')->where('c.subject_id', $b->subject_id)->orderByDesc('reviewed.recorded_at')->orderByDesc('reviewed.id')->first(['reviewed.id', 'reviewed.recorded_at']);
            $rows[] = ['recorded_status' => $recordedStatus, 'closure_reference' => $closure?->id, 'closure_observed_at' => $closure?->observed_at, 'latest_review_reference' => $latestReview?->id, 'latest_review_recorded_at' => $latestReview?->recorded_at, 'row_kind' => 'batch_summary', 'id' => $b->subject_id, 'public_code' => $b->public_code, 'facility' => $b->place_id, 'observed_at' => $b->observed_at, 'cohort_reference' => $b->commit_record_id, 'original_reference' => $b->input_measurement_id, 'effective_reference' => $this->measurementReference($b->input_measurement_id),
                'original_input_kg' => $original, 'input_kg' => $input?->net_kg, 'produced_kg' => Reports::kg($produced), 'unknown_outputs' => $unknown, 'measured_outputs' => count($outputs) - $unknown, 'eligible_outputs' => count($outputs),
                'variance_kg' => $unknown || ! count($outputs) || $inputGrams === null ? null : Reports::kg($inputGrams - $produced),
                'tolerance_kg' => $inputGrams === null ? null : number_format(max(100, $inputGrams * .02) / 1000, 3, '.', ''), 'status' => $status->status, 'historical_closed' => (int) $status->closed,
                'blockers_non_additive' => implode('|', $blockers), 'review_references' => DB::table('downstream_review_effects as e')->join('restriction_cases as c', 'c.id', '=', 'e.case_id')->where('c.subject_id', $b->subject_id)->orderBy('e.record_id')->pluck('e.record_id')->implode('|')];
            $details = array_filter($outputs, fn ($r) => ($filters['output_type'] === '' || $r['output_type'] === $filters['output_type']) && ($filters['material'] === '' || $r['material'] === $filters['material']));
            array_push($rows, ...$details);
        }

        return $rows;
    }

    private function measurementReference(?string $anchor): ?string
    {
        return $anchor === null ? null : (DB::table('downstream_fact_revisions')->where('anchor_kind', 'batch')->where('anchor_id', $anchor)->where('fact', 'net_kg')->orderByDesc('version')->value('review_id') ?? $anchor);
    }

    private function outputs(string $batch): array
    {
        $rows = [];
        $blocked = DB::selectOne('SELECT downstream_blocked(?) AS blocked', [$batch])->blocked;
        foreach (DB::table('batch_outputs')->where('batch_id', $batch)->orderBy('subject_id')->get() as $o) {
            $stock = collect(DB::select('SELECT key,value FROM jsonb_each_text(downstream_stock(?))', [$o->subject_id]))->pluck('value', 'key');
            $row = ['row_kind' => 'output_detail', 'id' => $o->subject_id, 'batch_id' => $batch, 'output_type' => $o->type, 'material' => $o->material_code, 'production_reference' => $o->production_record_id, 'original_reference' => $o->measurement_record_id, 'effective_reference' => $this->measurementReference($o->measurement_record_id),
                'original_produced_kg' => DB::table('batch_records')->where('id', $o->measurement_record_id)->value('net_kg')];
            foreach (['produced', 'dispatched', 'source_loss', 'reserved', 'accounted', 'reservable'] as $name) {
                $row[$name.'_kg'] = isset($stock[$name]) ? Reports::kg(Reports::grams($stock[$name])) : null;
            }
            foreach (['confirmed_source_loss', 'reported_unconfirmed_loss', 'accepted', 'refused', 'shortage', 'disputed', 'transit_loss', 'unreported', 'recovered', 'disposed', 'controlled_storage', 'accepted_loss', 'pending', 'original_recovered', 'qualified_recovered', 'disputed_recovered', 'original_disposed', 'qualified_disposed', 'disputed_disposed', 'original_controlled_storage', 'qualified_controlled_storage', 'disputed_controlled_storage'] as $key) {
                $row[$key.'_kg'] = '0.000';
            }
            $records = DB::table('downstream_effective_records')->where('output_id', $o->subject_id)->orderBy('id')->get();
            foreach ($records as $r) {
                $kg = Reports::grams($r->kg) ?? 0;
                $lossConfirmed = ! in_array($r->kind, ['source_loss', 'downstream_loss'], true) || DB::selectOne('SELECT downstream_loss_reviewed(?) AS confirmed', [$r->id])->confirmed;
                if (! $lossConfirmed) {
                    $row['reported_unconfirmed_loss_kg'] = Reports::kg(Reports::grams($row['reported_unconfirmed_loss_kg']) + $kg);
                }
                $bucket = match ($r->kind) {
                    'accept' => 'accepted', 'refuse' => 'refused', 'shortage' => 'shortage', 'dispute' => 'disputed', 'complete' => $r->outcome,
                    'source_loss' => $lossConfirmed ? 'confirmed_source_loss' : null,
                    'downstream_loss' => $records->firstWhere('id', $r->related_record_id)?->kind === 'dispatch' ? ($lossConfirmed ? 'transit_loss' : 'disputed') : ($lossConfirmed ? 'accepted_loss' : null), default => null,
                };
                if ($bucket !== null) {
                    $row[$bucket.'_kg'] = Reports::kg(Reports::grams($row[$bucket.'_kg']) + $kg);
                }
                if ($r->kind === 'complete') {
                    $original = DB::table('downstream_records')->where('id', $r->id)->value('kg');
                    $row['original_'.$r->outcome.'_kg'] = Reports::kg(Reports::grams($row['original_'.$r->outcome.'_kg']) + Reports::grams($original));
                    $redacted = $r->attachment_id && DB::table('attachments')->where('id', $r->attachment_id)->whereNotNull('redacted_at')->exists();
                    $mediaUnqualified = $o->type === 'storage' && DB::selectOne("SELECT sanitization_state(?)<>'satisfied' OR reporting_media_evidence_missing(?) AS missing", [$o->subject_id, $o->subject_id])->missing;
                    $key = ($blocked || $redacted || $mediaUnqualified ? 'disputed_' : 'qualified_').$r->outcome.'_kg';
                    $row[$key] = Reports::kg(Reports::grams($row[$key]) + $kg);
                }
            }
            $row['unreported_kg'] = Reports::kg(Reports::grams($row['dispatched_kg']) - array_sum(array_map(fn ($k) => Reports::grams($row[$k.'_kg']), ['accepted', 'refused', 'shortage', 'disputed', 'transit_loss'])));
            $row['pending_kg'] = Reports::kg(Reports::grams($row['accepted_kg']) - array_sum(array_map(fn ($k) => Reports::grams($row[$k.'_kg']), ['recovered', 'disposed', 'controlled_storage', 'accepted_loss'])));
            $row['total_lost_kg'] = Reports::kg(array_sum(array_map(fn ($k) => Reports::grams($row[$k.'_kg']), ['confirmed_source_loss', 'transit_loss', 'accepted_loss'])));
            foreach (['accounted_kg', 'reservable_kg', 'unreported_kg', 'pending_kg'] as $key) {
                abort_if($row[$key] !== null && str_starts_with($row[$key], '-'), 409, 'Report integrity requires review; no negative allocation was hidden.');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function summary(array $rows): array
    {
        $batches = array_filter($rows, fn ($r) => $r['row_kind'] === 'batch_summary');
        $outputs = array_filter($rows, fn ($r) => $r['row_kind'] === 'output_detail');
        $summary = ['Batches' => count($batches), 'Excluded mixed-category batches' => count(array_filter($rows, fn ($r) => $r['row_kind'] === 'excluded_mixed_category')),
            'R05 measured input kg' => Reports::kg(array_sum(array_map(fn ($r) => Reports::grams($r['input_kg']), $batches))), 'Input measurement coverage' => count(array_filter($batches, fn ($r) => $r['input_kg'] !== null)).'/'.count($batches),
            'Unmeasured outputs' => array_sum(array_column($batches, 'unknown_outputs')), 'R08 historical closed (overlapping)' => array_sum(array_column($batches, 'historical_closed'))];
        foreach (['produced', 'dispatched', 'source_loss', 'reserved', 'accounted', 'reservable', 'confirmed_source_loss', 'reported_unconfirmed_loss', 'accepted', 'refused', 'shortage', 'disputed', 'transit_loss', 'unreported', 'recovered', 'disposed', 'controlled_storage', 'accepted_loss', 'pending', 'total_lost', 'qualified_recovered', 'disputed_recovered', 'qualified_disposed', 'disputed_disposed', 'qualified_controlled_storage', 'disputed_controlled_storage'] as $key) {
            $summary[$key.' kg (known outputs)'] = Reports::kg(array_sum(array_map(fn ($r) => Reports::grams($r[$key.'_kg']), $outputs)));
        }
        foreach (['Pending measurement/accounting', 'Excess variance', 'Current evidence or obligation exception'] as $reason) {
            $summary['Blocker (non-additive): '.$reason] = count(array_filter($batches, fn ($r) => str_contains($r['blockers_non_additive'], $reason)));
        }
        foreach (['open', 'closed_recorded_outcomes', 'closed_recorded_outcomes_with_loss', 'closed_reviewed_accounting', 'closed_later_evidence_exception'] as $state) {
            $summary[$state] = count(array_filter($batches, fn ($r) => $state === 'open' ? ! str_starts_with($r['status'], 'closed_') : $r['status'] === $state));
        }

        return $summary;
    }

    public function incoming(User $actor, array $filters): array
    {
        [$from, $to] = Filters::bounds($filters);
        $rows = [];
        foreach (DB::table('downstream_effective_shipments as h')->join('downstream_records as created', 'created.id', '=', 'h.created_record_id')->where('h.receiver_actor_id', $actor->id)->where('created.observed_at', '>=', $from)->where('created.observed_at', '<', $to)->orderBy('h.id')->get(['h.id']) as $h) {
            try {
                $shipment = app(Context::class)->shipment($actor, $h->id);
            } catch (HttpExceptionInterface|ModelNotFoundException) {
                continue;
            }
            foreach (DB::table('downstream_effective_records')->where('shipment_id', $shipment->id)->whereIn('kind', ['dispatch', 'accept', 'refuse', 'shortage', 'dispute', 'complete', 'downstream_loss'])->orderBy('id')->get() as $r) {
                $rows[] = ['row_kind' => 'incoming_slice_non_additive', 'id' => $r->id, 'shipment_id' => $shipment->id, 'status' => $r->kind, 'observed_at' => $r->observed_at, 'quantity_kg' => $r->kg, 'outcome' => $r->outcome ?? '', 'original_reference' => $r->event_id];
            }
        }

        return $rows;
    }
}
