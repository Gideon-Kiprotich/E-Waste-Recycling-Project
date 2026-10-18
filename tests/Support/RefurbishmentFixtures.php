<?php

namespace Tests\Support;

use App\Actions\RefurbishItems;
use App\Support\Refurbishment\Context;
use App\Support\Refurbishment\Profiles;

trait RefurbishmentFixtures
{
    use MediaInventoryFixtures;

    protected function reuseFields(array $w, string $kind = 'work', bool $owner = false): array
    {
        $s = $w['ids'][0];
        $actor = ($owner ? $w['owner'] : $w['receiver'])->fresh();
        $ctx = app(Context::class);
        $f = $this->handoverEvidence() + ['expected_version' => $ctx->version($s), 'expected_context' => $ctx->token($actor, $s), 'reason' => 'Private synthetic work/result/evidence and explicit justification; no real hardware accessed'];
        if ($kind === 'work') {
            $f += ['outcome' => 'completed', 'configuration' => 'UNCHANGED'];
        } elseif ($kind === 'test') {
            $row = $ctx->item($actor, $s);
            $f['answers'] = array_fill_keys(array_keys(Profiles::questions($row)), 'PASS');
        } elseif (in_array($kind, ['acknowledge', 'withdraw', 'exception'], true)) {
            $a = $ctx->pending($s);
            $f['authorization_id'] = $a->id;
            if ($kind === 'acknowledge') {
                $f['received'] = $ctx->manifest($a->id)->pluck('subject_id')->all();
                $f['actual_receipt'] = '1';
            }
        }

        return $f;
    }

    protected function reuseRecord(array $w, string $kind = 'work', array $overrides = [], bool $owner = false): string
    {
        $id = app(RefurbishItems::class)->record($owner ? $w['owner'] : $w['receiver'], $w['ids'][0], $kind, array_replace($this->reuseFields($w, $kind, $owner), $overrides));
        $this->handoverBoundary();

        return $id;
    }

    protected function reusePassed(array $w): void
    {
        $this->reuseRecord($w);
        $this->reuseRecord($w, 'test');
    }

    protected function reuseParent(int $count = 1): array
    {
        $w = $this->inventoryWork();
        $ids = [];
        for ($n = 0; $n < $count; $n++) {
            $ids[] = $this->identifyMedium($w, $n % 2 === 0 ? 'ssd' : 'hdd');
        }
        $this->inventoryRecord($w, 'attest');
        foreach ($ids as $s) {
            $this->authorizeMedium($w, $s);
            $this->mediaSatisfied($this->mediumWork($w, $s));
        }

        return $w;
    }
}
