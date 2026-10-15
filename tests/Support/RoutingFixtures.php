<?php

namespace Tests\Support;

use App\Actions\AllocateCollector;
use App\Actions\ManageVehicle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait RoutingFixtures
{
    use AssignmentFixtures;

    protected function routingSetup(): array
    {
        [$admin,$owner,$collector,$place] = $this->allocationSetup();
        $depot = (array) DB::table('places')->where('id', $place)->first();
        $depot['id'] = Str::uuid()->toString();
        $depot['code'] = 'DEP-'.Str::random(12);
        $depot['name'] = 'Synthetic routing depot';
        $depot['kind'] = 'depot';
        DB::table('places')->insert($depot);
        $vehicle = app(ManageVehicle::class)->save($admin, null, ['organization_id' => $depot['organization_id'], 'collector_id' => $collector->id, 'code' => 'SYNTHETIC-100', 'synthetic' => '1', 'capacity_kg' => '100', 'max_duration_seconds' => 21600, 'available' => '1', 'idempotency_key' => Str::uuid()->toString()]);

        return [$admin, $owner, $collector, $place, $depot['id'], $vehicle];
    }

    protected function accepted(User $admin, User $owner, User $collector, string $place, array $items = []): string
    {
        $request = $this->readyRequest($admin, $owner, $items);
        $a = app(AllocateCollector::class)->allocate($admin, $request, $this->offerFields($request, $collector, $place));
        app(AllocateCollector::class)->respond($collector, $a, $this->responseFields($a));

        return $a;
    }

    protected function routeFields(string $depot, string $vehicle, array $assignments): array
    {
        return ['operator_id' => DB::table('places')->where('id', $depot)->value('organization_id'), 'depot_id' => $depot, 'vehicle_refs' => [$vehicle.':'.DB::table('vehicles')->where('id', $vehicle)->value('version')], 'assignment_refs' => array_map(fn ($id) => $id.':'.DB::table('assignments')->where('id', $id)->value('version'), $assignments), 'mode' => 'geometric', 'speed_kmh' => '20', 'service_seconds' => 600, 'idempotency_key' => Str::uuid()->toString()];
    }

    protected function approvalFields(): array
    {
        return ['idempotency_key' => Str::uuid()->toString()];
    }
}
