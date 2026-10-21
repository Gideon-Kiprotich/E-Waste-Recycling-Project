<?php

namespace App\Support\Restrictions;

use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\AdminMutation;
use Illuminate\Support\Facades\DB;

class Context
{
    public const LABELS = ['open' => 'Open investigation', 'assign' => 'Assign investigation', 'revoke' => 'Revoke investigation access', 'clarify' => 'Record original-observer clarification', 'note' => 'Record investigation note', 'proposal' => 'Record technical findings and proposed fact', 'continue' => 'Continue review — restriction retained', 'close' => 'Close investigation without changing restriction', 'approve' => 'Approve evidenced fact supersession', 'owner_clarify' => 'Clarify my return report', 'fulfill' => 'Verify fulfilled obligation'];

    public const CHECKS = ['direct_observation' => 'Direct observation and provenance support this finding', 'same_subject' => 'The same existing physical subject was compared', 'recording_error' => 'The original controlled fact was recorded erroneously', 'then_and_now' => 'Evidence supports the replacement fact at the original observation and now', 'no_remediation' => 'This does not record actual hazard remediation', 'no_movement' => 'This does not assert receipt or physical movement', 'no_configuration_change' => 'No replacement or configuration change is concealed', 'supported_known_fact' => 'The replacement fact is supported and known'];

    public function source(string $kind, string $id, string $fact): object
    {
        return DB::table('restriction_sources as r')->join('subjects as s', 's.id', '=', 'r.subject_id')->join('domain_events as e', 'e.id', '=', 'r.source_event_id')->leftJoin('items as i', 'i.subject_id', '=', 'r.subject_id')->leftJoin('categories as cat', 'cat.id', '=', 'i.category_id')->where('source_kind', $kind)->where('source_id', $id)->where('fact_key', $fact)->firstOrFail(['r.*', 's.public_code', 'cat.label as category_label', 'i.subtype', 'e.recorded_at as source_recorded_at']);
    }

    public function case(User $actor, string $id): object
    {
        $case = DB::table('restriction_cases as c')->join('subjects as s', 's.id', '=', 'c.subject_id')->where('c.id', $id)->firstOrFail(['c.*', 's.public_code']);
        abort_unless($this->access($actor, $case), 404);

        return $case;
    }

    public function access(User $actor, object $case): bool
    {
        if ($actor->role() === Role::Consumer) {
            return DB::table('items')->where('subject_id', $case->subject_id)->where('owner_user_id', $actor->id)->exists();
        }
        if (($case->contract ?? 'C14') === 'P15' && ! $case->active
            && DB::table('restriction_records')->where('case_id', $case->id)->where('actor_id', $actor->id)->exists()
            && DB::selectOne('SELECT media_review_scope(?,?) AS allowed', [$actor->id, $case->subject_id])->allowed) {
            return true;
        }

        return (bool) DB::selectOne('SELECT restriction_admin(?) OR restriction_investigator(?,?) OR restriction_clarifier(?,?) AS allowed', [$actor->id, $case->id, $actor->id, $case->id, $actor->id])->allowed;
    }

    public function investigator(User $actor, string $case): bool
    {
        return (bool) DB::selectOne('SELECT restriction_investigator(?,?) AS allowed', [$case, $actor->id])->allowed;
    }

    public function actor(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $current->setRelation('membership', $current->membership()->lockForUpdate()->first());
        abort_unless($current->canSignIn() && $current->session_version === $actor->session_version, 403);

        return $current;
    }

    public function dependencies(string $subject): array
    {
        return json_decode(DB::selectOne('SELECT restriction_dependency_context(?) AS data', [$subject])->data, true, flags: JSON_THROW_ON_ERROR);
    }

    public function actorContext(string $actor): array
    {
        return json_decode(DB::selectOne('SELECT restriction_actor_context(?) AS data', [$actor])->data, true, flags: JSON_THROW_ON_ERROR);
    }

    public function token(User $actor, object $source, ?object $case = null): string
    {
        return AdminMutation::hash(['source' => [$source->source_kind, $source->source_id, $source->fact_key, $source->source_version], 'case' => $case ? (array) $case : null, 'actor' => $this->actorContext($actor->id), 'dependencies' => $this->dependencies($source->subject_id)]);
    }

    public function epoch(string $subject): int
    {
        return (int) DB::selectOne('SELECT restriction_epoch(?) AS version', [$subject])->version;
    }

    public function impact(string $subject): array
    {
        return json_decode(DB::selectOne('SELECT restriction_impact_preview(?) AS data', [$subject])->data, true, flags: JSON_THROW_ON_ERROR);
    }

    public function candidates(string $subject, bool $supported = true, bool $typed = false)
    {
        return DB::table('users as u')->join('memberships as m', 'm.user_id', '=', 'u.id')->leftJoin('places as p', 'p.id', '=', 'm.place_id')->leftJoin('organizations as o', 'o.id', '=', 'm.organization_id')->where(function ($q) use ($subject, $supported, $typed) {
            $q->whereRaw($typed ? 'media_review_scope(u.id,?)' : 'restriction_staff(u.id,?)', [$subject]);
            if (! $supported) {
                $q->orWhereRaw('restriction_admin(u.id)');
            }
        })->orderBy('u.display_name')->get(['u.id', 'u.display_name', 'm.role', 'p.name as place_name', 'o.name as operator_name']);
    }

    public function proposedValue(string $fact): ?string
    {
        if (in_array($fact, ['association_confirm', 'association_end', 'discovery_error', 'discovery_supplement', 'work_configuration', 'work_outcome', 'return_report', 'impact_continuity'], true) || str_starts_with($fact, 'test_')) {
            return DB::selectOne('SELECT media_review_value(?) AS value', [$fact])->value;
        }

        return DB::selectOne('SELECT restriction_known_value(?) AS value', [$fact])->value;
    }
}
