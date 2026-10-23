<?php

namespace Tests\Feature;

use App\Actions\ManageOutputProfiles;
use App\Actions\MoveBatchOutputs;
use App\Support\Reporting\Reports;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DownstreamCorrectionFixtures;
use Tests\Support\ReportingSchema;
use Tests\TestCase;

class ReportingAccountingTest extends TestCase
{
    use DownstreamCorrectionFixtures,ReportingSchema;

    public function test_independent_original_and_effective_full_vector_loss_disposal_storage_reconciliation(): void
    {
        $drive = $this->downstreamWork(true);
        $material = array_replace($drive, ['media' => [], 'output' => DB::table('batch_outputs')->where('batch_id', $drive['batch'])->where('type', 'material')->value('subject_id'), 'profile' => (string) Str::uuid()]);
        $material['profile_record'] = app(ManageOutputProfiles::class)->record($material['admin'], $material['profile'], $this->profileFields($material));
        $this->handoverBoundary();
        $input = DB::table('dismantling_batches')->where('subject_id', $drive['batch'])->value('input_measurement_id');
        $this->batchRecord($drive, 'measurement', ['target_id' => $drive['batch'], 'previous_measurement_id' => $input, 'net_kg' => '10.100']);
        $measurement = DB::table('batch_outputs')->where('subject_id', $material['output'])->value('measurement_record_id');
        $this->batchRecord($drive, 'measurement', ['target_id' => $material['output'], 'previous_measurement_id' => $measurement, 'net_kg' => '9.600']);
        $this->batchRecord($drive, 'accounted');
        $h = $this->shipmentDraft($material, ['kg' => '7.600']);
        $reserve = $this->downstreamRecord($material, 'reserve', $h, overrides: ['kg' => '7.600']);
        $dispatch = $this->downstreamRecord($material, 'dispatch', $h, overrides: ['kg' => '7.600']);
        $sourceLoss = $this->downstreamRecord($material, 'source_loss', overrides: ['kg' => '2.000']);
        $this->correction($material, $sourceLoss, 'loss_confirm', [$this->fact('downstream', $sourceLoss, 'loss_status', 'confirmed')], false, ['quantified_loss' => 'YES']);
        $this->downstreamContinuities($material);
        $accept = $this->downstreamRecord($material, 'accept', $h, true, ['kg' => '6.100']);
        $transit = $this->downstreamRecord($material, 'downstream_loss', $h, true, ['kg' => '1.500', 'related_record_id' => $dispatch]);
        $this->correction($material, $transit, 'loss_confirm', [$this->fact('downstream', $transit, 'loss_status', 'confirmed')], facts: ['quantified_loss' => 'YES']);
        $this->downstreamContinuities($material);
        $recovered = $this->downstreamRecord($material, 'complete', $h, true, ['kg' => '4.100']);
        $this->downstreamRecord($material, 'complete', $h, true, ['kg' => '1.500', 'outcome' => 'disposed']);
        $acceptedLoss = $this->downstreamRecord($material, 'downstream_loss', $h, true, ['kg' => '0.500', 'related_record_id' => $accept]);
        $this->correction($material, $acceptedLoss, 'loss_confirm', [$this->fact('downstream', $acceptedLoss, 'loss_status', 'confirmed')], facts: ['quantified_loss' => 'YES']);
        $this->downstreamContinuities($material);
        $measurement = DB::table('batch_outputs')->where('subject_id', $material['output'])->value('measurement_record_id');
        $input = DB::table('dismantling_batches')->where('subject_id', $drive['batch'])->value('input_measurement_id');
        $this->correction($material, $measurement, 'measurement', [
            $this->fact('batch', $input, 'net_kg', '10.000'), $this->fact('batch', $measurement, 'net_kg', '9.500'),
            $this->fact('downstream', $reserve, 'kg', '7.500'), $this->fact('downstream', $dispatch, 'kg', '7.500'),
            $this->fact('downstream', $accept, 'kg', '6.000'), $this->fact('downstream', $recovered, 'kg', '4.000'),
        ], false, ['original_error' => 'YES', 'same_allocation' => 'YES'], both: true);
        $cases = $this->downstreamContinuities($material, false);
        $this->batchRecord($drive, 'accounted');
        $this->fulfillDownstream($material, $cases);
        $this->downstreamAuthorize($drive, $drive['receiver']);
        $this->mediaSatisfied($this->mediumWork($drive, $drive['output']));
        $dh = $this->shipmentDraft($drive);
        $this->downstreamRecord($drive, 'reserve', $dh);
        $this->downstreamRecord($drive, 'dispatch', $dh);
        $this->downstreamRecord($drive, 'accept', $dh, true);
        $this->downstreamAuthorize($drive, $drive['recipient']);
        $this->mediaRecord(array_replace($this->mediumWork($drive, $drive['output']), ['receiver' => $drive['recipient']]), 'revalidation');
        $this->downstreamRecord($drive, 'complete', $dh, true);
        $this->downstreamRecord($drive, 'close');
        $snapshot = app(Reports::class)->snapshot($drive['receiver'], ['kind' => 'batches']);
        $s = $snapshot['summary'];
        foreach (['R05 measured input kg' => '10.000', 'produced kg (known outputs)' => '10.000', 'dispatched kg (known outputs)' => '8.000', 'source_loss kg (known outputs)' => '2.000', 'reserved kg (known outputs)' => '0.000', 'accounted kg (known outputs)' => '0.000', 'reservable kg (known outputs)' => '0.000', 'accepted kg (known outputs)' => '6.500', 'recovered kg (known outputs)' => '4.000', 'disposed kg (known outputs)' => '1.500', 'controlled_storage kg (known outputs)' => '0.500', 'transit_loss kg (known outputs)' => '1.500', 'accepted_loss kg (known outputs)' => '0.500', 'total_lost kg (known outputs)' => '4.000', 'pending kg (known outputs)' => '0.000', 'unreported kg (known outputs)' => '0.000'] as $metric => $expected) {
            $this->assertSame($expected, $s[$metric], $metric);
        }
        $summary = collect($snapshot['rows'])->firstWhere('row_kind', 'batch_summary');
        $this->assertSame('10.100', $summary['original_input_kg']);
        $this->assertSame('0.000', $summary['variance_kg']);
        $this->assertSame('closed_recorded_outcomes_with_loss', $summary['status']);
        $output = collect($snapshot['rows'])->firstWhere('id', $material['output']);
        $this->assertSame('4.100', $output['original_recovered_kg']);
        $this->assertSame('4.000', $s['qualified_recovered kg (known outputs)']);
        $this->assertSame('1.500', $s['qualified_disposed kg (known outputs)']);
        $this->assertSame('0.500', $s['qualified_controlled_storage kg (known outputs)']);
        $this->assertSame(4000 + 1500 + 500 + 4000, 10000);
        $this->otherBatches();
        $combined = app(Reports::class)->snapshot($drive['admin'], ['kind' => 'batches'])['summary'];
        $this->assertSame(4, $combined['Batches']);
        $this->assertSame(2, $combined['R08 historical closed (overlapping)']);
        $this->assertSame(2, $combined['open']);
        $this->assertSame(1, $combined['closed_recorded_outcomes_with_loss']);
        $this->assertSame(1, $combined['closed_later_evidence_exception']);

    }

    private function otherBatches(): array
    {
        $b2 = $this->batchWork(0);
        $this->batchRecord($b2);
        $this->batchRecord($b2, 'commit', ['net_kg' => '5.000']);
        $this->batchRecord($b2, 'output', ['net_kg' => '4.800']);
        $this->batchRecord($b2, 'output', ['measure_mode' => 'unknown', 'net_kg' => null, 'measurement_source' => null, 'material_code' => 'Synthetic unmeasured separate output']);
        $b3 = $this->batchWork(0);
        $this->batchRecord($b3);
        $this->batchRecord($b3, 'commit', ['net_kg' => '2.000']);
        $this->batchRecord($b3, 'output', ['net_kg' => '3.000']);
        $b4 = $this->downstreamWork();
        $h = $this->shipmentDraft($b4);
        foreach (['reserve', 'dispatch', 'accept', 'complete'] as $kind) {
            $this->downstreamRecord($b4, $kind, $h, in_array($kind, ['accept', 'complete'], true));
        }
        $this->downstreamRecord($b4, 'close');
        $this->downstreamRecord($b4, 'measurement_dispute');

        return [$b2, $b3, $b4];
    }

    public function test_current_disputed_completion_proof_retains_original_closure_and_stock(): void
    {
        Storage::fake('directory_evidence');
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        foreach (['reserve', 'dispatch', 'accept'] as $kind) {
            $this->downstreamRecord($w, $kind, $h, $kind === 'accept');
        }
        $fields = $this->downstreamFields($w, 'complete', $h, true);
        $fields['evidence_kind'] = 'attachment';
        unset($fields['reference_issuer'],$fields['reference_code'],$fields['reference_attested']);
        $completed = app(MoveBatchOutputs::class)->record($w['recipient'], $w['batch'], 'complete', $fields, UploadedFile::fake()->image('recorded-evidence.jpg'));
        $this->handoverBoundary();
        $closed = $this->downstreamRecord($w, 'close');
        $attachment = DB::table('downstream_records')->where('id', $completed)->value('attachment_id');
        DB::table('attachments')->where('id', $attachment)->update(['redacted_at' => now()]);
        $snapshot = app(Reports::class)->snapshot($w['receiver'], ['kind' => 'batches']);
        $s = $snapshot['summary'];
        $this->assertSame('10.000', $s['recovered kg (known outputs)']);
        $this->assertSame('0.000', $s['qualified_recovered kg (known outputs)']);
        $this->assertSame('10.000', $s['disputed_recovered kg (known outputs)']);
        $this->assertSame('0.000', $s['accounted kg (known outputs)']);
        $this->assertSame(1, $s['R08 historical closed (overlapping)']);
        $this->assertSame(1, $s['closed_later_evidence_exception']);
        $row = collect($snapshot['rows'])->firstWhere('row_kind', 'batch_summary');
        $this->assertSame($closed, $row['closure_reference']);
        $this->assertNotNull($row['closure_observed_at']);
        $this->assertSame('closed_recorded_outcomes', $row['recorded_status']);
        $this->assertSame('closed_recorded_outcomes', DB::selectOne('SELECT downstream_status(?) status', [$w['batch']])->status);
        $subject = DB::table('batch_members')->where('batch_id', $w['batch'])->value('subject_id');
        $this->receiptSignIn($w['owner'])->get(route('timeline.show', $subject))->assertOk()->assertSee('Current exception requires authorized review')->assertDontSee('10.000');
    }

    public function test_unknown_measurement_negative_variance_and_historical_closure_are_independent_buckets(): void
    {
        [$b2,$b3,$b4] = $this->otherBatches();
        $r = app(Reports::class)->snapshot($b4['admin'], ['kind' => 'batches']);
        $rows = collect($r['rows'])->where('row_kind', 'batch_summary')->keyBy('id');
        $this->assertSame('5.000', $rows[$b2['batch']]['input_kg']);
        $this->assertSame(1, $rows[$b2['batch']]['unknown_outputs']);
        $this->assertSame('4.800', $rows[$b2['batch']]['produced_kg']);
        $this->assertNull($rows[$b2['batch']]['variance_kg']);
        $this->assertSame('-1.000', $rows[$b3['batch']]['variance_kg']);
        $this->assertSame('closed_later_evidence_exception', $rows[$b4['batch']]['status']);
        $this->assertSame(3, $r['summary']['Batches']);
        $this->assertSame(2, $r['summary']['open']);
        $this->assertSame(1, $r['summary']['closed_later_evidence_exception']);
        $this->assertSame(1, $r['summary']['R08 historical closed (overlapping)']);
        $this->receiptSignIn($b2['owner'])->get(route('reports.index', ['kind' => 'batches']))->assertForbidden();
        $incoming = app(Reports::class)->snapshot($b4['recipient'], ['kind' => 'incoming']);
        $this->assertNotEmpty($incoming['rows']);
        foreach ($incoming['rows'] as $row) {
            $this->assertArrayNotHasKey('input_kg', $row);
            $this->assertArrayNotHasKey('produced_kg', $row);
        }
    }
}
