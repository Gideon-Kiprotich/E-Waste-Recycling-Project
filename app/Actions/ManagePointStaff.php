<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\User;
use App\Support\Assignments\Input;
use App\Support\Collection\AdminMutation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManagePointStaff
{
    public function save(User $actor, string $userId, array $fields): string
    {
        $fields = Input::validate($fields, ['operation' => ['required', Rule::in(['link', 'deactivate'])], 'point_ref' => ['exclude_if:operation,deactivate', 'required_unless:operation,deactivate', Input::referenceRule()], 'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000'], 'idempotency_key' => ['required', 'uuid']]);

        return DB::transaction(function () use ($actor, $userId, $fields) {
            $actor = AdminMutation::actor($actor);
            $action = 'manage_point_staff:'.$userId;
            $hash = AdminMutation::hash($fields);
            if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                return $retry;
            }
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $m = $user->membership()->lockForUpdate()->first();
            abort_unless($m?->role === Role::Processor, 404);
            abort_unless((int) $m->version === (int) $fields['expected_version'], 409, 'Point access changed. Reload before saving.');
            $p = null;
            if ($fields['operation'] === 'link') {
                [$id,$v] = Input::reference($fields['point_ref']);
                $p = DB::table('places')->where('id', $id)->lockForUpdate()->first();
                $org = $p ? DB::table('organizations')->where('id', $p->organization_id)->lockForUpdate()->first() : null;
                if (! $p || ! $p->active || $p->review_status !== 'approved' || $p->kind !== 'collection_point' || $org?->review_status !== 'approved' || ! in_array($org->kind, ['processor', 'both'], true) || $user->status !== 'active') {
                    throw ValidationException::withMessages(['point_ref' => 'Choose an active reviewed collection point under a reviewed processor/both operator for an active Processor account.']);
                }
                abort_unless((int) $p->version === $v, 409, 'Point changed. Reload the choices.');
            }
            $same = $p && $m->active && $m->place_id === $p->id && $m->organization_id === $p->organization_id;
            $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $userId);
            if (! $same) {
                DB::table('memberships')->where('id', $m->id)->update(['organization_id' => $p?->organization_id, 'place_id' => $p?->id, 'active' => $p !== null, 'version' => $m->version + 1, 'updated_at' => now()]);
                $user->increment('version');
                $user->increment('session_version');
                DB::table('sessions')->where('user_id', $userId)->delete();
            }
            AdminMutation::event($actor, 'command', $command, 1, $same ? 'point_access_unchanged' : 'point_access_changed', $command, ['membership_id' => $m->id, 'point_id' => $p?->id, 'reason_ciphertext' => Crypt::encryptString($fields['reason'])]);

            return $userId;
        });
    }
}
