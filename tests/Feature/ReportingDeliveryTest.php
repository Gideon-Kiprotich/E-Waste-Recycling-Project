<?php

namespace Tests\Feature;

use App\Actions\AcceptDownstreamResponsibility;
use App\Enums\Role;
use App\Http\Controllers\InboxController;
use App\Models\User;
use App\Support\Assignments\Notices;
use App\Support\Notices\Delivery;
use App\Support\Notices\Milestones;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DownstreamCorrectionFixtures;
use Tests\Support\ReportingSchema;
use Tests\TestCase;

class ReportingDeliveryTest extends TestCase
{
    use DownstreamCorrectionFixtures, ReportingSchema;

    public function test_failure_rolls_back_inbox_and_persists_safe_five_attempt_backoff_then_scoped_retry(): void
    {
        $owner = User::factory()->create();
        $this->device($owner);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $id = DB::table('notification_outbox')->value('id');
        $failing = function ($row) {
            DB::table('milestone_notifications')->insert(['id' => (string) Str::uuid(), 'outbox_id' => $row->id, 'event_id' => $row->event_id, 'recipient_id' => $row->recipient_id]);
            throw new \RuntimeException('PRIVATE contact/evidence must never be persisted as delivery error');
        };
        $base = now();
        $elapsed = 0;
        foreach ([1, 5, 15, 60, null] as $index => $delay) {
            $now = $base->copy()->addMinutes($elapsed);
            $this->travelTo($now);
            $q = DB::table('notification_outbox as n')->where('n.id', $id)->where('n.state', 'pending')->select('n.*');
            $this->assertSame(0, app(Delivery::class)->run($q, 'n', $failing));
            $row = DB::table('notification_outbox')->where('id', $id)->first();
            $this->assertSame($index + 1, $row->attempts);
            $this->assertSame('delivery_failed', $row->delivery_error);
            $this->assertSame(0, DB::table('milestone_notifications')->count());
            if ($delay !== null) {
                $this->assertSame($now->copy()->addMinutes($delay)->toDateTimeString(), CarbonImmutable::parse($row->next_attempt_at)->toDateTimeString());
                $this->assertSame(0, app(Milestones::class)->deliver());
                $elapsed += $delay;
            }
        }
        $this->assertSame('failed', $row->state);
        $this->assertNull($row->next_attempt_at);
        $this->travelBack();
        $this->actingAs($owner)->withSession(['auth_session_version' => $owner->session_version])->post(route('inbox.retry', $id))->assertForbidden();
        $admin = User::factory()->create();
        $admin->membership()->update(['role' => Role::Administrator]);
        $admin->refresh();
        $this->actingAs($admin)->withSession(['auth_session_version' => $admin->session_version])->post(route('inbox.retry', $id))->assertRedirect();
        $this->assertSame(1, DB::table('notice_delivery_retries')->count());
        $this->assertSame(1, app(Milestones::class)->deliver());
        $this->assertSame('delivered', DB::table('notification_outbox')->where('id', $id)->value('state'));
        $this->assertSame(1, DB::table('milestone_notifications')->count());
    }

    public function test_suppressed_recipient_does_not_revive_when_membership_is_restored(): void
    {
        $owner = User::factory()->create();
        $this->device($owner);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $owner->membership()->update(['active' => false]);
        $this->assertSame(0, app(Milestones::class)->deliver());
        $this->assertSame('suppressed', DB::table('notification_outbox')->value('state'));
        $owner->membership()->update(['active' => true]);
        $this->assertSame(0, app(Milestones::class)->deliver());
        $this->assertSame(0, DB::table('milestone_notifications')->count());
    }

    public function test_manual_retry_rechecks_suspended_actor_after_the_request_authentication_snapshot(): void
    {
        $actor = User::factory()->create();
        $actor->membership()->update(['role' => Role::Administrator]);
        $actor->refresh()->load('membership');
        $request = Request::create('/inbox/delivery/test/retry', 'POST');
        $request->setUserResolver(fn () => $actor);
        DB::table('users')->where('id', $actor->id)->update(['status' => 'suspended', 'session_version' => 2]);
        try {
            app(InboxController::class)->retry($request, (string) Str::uuid());
            $this->fail('Suspended operator could retry.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_negative_treatment_notifies_and_success_alone_is_not_an_assurance_notice(): void
    {
        $w = $this->mediaWork('ssd');
        $failed = $this->mediaRecord($w, 'attempt', $this->mediaFields($w, 'attempt', 'failed'));
        $event = DB::table('sanitization_records')->where('id', $failed)->value('event_id');
        app(Milestones::class)->deliver(1000);
        $this->assertEqualsCanonicalizing([$w['owner']->id, $w['receiver']->id], DB::table('milestone_notifications')->where('event_id', $event)->pluck('recipient_id')->all());
        $success = $this->mediaRecord($w, 'attempt');
        $positive = DB::table('sanitization_records')->where('id', $success)->value('event_id');
        $this->assertSame(0, DB::table('notification_outbox')->where('event_id', $positive)->count());
        $this->mediaRecord($w, 'verification');
        $validation = $this->mediaRecord($w, 'validation');
        app(Milestones::class)->deliver(1000);
        $validationEvent = DB::table('sanitization_records')->where('id', $validation)->value('event_id');
        $this->assertSame(2, DB::table('milestone_notifications')->where('event_id', $validationEvent)->count());
    }

    public function test_responsibility_acceptance_has_one_owning_family_and_never_creates_physical_receipt(): void
    {
        $w = $this->downstreamWork();
        $successor = $this->pointStaff($w['admin'], $w['destination']);
        $this->handoverBoundary();
        $shipment = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $shipment);
        $this->downstreamRecord($w, 'dispatch', $shipment);
        $source = DB::table('downstream_shipments')->where('id', $shipment)->value('created_record_id');
        $this->correction($w, $source, 'recipient', [], false, extra: ['receiver_actor_id' => $successor->id, 'profile_record_id' => $w['profile_record']]);
        $action = app(AcceptDownstreamResponsibility::class);
        $record = $action->record($successor, $shipment, 'accepted', $this->handoverEvidence() + ['attested' => '1', 'expected_version' => 1, 'expected_context' => $action->token($successor, $shipment), 'reason' => 'Synthetic responsibility; no physical acknowledgment']);
        $this->handoverBoundary();
        foreach ([Notices::class, \App\Support\Receipts\Notices::class, \App\Support\Handovers\Notices::class, \App\Support\Assessments\Notices::class, \App\Support\Restrictions\Notices::class, Milestones::class] as $family) {
            app($family)->deliver(1000);
        }
        $event = DB::table('downstream_recipient_records')->where('id', $record)->value('event_id');
        $notices = DB::table('milestone_notifications')->where('event_id', $event)->pluck('recipient_id')->all();
        $this->assertEqualsCanonicalizing([$successor->id, $w['receiver']->id], $notices);
        $this->assertSame(0, DB::table('downstream_records')->where('kind', 'accept')->count());
        $this->assertSame(0, DB::table('domain_events')->where('type', 'downstream_accepted')->count());
        $this->assertSame(0, DB::table('notification_outbox')->where('delivery_error', 'delivery_failed')->count());
        $this->assertGreaterThan(0, DB::table('notification_outbox')->where('recipient_id', $w['recipient']->id)->where('state', 'suppressed')->count());
        $duplicates = DB::selectOne('SELECT count(*) count FROM (SELECT outbox_id FROM (SELECT outbox_id FROM assignment_notifications UNION ALL SELECT outbox_id FROM receipt_notifications UNION ALL SELECT outbox_id FROM handover_notifications UNION ALL SELECT outbox_id FROM assessment_notifications UNION ALL SELECT outbox_id FROM restriction_notifications UNION ALL SELECT outbox_id FROM milestone_notifications) n GROUP BY outbox_id HAVING count(*)>1) d')->count;
        $this->assertSame(0, $duplicates);
        $ownerCaseNotices = DB::table('restriction_notifications')->where('recipient_id', $w['owner']->id)->count();
        $this->assertGreaterThan(0, $ownerCaseNotices);
    }

    public function test_native_deferred_delivery_rejects_success_without_inbox_and_mismatched_recipient(): void
    {
        $owner = User::factory()->create();
        $this->device($owner);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $row = DB::table('notification_outbox')->first();
        foreach (['missing_inbox', 'wrong_recipient', 'mutated_tag'] as $mode) {
            try {
                DB::transaction(function () use ($mode, $row) {
                    if ($mode === 'missing_inbox') {
                        DB::table('notification_outbox')->where('id', $row->id)->update(['state' => 'delivered']);
                        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                    } elseif ($mode === 'wrong_recipient') {
                        $other = User::factory()->create();
                        DB::table('milestone_notifications')->insert(['id' => (string) Str::uuid(), 'outbox_id' => $row->id, 'event_id' => $row->event_id, 'recipient_id' => $other->id]);
                    } else {
                        DB::table('notification_outbox')->where('id', $row->id)->update(['delivery_version' => null]);
                    }
                });
                $this->fail('Invalid native notice state accepted.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('pending', DB::table('notification_outbox')->where('id', $row->id)->value('state'));
        $this->assertSame(1, app(Milestones::class)->deliver());
    }
}
