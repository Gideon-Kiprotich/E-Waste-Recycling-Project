<?php

namespace App\Support\MediaInventory;

use App\Models\User;
use App\Support\Collection\AdminMutation;
use Illuminate\Support\Facades\DB;

class Context
{
    public const CHECKS = [
        'identify' => ['physical_match' => 'I physically distinguish this medium and its placement', 'ownership_consistent' => 'Evidence is consistent with the parent accountability owner', 'safe_observation' => 'Identity and observation are safe, without unresolved restrictions'],
        'attest' => ['all_areas_accounted' => 'All relevant areas are accounted for; none inaccessible or unknown', 'configuration_storage' => 'Configuration storage is included, not only the main drive', 'identity_count_resolved' => 'All medium identities and counts are resolved', 'no_physical_change' => 'This inventory does not conceal removal or replacement'],
        'correct' => ['observation_correction_only' => 'This corrects an observation; it does not assert removal, replacement or an identity merge'],
        'authorize' => ['identity_confirmed' => 'Physical medium and current placement confirmed', 'safe_media' => 'Media-specific safety checks show no unresolved restriction', 'method_scope_known' => 'Actual media and proposed treatment scope are understood', 'no_unresolved_restriction' => 'No identity, custody or safety dispute remains'],
    ];

    public static function checks(string $kind): array
    {
        return self::CHECKS[$kind === 'link' ? 'identify' : $kind] ?? [];
    }

    public function parent(User $actor, string $id): object
    {
        $row = app(\App\Support\Assessments\Context::class)->authorized($actor, $id);
        abort_unless(DB::table('items')->where('subject_id', $id)->value('intake_class') === 'whole_device', 404);

        return $row;
    }

    public function snapshot(User $actor, object $row): array
    {
        $snapshot = app(\App\Support\Sanitization\Context::class)->snapshot($actor, $row);
        unset($snapshot['placement_record_id'], $snapshot['authorization_context_version'], $snapshot['authorization_id']);

        return $snapshot;
    }

    public function token(User $actor, object $row): string
    {
        return AdminMutation::hash($this->snapshot($actor, $row) + ['inventory_version' => $this->version($row->subject_id)]);
    }

    public function version(string $parent): int
    {
        return (int) (DB::table('media_inventory_current')->where('parent_id', $parent)->value('version') ?? 0);
    }

    public function coverage(string $parent): array
    {
        return json_decode(DB::selectOne('SELECT media_coverage(?) AS coverage', [$parent])->coverage, true, flags: JSON_THROW_ON_ERROR);
    }

    public function entries(string $parent)
    {
        return DB::table('media_inventory_entries as e')->join('media_inventory_records as r', 'r.id', '=', 'e.record_id')->leftJoin('subjects as s', 's.id', '=', 'e.medium_id')->leftJoin('storage_assets as sa', 'sa.subject_id', '=', 'e.medium_id')->where('e.parent_id', $parent)->orderBy('e.id')->get(['e.*', 's.public_code', 'sa.media_kind', 'r.placement_ciphertext']);
    }

    public function candidates(User $actor, object $parent)
    {
        return app(\App\Support\Assessments\Context::class)->rows($actor)->where('i.owner_user_id', $parent->owner_user_id)->where('i.intake_class', 'standalone_part')->whereIn('i.subtype', ['hdd', 'ssd'])->whereNotExists(fn ($q) => $q->selectRaw('1')->from('media_inventory_entries as e')->whereColumn('e.medium_id', 'i.subject_id'))->get();
    }
}
