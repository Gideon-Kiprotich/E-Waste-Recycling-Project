<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Assignments\Input;
use App\Support\Collection\AdminMutation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageVehicle
{
    public function save(User $actor, ?string $id, array $fields): string
    {
        $rules = ['collector_id' => ['required', 'uuid'], 'capacity_kg' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'], 'max_duration_seconds' => ['required', 'integer', 'between:1,86400'], 'available' => ['required', Rule::in(['0', '1'])], 'idempotency_key' => ['required', 'uuid']];
        $rules += $id ? ['expected_version' => ['required', 'integer', 'min:1']] : ['organization_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:60', 'regex:/\A[A-Za-z0-9 -]+\z/'], 'synthetic' => ['required', Rule::in(['0', '1'])]];
        $fields = Input::validate($fields, $rules);

        return DB::transaction(function () use ($actor, $id, $fields): string {
            $actor = AdminMutation::actor($actor);
            $action = 'manage_vehicle:'.($id ?? 'new');
            $hash = AdminMutation::hash($fields);
            if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                return $retry;
            }
            $old = $id ? DB::table('vehicles')->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            abort_if($old && $old->version !== (int) $fields['expected_version'], 409, 'Vehicle changed. Reload Vehicles before saving.');
            $orgId = $old?->organization_id ?? $fields['organization_id'];
            $org = DB::table('organizations')->where('id', $orgId)->lockForUpdate()->first();
            $m = DB::table('memberships')->where('user_id', $fields['collector_id'])->lockForUpdate()->first();
            $u = DB::table('users')->where('id', $fields['collector_id'])->lockForUpdate()->first();
            if (! $org || $org->review_status !== 'approved' || ! in_array($org->kind, ['collector', 'both'], true) || ! $m || $m->role !== 'collector' || ! $m->active || $m->organization_id !== $orgId || $u?->status !== 'active') {
                throw ValidationException::withMessages(['collector_id' => 'Choose an active Collector scoped to this approved collecting operator. Use Collector access to resolve setup.']);
            }
            $version = $old ? $old->version + 1 : 1;
            $values = ['collector_id' => $fields['collector_id'], 'capacity_kg' => $fields['capacity_kg'], 'max_duration_seconds' => $fields['max_duration_seconds'], 'available' => $fields['available'] === '1', 'version' => $version];
            if ($old) {
                DB::table('vehicles')->where('id', $id)->update($values);
            } else {
                $code = strtoupper(trim($fields['code']));
                if (DB::table('vehicles')->where('code', $code)->exists()) {
                    throw ValidationException::withMessages(['code' => 'This vehicle code is already recorded. Edit the existing vehicle.']);
                }
                $id = Str::uuid()->toString();
                DB::table('vehicles')->insert($values + ['id' => $id, 'organization_id' => $orgId, 'code' => $code, 'synthetic' => $fields['synthetic'] === '1']);
            }
            $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
            AdminMutation::event($actor, 'vehicle', $id, $version, 'vehicle_configured', $command, $values);

            return $id;
        });
    }
}
