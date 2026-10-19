<?php

namespace App\Support\Batches;

use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\AdminMutation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Context
{
    public const LABELS = ['draft' => 'Save draft selection', 'commit' => 'Record actual dismantling', 'output' => 'Record output observation', 'measurement' => 'Record measurement correction', 'accounted' => 'Attest complete output accounting', 'discrepancy' => 'Report unexpected media'];

    public function party(User $actor): string
    {
        $place = app(\App\Support\Assessments\Context::class)->scope($actor);
        abort_unless($place, 403, 'An active approved facility-scoped Processor is required.');

        return DB::table('parties')->where('place_id', $place->id)->value('id');
    }

    public function batch(User $actor, string $id): object
    {
        $q = DB::table('dismantling_batches as b')->join('subjects as s', 's.id', '=', 'b.subject_id')->where('b.subject_id', $id);
        if ($actor->role() === Role::Processor) {
            $q->where('b.facility_party_id', $this->party($actor));
        } else {
            abort_unless($actor->role() === Role::Consumer, 403);
            $q->whereExists(fn ($q) => $q->selectRaw('1')->from('batch_members as m')->join('items as i', 'i.subject_id', '=', 'm.subject_id')->whereColumn('m.batch_id', 'b.subject_id')->where('i.owner_user_id', $actor->id));
        }

        return $q->firstOrFail(['b.*', 's.public_code']);
    }

    public function inputs(object $batch): array
    {
        return json_decode(DB::selectOne('SELECT to_json(inputs) AS ids FROM dismantling_batches WHERE subject_id=?', [$batch->subject_id])->ids, true, flags: JSON_THROW_ON_ERROR);
    }

    public function snapshot(User $actor, object $batch, ?array $inputs = null): array
    {
        return json_decode(DB::selectOne('SELECT batch_context(?,?,?::uuid[]) AS context', [$batch->subject_id, $actor->id, '{'.implode(',', $inputs ?? $this->inputs($batch)).'}'])->context, true, flags: JSON_THROW_ON_ERROR);
    }

    public function token(User $actor, string $id): string
    {
        $batch = DB::table('dismantling_batches')->where('subject_id', $id)->first();
        $snapshot = $batch ? $this->snapshot($actor, $batch) : ['access' => json_decode(DB::selectOne('SELECT batch_access(?,?) AS context', [$actor->id, $this->party($actor)])->context, true, flags: JSON_THROW_ON_ERROR)];

        return AdminMutation::hash(['batch_id' => $id, 'version' => $batch?->version ?? 0, 'context' => $snapshot]);
    }

    public function balance(string $id): array
    {
        $balance = json_decode(DB::selectOne('SELECT batch_balance(?) AS balance', [$id])->balance, true, flags: JSON_THROW_ON_ERROR);
        if (Schema::hasTable('downstream_records') && DB::table('downstream_records')->where('batch_id', $id)->exists()) {
            $balance['status'] = DB::selectOne('SELECT downstream_status(?) AS status', [$id])->status;
        }

        return $balance;
    }

    public function blockers(User $actor, string $id): array
    {
        $row = app(\App\Support\Assessments\Context::class)->authorized($actor, $id);
        if (DB::selectOne('SELECT batch_input_ready(?,?) AS ready', [$id, $actor->id])->ready) {
            return [];
        }
        $messages = app(\App\Support\Assessments\Context::class)->blockers($row);
        $messages[] = 'Requires an intact current RECYCLE decision, complete applicable separable HDD/SSD inventory or evidenced empty inventory, or a verified basic ICT fan. Resolve retained holds, pending returns/transfers and disputed identities through their supported controls; missing correction workflows cannot be bypassed.';

        return $messages;
    }
}
