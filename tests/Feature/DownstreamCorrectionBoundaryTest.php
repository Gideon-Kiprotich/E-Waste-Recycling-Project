<?php

namespace Tests\Feature;

use App\Actions\MoveBatchOutputs;
use App\Actions\ReviewRestrictions;
use App\Support\Restrictions\Context;
use App\Support\Restrictions\DownstreamReview;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DownstreamCorrectionFixtures;
use Tests\TestCase;

class DownstreamCorrectionBoundaryTest extends TestCase
{
    use DatabaseTransactions, DownstreamCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(database_path('migrations/2026_10_22_*.php')) as $file) {
            if (! DB::table('migrations')->where('migration', basename($file, '.php'))->exists()) {
                (require $file)->up();
            }
        }
    }

    private function state(): array
    {
        $state = [];
        foreach (['restriction_cases' => 'id', 'restriction_records' => 'id', 'restriction_impacts' => 'id', 'downstream_review_effects' => 'record_id', 'downstream_fact_revisions' => 'id', 'downstream_recipient_records' => 'id', 'downstream_records' => 'id', 'downstream_batches' => 'batch_id', 'batch_outputs' => 'subject_id', 'custody_current' => 'subject_id', 'attachments' => 'id', 'commands' => 'id', 'domain_events' => 'id', 'notification_outbox' => 'id'] as $table => $key) {
            $state[$table] = DB::table($table)->orderBy($key)->get()->toJson();
        }

        return $state;
    }

    private function denied(callable $action): void
    {
        $before = $this->state();
        try {
            DB::transaction(function () use ($action) {
                $action();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $this->fail('The invalid operation must fail at its native enforcement boundary.');
            });
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [403, 404, 409]);
        } catch (PDOException $e) {
            $this->assertContains($e->errorInfo[0] ?? $e->getCode(), ['P1300', 'P1400', 'P1500', 'P1600', '23505', '23514']);
        }
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->assertSame($before, $this->state());
    }

    private function reviewWork(array $w, string $source, string $action, bool $destination = true): array
    {
        $w['subject'] = $w['batch'];
        $w['investigator'] = $destination ? $w['recipient'] : $w['receiver'];
        $w['source'] = app(Context::class)->source('downstream_correction', $source, 'downstream_'.$action);

        return $w;
    }

    public function test_unknown_loss_findings_do_not_release_stock_or_hide_the_incident(): void
    {
        $w = $this->downstreamWork();
        $loss = $this->downstreamRecord($w, 'source_loss', overrides: ['kg' => '2.000']);
        $case = $this->correction($w, $loss, 'loss_error', [$this->fact('downstream', $loss, 'active', '0')], false, ['original_error' => 'YES', 'continuous_control' => 'UNKNOWN', 'present_unallocated' => 'YES'], approve: false);
        $rw = $this->reviewWork($w, $loss, 'loss_error', false);
        $this->denied(fn () => app(ReviewRestrictions::class)->record($w['admin'], $case, 'approve', $this->restrictionFields($rw, 'approve', $case)));
        $this->assertEquals(8, app(\App\Support\Downstream\Context::class)->stock($w['output'])['reservable']);
        $this->assertSame('under_review', DB::table('restriction_cases')->where('id', $case)->value('state'));
    }

    public function test_inconsistent_measurement_vector_cannot_apply_partial_facts_or_negative_stock(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $m = DB::table('batch_outputs')->where('subject_id', $w['output'])->value('measurement_record_id');
        $case = $this->correction($w, $m, 'measurement', [$this->fact('batch', $m, 'net_kg', '9.000')], false, ['original_error' => 'YES', 'same_allocation' => 'YES'], approve: false);
        $rw = $this->reviewWork($w, $m, 'measurement', false);
        $this->denied(fn () => app(ReviewRestrictions::class)->record($w['admin'], $case, 'approve', $this->restrictionFields($rw, 'approve', $case)));
        $this->assertSame(0, DB::table('downstream_fact_revisions')->count());
        $this->assertEquals(10, app(\App\Support\Downstream\Context::class)->stock($w['output'])['dispatched']);
    }

    public function test_partial_reporting_error_retains_true_disputed_quantity_and_private_feedback(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $dispatch = $this->downstreamRecord($w, 'dispatch', $h);
        $dispute = $this->downstreamRecord($w, 'dispute', $h, true, ['kg' => '5.000']);
        $case = $this->correction($w, $dispute, 'report_error', [$this->fact('downstream', $dispute, 'kg', '3.000')], facts: ['original_error' => 'YES']);
        $this->assertEquals(7, DB::selectOne('SELECT downstream_remaining(?) AS kg', [$dispatch])->kg);
        $this->assertSame('5.000', DB::table('downstream_records')->where('id', $dispute)->value('kg'));
        $this->receiptSignIn($w['admin'])->get(route('restrictions.show', $case))->assertOk()->assertSee('3.000')->assertSee('original history', false);
        $this->receiptSignIn($w['owner'])->get(route('restrictions.show', $case))->assertNotFound();
    }

    public function test_false_completion_returns_to_pending_then_requires_separate_actual_completion(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $accept = $this->downstreamRecord($w, 'accept', $h, true);
        $old = $this->downstreamRecord($w, 'complete', $h, true);
        $this->correction($w, $old, 'completion_error', [$this->fact('downstream', $old, 'active', '0')], facts: ['original_error' => 'YES']);
        $this->assertEquals(10, DB::selectOne('SELECT downstream_remaining(?) AS kg', [$accept])->kg);
        $this->assertSame('10.000', DB::table('downstream_records')->where('id', $old)->value('kg'));
        $this->downstreamContinuities($w);
        $actual = $this->downstreamRecord($w, 'complete', $h, true);
        $this->assertNotSame($old, $actual);
        $this->assertEquals(10, app(\App\Support\Downstream\Context::class)->stock($w['output'])['completed']);
    }

    public function test_post_acceptance_loss_consumes_pending_acceptance_without_another_source_deduction(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $accept = $this->downstreamRecord($w, 'accept', $h, true);
        $loss = $this->downstreamRecord($w, 'downstream_loss', $h, true, ['related_record_id' => $accept, 'kg' => '3.001']);
        $this->correction($w, $loss, 'loss_confirm', [$this->fact('downstream', $loss, 'loss_status', 'confirmed')], facts: ['quantified_loss' => 'YES']);
        $this->downstreamContinuities($w);
        $this->downstreamRecord($w, 'complete', $h, true, ['kg' => '6.999']);
        $stock = app(\App\Support\Downstream\Context::class)->stock($w['output']);
        $this->assertEquals(0, $stock['accounted']);
        $this->assertEquals('3.001', $stock['downstream_loss']);
        $this->assertEquals('6.999', $stock['completed']);
        $this->assertTrue(DB::selectOne('SELECT downstream_closable(?) AS ok', [$w['batch']])->ok);
    }

    public function test_later_real_incident_retains_genuine_completion_and_historical_closure(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $accept = $this->downstreamRecord($w, 'accept', $h, true);
        $complete = $this->downstreamRecord($w, 'complete', $h, true);
        $close = $this->downstreamRecord($w, 'close');
        $this->denied(fn () => $this->downstreamRecord($w, 'downstream_loss', $h, true, ['related_record_id' => $accept]));
        $this->downstreamRecord($w, 'measurement_dispute', $h, true, ['shipment_id' => $h]);
        $this->assertSame($close, DB::table('downstream_batches')->where('batch_id', $w['batch'])->value('closure_record_id'));
        $this->assertSame('10.000', DB::table('downstream_effective_records')->where('id', $complete)->value('kg'));
        $this->assertSame('closed_later_evidence_exception', DB::selectOne('SELECT downstream_status(?) AS status', [$w['batch']])->status);
        $this->assertSame(1, DB::table('downstream_records')->where('kind', 'close')->count());
    }

    public function test_native_projection_and_fact_forgery_are_rejected_at_the_deferred_boundary(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->denied(fn () => DB::table('downstream_batches')->where('batch_id', $w['batch'])->update(['version' => DB::raw('version+1')]));
        $this->denied(fn () => DB::table('downstream_fact_revisions')->insert(['id' => (string) Str::uuid(), 'anchor_kind' => 'downstream', 'anchor_id' => DB::table('downstream_shipments')->where('id', $h)->value('created_record_id'), 'fact' => 'kg', 'value' => '100.000', 'version' => 1, 'predecessor_id' => null, 'review_id' => (string) Str::uuid()]));
    }

    public function test_input_precision_errors_are_associated_without_flashing_private_values(): void
    {
        $w = $this->downstreamWork();
        $m = DB::table('batch_outputs')->where('subject_id', $w['output'])->value('measurement_record_id');
        $case = $this->correction($w, $m, 'measurement', [$this->fact('batch', $m, 'net_kg', '10.001')], false, ['original_error' => 'YES', 'same_allocation' => 'YES', 'present_unallocated' => 'YES'], approve: false);
        $rw = $this->reviewWork($w, $m, 'measurement', false);
        $fields = $this->restrictionFields($rw, 'proposal', $case);
        $fields['checks'] = array_fill_keys(array_keys(DownstreamReview::CHECKS), 'YES');
        $rows = DownstreamReview::formData(app(Context::class)->case($w['receiver'], $case), $w['receiver'])['rows'];
        $fields['details'] = ['revisions' => array_map(fn ($r) => array_intersect_key($r, array_flip(['anchor_kind', 'anchor_id', 'fact', 'expected_version', 'predecessor_id'])) + ['value' => ''], $rows), 'party_findings' => [], 'facts' => ['identity_known' => 'YES', 'supported_facts' => 'YES']];
        $fields['details']['revisions'][0]['value'] = '0.0001';
        $url = route('restrictions.show', ['case' => $case, 'action' => 'proposal']);
        $this->receiptSignIn($w['receiver'])->from($url)->post(route('restrictions.store', [$case, 'proposal']), $fields)->assertRedirect($url)->assertSessionHasErrors('details.revisions.0.value')->assertSessionMissing('_old_input.details')->assertSessionMissing('_old_input.reason');
        $this->assertTrue(session('errors')->has('details.revisions.0.value'));
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get($url);
        $this->assertTrue(app('view')->shared('errors')->has('details.revisions.0.value'), 'Redirected errors must reach the view.');
        $response->assertOk()->assertSee('aria-invalid="true"', false)->assertSee('aria-describedby="details_revisions_0_value-error"', false);
    }

    public function test_upward_vector_cannot_create_unproved_available_stock_from_a_smaller_dispatch_restatement(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $reserve = $this->downstreamRecord($w, 'reserve', $h);
        $dispatch = $this->downstreamRecord($w, 'dispatch', $h, overrides: ['kg' => '5.000']);
        $this->downstreamRecord($w, 'withdraw', $h, overrides: ['kg' => '5.000']);
        $m = DB::table('batch_outputs')->where('subject_id', $w['output'])->value('measurement_record_id');
        $input = DB::table('dismantling_batches')->where('subject_id', $w['batch'])->value('input_measurement_id');
        $case = $this->correction($w, $m, 'measurement', [$this->fact('batch', $m, 'net_kg', '12.000'), $this->fact('batch', $input, 'net_kg', '12.000'), $this->fact('downstream', $reserve, 'kg', '11.000'), $this->fact('downstream', $dispatch, 'kg', '6.000')], false, ['original_error' => 'YES', 'same_allocation' => 'YES', 'present_unallocated' => 'UNKNOWN'], approve: false);
        $rw = $this->reviewWork($w, $m, 'measurement', false);
        $this->denied(fn () => app(ReviewRestrictions::class)->record($w['admin'], $case, 'approve', $this->restrictionFields($rw, 'approve', $case)));
        $this->assertEquals(5, app(\App\Support\Downstream\Context::class)->stock($w['output'])['reservable']);
    }

    public function test_review_cannot_invent_gross_or_tare_observations_for_a_net_only_original(): void
    {
        $w = $this->downstreamWork();
        $m = DB::table('batch_outputs')->where('subject_id', $w['output'])->value('measurement_record_id');
        $case = $this->correction($w, $m, 'measurement', [$this->fact('batch', $m, 'gross_kg', '11.000'), $this->fact('batch', $m, 'tare_kg', '1.000')], false, ['original_error' => 'YES', 'same_allocation' => 'YES', 'present_unallocated' => 'YES'], approve: false);
        $rw = $this->reviewWork($w, $m, 'measurement', false);
        $this->denied(fn () => app(ReviewRestrictions::class)->record($w['admin'], $case, 'approve', $this->restrictionFields($rw, 'approve', $case)));
        $this->assertSame(0, DB::table('downstream_fact_revisions')->count());
    }

    public function test_movement_evidence_reauthorizes_scope_and_denies_redacted_files(): void
    {
        Storage::fake('directory_evidence');
        $w = $this->downstreamWork();
        $fields = $this->downstreamFields($w, 'source_loss');
        $fields['evidence_kind'] = 'attachment';
        unset($fields['reference_issuer'],$fields['reference_code'],$fields['reference_attested']);
        $id = app(MoveBatchOutputs::class)->record($w['receiver'], $w['batch'], 'source_loss', $fields, UploadedFile::fake()->image('private.png'));
        $this->handoverBoundary();
        $this->receiptSignIn($w['receiver'])->get(route('downstream.evidence', $id))->assertOk();
        $this->receiptSignIn($w['recipient'])->get(route('downstream.evidence', $id))->assertNotFound();
        $attachment = DB::table('downstream_records')->where('id', $id)->value('attachment_id');
        DB::table('attachments')->where('id', $attachment)->update(['redacted_at' => now()]);
        $this->receiptSignIn($w['receiver'])->get(route('downstream.evidence', $id))->assertNotFound();
    }

    public function test_pending_succession_cannot_supply_a_named_receiver_signature_for_accounting(): void
    {
        $w = $this->downstreamWork();
        $successor = $this->pointStaff($w['admin'], $w['destination']);
        $this->handoverBoundary();
        $h = $this->shipmentDraft($w);
        $reserve = $this->downstreamRecord($w, 'reserve', $h);
        $dispatch = $this->downstreamRecord($w, 'dispatch', $h);
        $accept = $this->downstreamRecord($w, 'accept', $h, true);
        $draft = DB::table('downstream_shipments')->where('id', $h)->value('created_record_id');
        $this->correction($w, $draft, 'recipient', [], false, extra: ['receiver_actor_id' => $successor->id, 'profile_record_id' => $w['profile_record']]);
        $this->assertNull(DB::table('downstream_effective_shipments')->where('id', $h)->value('receiver_actor_id'));
        $measurement = DB::table('batch_outputs')->where('subject_id', $w['output'])->value('measurement_record_id');
        $input = DB::table('dismantling_batches')->where('subject_id', $w['batch'])->value('input_measurement_id');
        $case = $this->correction($w, $measurement, 'measurement', [$this->fact('batch', $measurement, 'net_kg', '9.900'), $this->fact('batch', $input, 'net_kg', '9.900'), $this->fact('downstream', $reserve, 'kg', '9.900'), $this->fact('downstream', $dispatch, 'kg', '9.900'), $this->fact('downstream', $accept, 'kg', '9.900')], true, ['original_error' => 'YES', 'same_allocation' => 'YES'], both: true, approve: false);
        $proposal = DB::table('restriction_cases')->where('id', $case)->value('record_id');
        $this->assertFalse(DB::selectOne('SELECT downstream_vector_valid(c,p) AS valid FROM restriction_cases c JOIN restriction_records p ON p.id=? WHERE c.id=?', [$proposal, $case])->valid, 'Pending responsibility has no named recipient who can sign this restatement.');
        $rw = $this->reviewWork($w, $measurement, 'measurement', true);
        $this->denied(fn () => app(ReviewRestrictions::class)->record($w['admin'], $case, 'approve', $this->restrictionFields($rw, 'approve', $case)));
    }

    public function test_a_new_canonical_draft_cannot_normalize_a_fraction_into_a_whole_unit(): void
    {
        $w = $this->downstreamWork(true);
        $this->denied(fn () => $this->shipmentDraft($w, ['kg' => '0.250']));
        $h = $this->shipmentDraft($w);
        $this->assertSame('0.500', DB::table('downstream_shipments')->where('id', $h)->value('requested_kg'));
        $this->assertSame(1, DB::table('downstream_shipments')->count());
    }
}
