<?php

namespace App\Support\Demo;

use App\Actions\CollectionRequests;
use App\Actions\ManageCollector;
use App\Actions\ManageDirectory;
use App\Actions\RegisterConsumer;
use App\Actions\RegisterDevice;
use App\Actions\ReviewRequest;
use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\Directory;
use App\Support\Collection\DirectoryDuplicates;
use App\Support\Collection\SiteLocation;
use App\Support\Devices\Questions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Ramsey\Uuid\Uuid;
use Throwable;

/** Bounded fixture orchestration; only the guarded console command exposes it. */
class NairobiDataset
{
    public const REASON = 'Simulated Nairobi demo review, not participation, certification, site verification or real acceptance. Public reference evidence is background only. Capabilities and positions are invented study configuration.';

    public const ACCOUNTS = [
        'collector-1' => ['name' => 'Akinyi Otieno (Nairobi demo)', 'role' => 'collector', 'operator' => 'study'],
        'collector-2' => ['name' => 'Kamau Mwangi (Nairobi demo)', 'role' => 'collector', 'operator' => 'weee'],
        'collector-3' => ['name' => 'Faith Wanjiku (Nairobi demo)', 'role' => 'collector', 'operator' => 'weee'],
        'processor-1' => ['name' => 'Brian Mutiso (Nairobi demo)', 'role' => 'processor'],
        'consumer-1' => ['name' => 'Amina Njeri (Nairobi demo)', 'role' => 'consumer'],
        'consumer-2' => ['name' => 'David Ochieng (Nairobi demo)', 'role' => 'consumer'],
    ];

    public const ORGANIZATIONS = [
        'weee' => ['name' => 'WEEE Centre — Nairobi demo reference (simulated)', 'kind' => 'both'],
        'enviroserve' => ['name' => 'Enviroserve — Nairobi demo reference (simulated)', 'kind' => 'processor'],
    ];

    public const PLACES = [
        'weee' => 'WEEE Centre, Mihango office reference — simulated receiving point',
        'enviroserve' => 'Nairobi demo receiving facility 02 — simulated Enviroserve reference',
    ];

    public static function key(string $label): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'ewaste:nairobi-demo:1:'.$label)->toString();
    }

    public static function email(string $key): string
    {
        return 'nairobi-demo-'.$key.'@example.test';
    }

    public function plan(User $actor): array
    {
        if (! $actor->canSignIn() || $actor->role() !== Role::Administrator) {
            throw new DemoSetupException('Select an existing active Administrator; no new Administrator is provisioned.');
        }
        $plan = ['organizations' => [], 'places' => [], 'accounts' => [], 'devices' => [], 'requests' => [], 'conflicts' => []];
        $study = DB::table('organizations')->where('code', 'synthetic-study-operator-1')->first();
        $area = DB::table('service_areas')->where('version_code', 'SYNTHETIC-STUDY-1.0')->first();
        if (! $study || ! $study->synthetic || $study->review_status !== 'approved' || ! in_array($study->kind, ['collector', 'both'], true)
            || ! $area || ! $area->synthetic || ! $area->active) {
            throw new DemoSetupException('An eligible existing synthetic-study-operator-1 and active SYNTHETIC-STUDY-1.0 are required. Existing study setup is never repaired or overwritten.');
        }
        app(Directory::class)->area($area->id, -1.28, 36.82);
        $studyPlaces = DB::table('places')->where('organization_id', $study->id)->where('synthetic', true)->where('active', true)->where('review_status', 'approved')->get();
        if (! $studyPlaces->contains(fn ($p) => $this->supportsDemo($p->id))) {
            throw new DemoSetupException('Existing synthetic collecting operator must explicitly accept Laptop, HDD and SSD at an active reviewed place. No capabilities are changed.');
        }
        $plan['study'] = $study->id;
        $plan['area'] = $area->id;
        foreach (self::ORGANIZATIONS as $key => $fields) {
            $id = $this->result($actor->id, 'manage_organization:new', 'organization-'.$key);
            $row = $id ? DB::table('organizations')->where('id', $id)->first() : DB::table('organizations')->whereRaw('directory_name_key(name) = ?', [DirectoryDuplicates::nameKey($fields['name'])])->first();
            if ($row && (! $row->synthetic || $row->review_status !== 'approved' || $row->kind !== $fields['kind'])) {
                throw new DemoSetupException('Demo organization '.$key.' is occupied or edited into an ineligible state; retained unchanged.');
            }
            if ($id && ! $row) {
                throw new DemoSetupException('Completed demo directory command references a missing record.');
            }
            $plan['organizations'][$key] = $row?->id;
            $placeId = $this->result($actor->id, 'manage_place:new', 'place-'.$key);
            $place = $placeId ? DB::table('places')->where('id', $placeId)->first() : ($row ? DB::table('places')->where('organization_id', $row->id)->whereRaw('directory_name_key(name) = ?', [DirectoryDuplicates::nameKey(self::PLACES[$key])])->first() : null);
            if (($placeId && ! $place) || ($place && (! $place->synthetic || $place->organization_id !== $row?->id || ! $place->active || $place->review_status !== 'approved' || ! in_array($place->kind, ['facility', 'collection_point'], true) || ! $this->supportsDemo($place->id)))) {
                throw new DemoSetupException('Demo receiving place '.$key.' is occupied, missing or no longer eligible; retained unchanged.');
            }
            $plan['places'][$key] = $place?->id;
        }
        foreach (self::ACCOUNTS as $key => $fields) {
            $action = $fields['role'] === 'collector' ? 'manage_collector:new' : 'nairobi_demo_account:'.$key;
            $id = $this->result($actor->id, $action, 'account-'.$key);
            $user = $id ? User::with('membership')->find($id) : User::whereRaw('lower(email) = ?', [self::email($key)])->first();
            if ($user && (! $id || ! $user->membership || $user->membership->role->value !== $fields['role'])) {
                throw new DemoSetupException('Demo account identity '.$key.' is occupied by an unrelated or role-changed account; no account edits permitted.');
            }
            if ($id && ! $user) {
                throw new DemoSetupException('Completed demo account command references a missing account.');
            }
            $plan['accounts'][$key] = $user?->id;
        }
        foreach (['laptop-1' => 'consumer-1', 'ssd-1' => 'consumer-1', 'hdd-1' => 'consumer-2'] as $key => $owner) {
            $plan['devices'][$key] = $plan['accounts'][$owner] ? $this->result($plan['accounts'][$owner], 'register_item', 'device-'.$key, 'subject_id') : null;
            if ($plan['devices'][$key] && ! DB::table('items')->where('subject_id', $plan['devices'][$key])->where('owner_user_id', $plan['accounts'][$owner])->exists()) {
                throw new DemoSetupException('A completed demo device is missing or ownership changed; retained history is not replayed.');
            }
        }
        foreach (['consumer-1', 'consumer-2'] as $key) {
            $plan['requests'][$key] = $plan['accounts'][$key] ? $this->result($plan['accounts'][$key], 'submit_request', 'request-'.$key, 'request_id') : null;
            if ($plan['requests'][$key] && ! DB::table('requests')->where('id', $plan['requests'][$key])->where('consumer_id', $plan['accounts'][$key])->exists()) {
                throw new DemoSetupException('A completed demo request is missing or ownership changed; retained history is not replayed.');
            }
        }
        $directoryChanges = in_array(null, $plan['organizations'], true) || in_array(null, $plan['places'], true);
        if ($directoryChanges) {
            $plan['conflicts'] = DB::table('requests')->whereExists(fn ($q) => $q->selectRaw('1')->from('request_items')->whereColumn('request_id', 'requests.id')->where('active', true))->orderBy('id')->pluck('id')->all();
            $plan['assignmentConflicts'] = DB::table('assignments')->where('active', true)->orderBy('id')->pluck('id')->all();
        } else {
            $plan['assignmentConflicts'] = [];
        }

        return $plan;
    }

    public function apply(User $actor, #[\SensitiveParameter] array $passwords, ?UploadedFile $evidence): array
    {
        $files = [];
        try {
            return DB::transaction(function () use ($actor, $passwords, $evidence, &$files): array {
                $actor = AdminMutation::actor($actor);
                $plan = $this->plan($actor);
                if ($plan['conflicts'] || $plan['assignmentConflicts']) {
                    throw new DemoSetupException('Existing active work would be invalidated. Apply refused; inspect dry-run safe references and obtain a separately scoped resolution.');
                }
                foreach ($plan['accounts'] as $key => $id) {
                    if (! $id) {
                        Validator::make(['password' => $passwords[$key] ?? null], ['password' => ['required', 'string', 'max:128', Password::min(12)]])->validate();
                    }
                }
                if ((in_array(null, $plan['organizations'], true) || in_array(null, $plan['places'], true)) && ! $evidence) {
                    throw new DemoSetupException('Provide genuine public-source reference evidence privately; no evidence or certificates are fabricated by this command.');
                }
                foreach (self::ORGANIZATIONS as $key => $fields) {
                    if (! $plan['organizations'][$key]) {
                        $plan['organizations'][$key] = app(ManageDirectory::class)->save($actor, 'organization', null, $fields + ['synthetic' => '1', 'review_status' => 'approved', 'contact' => 'Fictional demo coordinator only: nairobi-demo-'.$key.'@example.test. Not a real operator contact.', 'reason' => self::REASON, 'idempotency_key' => self::key('organization-'.$key), 'duplicate_review_ack' => '1'], $evidence);
                        $this->rememberFiles('organization', $plan['organizations'][$key], $files);
                    }
                    if (! $plan['places'][$key]) {
                        $fields = ['name' => self::PLACES[$key], 'kind' => 'collection_point', 'organization_id' => $plan['organizations'][$key], 'synthetic' => '1', 'review_status' => 'approved', 'active' => '1', 'contact' => 'Fictional demo coordinator only: nairobi-demo-'.$key.'@example.test. Not a real operator contact.', 'reason' => self::REASON, 'latitude' => '-1.280000', 'longitude' => '36.820000', 'site_address' => 'Synthetic study pin only. Not a verified entrance; no travel or delivery.', 'hours' => 'Real receiving hours unverified; demo only, no opening-hour promise.', 'capabilities' => ['laptop:', 'standalone_part:hdd', 'standalone_part:ssd'], 'idempotency_key' => self::key('place-'.$key), 'duplicate_review_ack' => '1'];
                        // Confirm only a declared synthetic study position; no real entrance is inferred.
                        $fields['site_confirmation'] = SiteLocation::token($actor->id, null, $fields);
                        $plan['places'][$key] = app(ManageDirectory::class)->save($actor, 'place', null, $fields, $evidence);
                        $this->rememberFiles('place', $plan['places'][$key], $files);
                    }
                }
                foreach (self::ACCOUNTS as $key => $fields) {
                    if ($plan['accounts'][$key]) {
                        continue;
                    }
                    $values = ['email' => self::email($key), 'display_name' => $fields['name'], 'password' => $passwords[$key]];
                    if ($fields['role'] === 'collector') {
                        $org = $fields['operator'] === 'study' ? $plan['study'] : $plan['organizations'][$fields['operator']];
                        $plan['accounts'][$key] = app(ManageCollector::class)->save($actor, null, $values + ['operation' => 'create', 'password_confirmation' => $passwords[$key], 'organization_ref' => $org.':'.DB::table('organizations')->where('id', $org)->value('version'), 'reason' => 'Fictional Nairobi demonstration account; not an employee or operational authorization.', 'idempotency_key' => self::key('account-'.$key)]);
                    } else {
                        $user = $fields['role'] === 'consumer' ? app(RegisterConsumer::class)->register($values) : User::create(['email' => $values['email'], 'display_name' => $values['display_name'], 'password_hash' => $values['password']]);
                        if ($fields['role'] === 'processor') {
                            // Match existing staff provisioning: no invented future facility permissions.
                            $user->membership()->create(['role' => Role::Processor, 'active' => true]);
                        }
                        $plan['accounts'][$key] = $user->id;
                        $command = AdminMutation::command($actor, 'nairobi_demo_account:'.$key, self::key('account-'.$key), AdminMutation::hash(['demo_account' => $key, 'role' => $fields['role']]), $user->id);
                        AdminMutation::event($actor, 'command', $command, 1, 'nairobi_demo_account_created', $command, ['role' => $fields['role'], 'synthetic' => true]);
                    }
                }
                foreach (['laptop-1' => ['consumer-1', 'laptop', null], 'ssd-1' => ['consumer-1', 'standalone_part', 'ssd'], 'hdd-1' => ['consumer-2', 'standalone_part', 'hdd']] as $key => [$owner, $category, $subtype]) {
                    if (! $plan['devices'][$key]) {
                        $fields = ['category' => $category, 'subtype' => $subtype, 'model' => 'Nairobi demo '.$key.' (synthetic)', 'data_status' => 'UNKNOWN', 'safety_concern' => 'NO', 'physical_damage' => 'UNKNOWN', 'answers' => array_fill_keys(array_keys(Questions::tests($category, $subtype)), 'UNKNOWN'), 'reason' => 'Synthetic fixture: no safety concern assumed for this example; function and damage unknown. No physical inspection, power-on or sanitization occurred.', 'idempotency_key' => self::key('device-'.$key)];
                        $plan['devices'][$key] = app(RegisterDevice::class)->register(User::findOrFail($plan['accounts'][$owner]), $fields, null)->subject_id;
                    }
                }
                // Finalize all directory/access setup before creating and reviewing new requests.
                foreach (['consumer-1' => ['laptop-1', 'ssd-1'], 'consumer-2' => ['hdd-1']] as $key => $deviceKeys) {
                    if ($plan['requests'][$key]) {
                        continue;
                    }
                    $ids = array_map(fn ($d) => $plan['devices'][$d], $deviceKeys);
                    $fields = ['items' => $ids, 'estimated_kg' => array_combine($ids, array_map(fn ($d) => $d === 'laptop-1' ? '2.000' : '0.250', $deviceKeys)), 'mode' => 'pickup', 'service_area_id' => $plan['area'], 'latitude' => '-1.280000', 'longitude' => '36.820000', 'address' => 'Synthetic Nairobi demo meeting point, not a real pickup entrance.', 'contact' => self::email($key), 'preferred_date' => now('Africa/Nairobi')->addDays(14)->toDateString(), 'privacy_ack' => '1', 'idempotency_key' => self::key('request-'.$key)];
                    $id = app(CollectionRequests::class)->submit(User::findOrFail($plan['accounts'][$key]), $fields);
                    app(ReviewRequest::class)->decide($actor, $id, ['decision' => 'ready', 'reason' => self::REASON, 'expected_version' => DB::table('requests')->where('id', $id)->value('version'), 'idempotency_key' => self::key('review-'.$key)]);
                    $plan['requests'][$key] = $id;
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

                return $plan;
            });
        } catch (Throwable $e) {
            foreach ($files as $key) {
                if (! DB::table('attachments')->where('storage_key', $key)->exists()) {
                    Storage::disk('directory_evidence')->delete($key);
                }
            }
            throw $e;
        }
    }

    private function result(string $actor, string $action, string $label, string $field = 'record_id'): ?string
    {
        $commands = DB::table('commands')->when(in_array($action, ['register_item', 'submit_request'], true), fn ($q) => $q->where('actor_id', $actor))->where('action', $action)->where('idempotency_key', self::key($label))->get();
        if ($commands->count() > 1) {
            throw new DemoSetupException('Ambiguous demo command identity; retained history requires investigation.');
        }
        $command = $commands->first();
        if (! $command) {
            return null;
        }
        if ($command->status !== 'completed') {
            throw new DemoSetupException('An incomplete demo command requires investigation; no replay permitted.');
        }

        return json_decode($command->safe_response, true, flags: JSON_THROW_ON_ERROR)[$field];
    }

    private function supportsDemo(string $place): bool
    {
        $caps = DB::table('place_categories as p')->join('categories as c', 'c.id', '=', 'p.category_id')->where('p.place_id', $place)->get(['c.code', 'p.subtype'])->map(fn ($c) => $c->code.':'.$c->subtype)->all();

        return ! array_diff(['laptop:', 'standalone_part:hdd', 'standalone_part:ssd'], $caps);
    }

    private function rememberFiles(string $kind, string $id, array &$files): void
    {
        $files = array_merge($files, DB::table('evidence_links as e')->join('attachments as a', 'a.id', '=', 'e.attachment_id')->where('e.record_kind', $kind)->where('e.record_id', $id)->pluck('a.storage_key')->all());
    }
}
