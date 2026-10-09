<?php

namespace Tests\Support;

use App\Actions\CollectionRequests;
use App\Actions\ManageCollector;
use App\Actions\ReviewRequest;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait AssignmentFixtures
{
    use CollectionFixtures;

    protected function allocationSetup(): array
    {
        $this->directory();
        // Synthetic directory fixtures explicitly support both collection and receipt.
        $org = DB::table('organizations')->first();
        DB::table('organizations')->where('id', $org->id)->update(['kind' => 'both', 'review_status' => 'approved']);
        DB::table('places')->where('organization_id', $org->id)->update(['active' => true, 'review_status' => 'approved']);
        $place = DB::table('places')->where('organization_id', $org->id)->where('code', 'synthetic-study-all-ict')->first();
        $admin = User::factory()->create();
        $admin->membership()->update(['role' => Role::Administrator]);
        $admin->refresh();
        $owner = User::factory()->create();
        $collector = User::factory()->create();
        $collector->membership()->update(['role' => Role::Collector]);
        $collector->refresh();
        app(ManageCollector::class)->save($admin, $collector->id, ['operation' => 'link', 'organization_ref' => $org->id.':'.$org->version, 'expected_version' => 1, 'reason' => 'Synthetic access fixture', 'idempotency_key' => Str::uuid()->toString()]);

        return [$admin, $owner, $collector->refresh(), $place->id];
    }

    protected function readyRequest(User $admin, User $owner, array $items = [], string $mode = 'pickup'): string
    {
        $items = $items ?: [$this->device($owner, 'standalone_part', 'ssd')->subject_id];
        $id = app(CollectionRequests::class)->submit($owner, $this->requestFields($items, $mode));
        $this->ready($admin, $id);

        return $id;
    }

    protected function ready(User $admin, string $id): void
    {
        app(ReviewRequest::class)->decide($admin, $id, ['decision' => 'ready', 'reason' => 'Synthetic review', 'expected_version' => DB::table('requests')->where('id', $id)->value('version'), 'idempotency_key' => Str::uuid()->toString()]);
    }

    protected function offerFields(string $id, User $collector, string $place): array
    {
        $request = DB::table('requests')->where('id', $id)->first();
        $member = DB::table('memberships')->where('user_id', $collector->id)->first();

        return ['operation' => 'offer', 'membership_ref' => $member->id.':'.$member->version, 'facility_ref' => $place.':'.DB::table('places')->where('id', $place)->value('version'), 'expected_request_version' => $request->version, 'expected_review_id' => $request->review_id, 'reason' => 'Private synthetic allocation reason', 'idempotency_key' => Str::uuid()->toString()];
    }

    protected function responseFields(string $id, string $operation = 'accept'): array
    {
        return ['operation' => $operation, 'expected_version' => DB::table('assignments')->where('id', $id)->value('version'), 'idempotency_key' => Str::uuid()->toString(), 'reason' => 'Private synthetic response reason'];
    }
}
