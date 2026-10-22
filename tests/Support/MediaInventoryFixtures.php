<?php

namespace Tests\Support;

use App\Actions\CustodyHandovers;
use App\Actions\InspectItems;
use App\Actions\InventoryMedia;
use App\Actions\RecordVisit;
use App\Support\MediaInventory\Context;
use Illuminate\Support\Facades\DB;

trait MediaInventoryFixtures
{
    use SanitizationFixtures;

    protected function inventoryWork(string $data = 'YES'): array
    {
        $w = $this->inspectionWork('laptop');
        $s = $w['ids'][0];
        $f = $this->inspectionFields($s, 'laptop-healthy');
        $f['answers']['data_status'] = $data;
        app(InspectItems::class)->inspect($w['receiver'], $s, $f);
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();

        return $w;
    }

    protected function inventoryFields(array $w, string $kind = 'identify'): array
    {
        $s = $w['ids'][0];
        $ctx = app(Context::class);
        $actor = $w['receiver']->fresh();
        $f = $this->handoverEvidence() + ['expected_version' => $ctx->version($s), 'expected_context' => $ctx->token($actor, $ctx->parent($actor, $s)), 'reason' => 'Private synthetic accountable observation, including configuration storage; no physical change or hardware access', 'checks' => array_fill_keys(array_keys(Context::checks($kind)), 'YES')];
        if (in_array($kind, ['identify', 'link', 'unresolved', 'correct'], true)) {
            $f['placement'] = 'Private synthetic bay and distinguishing physical evidence; not model/photo similarity alone';
        }
        if ($kind === 'identify') {
            $f['media_kind'] = 'ssd';
        } elseif ($kind === 'attest') {
            $f['completeness'] = 'YES';
            $f['na_justification'] = 'Synthetic complete scope evidence explicitly establishes no applicable nonvolatile storage when technician NO applies.';
        }

        return $f;
    }

    protected function inventoryRecord(array $w, string $kind = 'identify', array $overrides = []): string
    {
        $id = app(InventoryMedia::class)->record($w['receiver'], $w['ids'][0], $kind, array_replace($this->inventoryFields($w, $kind), $overrides));
        $this->handoverBoundary();

        return $id;
    }

    protected function identifyMedium(array $w, string $kind = 'ssd'): string
    {
        $r = $this->inventoryRecord($w, 'identify', ['media_kind' => $kind]);

        return DB::table('media_inventory_records')->where('id', $r)->value('medium_id');
    }

    protected function authorizeMedium(array $w, string $s): string
    {
        return $this->inventoryRecord($w, 'authorize', ['medium_id' => $s, 'expected_authorization_version' => DB::table('media_authorization_current')->where('subject_id', $s)->value('version') ?? 0]);
    }

    protected function mediumWork(array $w, string $s): array
    {
        return array_replace($w, ['ids' => [$s]]);
    }

    protected function standaloneAtParentFacility(array $w, string $kind = 'ssd'): string
    {
        $drive = $this->device($w['owner'], 'standalone_part', $kind)->subject_id;
        $request = $this->readyRequest($w['admin'], $w['owner'], [$drive], 'drop_off');
        app(RecordVisit::class)->record($w['pointActor'], $request, $this->visitFields($request));
        $this->handoverBoundary();
        $transfer = array_replace($w, ['ids' => [$drive], 'sender' => $w['pointActor']]);
        $id = $this->initiateHandover($transfer);
        app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $this->acknowledgeFields($id));
        $this->handoverBoundary();
        app(InspectItems::class)->inspect($w['receiver'], $drive, $this->inspectionFields($drive, $kind.'-healthy'));
        app(InspectItems::class)->decide($w['receiver'], $drive, $this->decisionFields($drive));
        $this->handoverBoundary();

        return $drive;
    }
}
