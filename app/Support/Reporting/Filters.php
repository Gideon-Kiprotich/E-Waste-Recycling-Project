<?php

namespace App\Support\Reporting;

use App\Enums\Role;
use App\Models\User;
use App\Support\Assessments\Context;
use App\Support\Devices\Questions;
use App\Support\NairobiTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Filters
{
    public static function normalize(User $actor, array $input): array
    {
        if (array_diff(array_keys($input), ['kind', 'from', 'to', 'category', 'subtype', 'facility', 'output_type', 'material', 'page', 'revision', '_token'])) {
            throw ValidationException::withMessages(['filters' => 'Use only the fixed report filters.']);
        }
        $role = $actor->role();
        abort_unless($actor->canSignIn(), 403);
        $kind = $input['kind'] ?? ($role === Role::Collector ? 'manifest' : 'collections');
        $allowed = match ($role) {
            Role::Administrator, Role::Processor => ['collections', 'facility', 'batches', 'incoming'],
            Role::Consumer => ['collections'], Role::Collector => ['manifest'], default => [],
        };
        abort_unless(is_string($kind) && in_array($kind, $allowed, true), 403);
        abort_if($kind === 'incoming' && $role !== Role::Processor, 403);
        $today = CarbonImmutable::now(NairobiTime::ZONE)->startOfDay();
        $from = self::date($input['from'] ?? $today->subDays(29)->toDateString());
        $to = self::date($input['to'] ?? $today->toDateString());
        if ($from > $to || $from->diffInDays($to) >= 366) {
            throw ValidationException::withMessages(['from' => 'Choose an inclusive period from 1 to 366 Nairobi calendar days.']);
        }
        $category = $input['category'] ?? '';
        $subtype = $input['subtype'] ?? '';
        abort_unless(is_string($category) && is_string($subtype), 422);
        abort_if($category !== '' && ! DB::table('categories')->where('code', $category)->where('supported', true)->exists(), 422);
        abort_if($subtype !== '' && ! array_key_exists($subtype, Questions::subtypes($category)), 422);
        $facility = $input['facility'] ?? '';
        abort_unless(is_string($facility), 422);
        abort_if($facility !== '' && ! Str::isUuid($facility), 422, 'Choose an authorized place.');
        if ($role === Role::Processor) {
            $place = app(Context::class)->scope($actor);
            abort_unless($place, 403, 'Current approved facility scope is required.');
            abort_if($facility !== '' && $facility !== $place->id, 403);
            $facility = $place->id;
        } elseif ($role !== Role::Administrator) {
            abort_if($facility !== '', 403);
        }
        abort_if($facility !== '' && ! DB::table('places')->where('id', $facility)->whereIn('kind', ['facility', 'collection_point'])->exists(), 422);
        abort_if($kind === 'incoming' && ($category !== '' || $subtype !== ''), 422, 'Incoming slices support period and personal destination scope only.');
        if ($kind === 'manifest') {
            abort_if($category !== '' || $subtype !== '' || $facility !== '', 422, 'Manifest follows live assigned work; report filters do not apply.');
        }

        $outputType = $input['output_type'] ?? '';
        $material = $input['material'] ?? '';
        abort_unless(is_string($outputType) && is_string($material) && strlen($material) <= 120, 422);
        abort_if($outputType !== '' && ! in_array($outputType, ['material', 'component', 'residue', 'storage'], true), 422);
        abort_if($kind !== 'batches' && ($outputType !== '' || $material !== ''), 422, 'Output filters apply only to batch output details.');
        if ($material !== '') {
            $materials = DB::table('batch_outputs as out')->join('dismantling_batches as b', 'b.subject_id', '=', 'out.batch_id')->join('parties as p', 'p.id', '=', 'b.facility_party_id')->where('out.material_code', $material);
            if ($facility !== '') {
                $materials->where('p.place_id', $facility);
            }
            abort_unless($materials->exists(), 422, 'Choose a material in the authorized source scope.');
        }

        return ['output_type' => $outputType, 'material' => $material, 'kind' => $kind, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'category' => $category, 'subtype' => $subtype, 'facility' => $facility];
    }

    public static function bounds(array $filters): array
    {
        return [self::date($filters['from'])->utc()->toDateTimeString(), self::date($filters['to'])->addDay()->utc()->toDateTimeString()];
    }

    private static function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
            throw ValidationException::withMessages(['from' => 'Use a real calendar date in YYYY-MM-DD format.']);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(NairobiTime::ZONE));
        if (substr($value, 0, 4) === '0000' || ! $date || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['from' => 'Use a real calendar date.']);
        }

        return CarbonImmutable::instance($date);
    }
}
