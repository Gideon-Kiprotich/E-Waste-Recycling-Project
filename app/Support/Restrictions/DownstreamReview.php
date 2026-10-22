<?php

namespace App\Support\Restrictions;

use App\Models\User;
use App\Support\Batches\Mass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DownstreamReview
{
    public const CHECKS = ['identity_known' => 'The same existing physical allocation is known', 'quantities_evidenced' => 'The exact original and proposed quantities have evidence', 'scope_known' => 'The appropriate source and destination findings are provided', 'history_preserved' => 'Original production, movement and work history remain', 'no_invented_movement' => 'No receipt, return or transfer is invented by this review', 'supported_facts' => 'Every proposed fact is known and within the supported action'];

    public const FACTS = ['identity_known', 'supported_facts', 'original_error', 'located_later', 'continuous_control', 'present_unallocated', 'quantified_loss', 'known_safe', 'same_allocation'];

    public static function applies(?string $type): bool
    {
        return str_starts_with($type ?? '', 'downstream_');
    }

    public static function details(array $fields, string $type, string $kind): array
    {
        if ($kind === 'assign') {
            if (array_key_exists('party_actor_id', $fields) && ($fields['party_actor_id'] === null || $fields['party_actor_id'] === '')) {
                unset($fields['party_actor_id']);
            }
            $rules = ['party_actor_id' => ['sometimes', 'uuid']];
        } elseif (in_array($kind, ['proposal', 'clarify'], true)) {
            if (isset($fields['revisions']) && ! is_array($fields['revisions'])) {
                throw ValidationException::withMessages(['details.revisions' => 'Provide a typed list of exact facts.']);
            }
            $fields['revisions'] = array_filter($fields['revisions'] ?? [], fn ($row) => ! is_array($row) || array_key_exists('value', $row) && $row['value'] !== '' && $row['value'] !== null);
            $fields['party_findings'] ??= [];
            $rules = ['revisions' => ['present', 'array', 'max:50'], 'revisions.*' => ['required', 'array:anchor_kind,anchor_id,fact,value,expected_version,predecessor_id'], 'revisions.*.anchor_kind' => ['required', Rule::in(['downstream', 'batch', 'discovery'])], 'revisions.*.anchor_id' => ['required', 'uuid'], 'revisions.*.fact' => ['required', Rule::in(['kg', 'active', 'kind', 'condition', 'disposition', 'loss_status', 'net_kg', 'gross_kg', 'tare_kg', 'movement'])], 'revisions.*.value' => ['required', 'string', 'max:60'], 'revisions.*.expected_version' => ['required', 'integer', 'min:0'], 'revisions.*.predecessor_id' => ['nullable', 'uuid'], 'party_findings' => ['present', 'array', 'max:6'], 'party_findings.*' => ['required', 'uuid', 'distinct'], 'facts' => ['required', 'array:'.implode(',', self::FACTS)]];
            foreach (self::FACTS as $fact) {
                $rules['facts.'.$fact] = ['sometimes', Rule::in(['YES', 'NO', 'UNKNOWN'])];
            }
            foreach (['identity_known', 'supported_facts'] as $fact) {
                $rules['facts.'.$fact][0] = 'required';
            }
            if ($type === 'downstream_recipient') {
                $rules += ['receiver_actor_id' => ['required', 'uuid'], 'profile_record_id' => ['required', 'uuid']];
            }
        } else {
            $rules = [];
        }
        $allowed = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
        if (array_diff(array_keys($fields), $allowed)) {
            throw ValidationException::withMessages(['details' => 'Use only the fields for this exact downstream review.']);
        }
        $validator = Validator::make($fields, $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn ($messages, $field) => ['details.'.$field => $messages])->all());
        }

        $validated = $validator->validated();
        $seen = [];
        foreach ($validated['revisions'] ?? [] as $index => $row) {
            $key = $row['anchor_kind'].':'.$row['anchor_id'].':'.$row['fact'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['details.revisions.'.$index.'.value' => 'Include each exact fact only once.']);
            }
            $seen[$key] = true;
            if (in_array($row['fact'], ['kg', 'net_kg', 'gross_kg', 'tare_kg'], true)) {
                $mass = Mass::milli($row['value'], 'details.revisions.'.$index.'.value');
                if ($mass <= 0 && in_array($row['fact'], ['kg', 'net_kg'], true)) {
                    throw ValidationException::withMessages(['details.revisions.'.$index.'.value' => 'Physical mass must stay positive; use the explicit claim retirement for a proven erroneous claim.']);
                }
            }
        }
        foreach ($validated['revisions'] ?? [] as $index => $row) {
            if ($row['fact'] === 'kg' && isset($seen[$row['anchor_kind'].':'.$row['anchor_id'].':active'])) {
                throw ValidationException::withMessages(['details.revisions.'.$index.'.value' => 'Choose either whole-claim retirement or remaining true kg; clear the other value.']);
            }
        }
        if (isset($validated['revisions'])) {
            $validated['revisions'] = array_values($validated['revisions']);
        }

        return $validated;
    }

    public static function typeLabel(string $type): string
    {
        return ucfirst(str_replace('_', ' ', substr($type, 11)));
    }

    public static function label(string $type, string $kind): string
    {
        return match ($kind) {
            'proposal' => match ($type) {
                'downstream_recipient' => 'Propose same-destination successor',
                'downstream_measurement' => 'Propose accounting correction',
                'downstream_discovery_movement' => 'Review discovery-output movement eligibility',
                'downstream_located' => 'Record later-located material findings',
                'downstream_loss_confirm' => 'Record supported incident findings',
                'downstream_downstream_continuity', 'downstream_closure_review' => 'Review downstream obligation',
                default => 'Propose reporting correction or supported disposition',
            },
            'clarify' => 'Sign linked party findings',
            'approve' => 'Approve exact evidenced downstream effects',
            default => Context::LABELS[$kind],
        };
    }

    public static function candidates(string $batch)
    {
        return DB::table('users as u')->join('memberships as m', 'm.user_id', '=', 'u.id')->join('places as place', 'place.id', '=', 'm.place_id')->join('organizations as operator', 'operator.id', '=', 'place.organization_id')->whereRaw('downstream_review_scope(u.id,?)', [$batch])->orderBy('u.display_name')->get(['u.id', 'u.display_name', 'm.role', 'place.name as place_name', 'operator.name as operator_name']);
    }

    private static function row(string $kind, string $id, string $fact, string $label, ?string $original, string $value = ''): array
    {
        $previous = DB::table('downstream_fact_revisions')->where('anchor_kind', $kind)->where('anchor_id', $id)->where('fact', $fact)->orderByDesc('version')->first();

        return ['anchor_kind' => $kind, 'anchor_id' => $id, 'fact' => $fact, 'expected_version' => $previous?->version ?? 0, 'predecessor_id' => $previous?->id, 'label' => $label, 'original' => $original, 'effective' => $previous?->value ?? $original, 'value' => $value];
    }

    public static function formData(object $case, User $actor, ?string $finding = null): array
    {
        $action = substr($case->fact_key, 11);
        $raw = DB::table('downstream_records')->where('id', $case->source_id)->first();
        $rows = [];
        if (in_array($action, ['report_error', 'located', 'loss_error', 'completion_error'], true)) {
            $rows[] = self::row('downstream', $raw->id, 'active', 'Retire this exact whole claim (0); clear for a partial correction', '1', '0');
            if (! $raw->canonical && $action !== 'completion_error') {
                $rows[] = self::row('downstream', $raw->id, 'kg', 'Remaining true disputed/lost kg (partial correction only)', $raw->kg);
            }
        } elseif ($action === 'damage_error') {
            $rows[] = self::row('downstream', $raw->id, 'condition', 'Evidenced erroneous condition replacement', $raw->condition, 'intact');
        } elseif ($action === 'damage_disposition') {
            $rows[] = self::row('downstream', $raw->id, 'disposition', 'Supported reviewed plan: recovered, disposed or controlled_storage', null);
        } elseif ($action === 'loss_confirm') {
            if (in_array($raw->kind, ['refuse', 'shortage', 'dispute'], true)) {
                $rows[] = self::row('downstream', $raw->id, 'kind', 'Known later loss outcome; original report retained', $raw->kind, 'downstream_loss');
            }
            $rows[] = self::row('downstream', $raw->id, 'loss_status', 'Quantified loss accountability; not recovered mass', 'pending', 'confirmed');
        } elseif ($action === 'discovery_movement') {
            $medium = DB::table('batch_records')->where('id', $case->source_id)->value('target_id');
            $rows[] = self::row('discovery', $medium, 'movement', 'Origin applicability only; normal movement gates remain', null, 'approved');
        } elseif ($action === 'measurement') {
            $source = DB::selectOne('SELECT downstream_party(?,?) AS party', [$case->subject_id, $actor->id])->party === 'source';
            if ($source) {
                $measurements = DB::table('batch_records as r')->where('r.batch_id', $case->subject_id)->where(function ($q) use ($case) {
                    $q->whereIn('r.id', DB::table('batch_outputs')->where('batch_id', $case->subject_id)->select('measurement_record_id'))->orWhereIn('r.id', DB::table('dismantling_batches')->where('subject_id', $case->subject_id)->select('input_measurement_id'));
                })->get();
                foreach ($measurements as $m) {
                    foreach (['net_kg', 'gross_kg', 'tare_kg'] as $fact) {
                        if ($m->$fact !== null) {
                            $rows[] = self::row('batch', $m->id, $fact, 'Original '.($m->target_id === $case->subject_id ? 'input' : 'output').' '.str_replace('_', ' ', $fact).' · '.$m->id, $m->$fact);
                        }
                    }
                }
            }
            foreach (DB::table('downstream_records as r')->where('batch_id', $case->subject_id)->whereNotNull('kg')->when(! $source, fn ($q) => $q->whereRaw('downstream_destination_actor(?,r.id)', [$actor->id]))->orderBy('version')->get() as $r) {
                $rows[] = self::row('downstream', $r->id, 'kg', 'Recorded '.str_replace('_', ' ', $r->kind).' kg · '.$r->id, $r->kg);
            }
            if ($raw?->kind === 'measurement_dispute') {
                $rows[] = self::row('downstream', $raw->id, 'active', 'Resolve this evidenced measurement dispute only', '1', '0');
            }
        }
        $seed = $finding ? DB::table('restriction_records')->where('case_id', $case->id)->where('id', $finding)->where('kind', 'clarify')->first() : null;
        $details = $seed ? json_decode($seed->details, true) : [];
        foreach ($rows as &$row) {
            foreach ($details['revisions'] ?? [] as $fact) {
                if ($fact['anchor_kind'] === $row['anchor_kind'] && $fact['anchor_id'] === $row['anchor_id'] && $fact['fact'] === $row['fact']) {
                    $row['value'] = $fact['value'];
                }
            }
        }
        unset($row);
        $findings = DB::table('restriction_records as r')->join('users as u', 'u.id', '=', 'r.actor_id')->where('r.case_id', $case->id)->where('r.kind', 'clarify')->whereRaw('r.actor_context=restriction_actor_context(r.actor_id)')->orderByDesc('r.version')->get(['r.id', 'r.version', 'u.display_name']);
        $recipientProfiles = collect();
        $recipientCandidates = collect();
        if ($action === 'recipient') {
            $h = DB::table('downstream_shipments')->where('created_record_id', $case->source_id)->firstOrFail();
            $profile = DB::table('output_profile_records')->where('id', $h->profile_record_id)->firstOrFail();
            $recipientProfiles = DB::table('output_profiles as p')->join('output_profile_records as r', 'r.id', '=', 'p.record_id')->where('p.id', $profile->profile_id)->where('r.approved', true)->get(['r.id', 'r.material_key']);
            $recipientCandidates = DB::table('users as u')->join('memberships as m', 'm.user_id', '=', 'u.id')->whereRaw("COALESCE((downstream_access(u.id,?)->>'eligible')::boolean,false)", [$profile->facility_party_id])->orderBy('u.display_name')->get(['u.id', 'u.display_name']);
        }

        return compact('rows', 'findings', 'recipientProfiles', 'recipientCandidates', 'details');
    }
}
