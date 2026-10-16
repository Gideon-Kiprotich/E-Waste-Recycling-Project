<?php

namespace Tests\Support;

use App\Actions\CustodyHandovers;
use App\Actions\RecordVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait InspectionFixtures
{
    use HandoverFixtures;

    protected function inspectionWork(?string $category = null, ?string $subtype = null): array
    {
        if ($category === null) {
            $w = $this->handoverWork();
        } else {
            [$admin,$owner,$collector,$point] = $this->allocationSetup();
            $facility = $this->facility($point);
            $receiver = $this->pointStaff($admin, $facility);
            $pointActor = $this->pointStaff($admin, $point);
            $ids = [$this->device($owner, $category, $subtype)->subject_id];
            $request = $this->readyRequest($admin, $owner, $ids, 'drop_off');
            $sender = $pointActor;
            app(RecordVisit::class)->record($sender, $request, $this->visitFields($request));
            $this->handoverBoundary();
            $w = compact('admin', 'owner', 'collector', 'point', 'facility', 'receiver', 'pointActor', 'ids', 'request', 'sender');
        }
        $id = $this->initiateHandover($w);
        app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $this->acknowledgeFields($id));
        $this->handoverBoundary();

        return $w;
    }

    protected function inspectionFields(string $subject, string $case = 'ssd-healthy'): array
    {
        $cases = json_decode(file_get_contents(base_path('docs/evaluation/phase-9-cases.json')), true, flags: JSON_THROW_ON_ERROR)['cases'];
        $fixture = collect($cases)->firstWhere('id', $case);

        return $this->handoverEvidence() + ['expected_version' => DB::table('assessment_current')->where('subject_id', $subject)->value('version') ?? 0, 'expected_subject_version' => DB::table('subjects')->where('id', $subject)->value('version'), 'answers' => $fixture['answers'], 'na_reasons' => $fixture['na_reasons'], 'reason' => $fixture['fault_reason']];
    }

    protected function decisionFields(string $subject, string $pathway = 'refurbish'): array
    {
        $c = DB::table('assessment_current')->where('subject_id', $subject)->firstOrFail();

        return ['idempotency_key' => Str::uuid()->toString(), 'expected_version' => $c->version, 'expected_subject_version' => DB::table('subjects')->where('id', $subject)->value('version'), 'assessment_id' => $c->assessment_id, 'observed_at' => now('UTC')->format('Y-m-d\TH:i'), 'pathway' => $pathway, 'reason' => 'Private synthetic technician decision reason'];
    }
}
