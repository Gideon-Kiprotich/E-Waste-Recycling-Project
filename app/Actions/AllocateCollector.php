<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\User;
use App\Support\Assignments\Eligibility;
use App\Support\Assignments\History;
use App\Support\Assignments\Input;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\Review;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AllocateCollector
{
    public function allocate(User $actor, string $requestId, #[\SensitiveParameter] array $fields): string
    {
        $fields = Input::validate($fields, ['operation' => ['required', Rule::in(['offer', 'withdraw', 'reassign'])], 'idempotency_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:1000'], 'expected_request_version' => ['exclude_if:operation,withdraw', 'required_unless:operation,withdraw', 'integer', 'min:1'], 'expected_review_id' => ['exclude_if:operation,withdraw', 'required_unless:operation,withdraw', 'uuid'], 'membership_ref' => ['exclude_if:operation,withdraw', 'required_unless:operation,withdraw', 'string', Input::referenceRule()], 'facility_ref' => ['exclude_if:operation,withdraw', 'required_unless:operation,withdraw', 'string', Input::referenceRule()], 'assignment_id' => ['required_unless:operation,offer', 'uuid'], 'expected_version' => ['required_unless:operation,offer', 'integer', 'min:1']]);
        try {
            return DB::transaction(function () use ($actor, $requestId, $fields): string {
                $actor = AdminMutation::actor($actor);
                $action = 'allocate_collector:'.$requestId;
                $hash = AdminMutation::hash($fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                $ids = DB::table('request_items')->where('request_id', $requestId)->where('active', true)->orderBy('item_id')->pluck('item_id');
                DB::table('subjects')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                DB::table('items')->whereIn('subject_id', $ids)->orderBy('subject_id')->lockForUpdate()->get();
                $request = DB::table('requests')->where('id', $requestId)->lockForUpdate()->firstOrFail();
                DB::table('users')->where('id', $request->consumer_id)->lockForUpdate()->firstOrFail();
                DB::table('memberships')->where('user_id', $request->consumer_id)->lockForUpdate()->get();
                $old = DB::table('assignments')->where('request_id', $requestId)->where('active', true)->lockForUpdate()->first();
                if ($fields['operation'] === 'offer') {
                    abort_if($old, 409, 'This pickup already has an active assignment. Reload before withdrawing or reassigning.');
                } else {
                    abort_unless($old && $old->id === $fields['assignment_id'] && (int) $old->version === (int) $fields['expected_version'], 409, 'This assignment changed. Reload before acting.');
                }
                $snapshot = null;
                if ($fields['operation'] !== 'withdraw') {
                    abort_unless((int) $request->version === (int) $fields['expected_request_version'] && $request->review_id === $fields['expected_review_id'], 409, 'This request or review changed. Review its current details.');
                    [$memberId, $memberVersion] = Input::reference($fields['membership_ref']);
                    [$placeId, $placeVersion] = Input::reference($fields['facility_ref']);
                    $snapshot = app(Eligibility::class)->snapshot($request, $memberId, $placeId, true);
                    abort_unless($snapshot['membership']['version'] === $memberVersion && $snapshot['facility']['version'] === $placeVersion, 409, 'Collector access or receiving place changed. Reload the choices.');
                }
                $id = $fields['operation'] === 'withdraw' ? $old->id : Str::uuid()->toString();
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
                if ($old) {
                    History::close($actor, $old, $fields['operation'] === 'withdraw' ? 'withdrawn' : 'replaced', $command, $fields['reason']);
                }
                if ($snapshot) {
                    DB::table('assignments')->insert(['id' => $id, 'request_id' => $requestId, 'collector_id' => $snapshot['membership']['user_id'], 'membership_id' => $memberId, 'operator_id' => $snapshot['membership']['organization_id'], 'facility_id' => $placeId, 'review_id' => $request->review_id, 'request_version' => $request->version, 'eligibility_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_by' => $actor->id, 'created_at' => now(), 'state' => 'offered', 'active' => true, 'version' => 1]);
                    History::record($actor, DB::table('assignments')->where('id', $id)->first(), $command, $fields['reason']);
                }

                return $id;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && str_contains($e->errorInfo[2] ?? '', '"assignments_active_request_unique"')) {
                abort(409, 'This pickup already has an active assignment. Reload its details.');
            }
            throw $e;
        }
    }

    public function respond(User $actor, string $id, #[\SensitiveParameter] array $fields): string
    {
        $fields = Input::validate($fields, ['operation' => ['required', Rule::in(['accept', 'decline'])], 'expected_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid'], 'reason' => ['required_if:operation,decline', 'nullable', 'string', 'max:1000']]);

        return DB::transaction(function () use ($actor, $id, $fields): string {
            Review::lock();
            $locked = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('membership', $locked->membership()->lockForUpdate()->first());
            $assignment = DB::table('assignments')->where('id', $id)->where('collector_id', $locked->id)->lockForUpdate()->firstOrFail();
            $org = DB::table('organizations')->where('id', $assignment->operator_id)->lockForUpdate()->first();
            abort_unless($locked->canSignIn() && $locked->role() === Role::Collector && $locked->session_version === $actor->session_version && $locked->membership->id === $assignment->membership_id && $locked->membership->organization_id === $assignment->operator_id && $org?->review_status === 'approved' && in_array($org->kind, ['collector', 'both'], true), 403);
            $action = 'respond_assignment:'.$id;
            $hash = AdminMutation::hash($fields);
            if ($retry = AdminMutation::retry($locked, $action, $fields['idempotency_key'], $hash)) {
                return $retry;
            }
            abort_unless($assignment->state === 'offered' && (int) $assignment->version === (int) $fields['expected_version'], 409, 'This offer changed or was already answered. Reload your work.');
            $request = DB::table('requests')->where('id', $assignment->request_id)->lockForUpdate()->firstOrFail();
            $ids = DB::table('request_items')->where('request_id', $request->id)->where('active', true)->orderBy('item_id')->pluck('item_id');
            DB::table('subjects')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            DB::table('items')->whereIn('subject_id', $ids)->orderBy('subject_id')->lockForUpdate()->get();
            DB::table('users')->where('id', $request->consumer_id)->lockForUpdate()->get();
            DB::table('memberships')->where('user_id', $request->consumer_id)->lockForUpdate()->get();
            if ($fields['operation'] === 'accept') {
                $snapshot = app(Eligibility::class)->snapshot($request, $assignment->membership_id, $assignment->facility_id, true);
                abort_unless($snapshot == json_decode($assignment->eligibility_snapshot, true, flags: JSON_THROW_ON_ERROR), 409, 'Eligibility changed. Administrator resolution and a fresh offer are required.');
            }
            $command = AdminMutation::command($locked, $action, $fields['idempotency_key'], $hash, $id);
            if ($fields['operation'] === 'decline') {
                History::close($locked, $assignment, 'declined', $command, $fields['reason']);
            } else {
                DB::table('assignments')->where('id', $id)->update(['state' => 'accepted', 'version' => (int) $assignment->version + 1, 'accepted_at' => now()]);
                History::record($locked, DB::table('assignments')->where('id', $id)->first(), $command, $fields['reason'] ?? null);
            }

            return $id;
        });
    }
}
