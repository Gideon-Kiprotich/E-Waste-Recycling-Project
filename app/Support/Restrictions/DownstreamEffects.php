<?php

namespace App\Support\Restrictions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DownstreamEffects
{
    public function prepare(array $details, User $actor, string $batch): array
    {
        foreach (['party_actor_id' => 'party_context', 'receiver_actor_id' => 'receiver_context'] as $field => $context) {
            if (isset($details[$field])) {
                if (! DB::selectOne('SELECT downstream_review_scope(?,?) AS ok', [$details[$field], $batch])->ok) {
                    throw ValidationException::withMessages(['details.'.$field => 'Choose a current capable actor at the actual source or destination facility.']);
                }
                $details[$context] = app(Context::class)->actorContext($details[$field]);
            }
        }

        if (isset($details['revisions'])) {
            // Preserve decimal text; native approval compares the exact pre-revision stock map.
            $details['before_stock'] = DB::selectOne('SELECT downstream_stock_map(?)::text AS stock', [$batch])->stock;
        }

        return $details;
    }

    public function apply(User $actor, object $case, object $review): void
    {
        $proposal = DB::table('restriction_records')->where('id', $review->proposal_id)->firstOrFail();
        $details = json_decode($proposal->details, true, flags: JSON_THROW_ON_ERROR);
        if ($review->kind !== 'approve') {
            return;
        }
        foreach ($details['revisions'] as $fact) {
            DB::table('downstream_fact_revisions')->insert(['id' => Str::uuid()->toString(), 'anchor_kind' => $fact['anchor_kind'], 'anchor_id' => $fact['anchor_id'], 'fact' => $fact['fact'], 'version' => $fact['expected_version'] + 1, 'predecessor_id' => $fact['predecessor_id'] ?? null, 'value' => $fact['value'], 'review_id' => $review->id]);
        }
        if ($case->fact_key === 'downstream_recipient') {
            $h = DB::table('downstream_shipments')->where('created_record_id', $case->source_id)->firstOrFail();
            $previous = DB::table('downstream_recipient_current')->where('shipment_id', $h->id)->first();
            $membership = DB::table('memberships')->where('user_id', $details['receiver_actor_id'])->lockForUpdate()->firstOrFail();
            DB::table('downstream_recipient_records')->insert(['id' => Str::uuid()->toString(), 'shipment_id' => $h->id, 'version' => ($previous?->version ?? 0) + 1, 'predecessor_id' => $previous?->id, 'kind' => 'proposed', 'review_id' => $review->id, 'receiver_actor_id' => $details['receiver_actor_id'], 'membership_id' => $membership->id, 'scope_version' => $membership->version, 'profile_record_id' => $details['profile_record_id'], 'actor_id' => $actor->id, 'reason_ciphertext' => $review->reason_ciphertext, 'actor_context' => $review->actor_context, 'receiver_context' => json_encode($details['receiver_context'], JSON_THROW_ON_ERROR), 'command_id' => $review->command_id, 'event_id' => $review->event_id, 'observed_at' => $review->observed_at, 'recorded_at' => $review->recorded_at, 'attachment_id' => $review->attachment_id, 'reference_ciphertext' => $review->reference_ciphertext]);
        }
    }

    public function finish(object $case, object $review): void
    {
        $proposal = DB::table('restriction_records')->where('id', $review->proposal_id)->firstOrFail();
        DB::table('downstream_review_effects')->insert(['record_id' => $review->id, 'case_id' => $case->id, 'proposal_id' => $proposal->id, 'action' => substr($case->fact_key, 11), 'observer_id' => $proposal->actor_id, 'context' => DB::selectOne('SELECT downstream_live_proof(?) AS proof', [$case->subject_id])->proof]);
    }
}
