<?php

namespace App\Support\Collection;

use App\Models\Item;
use App\Support\Devices\Questions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Eligibility
{
    public const VERSION = 'REQUEST-ELIGIBILITY-1.0';

    public const REASONS = ['receipt_safety_hold' => 'Unresolved physical safety hold: routine collection is blocked; reviewed correction is required.', 'receipt_identity_mismatch' => 'Unresolved physical identity mismatch: routine collection is blocked; reviewed correction is required.', 'safety_declared' => 'Safety concern declared: human review required.', 'safety_unknown' => 'Safety is unknown: human review required.', 'fan_scope_unknown' => 'Fan scope is uncertain: human review required.', 'fan_scope_outside' => 'Fan is outside intake scope: ordinary scheduling blocked.', 'fan_electronics_unknown' => 'Fan electronics are uncertain: human review required.', 'fan_electronics_outside' => 'Additional fan modules are outside intake scope: ordinary scheduling blocked.'];

    public function inspect(Item $item, bool $newSubmission = true): array
    {
        if (! $item->category->supported || ! isset(Questions::CATEGORIES[$item->category->code]) || $item->processing_state !== 'registered') {
            throw ValidationException::withMessages(['items' => 'A selected item is no longer eligible. Review your devices and try again.']);
        }
        $observation = $item->observations()->orderByDesc('revision')->firstOrFail();
        $values = $observation->answers;
        $reasons = match ($values['safety_concern'] ?? 'UNKNOWN') {
            'YES' => ['safety_declared'], 'NO' => [], default => ['safety_unknown'],
        };
        if ($item->subtype === 'ict_cooling_fan') {
            $scope = $values['answers']['fan_scope'] ?? 'UNKNOWN';
            $electronics = $values['answers']['fan_electronics'] ?? 'UNKNOWN';
            if ($newSubmission && ($scope === 'NO' || $electronics === 'ADDITIONAL')) {
                throw ValidationException::withMessages(['items' => 'A selected fan is now outside the standalone intake scope. It cannot enter a new collection request.']);
            }
            if ($scope !== 'YES') {
                $reasons[] = $scope === 'NO' ? 'fan_scope_outside' : 'fan_scope_unknown';
            }
            if ($electronics !== 'BASIC') {
                $reasons[] = $electronics === 'ADDITIONAL' ? 'fan_electronics_outside' : 'fan_electronics_unknown';
            }
        }

        if (DB::getSchemaBuilder()->hasTable('receipt_discrepancies')) {
            $physical = DB::table('receipt_discrepancies as d')->join('receipt_outcomes as o', 'o.id', '=', 'd.outcome_id')->join('request_items as ri', 'ri.id', '=', 'o.request_item_id')->where('ri.item_id', $item->subject_id)->whereIn('d.kind', ['safety_hold', 'mismatch'])->pluck('d.kind');
            if ($physical->contains('safety_hold')) {
                $reasons[] = 'receipt_safety_hold';
            }
            if ($physical->contains('mismatch')) {
                $reasons[] = 'receipt_identity_mismatch';
            }
        }

        return ['observation_id' => $observation->id, 'subject_version' => $observation->revision, 'review_reasons' => $reasons];
    }
}
