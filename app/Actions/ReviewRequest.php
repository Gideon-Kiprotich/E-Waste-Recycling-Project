<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Assignments\History;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\PrivateEvidence;
use App\Support\Collection\Review;
use App\Support\Collection\ReviewFeedback;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReviewRequest
{
    public function decide(User $actor, string $id, #[\SensitiveParameter] array $fields, ?UploadedFile $evidence = null): string
    {
        $rules = ['decision' => ['required', Rule::in(['ready', 'hold'])], 'reason' => ['required', 'string', 'max:1000'], 'expected_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']];
        if (array_diff(array_keys($fields), array_keys($rules))) {
            throw ValidationException::withMessages(['submission' => 'Unsupported review fields. A safety override is not available.']);
        }
        $fields = Validator::make($fields, $rules)->validate();
        Validator::make(['evidence' => $evidence], ['evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']])->validate();
        $fields['evidence_checksum'] = $evidence ? hash_file('sha256', $evidence->getPathname()) : null;
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $id, $fields, $evidence, &$stored): string {
                $actor = AdminMutation::actor($actor);
                $action = 'review_request:'.$id;
                $hash = AdminMutation::hash($fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                $ids = DB::table('request_items')->where('request_id', $id)->where('active', true)->orderBy('item_id')->pluck('item_id');
                DB::table('subjects')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                DB::table('items')->whereIn('subject_id', $ids)->orderBy('subject_id')->lockForUpdate()->get();
                $request = DB::table('requests')->where('id', $id)->lockForUpdate()->firstOrFail();
                abort_unless((int) $request->version === (int) $fields['expected_version'], 409, 'This request changed. Reload and review its current details.');
                DB::table('users')->where('id', $request->consumer_id)->lockForUpdate()->firstOrFail();
                DB::table('memberships')->where('user_id', $request->consumer_id)->lockForUpdate()->get();
                $snapshot = app(Review::class)->assess($request);
                if (in_array('no_outstanding_items', $snapshot['blockers'], true)) {
                    throw ValidationException::withMessages(['decision' => 'There are no outstanding items to review.']);
                }
                if ($fields['decision'] === 'ready' && $snapshot['blockers']) {
                    throw ValidationException::withMessages(['decision' => ReviewFeedback::readyError($snapshot['blockers'])]);
                }
                $version = (int) $request->version + 1;
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
                $review = Str::uuid()->toString();
                $event = AdminMutation::event($actor, 'request', $id, $version, 'request_review_recorded', $command, ['review_id' => $review, 'decision' => $fields['decision'], 'evaluated_version' => $request->version, 'blockers' => $snapshot['blockers']]);
                DB::table('request_reviews')->insert(['id' => $review, 'request_id' => $id, 'evaluated_version' => $request->version, 'decision' => $fields['decision'], 'actor_id' => $actor->id, 'reason_ciphertext' => Crypt::encryptString($fields['reason']), 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'event_id' => $event, 'created_at' => now()]);
                if ($evidence) {
                    $stored = app(PrivateEvidence::class)->store($evidence);
                    app(PrivateEvidence::class)->link($stored, $actor->id, $event, 'request_review', $review);
                }
                DB::table('requests')->where('id', $id)->update(['version' => $version, 'review_state' => $fields['decision'], 'review_id' => $review]);
                DB::table('notification_outbox')->insert(['id' => Str::uuid()->toString(), 'event_id' => $event, 'recipient_id' => $request->consumer_id, 'channel' => 'in_app', 'state' => 'pending']);
                History::invalidate($actor, $id, $command, 'review_changed');

                return $id;
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk('directory_evidence')->delete($stored['storage_key']);
            }
            throw $exception;
        }
    }
}
