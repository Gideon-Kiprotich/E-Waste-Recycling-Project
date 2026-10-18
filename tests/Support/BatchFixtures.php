<?php

namespace Tests\Support;

use App\Actions\DismantleBatches;
use App\Actions\InspectItems;
use App\Support\Batches\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait BatchFixtures
{
    use RefurbishmentFixtures;

    protected function batchWork(int $drives = 1, bool $fan = false): array
    {
        if ($fan) {
            $w = $this->inspectionWork('standalone_part', 'ict_cooling_fan');
            app(InspectItems::class)->inspect($w['receiver'], $w['ids'][0], $this->inspectionFields($w['ids'][0], 'ict_cooling_fan-healthy'));
        } else {
            $w = $this->inventoryWork($drives ? 'YES' : 'NO');
        }
        app(InspectItems::class)->decide($w['receiver'], $w['ids'][0], $this->decisionFields($w['ids'][0], 'recycle'));
        $w['media'] = [];
        if (! $fan) {
            for ($i = 0; $i < $drives; $i++) {
                $w['media'][] = $this->identifyMedium($w, $i % 2 === 0 ? 'hdd' : 'ssd');
            }
            $this->inventoryRecord($w, 'attest');
        }
        $this->handoverBoundary();
        $w['batch'] = Str::uuid()->toString();

        return $w;
    }

    protected function batchFields(array $w, string $kind = 'draft'): array
    {
        $b = DB::table('dismantling_batches')->where('subject_id', $w['batch'])->first();
        $f = $this->handoverEvidence() + ['expected_version' => $b?->version ?? 0, 'expected_context' => app(Context::class)->token($w['receiver']->fresh(), $w['batch']), 'reason' => 'Private synthetic actual activity, measurement and evidence; no physical work executed'];
        if ($kind === 'draft') {
            $f['inputs'] = $w['ids'];
        } elseif ($kind === 'commit') {
            $f += ['unit' => 'kg', 'measure_mode' => 'net', 'net_kg' => '10.000', 'measurement_source' => 'Synthetic scale reference/provenance', 'detached' => $w['media'], 'checks' => array_fill_keys(['actual_dismantling', 'all_media_detached', 'identity_safety'], 'YES')];
        } elseif ($kind === 'output') {
            $f += ['unit' => 'kg', 'measure_mode' => 'net', 'net_kg' => '9.500', 'measurement_source' => 'Synthetic output scale/provenance', 'output_type' => 'material', 'material_code' => 'Synthetic sorted metal', 'checks' => ['actual_output' => 'YES']];
        } elseif ($kind === 'measurement') {
            $target = $w['media'][0] ?? $w['batch'];
            $f += ['unit' => 'kg', 'measure_mode' => 'net', 'net_kg' => '0.500', 'measurement_source' => 'Synthetic corrected scale/provenance', 'target_id' => $target, 'previous_measurement_id' => $target === $w['batch'] ? $b->input_measurement_id : DB::table('batch_outputs')->where('subject_id', $target)->value('measurement_record_id')];
        } elseif ($kind === 'accounted') {
            $f['checks'] = ['all_outputs_accounted' => 'YES'];
        } elseif ($kind === 'discrepancy') {
            $f['checks'] = ['unexpected_media' => 'YES'];
        }

        return $f;
    }

    protected function batchRecord(array $w, string $kind = 'draft', array $overrides = []): string
    {
        $id = app(DismantleBatches::class)->record($w['receiver'], $w['batch'], $kind, array_replace($this->batchFields($w, $kind), $overrides));
        $this->handoverBoundary();

        return $id;
    }
}
