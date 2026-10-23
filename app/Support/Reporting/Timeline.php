<?php

namespace App\Support\Reporting;

use App\Enums\Role;
use App\Models\User;
use App\Support\Assessments\Context;
use App\Support\Assignments\Eligibility;
use App\Support\Notices\Catalogue;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class Timeline
{
    public function subject(User $actor, string $id): object
    {
        abort_unless($actor->canSignIn(), 403);
        $row = DB::table('items as i')->join('subjects as s', 's.id', '=', 'i.subject_id')->where('i.subject_id', $id)->first(['i.*', 's.public_code']);
        abort_unless($row, 404);
        if ($actor->role() === Role::Consumer) {
            abort_unless($row->owner_user_id === $actor->id, 404);
        } elseif ($actor->role() === Role::Processor) {
            app(Context::class)->authorized($actor, $id);
        } elseif ($actor->role() === Role::Collector) {
            $valid = false;
            foreach (DB::table('assignments as a')->join('request_items as ri', 'ri.request_id', '=', 'a.request_id')->where('ri.item_id', $id)->where('ri.active', true)->where('a.collector_id', $actor->id)->where('a.active', true)->whereIn('a.state', ['offered', 'accepted'])->get(['a.*']) as $a) {
                $valid = $valid || app(Eligibility::class)->current($a);
            }
            abort_unless($valid, 404);
        } else {
            // Aggregate oversight and an individual case grant do not authorize private lineage.
            abort(403);
        }

        return $row;
    }

    public function status(User $actor, string $id): array
    {
        $item = $this->subject($actor, $id);
        $facts = DB::selectOne('SELECT reporting_subject_exception(?) AS exception, reporting_reuse_historical(?) AS returned, reporting_reuse_qualified(?) AS qualified', [$id, $id, $id]);
        $batches = DB::table('batch_members')->where('subject_id', $id)->pluck('batch_id')
            ->merge(DB::table('batch_detachments')->where('subject_id', $id)->pluck('batch_id'))
            ->merge(DB::table('batch_media_discoveries')->where('medium_id', $id)->pluck('batch_id'))->unique();
        $closed = 0;
        $exception = $facts->exception;
        foreach ($batches as $batch) {
            $current = DB::selectOne('SELECT downstream_closed(?) AS closed, downstream_blocked(?) AS blocked, downstream_status(?) AS status', [$batch, $batch, $batch]);
            $closed += (int) $current->closed;
            $exception = $exception || $current->blocked || $current->status === 'closed_later_evidence_exception';
        }
        $outcome = match (true) {
            $exception => 'Current exception requires authorized review; original history remains.',
            $facts->qualified => 'Reuse return acknowledged and currently qualified.',
            $facts->returned => 'Reuse return historically acknowledged; current qualification is unavailable.',
            count($batches) > 0 && $closed === count($batches) => 'Linked batch outcomes recorded; this does not assign an individual yield.',
            $closed > 0 => 'Recorded batch closure exists; other linked outcomes remain pending.',
            default => 'Outcome pending; no currently qualified return or linked batch closure.',
        };

        return ['stage' => ucfirst($item->processing_state), 'outcome' => $outcome,
            'origin' => $item->intake_class === 'processor_derived' ? 'Media identified at a facility; this is not a new consumer collection.' : 'Consumer-origin physical subject; storage facets do not add another item.'];
    }

    public function events(User $actor, string $id, ?string $cursorToken = null)
    {
        $row = $this->subject($actor, $id);
        $cursor = $this->cursor($cursorToken);
        $batchIds = DB::table('batch_members')->where('subject_id', $id)->pluck('batch_id')
            ->merge(DB::table('batch_detachments')->where('subject_id', $id)->pluck('batch_id'))
            ->merge(DB::table('batch_media_discoveries')->where('medium_id', $id)->pluck('batch_id'))->unique();
        $inventoryEvents = DB::table('media_inventory_records')->where('parent_id', $id)->orWhere('medium_id', $id)->pluck('event_id');
        $discoveryReviews = DB::table('batch_media_discoveries')->where('parent_id', $id)->orWhere('medium_id', $id)->pluck('review_id');
        $inventoryEvents = $inventoryEvents->merge(DB::table('restriction_records')->whereIn('id', $discoveryReviews)->pluck('event_id'));
        $requestIds = DB::table('request_items')->where('item_id', $id)->pluck('request_id');
        $assignmentIds = DB::table('assignments')->whereIn('request_id', $requestIds)->pluck('id');
        $handoverIds = DB::table('handover_items')->where('subject_id', $id)->pluck('handover_id');
        $caseIds = DB::table('restriction_cases')->whereIn('subject_id', collect([$id])->merge($batchIds))->pluck('id');
        $q = DB::table('domain_events as e')->where(function ($q) use ($id, $batchIds, $requestIds, $assignmentIds, $handoverIds, $caseIds, $inventoryEvents) {
            $q->whereIn('e.id', $inventoryEvents)->orWhere('e.subject_id', $id)->orWhere(fn ($q) => $q->where('e.aggregate_kind', 'request')->whereIn('e.aggregate_id', $requestIds))
                ->orWhere(fn ($q) => $q->where('e.aggregate_kind', 'assignment')->whereIn('e.aggregate_id', $assignmentIds))
                ->orWhere(fn ($q) => $q->where('e.aggregate_kind', 'handover')->whereIn('e.aggregate_id', $handoverIds))
                ->orWhere(fn ($q) => $q->whereIn('e.aggregate_kind', ['batch', 'downstream'])->whereIn('e.aggregate_id', $batchIds))
                ->orWhere(fn ($q) => $q->where('e.aggregate_kind', 'restriction')->whereIn('e.aggregate_id', $caseIds));
        })->whereNotIn('e.type', ['downstream_accepted', 'batch_draft', 'downstream_draft', 'downstream_reserve', 'downstream_withdraw']);
        // Prefer the selected subject's physical member event over its same-command operation.
        $q->whereNotExists(fn ($sub) => $sub->from('domain_events as member')->whereColumn('member.command_id', 'e.command_id')->where('member.subject_id', $id)->whereColumn('member.id', '<>', 'e.id')->whereIn('member.type', ['first_receipt', 'handover_accepted', 'reuse_returned', 'dismantled_in_batch'])->whereIn('e.type', ['physical_visit_recorded', 'handover_acknowledged', 'refurbishment_acknowledge', 'batch_commit']));
        $events = $q->orderByDesc('recorded_at')->orderByDesc('id')->cursorPaginate(25, ['e.id', 'e.type', 'e.occurred_at', 'e.recorded_at', 'e.aggregate_version', 'e.aggregate_kind', 'e.aggregate_id'], 'cursor', $cursor);
        $events->through(fn ($e) => ['id' => $e->id, 'label' => Catalogue::label($e->type), 'observed_at' => $e->occurred_at, 'recorded_at' => $e->recorded_at, 'version' => $e->aggregate_version, 'reference' => $e->aggregate_kind === 'restriction' ? $e->aggregate_id : null]);

        return $events;
    }

    private function cursor(?string $token): ?Cursor
    {
        if ($token === null) {
            return null;
        }
        try {
            abort_if(strlen($token) > 2048, 422, 'Invalid timeline cursor.');
            $cursor = Cursor::fromEncoded($token);
            $values = $cursor?->toArray();
            abort_unless(is_array($values) && count($values) === 3 && is_bool($values['_pointsToNextItems'] ?? null), 422, 'Invalid timeline cursor.');
            $valid = Validator::make($values, ['id' => ['required', 'uuid'], 'recorded_at' => ['required', 'date', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}(?::?[0-9]{2})?)\z/']])->passes();
            abort_unless($valid, 422, 'Invalid timeline cursor.');

            return $cursor;
        } catch (\Throwable) {
            abort(422, 'Invalid timeline cursor.');
        }
    }
}
