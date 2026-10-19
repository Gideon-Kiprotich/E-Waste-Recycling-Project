<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Collection\AdminMutation;
use App\Support\Downstream\Input;
use App\Support\Downstream\Violation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManageOutputProfiles
{
    public function token(string $id): string
    {
        return AdminMutation::hash(['id' => $id, 'profile' => (array) DB::table('output_profiles')->where('id', $id)->first()]);
    }

    public function record(User $actor, string $id, #[\SensitiveParameter] array $fields, ?UploadedFile $file = null): string
    {
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $id, $fields, $file, &$stored) {
                $actor = AdminMutation::actor($actor);
                $fields = Input::validate($fields, 'profile', $file);
                $action = 'output_profile:'.$id;
                $hash = AdminMutation::hash(['profile_id' => $id] + $fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                $cur = DB::table('output_profiles')->where('id', $id)->lockForUpdate()->first();
                abort_unless((int) ($cur?->version ?? 0) === (int) $fields['expected_version'] && hash_equals($this->token($id), $fields['expected_context']), 409, 'Acceptance review changed. Reload Output acceptance profiles.');
                $place = DB::table('places')->where('id', $fields['facility_id'])->where('kind', 'facility')->firstOrFail();
                $party = DB::table('parties')->where('place_id', $place->id)->value('id');
                if (! $party) {
                    $party = Str::uuid()->toString();
                    DB::table('parties')->insert(['id' => $party, 'kind' => 'place', 'place_id' => $place->id]);
                }
                $record = Str::uuid()->toString();
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $record);
                $version = ($cur?->version ?? 0) + 1;
                $observed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $fields['observed_at'], 'UTC');
                $event = Str::uuid()->toString();
                DB::table('domain_events')->insert(['id' => $event, 'subject_id' => null, 'aggregate_kind' => 'output_profile', 'aggregate_id' => $id, 'aggregate_version' => $version, 'actor_id' => $actor->id, 'command_id' => $command, 'type' => 'output_profile_reviewed', 'occurred_at' => $observed, 'recorded_at' => now(), 'safe_payload' => json_encode(['record_id' => $record])]);
                $key = DB::selectOne('SELECT downstream_material(?) AS value', [$fields['material_key']])->value;
                DB::table('output_profile_records')->insert(['id' => $record, 'profile_id' => $id, 'version' => $version, 'facility_party_id' => $party, 'output_type' => $fields['output_type'], 'material_key' => $key, 'hazards' => $fields['hazards'], 'approved' => $fields['approved'] === '1', 'actor_id' => $actor->id, 'command_id' => $command, 'event_id' => $event, 'observed_at' => $observed, 'recorded_at' => now(), 'reason_ciphertext' => Crypt::encryptString($fields['reason']), 'context' => DB::selectOne('SELECT output_profile_context(?,?) AS context', [$actor->id, $party])->context] + app(DismantleBatches::class)->evidence($actor, $fields, $file, $stored));
                $projection = ['version' => $version, 'record_id' => $record];
                $cur ? DB::table('output_profiles')->where('id', $id)->update($projection) : DB::table('output_profiles')->insert(['id' => $id, 'facility_party_id' => $party, 'output_type' => $fields['output_type'], 'material_key' => $key, 'hazards' => $fields['hazards']] + $projection);

                return $record;
            });
        } catch (Throwable $e) {
            app(DismantleBatches::class)->cleanup($stored);
            if ($e instanceof QueryException && ($e->errorInfo[0] ?? null) === '23505' && str_contains($e->errorInfo[2] ?? '', 'output_profile_namespace')) {
                throw ValidationException::withMessages(['material_key' => 'An acceptance profile already exists for this facility, type and normalized specification. Open its existing review.']);
            }
            Violation::raise($e);
        }
    }
}
