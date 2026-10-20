<?php

namespace App\Support\Restrictions;

use Illuminate\Support\Facades\DB;

class Effective
{
    public static function available(): bool
    {
        return DB::getSchemaBuilder()->hasTable('restriction_cases');
    }

    public static function receiptKinds(string $subject): array
    {
        if (self::available()) {
            return DB::table('effective_restrictions')->where('subject_id', $subject)->where('source_kind', 'receipt')->pluck('fact_key')->all();
        }

        // Isolated populated-upgrade fixtures still exercise the preceding schema.
        return DB::table('receipt_discrepancies as d')->join('receipt_outcomes as o', 'o.id', '=', 'd.outcome_id')->join('request_items as ri', 'ri.id', '=', 'o.request_item_id')->where('ri.item_id', $subject)->whereIn('d.kind', ['mismatch', 'safety_hold'])->pluck('d.kind')->all();
    }

    public static function inspectionBlocked(string $subject): bool
    {
        return self::available()
            ? (bool) DB::selectOne('SELECT restriction_inspection_blocked(?) AS blocked', [$subject])->blocked
            : DB::table('assessments')->where('subject_id', $subject)->where('hard_hold', true)->exists();
    }

    public static function freshInspection(string $subject): bool
    {
        return ! self::available() || (bool) DB::selectOne('SELECT restriction_fresh_inspection(?) AS fresh', [$subject])->fresh;
    }
}
