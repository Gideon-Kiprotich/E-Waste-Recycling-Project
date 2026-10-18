<?php

namespace App\Support\Refurbishment;

use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\AdminMutation;
use Illuminate\Support\Facades\DB;

class Context
{
    public const LABELS = ['work' => 'Record work', 'test' => 'Record functional test', 'fan_na' => 'Record fan N/A', 'authorize' => 'Authorize owner return', 'withdraw' => 'Withdraw authorization', 'exception' => 'Report incomplete or mismatched return', 'acknowledge' => 'Acknowledge complete receipt'];

    public function item(User $actor, string $subject): object
    {
        if ($actor->role() === Role::Processor) {
            $row = app(\App\Support\Assessments\Context::class)->authorized($actor, $subject);
            $row->intake_class = DB::table('items')->where('subject_id', $subject)->value('intake_class');
        } else {
            abort_unless($actor->role() === Role::Consumer, 403);
            $row = DB::table('items as i')->join('subjects as s', 's.id', '=', 'i.subject_id')->where('i.subject_id', $subject)->where('i.owner_user_id', $actor->id)->firstOrFail(['i.*', 's.public_code']);
        }
        abort_unless(in_array($row->intake_class, ['whole_device', 'standalone_part'], true), 404);

        return $row;
    }

    public function version(string $subject): int
    {
        return (int) (DB::table('refurbishment_current')->where('subject_id', $subject)->value('version') ?? 0);
    }

    public function snapshot(string $subject, string $actor): array
    {
        return json_decode(DB::selectOne('SELECT refurbishment_context(?,?) AS context', [$subject, $actor])->context, true, flags: JSON_THROW_ON_ERROR);
    }

    public function pending(string $subject): ?object
    {
        return DB::table('reuse_authorizations')->where('subject_id', $subject)->where('state', 'pending')->first();
    }

    public function token(User $actor, string $subject): string
    {
        if ($actor->role() === Role::Consumer) {
            $a = $this->pending($subject);
            $staff = $a ? DB::table('refurbishment_records')->where('id', $a->id)->value('actor_id') : $actor->id;

            return AdminMutation::hash($this->snapshot($subject, $staff) + ['owner_session' => $actor->session_version]);
        }

        return AdminMutation::hash($this->snapshot($subject, $actor->id));
    }

    public function blockers(string $subject, string $staff, bool $release = false): array
    {
        $messages = [];
        $ready = DB::selectOne('SELECT refurbishment_ready(?,?) AS ready', [$subject, $staff])->ready;
        if (! $ready) {
            $row = app(\App\Support\Assessments\Context::class)->rows(User::findOrFail($staff))->where('i.subject_id', $subject)->first();
            if ($row) {
                $messages = app(\App\Support\Assessments\Context::class)->blockers($row);
            }
            $inspection = app(\App\Support\Assessments\Context::class);
            if ($inspection->restricted($subject)) {
                $messages[] = 'A retained inspection hard hold requires the reviewed correction workflow, which is unavailable. Routine reassessment cannot clear it.';
            }
            $decision = DB::table('assessment_current as ac')->join('decisions as d', 'd.id', '=', 'ac.decision_id')->where('ac.subject_id', $subject)->value('d.pathway');
            if ($decision !== 'refurbish') {
                $messages[] = 'Open Assessments / Inspection and record a current human REFURBISH decision; a recommendation or RECYCLE/HOLD decision is insufficient.';
            }
            if (DB::table('media_inventory_entries')->where('medium_id', $subject)->exists()) {
                $messages[] = 'This medium has a retained parent association and cannot return independently. Use the intact parent owner-return manifest, or Dismantling / Batches for permitted canonical detachment; that does not authorize an independent return.';
            }
            if (DB::table('media_inventory_entries')->where('parent_id', $subject)->whereIn('state', ['unresolved', 'disputed'])->exists()) {
                $messages[] = 'Open Media inventory to resolve unbound uncertainty. A disputed canonical association requires unavailable reviewed correction; it cannot be omitted from a return.';
            }
            if (DB::table('refurbishment_records')->where('subject_id', $subject)->where('kind', 'exception')->exists()) {
                $messages[] = 'An incomplete/mismatched return exception retains facility custody and blocks another routine release. Reviewed correction is unavailable.';
            }
            if (DB::table('refurbishment_records')->where('subject_id', $subject)->where(fn ($q) => $q->whereIn('configuration', ['CHANGED', 'UNKNOWN'])->orWhereRaw("answers->>'safety' IN ('FAIL','UNKNOWN') OR answers->>'configuration' IN ('FAIL','UNKNOWN')"))->exists()) {
                $messages[] = 'Recorded safety/configuration uncertainty remains restricted. Replacement and the reviewed correction workflow are unavailable; new notes or routine reassessment cannot clear it.';
            }
            if (! $messages) {
                $messages[] = 'After failed/incomplete work or testing, record a fresh inspection and a fresh human decision in Assessments / Inspection before further work. A new decision on the old inspection is insufficient.';
            }
        }
        if ($release) {
            if (! DB::selectOne('SELECT refurbishment_test_ready(?) AS ready', [$subject])->ready) {
                $messages[] = 'Record completed work with unchanged configuration and a current passed functional/safety test. New work invalidates old tests. Changed inspection/inventory needs a fresh test against the latest actual work; do not invent repeated repairs.';
            }
            if (! DB::selectOne('SELECT refurbishment_data_ready(?) AS ready', [$subject])->ready) {
                $item = DB::table('items')->where('subject_id', $subject)->firstOrFail();
                if ($item->intake_class === 'whole_device') {
                    $coverage = app(\App\Support\MediaInventory\Context::class)->coverage($subject);
                    $messages[] = 'Open Media inventory / Media sanitization: '.implode('; ', $coverage['reasons']).($coverage['destruction_concern'] ? ' Destruction concern permanently blocks reuse of this claimed configuration.' : '');
                } elseif (in_array($item->subtype, ['hdd', 'ssd'], true)) {
                    $state = app(\App\Support\Sanitization\Context::class)->state($subject);
                    $messages[] = 'Open Media sanitization: '.\App\Support\Sanitization\Context::labels()[$state].'. Any destruction concern prevents reuse even when destruction treatment is satisfied.';
                } else {
                    $messages[] = 'Record fan N/A with explicit private evidence and current qualifying basic-fan technician findings establishing NO. Consumer declarations or unknown electronics cannot clear the gate.';
                }
            }
        }

        return $messages;
    }

    public function manifest(string $authorization)
    {
        return DB::table('reuse_manifest as m')->join('subjects as s', 's.id', '=', 'm.subject_id')->join('items as i', 'i.subject_id', '=', 'm.subject_id')->where('m.authorization_id', $authorization)->orderBy('s.public_code')->get(['m.subject_id', 's.public_code', 'i.model', 'i.subtype']);
    }
}
