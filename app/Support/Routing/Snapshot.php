<?php

namespace App\Support\Routing;

use App\Support\Assignments\Eligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Snapshot
{
    public function capture(array $fields): array
    {
        $operator = DB::table('organizations')->where('id', $fields['operator_id'])->first();
        $depot = DB::table('places')->where('id', $fields['depot_id'])->first();
        $this->require($operator && $operator->review_status === 'approved' && in_array($operator->kind, ['collector', 'both'], true), 'operator_id', 'Choose an approved collecting operator. Open Operators and places to finish setup.');
        $this->require($depot && $depot->organization_id === $operator->id && $depot->kind === 'depot' && $depot->active && $depot->review_status === 'approved', 'depot_id', 'Choose an active reviewed depot belonging to this collecting operator. Finish directory setup before reviewing and accepting jobs again.');
        $vehicles = [];
        foreach ($fields['vehicle_refs'] as $ref) {
            [$id, $version] = explode(':', $ref);
            $v = DB::table('vehicles')->where('id', $id)->first();
            $m = $v ? DB::table('memberships')->where('user_id', $v->collector_id)->first() : null;
            $u = $m ? DB::table('users')->where('id', $m->user_id)->first() : null;
            $this->require($v && (int) $v->version === (int) $version && $v->available && $v->organization_id === $operator->id && $m && $m->active && $m->role === 'collector' && $m->organization_id === $operator->id && $u?->status === 'active', 'vehicle_refs', 'A vehicle or its Collector scope is unavailable or changed. Update Vehicles and Collector access, then reload Route planning.');
            $vehicles[] = ['id' => $v->id, 'version' => (int) $v->version, 'code' => $v->code, 'capacity_kg' => (float) $v->capacity_kg, 'max_duration_seconds' => $v->max_duration_seconds, 'collector_id' => $v->collector_id, 'membership_id' => $m->id, 'membership_version' => (int) $m->version, 'synthetic' => $v->synthetic];
        }
        $this->require(count(array_unique(array_column($vehicles, 'collector_id'))) === count($vehicles), 'vehicle_refs', 'Choose at most one vehicle per Collector for this run.');
        usort($vehicles, fn ($a, $b) => strcmp($a['id'], $b['id']));
        $jobs = $excluded = $dependencies = [];
        $this->dependency($dependencies, 'organizations', $operator->id);
        $this->dependency($dependencies, 'places', $depot->id);
        foreach ($vehicles as $v) {
            $this->dependency($dependencies, 'vehicles', $v['id']);
            $this->dependency($dependencies, 'memberships', $v['membership_id']);
            $this->dependency($dependencies, 'users', $v['collector_id']);
        }
        foreach ($fields['assignment_refs'] as $ref) {
            [$id, $version] = explode(':', $ref);
            $a = DB::table('assignments')->where('id', $id)->first();
            $r = $a ? DB::table('requests')->where('id', $a->request_id)->first() : null;
            $reason = null;
            if (! $a || ! $r || $a->operator_id !== $operator->id) {
                $reason = 'Assignment unavailable for this operator. Return to Pickup allocation.';
            } elseif ($a->state !== 'accepted' || ! $a->active || (int) $a->version !== (int) $version) {
                $reason = 'Current Collector acceptance required. Return to Pickup allocation and obtain fresh acknowledgment.';
            } else {
                try {
                    $binding = app(Eligibility::class)->snapshot($r, $a->membership_id, $a->facility_id);
                    if ($binding != json_decode($a->eligibility_snapshot, true, flags: JSON_THROW_ON_ERROR)) {
                        $reason = 'Accepted eligibility binding changed. Open Request review, resolve the changed facts, then offer and accept again.';
                    }
                } catch (ValidationException $e) {
                    $reason = implode(' ', array_merge([], ...array_values($e->errors()))).' Open Request review or Pickup allocation to resolve this before fresh acknowledgment.';
                }
            }
            $rows = $r ? DB::table('request_items')->where('request_id', $r->id)->where('active', true)->orderBy('item_id')->get() : collect();
            $p = $r ? DB::table('request_private_details')->where('id', $r->private_details_id)->first() : null;
            if (! $reason && ($rows->isEmpty() || $rows->contains(fn ($row) => $row->outcome !== 'outstanding' || ! is_numeric($row->estimated_kg) || $row->estimated_kg <= 0))) {
                $reason = 'Outstanding items need positive request mass estimates. Resolve the request; no mass is invented.';
            }
            if (! $reason && (! $p || ! is_numeric($p->latitude) || ! is_numeric($p->longitude) || abs((float) $p->latitude) > 90 || abs((float) $p->longitude) > 180)) {
                $reason = 'Pickup location is missing or invalid. The Consumer must correct the request before fresh review.';
            }
            if ($reason) {
                $excluded[] = ['id' => $id, 'request_id' => $r?->id, 'code' => $r?->public_code ?? 'Unavailable assignment', 'reason' => $reason];

                continue;
            }
            $jobs[] = ['id' => $id, 'request_id' => $r->id, 'code' => $r->public_code, 'submitted_at' => $r->created_at, 'request_version' => (int) $r->version, 'assignment_version' => (int) $a->version, 'collector_id' => $a->collector_id, 'facility_id' => $a->facility_id, 'latitude' => (string) $p->latitude, 'longitude' => (string) $p->longitude, 'kg' => (float) $rows->sum('estimated_kg'), 'service_seconds' => (int) $fields['service_seconds']];
            foreach (['assignments' => $a->id, 'requests' => $r->id, 'request_private_details' => $p->id, 'service_areas' => $r->service_area_id, 'users' => $r->consumer_id, 'memberships' => $a->membership_id, 'places' => $a->facility_id] as $table => $record) {
                $this->dependency($dependencies, $table, $record);
            }
            $this->dependency($dependencies, 'users', $a->collector_id);
            $eligibility = json_decode($a->eligibility_snapshot, true, flags: JSON_THROW_ON_ERROR);
            foreach ($eligibility['review_snapshot']['directory'] as $org) {
                $this->dependency($dependencies, 'organizations', $org['id']);
            }
            foreach ($eligibility['review_snapshot']['places'] as $place) {
                $this->dependency($dependencies, 'places', $place['id']);
            }
            foreach ($rows as $row) {
                $this->dependency($dependencies, 'request_items', $row->id);
                $this->dependency($dependencies, 'subjects', $row->item_id);
            }
        }
        usort($jobs, fn ($a, $b) => strcmp($a['id'], $b['id']));
        usort($excluded, fn ($a, $b) => strcmp($a['id'], $b['id']));
        ksort($dependencies);

        return ['fields' => $fields, 'operator' => ['id' => $operator->id, 'name' => $operator->name, 'version' => (int) $operator->version, 'synthetic' => $operator->synthetic], 'depot' => ['id' => $depot->id, 'name' => $depot->name, 'version' => (int) $depot->version, 'latitude' => (string) $depot->latitude, 'longitude' => (string) $depot->longitude], 'vehicles' => $vehicles, 'jobs' => $jobs, 'excluded' => $excluded, 'dependencies' => array_values($dependencies), 'source' => 'HAVERSINE-SIMULATION-1.0', 'profile' => 'straight-line; constant speed; no traffic', 'speed_kmh' => (float) $fields['speed_kmh'], 'service_seconds' => (int) $fields['service_seconds']];
    }

    public function current(array $snapshot): bool
    {
        try {
            $fresh = $this->capture($snapshot['fields']);
            unset($snapshot['matrix']);

            return $fresh == $snapshot;
        } catch (ValidationException|HttpException) {
            return false;
        }
    }

    private function dependency(array &$dependencies, string $table, string $id): void
    {
        if (isset($dependencies[$table.':'.$id])) {
            return;
        }
        $row = DB::table($table)->where('id', $id)->first();
        if ($table === 'memberships' && $row && ($row->place_id ?? null) === null) {
            unset($row->place_id);
        }
        $dependencies[$table.':'.$id] = ['kind' => $table, 'record_id' => $id, 'fingerprint' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR))];
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
