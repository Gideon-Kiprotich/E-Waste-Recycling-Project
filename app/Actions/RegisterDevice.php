<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Item;
use App\Models\Subject;
use App\Models\User;
use App\Support\Devices\Identifiers;
use App\Support\Devices\PrivatePhoto;
use App\Support\Devices\Questions;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegisterDevice
{
    public function __construct(private PrivatePhoto $photos) {}

    public function register(User $actor, #[\SensitiveParameter] array $fields, ?UploadedFile $photo): Item
    {
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $fields, $photo, &$stored): Item {
                $actor = $this->lockActor($actor);
                $payloadHash = $this->payloadHash($fields, $photo);
                $retry = $this->retry($actor, 'register_item', $fields, $payloadHash);
                if ($retry) {
                    return $retry;
                }
                $category = Category::where('code', $fields['category'])->where('supported', true)->lockForUpdate()->firstOrFail();
                // Derived values must not alter existing command digest bytes or retry resolution.
                $fields['data_status'] = Questions::dataStatus($category->code, $fields['subtype'] ?? null, $fields);
                $id = Str::uuid()->toString();
                $identifier = Identifiers::normalize($fields['identifier'] ?? null);
                $digest = $identifier === null ? null : Identifiers::digest($identifier);
                if ($digest) {
                    // Retry resolution precedes global uniqueness; the index also protects direct writers.
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$digest]);
                    if (Item::where('identifier_hmac', $digest)->exists()) {
                        throw ValidationException::withMessages(['identifier' => Identifiers::DUPLICATE_MESSAGE]);
                    }
                }
                DB::table('subjects')->insert(['id' => $id, 'public_code' => 'EWC-'.strtoupper(bin2hex(random_bytes(16))), 'kind' => 'item', 'version' => 1, 'created_at' => now()]);
                DB::table('items')->insert([
                    'subject_id' => $id, 'owner_user_id' => $actor->id, 'category_id' => $category->id, 'subtype' => $fields['subtype'] ?? null,
                    'model' => $fields['model'] ?? null, 'identifier_ciphertext' => $identifier === null ? null : Crypt::encryptString(trim($fields['identifier'])),
                    'identifier_hmac' => $digest, 'identifier_key_version' => $identifier === null ? null : Identifiers::KEY_VERSION,
                    'identifier_kind' => $identifier === null ? null : ($fields['identifier_kind'] ?? 'serial'),
                    'processing_state' => 'registered', 'data_status' => $fields['data_status'],
                    'intake_class' => $category->code === 'standalone_part' ? 'standalone_part' : 'whole_device',
                ]);
                if ($category->code === 'standalone_part' && in_array($fields['subtype'], ['hdd', 'ssd'], true)) {
                    DB::table('storage_assets')->insert(['subject_id' => $id, 'media_kind' => $fields['subtype']]);
                }
                $event = $this->record($actor, 'register_item', $fields, $payloadHash, $id, 1, 'item_registered', Questions::version($category->code));
                $party = DB::table('parties')->where('user_id', $actor->id)->value('id');
                if (! $party) {
                    $party = Str::uuid()->toString();
                    DB::table('parties')->insert(['id' => $party, 'kind' => 'user', 'user_id' => $actor->id]);
                }
                DB::table('custody_current')->insert(['subject_id' => $id, 'party_id' => $party, 'last_event_id' => $event, 'version' => 1]);
                if ($photo) {
                    $stored = $this->photos->store($photo);
                    $attachment = Str::uuid()->toString();
                    DB::table('attachments')->insert(array_merge($stored, ['id' => $attachment, 'uploaded_by' => $actor->id, 'privacy_class' => 'private']));
                    DB::table('evidence_links')->insert(['id' => Str::uuid()->toString(), 'attachment_id' => $attachment, 'subject_id' => $id, 'event_id' => $event]);
                }

                return Item::with('subject', 'category')->findOrFail($id);
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk('device_photos')->delete($stored['storage_key']);
            }
            if ($exception instanceof QueryException && Identifiers::isDuplicateConflict($exception)) {
                throw ValidationException::withMessages(['identifier' => Identifiers::DUPLICATE_MESSAGE]);
            }
            throw $exception;
        }
    }

    public function revise(User $actor, Item $item, #[\SensitiveParameter] array $fields): Item
    {
        return DB::transaction(function () use ($actor, $item, $fields): Item {
            $actor = $this->lockActor($actor);
            $subject = Subject::whereKey($item->subject_id)->lockForUpdate()->firstOrFail();
            $item = Item::with('category')->where('owner_user_id', $actor->id)->lockForUpdate()->findOrFail($item->subject_id);
            Gate::forUser($actor)->authorize('view', $item);
            $payloadHash = $this->payloadHash($fields);
            $retry = $this->retry($actor, 'revise_item:'.$item->subject_id, $fields, $payloadHash);
            if ($retry) {
                return $retry;
            }
            if ($item->processing_state !== 'registered') {
                abort(409, 'Self-report can only be revised before receipt.');
            }
            if ($subject->version !== (int) $fields['expected_version']) {
                abort(409, 'This device has changed. Reload its detail page before editing.');
            }
            $version = $subject->version + 1;
            $fields['data_status'] = Questions::dataStatus($item->category->code, $item->subtype, $fields);
            $subject->version = $version;
            $subject->save();
            DB::table('items')->where('subject_id', $item->subject_id)->update(['data_status' => $fields['data_status']]);
            $event = $this->record($actor, 'revise_item:'.$item->subject_id, $fields, $payloadHash, $item->subject_id, $version, 'self_report_revised', Questions::version($item->category->code));
            app(CollectionRequests::class)->refreshEligibility($actor, $item, $event);

            return $item->refresh()->load('subject', 'category');
        });
    }

    private function lockActor(User $actor): User
    {
        $locked = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $locked->setRelation('membership', $locked->membership()->lockForUpdate()->first());
        abort_unless($locked->canSignIn() && $locked->role() === Role::Consumer && $locked->session_version === $actor->session_version, 403);

        return $locked;
    }

    private function payloadHash(#[\SensitiveParameter] array $fields, ?UploadedFile $photo = null): string
    {
        unset($fields['idempotency_key'], $fields['photo']);
        if (isset($fields['answers'])) {
            ksort($fields['answers']);
        }
        ksort($fields);
        $fields['photo_checksum'] = $photo ? hash_file('sha256', $photo->getPathname()) : null;

        return Identifiers::digest(json_encode($fields, JSON_THROW_ON_ERROR), 'command');
    }

    private function retry(User $actor, string $action, array $fields, string $hash): ?Item
    {
        $command = DB::table('commands')->where('actor_id', $actor->id)->where('action', $action)->where('idempotency_key', $fields['idempotency_key'])->first();
        if (! $command) {
            return null;
        }
        abort_unless(hash_equals($command->payload_hash, $hash), 409, 'This submission reference was already used for different information. Open a fresh form.');
        $result = json_decode($command->safe_response, true, flags: JSON_THROW_ON_ERROR);
        $item = Item::where('owner_user_id', $actor->id)->findOrFail($result['subject_id']);
        Gate::forUser($actor)->authorize('view', $item);

        return $item->load('subject', 'category');
    }

    private function record(User $actor, string $action, array $fields, string $hash, string $id, int $version, string $type, string $questionVersion): string
    {
        $command = Str::uuid()->toString();
        $event = Str::uuid()->toString();
        $observation = Str::uuid()->toString();
        DB::table('commands')->insert(['id' => $command, 'actor_id' => $actor->id, 'action' => $action, 'idempotency_key' => $fields['idempotency_key'], 'payload_hash' => $hash, 'status' => 'completed', 'safe_response' => json_encode(['subject_id' => $id])]);
        DB::table('item_observations')->insert([
            'id' => $observation, 'item_id' => $id, 'source' => 'self_report', 'observer_id' => $actor->id,
            'answers' => json_encode(array_intersect_key($fields, array_flip(['answers', 'data_status', 'safety_concern', 'physical_damage', 'reason']))),
            'question_version' => $questionVersion, 'revision' => $version, 'observed_at' => now(),
        ]);
        DB::table('domain_events')->insert([
            'id' => $event, 'subject_id' => $id, 'aggregate_kind' => 'item', 'aggregate_id' => $id, 'aggregate_version' => $version, 'actor_id' => $actor->id,
            'type' => $type, 'occurred_at' => now(), 'recorded_at' => now(), 'command_id' => $command,
            'safe_payload' => json_encode(['observation_id' => $observation, 'question_version' => $questionVersion]),
        ]);
        DB::table('notification_outbox')->insert(['id' => Str::uuid()->toString(), 'event_id' => $event, 'recipient_id' => $actor->id, 'channel' => 'in_app', 'state' => 'pending']);

        return $event;
    }
}
