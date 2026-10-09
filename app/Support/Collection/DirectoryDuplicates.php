<?php

namespace App\Support\Collection;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DirectoryDuplicates
{
    public static function requireSchema(): void
    {
        abort_unless(Schema::hasColumn('organizations', 'brs_number') && Schema::hasColumn('places', 'site_address_ciphertext'), 503, 'Directory refinements require the pending migration. Resolve existing organization-name conflicts privately first; existing records are preserved.');
    }

    public static function nameKey(string $name): string
    {
        return DB::selectOne('SELECT directory_name_key(?) AS key', [$name])->key;
    }

    public static function identifierKey(?string $number): ?string
    {
        return $number === null || trim($number) === '' ? null : strtoupper(preg_replace('/\s+/', '', $number));
    }

    public static function matches(string $kind, #[\SensitiveParameter] array $fields, ?string $id = null): array
    {
        if (empty($fields['name']) || ! is_string($fields['name'])) {
            return [];
        }
        $key = self::nameKey($fields['name']);
        $rows = DB::table($kind === 'organization' ? 'organizations' : 'places')->selectRaw('*, directory_name_key(name) AS name_key')->when($id, fn ($q) => $q->where('id', '<>', $id));
        if ($kind === 'place') {
            if (empty($fields['organization_id'])) {
                return [];
            }
            $rows->where('organization_id', $fields['organization_id']);
        }
        $matches = [];
        foreach ($rows->orderBy('id')->get() as $row) {
            $exact = $row->name_key === $key;
            $number = self::identifierKey($fields['brs_number'] ?? null);
            $identifier = $kind === 'organization' && $number !== null && $row->brs_number === $number;
            $near = $kind === 'place' && isset($fields['latitude'], $fields['longitude']) && is_numeric($fields['latitude']) && is_numeric($fields['longitude']) && self::distance((float) $fields['latitude'], (float) $fields['longitude'], (float) $row->latitude, (float) $row->longitude) <= 100;
            $sameSite = $kind === 'place' && isset($fields['latitude'], $fields['longitude']) && is_numeric($fields['latitude']) && is_numeric($fields['longitude']) && number_format((float) $fields['latitude'], 6, '.', '') === number_format((float) $row->latitude, 6, '.', '') && number_format((float) $fields['longitude'], 6, '.', '') === number_format((float) $row->longitude, 6, '.', '');
            $address = $kind === 'place' && ! empty($fields['site_address']) && $row->site_address_ciphertext && self::addressKey($fields['site_address']) === self::addressKey(Crypt::decryptString($row->site_address_ciphertext));
            if ($identifier || $exact || $near || $address || self::similar($key, $row->name_key)) {
                $blocked = $identifier || ($exact && ($kind === 'organization' || $sameSite));
                $matches[] = ['id' => $row->id, 'name' => $row->name, 'code' => $row->code, 'field' => $identifier ? 'brs_number' : 'name', 'blocked' => $blocked, 'reason' => $blocked ? 'Equivalent directory identity already exists, including inactive records.' : 'Similar name, address or site within approximately 100 metres. Confirm whether these are distinct records; proximity is not proof of duplication.'];
            }
        }

        return $matches;
    }

    public static function validate(string $kind, #[\SensitiveParameter] array $fields, ?string $id): void
    {
        self::requireSchema();
        $matches = self::matches($kind, $fields, $id);
        foreach ($matches as $match) {
            if ($match['blocked']) {
                throw ValidationException::withMessages([$match['field'] => 'This directory identity is already recorded. Review existing record '.$match['code'].' instead of creating a duplicate.']);
            }
        }
        if ($matches && ($fields['duplicate_review_ack'] ?? null) !== '1') {
            throw ValidationException::withMessages(['duplicate_review_ack' => 'Review the possible matches below and confirm these are distinct records before saving. Similar names or nearby sites alone do not prove duplication.']);
        }
    }

    private static function addressKey(#[\SensitiveParameter] string $address): string
    {
        // Address hints are compared in memory so private text is never a SQL query binding.
        return trim(preg_replace('/[[:space:]]+/u', ' ', mb_strtolower($address)));
    }

    private static function similar(string $a, string $b): bool
    {
        // Punctuation/legal-suffix removal is only a warning heuristic, never the unique identity key.
        $tokens = fn ($name) => trim(preg_replace('/\b(ltd|limited|plc)\b/u', '', preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name)));
        $a = preg_replace('/ +/', ' ', $tokens($a));
        $b = preg_replace('/ +/', ' ', $tokens($b));
        if ($a === '' || $b === '') {
            return false;
        }
        similar_text($a, $b, $percent);

        return $a === $b || $percent >= 82;
    }

    private static function distance(float $lat, float $lon, float $otherLat, float $otherLon): float
    {
        $a = sin(deg2rad($otherLat - $lat) / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($otherLat)) * sin(deg2rad($otherLon - $lon) / 2) ** 2;

        return 6371000 * 2 * asin(sqrt(min(1, $a)));
    }
}
