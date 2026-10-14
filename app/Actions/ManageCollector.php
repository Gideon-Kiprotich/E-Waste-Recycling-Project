<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\User;
use App\Support\Assignments\History;
use App\Support\Assignments\Input;
use App\Support\Collection\AdminMutation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ManageCollector
{
    public function save(User $actor, ?string $userId, #[\SensitiveParameter] array $fields): string
    {
        $rules = ['operation' => ['required', Rule::in($userId ? ['link', 'deactivate'] : ['create'])], 'reason' => ['required', 'string', 'max:1000'], 'idempotency_key' => ['required', 'uuid'], 'organization_ref' => ['exclude_if:operation,deactivate', 'required_unless:operation,deactivate', 'string', Input::referenceRule()]];
        if ($userId) {
            $rules['expected_version'] = ['required', 'integer', 'min:1'];
        } else {
            $rules += ['email' => ['required', 'email:rfc', 'max:254'], 'display_name' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)], 'password_confirmation' => ['required', 'string', 'max:128']];
            if (is_string($fields['email'] ?? null)) {
                $fields['email'] = strtolower(trim($fields['email']));
            }
        }
        $fields = Input::validate($fields, $rules);
        try {
            return DB::transaction(function () use ($actor, $userId, $fields): string {
                $actor = AdminMutation::actor($actor);
                $action = 'manage_collector:'.($userId ?? 'new');
                $hash = AdminMutation::hash($fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                $user = $userId ? User::whereKey($userId)->lockForUpdate()->firstOrFail() : null;
                $member = $user?->membership()->lockForUpdate()->first();
                if ($user) {
                    abort_unless($member?->role === Role::Collector, 404);
                    abort_unless((int) $member->version === (int) $fields['expected_version'], 409, 'Collector access changed. Reload before saving.');
                }
                $org = null;
                if ($fields['operation'] !== 'deactivate') {
                    [$orgId, $orgVersion] = Input::reference($fields['organization_ref']);
                    $org = DB::table('organizations')->where('id', $orgId)->lockForUpdate()->first();
                    if (! $org || $org->review_status !== 'approved' || ! in_array($org->kind, ['collector', 'both'], true) || ($user && $user->status !== 'active')) {
                        throw ValidationException::withMessages(['organization_ref' => 'Link an active Collector account to a reviewed collector/both operator.']);
                    }
                    abort_unless((int) $org->version === $orgVersion, 409, 'This operator changed. Reload before granting access.');
                }
                if ($user && $fields['operation'] === 'link' && $member->active && $member->organization_id === $org->id) {
                    // Audit an unchanged access check without invalidating valid work or sessions.
                    $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $user->id);
                    AdminMutation::event($actor, 'command', $command, 1, 'collector_access_unchanged', $command, ['membership_id' => $member->id, 'organization_id' => $org->id, 'reason_ciphertext' => Crypt::encryptString($fields['reason'])]);

                    return $user->id;
                }
                if (! $user) {
                    $user = User::create(['display_name' => $fields['display_name'], 'email' => $fields['email'], 'password_hash' => $fields['password']]);
                    $member = $user->membership()->create(['role' => Role::Collector, 'active' => true]);
                    DB::table('memberships')->where('id', $member->id)->update(['organization_id' => $org->id]);
                } else {
                    DB::table('memberships')->where('id', $member->id)->update(['organization_id' => $org?->id ?? $member->organization_id, 'active' => $fields['operation'] !== 'deactivate', 'version' => (int) $member->version + 1, 'updated_at' => now()]);
                    $user->increment('version');
                    $user->increment('session_version');
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $user->id);
                $updated = DB::table('memberships')->where('id', $member->id)->first();
                AdminMutation::event($actor, 'membership', $member->id, $updated->version, 'collector_access_'.$fields['operation'], $command, ['organization_id' => $updated->organization_id, 'active' => $updated->active, 'reason_ciphertext' => Crypt::encryptString($fields['reason'])]);
                foreach (DB::table('assignments')->where('collector_id', $user->id)->where('active', true)->orderBy('id')->lockForUpdate()->get() as $assignment) {
                    History::close($actor, $assignment, 'invalidated', $command, null, 'collector_access_changed');
                }

                return $user->id;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && (str_contains($e->errorInfo[2] ?? '', '"users_email_normalized_unique"') || str_contains($e->errorInfo[2] ?? '', '"users_email_unique"'))) {
                throw ValidationException::withMessages(['email' => 'This email cannot be used for a new Collector. Link an existing Collector through its access controls.']);
            }
            throw $e;
        }
    }
}
