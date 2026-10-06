<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\Item;
use App\Models\User;
use App\Support\Collection\Directory;
use App\Support\Collection\Eligibility;
use App\Support\Collection\Input;
use App\Support\Devices\Identifiers;
use App\Support\PrivacyNotice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CollectionRequests
{
    public function __construct(private Directory $directory, private Eligibility $eligibility) {}

    public function submit(User $actor, #[\SensitiveParameter] array $fields): string
    {
        $fields = Input::final($fields);
        try {
            return DB::transaction(function () use ($actor, $fields): string {
                $actor = $this->lockActor($actor);
                $action = 'submit_request';
                $hash = $this->hash($fields);
                if ($retry = $this->retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                $items = $this->items($actor, $fields['items'], true);
                $snapshots = $this->available($items);
                $this->futureDate($fields['preferred_date']);
                $this->location($fields, $items, true);
                $id = Str::uuid()->toString();
                $private = Str::uuid()->toString();
                DB::table('request_private_details')->insert(['id' => $private, 'encrypted_contact' => Crypt::encryptString($fields['contact']), 'encrypted_address' => isset($fields['address']) ? Crypt::encryptString($fields['address']) : null, 'latitude' => $fields['latitude'] ?? null, 'longitude' => $fields['longitude'] ?? null]);
                DB::table('requests')->insert(['id' => $id, 'public_code' => 'EWR-'.strtoupper(bin2hex(random_bytes(16))), 'consumer_id' => $actor->id, 'mode' => $fields['mode'], 'point_id' => $fields['point_id'] ?? null, 'private_details_id' => $private, 'service_area_id' => $fields['service_area_id'], 'preferred_date' => $fields['preferred_date'], 'created_at' => now()]);
                foreach ($items as $item) {
                    $snapshot = $snapshots[$item->subject_id];
                    DB::table('request_items')->insert(['id' => Str::uuid()->toString(), 'request_id' => $id, 'item_id' => $item->subject_id, 'estimated_kg' => $fields['estimated_kg'][$item->subject_id], 'preferred_date' => $fields['preferred_date'], 'initial_observation_id' => $snapshot['observation_id'], 'current_observation_id' => $snapshot['observation_id'], 'initial_subject_version' => $snapshot['subject_version'], 'current_subject_version' => $snapshot['subject_version'], 'eligibility_version' => Eligibility::VERSION, 'initial_review_reasons' => json_encode($snapshot['review_reasons']), 'review_reasons' => json_encode($snapshot['review_reasons'])]);
                }
                $command = $this->command($actor, $action, $fields['idempotency_key'], $hash, $id);
                $this->event($actor, $id, 1, 'request_submitted', $command, ['item_ids' => $fields['items'], 'eligibility_version' => Eligibility::VERSION, 'privacy_acknowledged' => true, 'notice_version' => PrivacyNotice::VERSION]);

                return $id;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505' && str_contains($e->errorInfo[2] ?? '', 'request_items_active_item_unique')) {
                throw ValidationException::withMessages(['items' => 'A selected item already has an active request. Review My requests before submitting again.']);
            }
            throw $e;
        }
    }

    public function items(User $actor, array $ids, bool $lock = false): Collection
    {
        sort($ids);
        if ($lock) {
            DB::table('subjects')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        }
        $query = Item::with('subject', 'category')->where('owner_user_id', $actor->id)->whereIn('subject_id', $ids)->orderBy('subject_id');
        $items = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($items->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => 'Choose eligible items from your own registered devices.']);
        }

        return $items;
    }

    public function available(Collection $items): array
    {
        $snapshots = [];
        foreach ($items as $item) {
            $snapshots[$item->subject_id] = $this->eligibility->inspect($item);
            if (DB::table('request_items')->where('item_id', $item->subject_id)->where('active', true)->exists()) {
                throw ValidationException::withMessages(['items' => 'A selected item already has an active request. Review My requests before submitting again.']);
            }
        }

        return $snapshots;
    }

    public function location(#[\SensitiveParameter] array $fields, Collection $items, bool $lock = false): void
    {
        if ($fields['mode'] === 'drop_off') {
            $point = $this->directory->point($fields['point_id'], $items, $lock);
            $lat = (float) $point->latitude;
            $lon = (float) $point->longitude;
        } else {
            $lat = (float) $fields['latitude'];
            $lon = (float) $fields['longitude'];
        }
        $this->directory->area($fields['service_area_id'], $lat, $lon, $lock);
    }

    public function change(User $actor, string $id, #[\SensitiveParameter] array $fields): string
    {
        $rules = ['items' => ['required', 'array', 'min:1', 'max:25'], 'items.*' => ['required', 'uuid', 'distinct'], 'expected_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid'], 'operation' => ['required', Rule::in(['cancel', 'date'])], 'reason' => ['required', 'string', 'max:1000'], 'preferred_date' => ['required_if:operation,date', 'prohibited_if:operation,cancel', 'nullable', 'date_format:Y-m-d']];
        if (array_diff(array_keys($fields), array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.')))) {
            throw ValidationException::withMessages(['submission' => 'Unsupported request change fields.']);
        }
        $fields = Validator::make($fields, $rules)->validate();
        sort($fields['items']);

        return DB::transaction(function () use ($actor, $id, $fields): string {
            $actor = $this->lockActor($actor);
            $request = $this->owned($actor, $id);
            $action = 'change_request:'.$id;
            $hash = $this->hash($fields);
            if ($retry = $this->retry($actor, $action, $fields['idempotency_key'], $hash)) {
                return $retry;
            }
            $items = $this->items($actor, $fields['items'], true);
            $request = DB::table('requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless((int) $request->version === (int) $fields['expected_version'], 409, 'This request has changed. Reload its detail page before making changes.');
            $rows = DB::table('request_items')->where('request_id', $id)->whereIn('item_id', $fields['items'])->orderBy('item_id')->lockForUpdate()->get();
            if ($rows->count() !== $items->count() || $rows->contains(fn ($row) => $row->outcome !== 'outstanding' || ! $row->active) || $items->contains(fn ($item) => $item->processing_state !== 'registered')) {
                throw ValidationException::withMessages(['items' => 'Only outstanding items that have not been received may be changed.']);
            }
            if ($fields['operation'] === 'date') {
                $this->futureDate($fields['preferred_date']);
                foreach ($items as $item) {
                    $this->eligibility->inspect($item);
                }
                $private = DB::table('request_private_details')->where('id', $request->private_details_id)->first();
                $this->location(['mode' => $request->mode, 'point_id' => $request->point_id, 'service_area_id' => $request->service_area_id, 'latitude' => $private->latitude, 'longitude' => $private->longitude], $this->items($actor, DB::table('request_items')->where('request_id', $id)->where('active', true)->pluck('item_id')->all()), true);
                DB::table('request_items')->whereIn('id', $rows->pluck('id'))->update(['preferred_date' => $fields['preferred_date']]);
            } else {
                DB::table('request_items')->whereIn('id', $rows->pluck('id'))->update(['outcome' => 'cancelled', 'active' => false]);
            }
            $version = (int) $request->version + 1;
            $outcomes = DB::table('request_items')->where('request_id', $id)->pluck('outcome');
            $state = $outcomes->contains('outstanding') ? ($outcomes->contains('collected') ? 'PARTIALLY_COMPLETED' : 'SUBMITTED') : ($outcomes->every(fn ($value) => $value === 'cancelled') ? 'CANCELLED' : 'COMPLETED');
            DB::table('requests')->where('id', $id)->update(['version' => $version, 'state' => $state]);
            $command = $this->command($actor, $action, $fields['idempotency_key'], $hash, $id);
            $this->event($actor, $id, $version, $fields['operation'] === 'cancel' ? 'request_items_cancelled' : 'request_date_preference_changed', $command, ['item_ids' => $fields['items'], 'preferred_date' => $fields['preferred_date'] ?? null, 'reason_ciphertext' => Crypt::encryptString($fields['reason'])]);

            return $id;
        });
    }

    public function refreshEligibility(User $actor, Item $item, string $itemEvent): void
    {
        // Historical migration fixtures intentionally exercise the pre-collection schema.
        if (! DB::getSchemaBuilder()->hasTable('request_items')) {
            return;
        }
        $row = DB::table('request_items')->where('item_id', $item->subject_id)->where('active', true)->first();
        if (! $row) {
            return;
        }
        $request = DB::table('requests')->where('id', $row->request_id)->lockForUpdate()->first();
        $snapshot = $this->eligibility->inspect($item->refresh()->load('category'), false);
        DB::table('request_items')->where('id', $row->id)->update(['current_observation_id' => $snapshot['observation_id'], 'current_subject_version' => $snapshot['subject_version'], 'review_reasons' => json_encode($snapshot['review_reasons'])]);
        $version = (int) $request->version + 1;
        DB::table('requests')->where('id', $request->id)->update(['version' => $version]);
        $this->event($actor, $request->id, $version, 'request_eligibility_revalidated', DB::table('domain_events')->where('id', $itemEvent)->value('command_id'), ['item_id' => $item->subject_id, 'observation_id' => $snapshot['observation_id'], 'review_reasons' => $snapshot['review_reasons'], 'normal_scheduling_cleared' => false]);
    }

    public function owned(User $actor, string $id): object
    {
        return DB::table('requests')->where('consumer_id', $actor->id)->where('id', $id)->firstOrFail();
    }

    public function futureDate(string $date): void
    {
        if ($date < now('Africa/Nairobi')->toDateString()) {
            throw ValidationException::withMessages(['preferred_date' => 'Choose today or a future preferred date. This is not a confirmed booking.']);
        }
    }

    private function lockActor(User $actor): User
    {
        $locked = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $locked->setRelation('membership', $locked->membership()->lockForUpdate()->first());
        abort_unless($locked->canSignIn() && $locked->role() === Role::Consumer && $locked->session_version === $actor->session_version, 403);

        return $locked;
    }

    private function hash(#[\SensitiveParameter] array $fields): string
    {
        unset($fields['idempotency_key']);
        ksort($fields);

        return Identifiers::digest(json_encode($fields, JSON_THROW_ON_ERROR), 'command');
    }

    private function retry(User $actor, string $action, string $key, string $hash): ?string
    {
        $command = DB::table('commands')->where('actor_id', $actor->id)->where('action', $action)->where('idempotency_key', $key)->first();
        if (! $command) {
            return null;
        }
        abort_unless(hash_equals($command->payload_hash, $hash), 409, 'This submission reference was already used for different information. Open a fresh form.');
        $id = json_decode($command->safe_response, true, flags: JSON_THROW_ON_ERROR)['request_id'];
        $this->owned($actor, $id);

        return $id;
    }

    private function command(User $actor, string $action, string $key, string $hash, string $id): string
    {
        $command = Str::uuid()->toString();
        DB::table('commands')->insert(['id' => $command, 'actor_id' => $actor->id, 'action' => $action, 'idempotency_key' => $key, 'payload_hash' => $hash, 'status' => 'completed', 'safe_response' => json_encode(['request_id' => $id])]);

        return $command;
    }

    private function event(User $actor, string $id, int $version, string $type, string $command, array $payload): void
    {
        $event = Str::uuid()->toString();
        DB::table('domain_events')->insert(['id' => $event, 'subject_id' => null, 'aggregate_kind' => 'request', 'aggregate_id' => $id, 'aggregate_version' => $version, 'actor_id' => $actor->id, 'type' => $type, 'occurred_at' => now(), 'recorded_at' => now(), 'command_id' => $command, 'safe_payload' => json_encode($payload)]);
        DB::table('notification_outbox')->insert(['id' => Str::uuid()->toString(), 'event_id' => $event, 'recipient_id' => $actor->id, 'channel' => 'in_app', 'state' => 'pending']);
    }
}
