<?php

namespace Tests\Support;

use App\Actions\CustodyHandovers;
use App\Actions\PlanRoutes;
use App\Actions\RecordVisit;
use App\Models\User;
use App\Support\Handovers\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait HandoverFixtures
{
    use ReceiptFixtures;

    protected function facility(string $point): string
    {
        $row = (array) DB::table('places')->where('id', $point)->first();
        $row['id'] = Str::uuid()->toString();
        $row['code'] = 'SYN-FAC-'.Str::random(10);
        $row['name'] = 'Synthetic receiving facility '.Str::random(8);
        $row['kind'] = 'facility';
        DB::table('places')->insert($row);
        foreach (DB::table('place_categories')->where('place_id', $point)->get() as $c) {
            DB::table('place_categories')->insert(['place_id' => $row['id'], 'category_id' => $c->category_id, 'subtype' => $c->subtype]);
        }

        return $row['id'];
    }

    protected function handoverWork(int $count = 1, bool $dropoff = false): array
    {
        [$admin,$owner,$collector,$point,$depot,$vehicle] = $this->routingSetup();
        $facility = $this->facility($point);
        $receiver = $this->pointStaff($admin, $facility);
        $pointActor = $this->pointStaff($admin, $point);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->device($owner, 'standalone_part', $i % 2 ? 'hdd' : 'ssd')->subject_id;
        }
        if ($dropoff) {
            $request = $this->readyRequest($admin, $owner, $ids, 'drop_off');
            $sender = $pointActor;
        } else {
            $a = $this->accepted($admin, $owner, $collector, $point, $ids);
            $run = app(PlanRoutes::class)->create($admin, $this->routeFields($depot, $vehicle, [$a]));
            app(PlanRoutes::class)->approve($admin, $run, $this->approvalFields());
            $request = DB::table('assignments')->where('id', $a)->value('request_id');
            $sender = $collector;
        }
        app(RecordVisit::class)->record($sender, $request, $this->visitFields($request));
        $this->handoverBoundary();

        return compact('admin', 'owner', 'collector', 'point', 'pointActor', 'receiver', 'facility', 'ids', 'request', 'sender');
    }

    protected function handoverBoundary(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    protected function handoverEvidence(): array
    {
        return ['idempotency_key' => Str::uuid()->toString(), 'observed_at' => now('UTC')->format('Y-m-d\TH:i'), 'evidence_kind' => 'operator_reference', 'reference_issuer' => 'Private synthetic issuer', 'reference_code' => 'PRIVATE-SYNTHETIC-01', 'reference_attested' => '1'];
    }

    protected function initiateFields(User $sender, User $receiver): array
    {
        $r = app(Context::class)->recipients($sender)->firstWhere('id', $receiver->id);

        return $this->handoverEvidence() + ['recipient_ref' => $r->id.':'.$r->version, 'destination_version' => $r->destination_version, 'operator_version' => $r->operator_version, 'subjects' => app(Context::class)->held($sender)->map(fn ($r) => $r->subject_id.':'.$r->version)->all(), 'reason' => 'Private synthetic manifest', 'sender_attested' => '1'];
    }

    protected function acknowledgeFields(string $id, array $outcomes = []): array
    {
        return $this->handoverEvidence() + ['expected_version' => DB::table('handovers')->where('id', $id)->value('version'), 'items' => app(Context::class)->rows($id)->map(fn ($r, $i) => ['id' => $r->id, 'code' => $r->public_code, 'outcome' => $outcomes[$i] ?? 'accepted', 'attested' => in_array($outcomes[$i] ?? 'accepted', ['accepted', 'closed_no_transfer'], true) ? '1' : '0', 'reason' => 'Private synthetic physical comparison/review'])->all()];
    }

    protected function initiateHandover(array $w): string
    {
        return app(CustodyHandovers::class)->initiate($w['sender'], $this->initiateFields($w['sender'], $w['receiver']));
    }
}
