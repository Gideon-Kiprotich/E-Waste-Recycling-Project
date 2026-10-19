<?php

namespace Tests\Support;

use App\Actions\AuthorizeBatchMedia;
use App\Actions\ManageOutputProfiles;
use App\Actions\MoveBatchOutputs;
use App\Models\User;
use App\Support\Downstream\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait DownstreamFixtures
{
    use BatchFixtures;

    protected function downstreamWork(bool $drive = false): array
    {
        $w = $this->batchWork($drive ? 1 : 0);
        $w['destination'] = $this->facility($w['point']);
        $w['recipient'] = $this->pointStaff($w['admin'], $w['destination']);
        $this->handoverBoundary();
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->batchRecord($w, 'output', ['net_kg' => $drive ? '9.500' : '10.000']);
        if ($drive) {
            $this->batchRecord($w, 'measurement');
            $this->downstreamAuthorize($w, $w['receiver']);
            $this->mediaSatisfied($this->mediumWork($w, $w['media'][0]));
        }
        $this->batchRecord($w, 'accounted');
        $w['output'] = $drive ? $w['media'][0] : DB::table('batch_outputs')->where('batch_id', $w['batch'])->where('type', 'material')->value('subject_id');
        $w['profile'] = Str::uuid()->toString();
        $f = $this->profileFields($w);
        $w['profile_record'] = app(ManageOutputProfiles::class)->record($w['admin'], $w['profile'], $f);
        $this->handoverBoundary();

        return $w;
    }

    protected function profileFields(array $w): array
    {
        $p = DB::table('output_profiles')->where('id', $w['profile'])->first();

        return $this->handoverEvidence() + ['expected_version' => $p?->version ?? 0, 'expected_context' => app(ManageOutputProfiles::class)->token($w['profile']), 'attested' => '1', 'reason' => 'Synthetic reviewed receiving scope, no operational certification', 'facility_id' => $w['destination'], 'output_type' => count($w['media']) ? 'storage' : 'material', 'material_key' => count($w['media']) ? 'canonical_storage' : 'synthetic sorted metal', 'hazards' => 'NONE', 'approved' => '1'];
    }

    protected function downstreamFields(array $w, string $kind, ?string $shipment = null, bool $receiving = false): array
    {
        $ctx = app(Context::class);
        $actor = ($receiving ? $w['recipient'] : $w['receiver'])->fresh();
        $f = $this->handoverEvidence() + ['expected_version' => $ctx->version($w['batch']), 'expected_context' => $ctx->token($w['batch'], $actor, $shipment), 'reason' => 'Synthetic private actual movement and evidence', 'attested' => '1'];
        if ($kind === 'draft') {
            $f += ['output_id' => $w['output'], 'profile_record_id' => $w['profile_record'], 'receiver_actor_id' => $w['recipient']->id, 'kg' => count($w['media']) ? '0.500' : '10.000', 'hazards' => 'NONE'];
        } elseif (! in_array($kind, ['close', 'measurement_dispute'], true)) {
            $f['kg'] = count($w['media']) ? '0.500' : '10.000';
            $f += $kind === 'source_loss' ? ['output_id' => $w['output']] : ['shipment_id' => $shipment];
            if (in_array($kind, ['accept', 'refuse', 'shortage', 'dispute', 'complete', 'downstream_loss'], true)) {
                $f['related_record_id'] = DB::table('downstream_records')->where('shipment_id', $shipment)->where('kind', $kind === 'complete' ? 'accept' : 'dispatch')->orderByDesc('version')->value('id');
            }
            if ($kind === 'accept') {
                $f['condition'] = 'intact';
                $f['identity_code'] = DB::table('subjects')->where('id', $w['output'])->value('public_code');
            }
            if ($kind === 'complete') {
                $f += ['outcome' => count($w['media']) ? 'controlled_storage' : 'recovered', 'issuer' => 'Synthetic actual recipient assertion'];
            }
        }

        return $f;
    }

    protected function downstreamRecord(array $w, string $kind, ?string $shipment = null, bool $receiving = false, array $overrides = []): string
    {
        $id = app(MoveBatchOutputs::class)->record(($receiving ? $w['recipient'] : $w['receiver'])->fresh(), $w['batch'], $kind, array_replace($this->downstreamFields($w, $kind, $shipment, $receiving), $overrides));
        $this->handoverBoundary();

        return $id;
    }

    protected function shipmentDraft(array $w, array $overrides = []): string
    {
        $id = $this->downstreamRecord($w, 'draft', overrides: $overrides);

        return DB::table('downstream_records')->where('id', $id)->value('shipment_id');
    }

    protected function downstreamAuthorize(array $w, User $actor): void
    {
        $a = app(AuthorizeBatchMedia::class);
        $s = $w['media'][0];
        $f = $this->handoverEvidence() + ['expected_version' => DB::table('media_authorization_current')->where('subject_id', $s)->value('version') ?? 0, 'expected_context' => $a->token($actor->fresh(), $w['batch'], $s), 'reason' => 'Synthetic evidenced canonical batch/received control', 'checks' => array_fill_keys(['identity_confirmed', 'safe_media', 'method_scope_known', 'no_unresolved_restriction'], 'YES')];
        $a->record($actor, $w['batch'], $s, $f);
        $this->handoverBoundary();
    }
}
