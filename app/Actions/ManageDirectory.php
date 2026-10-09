<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Assignments\History;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\DirectoryDuplicates;
use App\Support\Collection\DirectoryInput;
use App\Support\Collection\PrivateEvidence;
use App\Support\Collection\SiteLocation;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManageDirectory
{
    public function save(User $actor, string $kind, ?string $id, #[\SensitiveParameter] array $fields, ?UploadedFile $evidence = null): string
    {
        abort_unless(in_array($kind, ['organization', 'place'], true), 404);
        $fields = DirectoryInput::validate($kind, $id !== null, $fields, $evidence);
        $fields['evidence_checksum'] = $evidence ? hash_file('sha256', $evidence->getPathname()) : null;
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $kind, $id, $fields, $evidence, &$stored): string {
                $actor = AdminMutation::actor($actor);
                $table = $kind === 'organization' ? 'organizations' : 'places';
                $action = 'manage_'.$kind.':'.($id ?? 'new');
                $hash = AdminMutation::hash($fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    DB::table($table)->where('id', $retry)->firstOrFail();

                    return $retry;
                }
                $old = $id ? DB::table($table)->where('id', $id)->lockForUpdate()->firstOrFail() : null;
                if ($old) {
                    abort_unless((int) $old->version === (int) $fields['expected_version'], 409, 'This directory record changed. Reload before saving.');
                }
                if ($kind === 'place') {
                    SiteLocation::validate($actor->id, $id, $fields);
                }
                DirectoryDuplicates::validate($kind, $fields + ['organization_id' => $kind === 'place' ? $old?->organization_id : null], $id);
                $id ??= Str::uuid()->toString();
                $hasEvidence = $evidence !== null || DB::table('evidence_links')->join('attachments', 'attachments.id', '=', 'evidence_links.attachment_id')->where('record_kind', $kind)->where('record_id', $id)->whereNull('redacted_at')->exists();
                if ($fields['review_status'] === 'approved' && ! $hasEvidence) {
                    throw ValidationException::withMessages(['evidence' => 'Review evidence is required before marking this record active/reviewed. Save pending if evidence is incomplete.']);
                }
                $version = $old ? (int) $old->version + 1 : 1;
                $values = ['name' => $fields['name'], 'kind' => $fields['kind'], 'review_status' => $fields['review_status'], 'encrypted_contact' => Crypt::encryptString($fields['contact']), 'version' => $version, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => Crypt::encryptString($fields['reason'])];
                if ($kind === 'organization' && array_key_exists('brs_number', $fields)) {
                    $number = DirectoryDuplicates::identifierKey($fields['brs_number']);
                    if ($number !== ($old?->brs_number ?? null) && $number !== null && (($fields['identifier_verified'] ?? null) !== '1' || ! $evidence)) {
                        throw ValidationException::withMessages(['brs_number' => 'Independently verify this BRS number, explicitly confirm the verification and upload supporting evidence. Leave it blank if unavailable.']);
                    }
                    if ($number === null && ($old?->brs_number ?? null) !== null) {
                        throw ValidationException::withMessages(['brs_number' => 'An existing verified number cannot be silently removed. Record an evidenced correction instead.']);
                    }
                    $values += ['brs_number' => $number, 'identifier_verified_by' => $number !== null ? ($number === ($old?->brs_number ?? null) ? $old->identifier_verified_by : $actor->id) : null, 'identifier_verified_at' => $number !== null ? ($number === ($old?->brs_number ?? null) ? $old->identifier_verified_at : now()) : null];
                }
                if ($kind === 'place') {
                    $orgId = $old?->organization_id ?? $fields['organization_id'];
                    $org = DB::table('organizations')->where('id', $orgId)->lockForUpdate()->first();
                    if (! $org || ($fields['active'] === '1' && ($org->review_status !== 'approved' || $fields['review_status'] !== 'approved'))) {
                        throw ValidationException::withMessages(['active' => 'An active place requires a reviewed organization and reviewed place.']);
                    }
                    if (! $old && ($fields['synthetic'] === '1') !== (bool) $org->synthetic) {
                        throw ValidationException::withMessages(['synthetic' => 'A place must retain its organization’s synthetic classification.']);
                    }
                    $values += ['latitude' => $fields['latitude'], 'longitude' => $fields['longitude'], 'hours' => json_encode(['description' => $fields['hours']]), 'active' => $fields['active'] === '1'];
                    if (array_key_exists('site_address', $fields)) {
                        $values['site_address_ciphertext'] = empty($fields['site_address']) ? null : Crypt::encryptString($fields['site_address']);
                    }
                }
                if ($old) {
                    DB::table($table)->where('id', $id)->update($values);
                } else {
                    $values += ['id' => $id, 'code' => ($kind === 'place' ? 'PL-' : 'ORG-').strtoupper(bin2hex(random_bytes(8))), 'synthetic' => $fields['synthetic'] === '1'];
                    if ($kind === 'place') {
                        $values['organization_id'] = $orgId;
                    }
                    DB::table($table)->insert($values);
                }
                if ($kind === 'place') {
                    DB::table('place_categories')->where('place_id', $id)->delete();
                    foreach ($fields['capabilities'] as $capability) {
                        [$category, $subtype] = explode(':', $capability, 2);
                        $categoryId = DB::table('categories')->where('code', $category)->where('supported', true)->value('id');
                        if (! $categoryId) {
                            throw ValidationException::withMessages(['capabilities' => 'Choose supported categories and exact subtypes.']);
                        }
                        DB::table('place_categories')->insert(['place_id' => $id, 'category_id' => $categoryId, 'subtype' => $subtype]);
                    }
                }
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
                $event = AdminMutation::event($actor, $kind, $id, $version, 'directory_review_recorded', $command, ['review_status' => $fields['review_status']]);
                DB::table('directory_revisions')->insert(['id' => Str::uuid()->toString(), 'record_kind' => $kind, 'record_id' => $id, 'version' => $version, 'actor_id' => $actor->id, 'event_id' => $event, 'reason_ciphertext' => Crypt::encryptString($fields['reason']), 'snapshot_ciphertext' => Crypt::encryptString(json_encode(['record' => DB::table($table)->where('id', $id)->first(), 'capabilities' => $fields['capabilities'] ?? []], JSON_THROW_ON_ERROR)), 'created_at' => now()]);
                if ($evidence) {
                    $stored = app(PrivateEvidence::class)->store($evidence);
                    app(PrivateEvidence::class)->link($stored, $actor->id, $event, $kind, $id);
                }
                $this->invalidate($actor, $kind, $id, $command);

                return $id;
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk('directory_evidence')->delete($stored['storage_key']);
            }
            if ($exception instanceof QueryException && $exception->getCode() === '23505') {
                foreach (['organizations_name_identity' => 'name', 'organizations_brs_identity' => 'brs_number', 'places_site_identity' => 'name'] as $constraint => $field) {
                    if (str_contains($exception->getMessage(), '"'.$constraint.'"')) {
                        throw ValidationException::withMessages([$field => 'This directory identity is already recorded. Review existing records before saving.']);
                    }
                }
            }
            throw $exception;
        }
    }

    private function invalidate(User $actor, string $kind, string $id, string $command): void
    {
        $requests = DB::table('requests')->whereExists(fn ($q) => $q->selectRaw('1')->from('request_items')->whereColumn('request_id', 'requests.id')->where('active', true))->orderBy('id')->lockForUpdate()->get();
        foreach ($requests as $request) {
            $version = (int) $request->version + 1;
            DB::table('requests')->where('id', $request->id)->update(['version' => $version, 'review_state' => 'stale']);
            $event = AdminMutation::event($actor, 'request', $request->id, $version, 'request_directory_changed', $command, ['record_kind' => $kind, 'record_id' => $id, 'review_required' => true]);
            DB::table('notification_outbox')->insert(['id' => Str::uuid()->toString(), 'event_id' => $event, 'recipient_id' => $request->consumer_id, 'channel' => 'in_app', 'state' => 'pending']);
            History::invalidate($actor, $request->id, $command, 'directory_changed');
        }
    }
}
