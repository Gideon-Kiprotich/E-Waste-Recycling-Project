<?php

namespace Tests\Feature;

use App\Actions\AuthorizeBatchMedia;
use App\Actions\InspectItems;
use App\Actions\ReviewRestrictions;
use App\Models\User;
use App\Support\Devices\Identifiers;
use App\Support\MediaInventory\Context;
use App\Support\Restrictions\Effective;
use App\Support\Restrictions\MediaReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\RestrictionFixtures;
use Tests\TestCase;

class MediaCorrectionTest extends TestCase
{
    use RestrictionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('database.connections.pgsql');
        $this->assertSame('ewaste_testing', $config['database']);
        $config['search_path'] = 'media_correction_'.str_replace('-', '', Str::uuid()->toString());
        config(['database.connections.media_correction' => $config]);
        DB::setDefaultConnection('media_correction');
        Schema::clearResolvedInstance('db.schema');
        DB::beginTransaction();
        DB::statement('CREATE SCHEMA "'.$config['search_path'].'"');
        $files = glob(database_path('migrations/*.php'));
        sort($files);
        foreach ($files as $file) {
            if (basename($file) >= '2026_10_21_000002_seal_obligation_signatures.php' && $this->name() === 'test_restored_actor_scope_requires_fresh_obligation_signatures_without_rewriting_history') {
                continue; // This historical upgrade must seal Phase 15 before loading later wrappers.
            }
            (require $file)->up();
        }
    }

    protected function tearDown(): void
    {
        try {
            while (DB::connection('media_correction')->transactionLevel() > 0) {
                DB::connection('media_correction')->rollBack();
            }
            DB::setDefaultConnection('pgsql');
            Schema::clearResolvedInstance('db.schema');
            DB::purge('media_correction');
        } finally {
            parent::tearDown();
        }
    }

    private function typedWork(array $w, string $type, ?string $source = null): array
    {
        $w['subject'] = str_starts_with($type, 'discovery') ? $w['batch'] : $w['ids'][0];
        $w['investigator'] = $w['receiver'];
        $w['source'] = DB::table('restriction_sources')->where('source_kind', 'media_correction')->where('subject_id', $w['subject'])->where('fact_key', $type)->when($source, fn ($q) => $q->where('source_id', $source))->firstOrFail();

        return $w;
    }

    private function typedProposal(array $w, string $case, array $details = []): string
    {
        $f = $this->restrictionFields($w, 'proposal', $case);
        $f['checks'] = array_fill_keys(array_keys(MediaReview::checks($w['source']->fact_key)), 'YES');
        $f['details'] = $details;

        return app(ReviewRestrictions::class)->record($w['investigator'], $case, 'proposal', $f);
    }

    public static function reconfirmationKinds(): array
    {
        return [['ssd'], ['other_nonvolatile']];
    }

    #[DataProvider('reconfirmationKinds')]
    public function test_reconfirmation_retains_the_same_origin_and_requires_fresh_inventory(string $kind): void
    {
        $w = $this->inventoryWork();
        $medium = $this->identifyMedium($w, $kind);
        $entry = DB::table('media_inventory_entries')->where('medium_id', $medium)->firstOrFail();
        $origin = DB::table('media_origins')->where('subject_id', $medium)->first();
        $custody = DB::table('custody_current')->where('subject_id', $medium)->first();
        $this->inventoryRecord($w, 'correct', ['entry_id' => $entry->id, 'entry_state' => 'disputed']);
        $w = $this->typedWork($w, 'association_confirm');
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->typedProposal($w, $case);
        $this->handoverBoundary();
        $this->restrictionRecord($w, $case, 'approve');
        $this->assertSame('confirmed', DB::table('media_inventory_entries')->where('id', $entry->id)->value('state'));
        $this->assertEquals($origin, DB::table('media_origins')->where('subject_id', $medium)->first());
        $this->assertEquals($custody, DB::table('custody_current')->where('subject_id', $medium)->first());
        $this->assertSame('review_binding', DB::table('media_inventory_records')->where('id', DB::table('media_inventory_current')->where('parent_id', $w['subject'])->value('record_id'))->value('kind'));
        $this->assertSame('blocked', app(Context::class)->coverage($w['subject'])['status']);
        $this->assertDatabaseCount('first_receipts', 1);
        if ($kind === 'other_nonvolatile') {
            $this->assertFalse(DB::selectOne('SELECT sanitization_ready(?,?) AS ready', [$medium, $w['receiver']->id])->ready);
        }
    }

    private function approveTyped(array $w, array $details = []): string
    {
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->typedProposal($w, $case, $details);
        $this->handoverBoundary();
        $this->restrictionRecord($w, $case, 'approve');

        return $case;
    }

    private function continuities(array $w): array
    {
        $cases = [];
        foreach (DB::table('restriction_impacts')->whereIn('subject_id', array_column(DB::select('SELECT subject_id FROM restriction_family(?)', [$w['ids'][0]]), 'subject_id'))->pluck('id') as $impact) {
            $source = DB::table('restriction_sources')->where('source_kind', 'media_correction')->where('source_id', $impact)->where('fact_key', 'impact_continuity')->firstOrFail();
            $e = array_replace($w, ['subject' => $source->subject_id, 'source' => $source, 'investigator' => $w['receiver']]);
            $cases[] = [$e, $this->approveTyped($e)];
        }

        return $cases;
    }

    private function fulfillContinuities(array $cases): void
    {
        foreach ($cases as [$e,$case]) {
            $this->typedProposal($e, $case);
            $this->handoverBoundary();
            $p = DB::table('restriction_records')->where('case_id', $case)->where('kind', 'proposal')->orderByDesc('version')->value('id');
            $this->restrictionRecord($e, $case, 'fulfill', ['proposal_id' => $p]);
        }
    }

    public function test_erroneous_standalone_association_ends_only_the_effective_claim(): void
    {
        $w = $this->inventoryWork('NO');
        $medium = $this->standaloneAtParentFacility($w);
        $this->inventoryRecord($w, 'link', ['existing_code' => DB::table('subjects')->where('id', $medium)->value('public_code')]);
        $entry = DB::table('media_inventory_entries')->where('medium_id', $medium)->firstOrFail();
        $before = DB::table('custody_current')->where('subject_id', $medium)->first();
        $this->inventoryRecord($w, 'correct', ['entry_id' => $entry->id, 'entry_state' => 'disputed']);
        $w = $this->typedWork($w, 'association_end');
        $this->approveTyped($w);
        $this->assertSame('association_superseded', DB::table('media_inventory_entries')->where('id', $entry->id)->value('state'));
        $this->assertSame($medium, DB::table('media_inventory_entries')->where('id', $entry->id)->value('medium_id'));
        $this->assertFalse(DB::table('media_current_bindings')->where('medium_id', $medium)->exists());
        $this->assertEquals($before, DB::table('custody_current')->where('subject_id', $medium)->first());
        $this->assertDatabaseCount('media_origins', 0);
        app(InspectItems::class)->inspect($w['receiver'], $w['subject'], $this->inspectionFields($w['subject'], 'laptop-healthy'));
        $this->handoverBoundary();
        app(InspectItems::class)->decide($w['receiver'], $w['subject'], $this->decisionFields($w['subject']));
        $this->handoverBoundary();
        $this->assertSame('blocked', app(Context::class)->coverage($w['subject'])['status']);
    }

    public function test_new_batch_discovery_preserves_frozen_inputs_and_unknown_output_is_pending(): void
    {
        $w = $this->batchWork(0);
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $members = DB::table('batch_members')->get();
        $this->batchRecord($w, 'discrepancy');
        $w = $this->typedWork($w, 'discovery_supplement');
        $unit = ['id' => (string) Str::uuid(), 'parent_id' => $w['ids'][0], 'media_kind' => 'ssd', 'mode' => 'unaccounted'];
        $case = $this->approveTyped($w, ['count' => 1, 'units' => [$unit]]);
        $this->assertSame('provenance_supplement_recorded', DB::table('restriction_cases')->where('id', $case)->value('state'));
        $this->receiptSignIn($w['admin'])->get(route('restrictions.show', $case))->assertOk()->assertSee('Approve evidenced discovery supplement')->assertDontSee('Approve evidenced fact supersession');
        $reviewEvent = DB::table('restriction_records as r')->join('domain_events as e', 'e.id', '=', 'r.event_id')->where('r.case_id', $case)->where('r.kind', 'approve')->value('e.safe_payload');
        $this->assertStringContainsString('On-site provenance supplement recorded', $reviewEvent);
        $this->assertStringNotContainsString('fact superseded', $reviewEvent);

        $this->assertEquals($members, DB::table('batch_members')->get());
        $this->assertDatabaseCount('batch_detachments', 0);
        $this->assertDatabaseCount('first_receipts', 1);
        $this->assertSame('batch_discovery', DB::table('media_origins')->where('subject_id', $unit['id'])->value('origin_kind'));
        $this->assertSame('10.000', DB::table('batch_records')->where('id', DB::table('dismantling_batches')->where('subject_id', $w['batch'])->value('input_measurement_id'))->value('net_kg'));
        $this->assertNull(DB::table('batch_outputs')->where('subject_id', $unit['id'])->value('measurement_record_id'));
        $this->assertSame('pending_accounting', app(\App\Support\Batches\Context::class)->balance($w['batch'])['status']);
        $this->assertSame(5, app(\App\Support\Sanitization\Context::class)->snapshot($w['receiver']->fresh(), app(\App\Support\Assessments\Context::class)->authorized($w['receiver'], $unit['id']))['authorization_context_version']);
        $this->assertTrue(DB::selectOne('SELECT restriction_dependent(?) AS blocked', [$w['batch']])->blocked);
        $cases = $this->continuities($w);
        $this->assertNotEmpty($cases);
        $this->fulfillContinuities($cases);
        $this->assertFalse(DB::selectOne('SELECT restriction_dependent(?) AS blocked', [$w['batch']])->blocked);
        $this->assertSame('pending_accounting', app(\App\Support\Batches\Context::class)->balance($w['batch'])['status']);
        $actor = $w['receiver']->fresh();
        $f = $this->handoverEvidence() + ['expected_version' => 0, 'expected_context' => app(AuthorizeBatchMedia::class)->token($actor, $w['batch'], $unit['id']), 'reason' => 'Synthetic safe scope and exact provenance applicability', 'checks' => array_fill_keys(['identity_confirmed', 'safe_media', 'method_scope_known', 'no_unresolved_restriction'], 'YES')];
        app(AuthorizeBatchMedia::class)->record($actor, $w['batch'], $unit['id'], $f);
        $this->handoverBoundary();
        $this->mediaSatisfied($this->mediumWork($w, $unit['id']));
        $this->assertSame('satisfied', app(\App\Support\Sanitization\Context::class)->state($unit['id']));
        $this->assertDatabaseCount('batch_detachments', 0);
    }

    public function test_work_correction_requires_continuity_fresh_applicability_and_new_full_test(): void
    {
        $w = $this->mediaWork('ssd');
        $this->mediaSatisfied($w);
        $work = $this->reuseRecord($w, 'work', ['configuration' => 'UNKNOWN']);
        $w = $this->typedWork($w, 'work_configuration', $work);
        $case = $this->approveTyped($w);
        $review = DB::table('restriction_cases')->where('id', $case)->value('record_id');
        $cases = $this->continuities($w);
        $this->assertNotEmpty($cases);
        $s = $w['ids'][0];
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s));
        $this->handoverBoundary();
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();
        $this->mediaRecord($w, 'revalidation');
        $this->assertFalse(DB::selectOne('SELECT refurbishment_ready(?,?) AS ready', [$s, $w['receiver']->id])->ready);
        $this->receiptSignIn($w['receiver'])->get(route('refurbishment.show', ['subject' => $w['ids'][0], 'action' => 'work_applicability']))
            ->assertOk()->assertSee('Latest retained actual work')->assertSee('Approved correction for this item')
            ->assertSee('value="'.$work.'"', false)->assertSee('value="'.$review.'"', false)->assertSee('Actual work v1');
        $this->correctionDenied(fn () => $this->reuseRecord($w, 'test'));
        $this->reuseRecord($w, 'work_applicability', ['work_id' => $work, 'correction_review_id' => $review]);
        $this->assertFalse(DB::selectOne('SELECT refurbishment_test_ready(?) AS ready', [$s])->ready);
        $this->reuseRecord($w, 'test');
        $epoch = DB::selectOne('SELECT restriction_epoch(?) AS n', [$s])->n;
        $this->fulfillContinuities($cases);
        $this->assertSame($epoch, DB::selectOne('SELECT restriction_epoch(?) AS n', [$s])->n);
        $authorization = $this->reuseRecord($w, 'authorize');
        $this->assertFalse(DB::selectOne('SELECT restriction_dependent(?) AS blocked', [$s])->blocked);
        $this->reuseRecord($w, 'acknowledge', [], true);
        $this->assertSame('acknowledged', DB::table('reuse_authorizations')->where('id', $authorization)->value('state'));
        $this->assertDatabaseCount('refurbishment_records', 5);
    }

    public function test_paired_reclassification_retains_original_measurement_and_records_honest_variance(): void
    {
        $w = $this->batchWork(0);
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->batchRecord($w, 'output');
        $aggregate = DB::table('batch_outputs')->where('batch_id', $w['batch'])->firstOrFail();
        $old = DB::table('batch_records')->where('id', $aggregate->measurement_record_id)->firstOrFail();
        $this->batchRecord($w, 'discrepancy');
        $w = $this->typedWork($w, 'discovery_supplement');
        $unit = ['id' => (string) Str::uuid(), 'parent_id' => $w['ids'][0], 'media_kind' => 'hdd', 'mode' => 'reclassified', 'source_output_id' => $aggregate->subject_id, 'measurement' => ['mode' => 'net', 'net' => '0.500', 'source' => 'Synthetic separate scale observation'], 'remaining' => ['mode' => 'gross_tare', 'gross' => '8.100', 'tare' => '0.100', 'source' => 'Synthetic remaining aggregate gross and tare']];
        $this->approveTyped($w, ['count' => 1, 'units' => [$unit]]);
        $this->assertEquals($old, DB::table('batch_records')->where('id', $old->id)->first());
        $balance = app(\App\Support\Batches\Context::class)->balance($w['batch']);
        $this->assertSame(8.5, $balance['output_kg']);
        $this->assertSame(1.5, $balance['difference_kg']);
        $this->assertTrue($balance['excess_variance']);
        $this->assertDatabaseCount('batch_outputs', 2);
        $this->assertDatabaseCount('batch_members', 1);
        $this->assertDatabaseCount('batch_detachments', 0);
        $this->assertSame($aggregate->production_record_id, DB::table('batch_outputs')->where('subject_id', $aggregate->subject_id)->value('production_record_id'));
    }

    public function test_owner_report_correction_retains_exception_then_requires_separate_actual_receipt(): void
    {
        $w = $this->mediaWork();
        $this->mediaSatisfied($w);
        $this->reusePassed($w);
        $authorization = $this->reuseRecord($w, 'authorize');
        $exception = $this->reuseRecord($w, 'exception', [], true);
        $before = DB::table('custody_current')->where('subject_id', $w['ids'][0])->first();
        $w = $this->typedWork($w, 'return_report', $exception);
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $f = $this->restrictionFields($w, 'owner_clarify', $case, $w['owner']);
        $f['details'] = ['no_handover' => 'YES', 'manifest' => [$w['ids'][0]]];
        app(ReviewRestrictions::class)->record($w['owner'], $case, 'owner_clarify', $f);
        $this->handoverBoundary();
        $this->typedProposal($w, $case);
        $this->handoverBoundary();
        $this->restrictionRecord($w, $case, 'approve');
        $review = DB::table('restriction_cases')->where('id', $case)->value('record_id');
        $count = DB::table('restriction_records')->count();
        $this->receiptSignIn($w['owner'])->post(route('restrictions.owner-clarification', $case), $f)->assertRedirect();
        $this->assertSame($count, DB::table('restriction_records')->count());
        $this->receiptSignIn($w['owner'])->post(route('restrictions.owner-clarification', $case), array_replace($f, ['reason' => 'Changed retry']))->assertConflict();
        $this->assertEquals($before, DB::table('custody_current')->where('subject_id', $w['ids'][0])->first());
        $this->assertSame('exception', DB::table('reuse_authorizations')->where('id', $authorization)->value('state'));
        $cases = $this->continuities($w);
        $s = $w['ids'][0];
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s));
        $this->handoverBoundary();
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();
        $this->mediaRecord($w, 'revalidation');
        $work = DB::table('refurbishment_records')->where('subject_id', $s)->where('kind', 'work')->value('id');
        $this->reuseRecord($w, 'work_applicability', ['work_id' => $work, 'correction_review_id' => $review]);
        $this->reuseRecord($w, 'test');
        $this->fulfillContinuities($cases);
        $new = $this->reuseRecord($w, 'authorize');
        $this->assertNotSame($new, $authorization);
        $this->assertEquals($before, DB::table('custody_current')->where('subject_id', $s)->first());
        $this->reuseRecord($w, 'acknowledge', [], true);
        $this->assertSame('released', DB::table('items')->where('subject_id', $s)->value('processing_state'));
        $this->assertSame('exception', DB::table('reuse_authorizations')->where('id', $authorization)->value('state'));
    }

    private function correctionDenied(callable $action): void
    {
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $action();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Unsupported or forged correction must not succeed.');
        } catch (ValidationException|HttpException|\PDOException $e) {
            $this->assertTrue(true);
        } finally {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
        }
    }

    public function test_derived_sole_origin_cannot_be_superseded_and_aliases_do_not_open_competing_cases(): void
    {
        $w = $this->inventoryWork();
        $medium = $this->identifyMedium($w);
        $entry = DB::table('media_inventory_entries')->where('medium_id', $medium)->firstOrFail();
        $this->inventoryRecord($w, 'correct', ['entry_id' => $entry->id, 'entry_state' => 'disputed']);
        $w = $this->typedWork($w, 'association_end');
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->typedProposal($w, $case);
        $this->handoverBoundary();
        $this->correctionDenied(fn () => $this->restrictionRecord($w, $case, 'approve'));
        $legacy = $w;
        $legacy['source'] = DB::table('restriction_sources')->where('source_kind', 'inventory')->where('source_id', $w['source']->source_id)->firstOrFail();
        $this->correctionDenied(fn () => $this->openRestriction($legacy));
        $this->assertSame('disputed', DB::table('media_inventory_entries')->where('id', $entry->id)->value('state'));
        $this->assertDatabaseCount('media_review_effects', 0);
        $this->receiptSignIn($w['owner'])->get(route('restrictions.show', $case))->assertOk()->assertDontSee('basis')->assertDontSee('Private synthetic');
        $other = User::factory()->create();
        $this->receiptSignIn($other)->get(route('restrictions.show', $case))->assertNotFound();
    }

    public function test_erroneous_discovery_creates_no_identity_or_stock_and_requires_independent_current_authors(): void
    {
        $w = $this->batchWork(0);
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->batchRecord($w, 'discrepancy');
        $w = $this->typedWork($w, 'discovery_error');
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->typedProposal($w, $case, ['accounted_media' => []]);
        $this->handoverBoundary();
        $this->correctionDenied(fn () => $this->restrictionRecord($w, $case, 'approve', [], $w['receiver']));
        $count = DB::table('subjects')->count();
        $this->restrictionRecord($w, $case, 'approve');
        $this->assertSame($count, DB::table('subjects')->count());
        $this->assertDatabaseCount('batch_outputs', 0);
        $this->assertDatabaseCount('batch_media_discoveries', 0);
        $this->assertFalse(DB::selectOne('SELECT media_batch_unresolved(?) AS blocked', [$w['batch']])->blocked);
        $this->assertDatabaseCount('batch_members', 1);
        $this->assertTrue(DB::selectOne('SELECT restriction_dependent(?) AS blocked', [$w['batch']])->blocked);
    }

    public function test_discovery_requires_every_unit_and_direct_forged_effects_are_rejected(): void
    {
        $w = $this->batchWork(0);
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->batchRecord($w, 'discrepancy');
        $w = $this->typedWork($w, 'discovery_supplement');
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $u = ['id' => (string) Str::uuid(), 'parent_id' => $w['ids'][0], 'media_kind' => 'ssd', 'mode' => 'unaccounted'];
        $this->correctionDenied(fn () => $this->typedProposal($w, $case, ['count' => 2, 'units' => [$u]]));
        $this->correctionDenied(fn () => $this->typedProposal($w, $case, ['count' => 2, 'units' => [$u, $u]]));
        $this->correctionDenied(fn () => $this->typedProposal($w, $case, ['count' => 1, 'units' => [array_replace($u, ['media_kind' => 'other_nonvolatile'])]]));
        $this->correctionDenied(fn () => DB::table('restriction_source_claims')->where('case_id', $case)->update(['active' => false]));
        $this->correctionDenied(fn () => DB::table('items')->where('subject_id', $w['ids'][0])->update(['processing_state' => 'inventoried']));
        $this->assertDatabaseCount('batch_outputs', 0);
        $this->assertDatabaseCount('batch_media_discoveries', 0);
        $this->receiptSignIn($w['receiver'])->get(route('restrictions.show', ['case' => $case, 'action' => 'proposal', 'units' => 2]))->assertOk()->assertSee('details[units][1][parent_id]', false)->assertSee('details_units_1_parent_id', false)->assertDontSee('details[units]"', false);
    }

    public function test_owner_clarification_is_narrow_and_not_a_proxy_acknowledgment(): void
    {
        $w = $this->mediaWork();
        $this->mediaSatisfied($w);
        $this->reusePassed($w);
        $this->reuseRecord($w, 'authorize');
        $exception = $this->reuseRecord($w, 'exception', [], true);
        $w = $this->typedWork($w, 'return_report', $exception);
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->typedProposal($w, $case);
        $this->handoverBoundary();
        $this->correctionDenied(fn () => $this->restrictionRecord($w, $case, 'approve'));
        $this->receiptSignIn($w['owner'])->get(route('restrictions.show', $case))->assertOk()->assertSee('Clarify my return report')->assertDontSee('Private identity')->assertDontSee('Technical proposal');
        $f = $this->restrictionFields($w, 'owner_clarify', $case, $w['owner']);
        $f['details'] = ['no_handover' => 'UNKNOWN', 'manifest' => [$w['ids'][0]]];
        $this->receiptSignIn($w['owner'])->post(route('restrictions.owner-clarification', $case), $f)->assertRedirect()->assertSessionHasErrors('details.no_handover');
        $this->assertSame(['idempotency_key' => $f['idempotency_key']], session('_old_input'));
        $this->receiptSignIn($w['receiver'])->post(route('restrictions.owner-clarification', $case), [])->assertForbidden();
        $other = User::factory()->create();
        $this->receiptSignIn($other)->get(route('restrictions.show', $case))->assertNotFound();
        $this->assertDatabaseCount('reuse_return_items', 0);
    }

    public function test_multiple_historical_obligations_use_latest_evidence_without_reviving_failed_history(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $failed = $this->mediaRecord($w, 'attempt', $this->mediaFields($w, 'attempt', 'failed'));
        $this->mediaSatisfied($w);
        $oldWork = $this->reuseRecord($w);
        $latestWork = $this->reuseRecord($w);
        $answers = $this->reuseFields($w, 'test')['answers'];
        $answers['safety'] = 'UNKNOWN';
        $badTest = $this->reuseRecord($w, 'test', ['answers' => $answers]);
        $w = $this->typedWork($w, 'test_safety', $badTest);
        $case = $this->approveTyped($w);
        $review = DB::table('restriction_cases')->where('id', $case)->value('record_id');
        $cases = $this->continuities($w);
        $this->assertCount(4, $cases);
        $this->assertFalse(DB::selectOne("SELECT media_recovery_allowed(?,'treatment') AS allowed", [$s])->allowed);
        $this->correctionDenied(fn () => $this->mediaRecord($w));
        $this->correctionDenied(fn () => $this->reuseRecord($w));
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s));
        $this->handoverBoundary();
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();
        $this->mediaRecord($w, 'revalidation');
        $basis = $this->reuseRecord($w, 'work_applicability', ['work_id' => $latestWork, 'correction_review_id' => $review]);
        $this->reuseRecord($w, 'test');
        $this->fulfillContinuities($cases);
        $this->assertFalse(DB::selectOne('SELECT restriction_dependent(?) AS blocked', [$s])->blocked);
        $this->assertSame('failed', DB::table('sanitization_records')->where('id', $failed)->value('outcome'));
        $this->assertSame('incomplete', DB::table('refurbishment_records')->where('id', $badTest)->value('result'));
        $this->assertSame($latestWork, DB::table('refurbishment_records')->where('id', $basis)->value('work_id'));
        $this->assertNotSame($oldWork, $latestWork);
        $this->assertSame(2, DB::table('sanitization_records')->where('kind', 'attempt')->count());
        $this->assertSame(2, DB::table('refurbishment_records')->where('kind', 'work')->count());
        $this->mediaRecord($w, 'attempt', $this->mediaFields($w, 'attempt', 'failed'));
        $this->assertTrue(DB::selectOne('SELECT restriction_dependent(?) AS blocked', [$s])->blocked);
        foreach ($cases as [$e,$id]) {
            $this->assertSame('obligation_fulfilled', DB::table('restriction_cases')->where('id', $id)->value('state'));
        }
        $this->assertFalse(DB::selectOne('SELECT refurbishment_data_ready(?) AS ready', [$s])->ready);
    }

    public function test_completed_work_is_preserved_and_receives_a_blocking_dependent_exception(): void
    {
        $work = $this->mediaWork();
        $this->reuseRecord($work);
        $before = DB::table('refurbishment_records')->get()->toJson();
        $subject = $work['ids'][0];
        $fields = $this->inspectionFields($subject);
        $fields['answers']['safety_concern'] = 'YES';
        app(InspectItems::class)->inspect($work['receiver'], $subject, $fields);
        $this->handoverBoundary();
        $source = DB::table('restriction_sources')->where('subject_id', $subject)->where('source_kind', 'inspection')->where('fact_key', 'safety_concern')->firstOrFail();
        $w = ['admin' => $work['admin'], 'owner' => $work['owner'], 'investigator' => $work['receiver'], 'subject' => $subject, 'source' => $source];
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        $this->restrictionRecord($w, $case, 'approve');
        $this->assertFalse(Effective::inspectionBlocked($subject));
        $this->assertGreaterThan(0, DB::table('restriction_impacts')->where('subject_id', $subject)->count());
        $this->assertSame($before, DB::table('refurbishment_records')->get()->toJson());
        $this->assertFalse(Effective::freshInspection($subject));
        $this->assertTrue(DB::table('restriction_sources')->where('source_kind', 'dependent')->where('subject_id', $subject)->exists());
    }

    public function test_parent_correction_stales_media_inventory_authority_and_assurance_transitively(): void
    {
        $work = $this->inventoryWork();
        $medium = $this->identifyMedium($work);
        $this->inventoryRecord($work, 'attest');
        $this->authorizeMedium($work, $medium);
        $this->mediaSatisfied($this->mediumWork($work, $medium));
        $custody = DB::table('custody_current')->orderBy('subject_id')->get()->toJson();
        $assurance = DB::table('sanitization_records')->get()->toJson();
        $fields = $this->inspectionFields($work['ids'][0], 'laptop-healthy');
        $fields['answers']['safety_concern'] = 'YES';
        app(InspectItems::class)->inspect($work['receiver'], $work['ids'][0], $fields);
        $this->handoverBoundary();
        $source = DB::table('restriction_sources')->where('subject_id', $work['ids'][0])->where('source_kind', 'inspection')->where('fact_key', 'safety_concern')->firstOrFail();
        $w = ['admin' => $work['admin'], 'owner' => $work['owner'], 'investigator' => $work['receiver'], 'subject' => $work['ids'][0], 'source' => $source];
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        $this->restrictionRecord($w, $case, 'approve');
        $this->assertSame(1, (int) DB::selectOne('SELECT restriction_epoch(?) AS epoch', [$medium])->epoch);
        $this->assertSame('review_required', app(\App\Support\Sanitization\Context::class)->state($medium));
        $this->assertSame('blocked', app(Context::class)->coverage($w['subject'])['status']);
        $this->assertSame($custody, DB::table('custody_current')->orderBy('subject_id')->get()->toJson());
        $this->assertSame($assurance, DB::table('sanitization_records')->get()->toJson());
    }

    public function test_recognized_treated_media_keeps_identity_and_gets_its_own_dependency_review(): void
    {
        $w = $this->batchWork(0);
        $medium = $this->standaloneAtParentFacility($w);
        $this->mediaSatisfied($this->mediumWork($w, $medium));
        $before = ['subject' => DB::table('subjects')->where('id', $medium)->first(), 'item' => DB::table('items')->where('subject_id', $medium)->first(), 'custody' => DB::table('custody_current')->where('subject_id', $medium)->first(), 'assurance' => DB::table('sanitization_records')->get()];
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->batchRecord($w, 'discrepancy');
        $w = $this->typedWork($w, 'discovery_supplement');
        $recognized = ['id' => (string) Str::uuid(), 'parent_id' => $w['ids'][0], 'media_kind' => 'ssd', 'mode' => 'unaccounted', 'existing_code' => $before['subject']->public_code];
        $new = ['id' => (string) Str::uuid(), 'parent_id' => $w['ids'][0], 'media_kind' => 'hdd', 'mode' => 'unaccounted'];
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $other = User::factory()->create();
        $outside = $this->device($other, 'standalone_part', 'ssd');
        $this->correctionDenied(fn () => $this->typedProposal($w, $case, ['count' => 1, 'units' => [array_replace($recognized, ['existing_code' => DB::table('subjects')->where('id', $outside->subject_id)->value('public_code')])]]));
        $this->typedProposal($w, $case, ['count' => 2, 'units' => [$recognized, $new]]);
        $this->handoverBoundary();
        $this->receiptSignIn($w['receiver'])->get(route('restrictions.show', ['case' => $case, 'action' => 'proposal', 'units' => 1]))
            ->assertOk()->assertSee('name="details[count]" value="2"', false)->assertSee($recognized['id'])->assertSee($new['id'])
            ->assertSee('recorded unit count and references are retained');
        $this->restrictionRecord($w, $case, 'approve');
        $this->assertEquals($before['subject'], DB::table('subjects')->where('id', $medium)->first());
        $this->assertEquals($before['item'], DB::table('items')->where('subject_id', $medium)->first());
        $this->assertEquals($before['custody'], DB::table('custody_current')->where('subject_id', $medium)->first());
        $this->assertEquals($before['assurance'], DB::table('sanitization_records')->get());
        $this->assertFalse(DB::table('media_origins')->where('subject_id', $medium)->exists());
        $this->assertDatabaseCount('batch_media_discoveries', 2);
        $this->assertDatabaseCount('batch_outputs', 2);
        $this->assertDatabaseCount('batch_members', 1);
        $this->assertDatabaseCount('batch_detachments', 0);
        $this->assertDatabaseCount('first_receipts', 2);
        $this->assertSame(1, DB::table('restriction_impacts')->where('subject_id', $medium)->count());
        $cases = $this->continuities($w);
        $this->assertCount(3, $cases);
        $actor = $w['receiver']->fresh();
        $auth = app(AuthorizeBatchMedia::class);
        $auth->record($actor, $w['batch'], $medium, $this->handoverEvidence() + ['expected_version' => 0, 'expected_context' => $auth->token($actor, $w['batch'], $medium), 'reason' => 'Synthetic retained-media applicability', 'checks' => array_fill_keys(['identity_confirmed', 'safe_media', 'method_scope_known', 'no_unresolved_restriction'], 'YES')]);
        $this->handoverBoundary();
        $this->mediaRecord($this->mediumWork($w, $medium), 'revalidation');
        $this->fulfillContinuities($cases);
        $this->assertFalse(DB::selectOne('SELECT restriction_dependent(?) AS blocked', [$medium])->blocked);
        $this->assertSame(1, DB::table('sanitization_records')->where('kind', 'attempt')->count());
        $this->assertSame('satisfied', app(\App\Support\Sanitization\Context::class)->state($medium));
        $this->assertSame('required', app(\App\Support\Sanitization\Context::class)->state($new['id']));
        $this->assertNotContains($medium, app(Context::class)->candidates($actor, (object) ['owner_user_id' => $w['owner']->id])->pluck('subject_id')->all(), 'Discovery stock cannot be offered as an ordinary parent inventory link.');
        DB::statement('CREATE TABLE media_discovery_inventory_probe(kind text,medium_id uuid)');
        DB::statement('CREATE TRIGGER discovery_inventory_guard BEFORE INSERT ON media_discovery_inventory_probe FOR EACH ROW EXECUTE FUNCTION media_inventory_insert_guard()');
        DB::beginTransaction();
        try {
            DB::table('media_discovery_inventory_probe')->insert(['kind' => 'link', 'medium_id' => $medium]);
            $this->fail('Discovery stock cannot acquire a new embedding through a direct write.');
        } catch (\PDOException $e) {
            $this->assertSame('P1500', $e->errorInfo[0] ?? null);
        } finally {
            DB::rollBack();
        }

        // Recognition must not revive the earlier standalone REFURBISH/return path.
        $this->assertFalse(DB::selectOne('SELECT refurbishment_ready_before_restriction(?,?) AS ready', [$medium, $actor->id])->ready);
        $this->assertStringContainsString('permanent on-site discovery output', implode(' ', app(\App\Support\Refurbishment\Context::class)->blockers($medium, $actor->id)));
        $control = DB::table('custody_current')->where('subject_id', $medium)->first();
        $this->correctionDenied(fn () => $this->reuseRecord($this->mediumWork($w, $medium), 'work'));
        $this->correctionDenied(fn () => DB::table('custody_current')->where('subject_id', $medium)->update(['version' => $control->version + 1]));
        // Probe the same native handover guard before unrelated row-shape checks.
        DB::statement('CREATE TABLE media_discovery_guard_probe(subject_id uuid)');
        DB::statement('CREATE TRIGGER discovery_handover_guard BEFORE INSERT ON media_discovery_guard_probe FOR EACH ROW EXECUTE FUNCTION media_custody_guard()');
        DB::beginTransaction();
        try {
            DB::table('media_discovery_guard_probe')->insert(['subject_id' => $medium]);
            $this->fail('Discovery output cannot enter a handover.');
        } catch (\PDOException $e) {
            $this->assertSame('P1500', $e->errorInfo[0] ?? null);
        } finally {
            DB::rollBack();
        }
        $this->assertEquals($control, DB::table('custody_current')->where('subject_id', $medium)->first());
        $this->assertDatabaseCount('batch_outputs', 2);

    }

    public function test_native_null_findings_cannot_support_positive_review(): void
    {
        $w = $this->batchWork(0);
        foreach (['draft', 'commit', 'discrepancy'] as $kind) {
            $this->batchRecord($w, $kind);
        }
        $w = $this->typedWork($w, 'discovery_supplement');
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->typedProposal($w, $case, ['count' => 1, 'units' => [['id' => (string) Str::uuid(), 'parent_id' => $w['ids'][0], 'media_kind' => 'hdd', 'mode' => 'unaccounted']]]);
        $this->handoverBoundary();
        $this->assertTrue(DB::selectOne('SELECT media_review_eligible(c,p) AS ready FROM restriction_cases c JOIN restriction_records p ON p.id=c.record_id WHERE c.id=?', [$case])->ready);
        $this->assertFalse(DB::selectOne("SELECT media_review_eligible(c,jsonb_populate_record(NULL::restriction_records,jsonb_set(to_jsonb(p),'{checks,distinct_units}','null'::jsonb))) AS ready FROM restriction_cases c JOIN restriction_records p ON p.id=c.record_id WHERE c.id=?", [$case])->ready);
        DB::beginTransaction();
        try {
            DB::select("SELECT media_validate_record(jsonb_populate_record(NULL::restriction_records,to_jsonb(p)||jsonb_build_object('id',?::uuid,'version',c.version+1,'predecessor_id',p.id,'checks',jsonb_set(p.checks,'{distinct_units}','null'::jsonb)))) FROM restriction_cases c JOIN restriction_records p ON p.id=c.record_id WHERE c.id=?", [Str::uuid(), $case]);
            $this->fail('A direct proposal with a null finding must be rejected.');
        } catch (\PDOException $e) {
            $this->assertSame('P1500', $e->errorInfo[0] ?? null);
            $this->assertStringContainsString('exact typed evidence template', $e->errorInfo[2] ?? '');
        } finally {
            DB::rollBack();
        }
    }

    public function test_discovery_recognition_rejects_pending_returns_and_hard_holds(): void
    {
        foreach (['return', 'hold'] as $mode) {
            DB::beginTransaction();
            try {
                $w = $this->batchWork(0);
                $medium = $this->standaloneAtParentFacility($w);
                $mw = $this->mediumWork($w, $medium);
                if ($mode === 'return') {
                    $this->mediaSatisfied($mw);
                    $this->reusePassed($mw);
                    $this->reuseRecord($mw, 'authorize');
                } elseif ($mode === 'hold') {
                    $f = $this->inspectionFields($medium);
                    $f['answers']['thermal_issue'] = 'UNKNOWN';
                    app(InspectItems::class)->inspect($w['receiver'], $medium, $f);
                    $this->handoverBoundary();
                }
                foreach (['draft', 'commit', 'discrepancy'] as $kind) {
                    $this->batchRecord($w, $kind);
                }
                $w = $this->typedWork($w, 'discovery_supplement');
                $case = $this->openRestriction($w);
                $this->restrictionRecord($w, $case, 'assign');
                $control = DB::table('custody_current')->where('subject_id', $medium)->first();
                $this->correctionDenied(fn () => $this->typedProposal($w, $case, ['count' => 1, 'units' => [['id' => (string) Str::uuid(), 'parent_id' => $w['ids'][0], 'media_kind' => 'ssd', 'mode' => 'unaccounted', 'existing_code' => DB::table('subjects')->where('id', $medium)->value('public_code')]]]));
                $this->assertDatabaseCount('batch_media_discoveries', 0);
                $this->assertDatabaseCount('batch_outputs', 0);
                $this->assertEquals($control, DB::table('custody_current')->where('subject_id', $medium)->first());
            } finally {
                DB::rollBack();
            }
        }
    }

    public static function correctionProfiles(): array
    {
        return array_map(fn ($key) => [$key], ['laptop', 'desktop', 'phone', 'flat_monitor', 'printer', 'router', 'keyboard', 'hdd', 'ssd', 'ict_cooling_fan']);
    }

    #[DataProvider('correctionProfiles')]
    public function test_profile_fact_correction_preserves_negative_history_and_live_data_boundaries(string $key): void
    {
        $category = in_array($key, ['router', 'keyboard'], true) ? 'peripheral' : (in_array($key, ['hdd', 'ssd', 'ict_cooling_fan'], true) ? 'standalone_part' : $key);
        $w = $this->inspectionWork($category, $category !== $key ? $key : null);
        $s = $w['ids'][0];
        $whole = $category !== 'standalone_part';
        $supported = ! $whole || in_array($key, ['laptop', 'desktop'], true);
        $inspect = function () use ($w, $s, $key, $whole): void {
            $f = $this->inspectionFields($s, $key.'-healthy');
            if ($whole) {
                $f['answers']['data_status'] = 'YES';
            }
            app(InspectItems::class)->inspect($w['receiver'], $s, $f);
            $this->handoverBoundary();
            app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
            $this->handoverBoundary();
        };
        $inspect();
        $medium = $s;
        if ($whole) {
            $medium = $this->identifyMedium($w, $supported ? 'ssd' : 'other_nonvolatile');
            $this->inventoryRecord($w, 'attest');
            if ($supported) {
                $this->authorizeMedium($w, $medium);
                $this->mediaSatisfied($this->mediumWork($w, $medium));
            }
        } elseif ($key === 'ict_cooling_fan') {
            $this->reuseRecord($w, 'fan_na');
        } else {
            $this->mediaSatisfied($w);
        }
        $actual = $this->reuseRecord($w, 'work');
        $answers = $this->reuseFields($w, 'test')['answers'];
        $answers['safety'] = 'UNKNOWN';
        $original = $this->reuseRecord($w, 'test', ['answers' => $answers]);
        $w = $this->typedWork($w, 'test_safety', $original);
        $case = $this->approveTyped($w);
        $review = DB::table('restriction_cases')->where('id', $case)->value('record_id');
        $cases = $this->continuities($w);
        $inspect();
        if ($whole) {
            $this->inventoryRecord($w, 'attest');
            if ($supported) {
                $this->authorizeMedium($w, $medium);
                $this->mediaRecord($this->mediumWork($w, $medium), 'revalidation');
            }
        } elseif ($key === 'ict_cooling_fan') {
            $this->reuseRecord($w, 'fan_na');
        } else {
            $this->mediaRecord($w, 'revalidation');
        }
        $this->assertSame('incomplete', DB::table('refurbishment_records')->where('id', $original)->value('result'));
        $this->assertSame('UNKNOWN', json_decode(DB::table('refurbishment_records')->where('id', $original)->value('answers'), true)['safety']);
        $this->assertFalse(DB::selectOne('SELECT refurbishment_test_ready(?) ready', [$s])->ready);
        if (! $supported) {
            $this->correctionDenied(fn () => $this->reuseRecord($w, 'work_applicability', ['work_id' => $actual, 'correction_review_id' => $review]));
            $this->assertFalse(DB::selectOne('SELECT refurbishment_data_ready(?) ready', [$s])->ready);
            $this->assertSame('required', app(\App\Support\Sanitization\Context::class)->state($medium));

            return;
        }
        $this->correctionDenied(fn () => $this->reuseRecord($w, 'test'));
        $this->reuseRecord($w, 'work_applicability', ['work_id' => $actual, 'correction_review_id' => $review]);
        $fresh = $this->reuseRecord($w, 'test');
        $this->assertNotSame($original, $fresh);
        $this->fulfillContinuities($cases);
        $authorized = $this->reuseRecord($w, 'authorize');
        $this->assertSame('pending', DB::table('reuse_authorizations')->where('id', $authorized)->value('state'));
        $this->assertSame(1, DB::table('refurbishment_records')->where('subject_id', $s)->where('kind', 'work')->count());
    }

    public function test_new_discovery_identifier_conflict_rolls_back_without_matched_record_disclosure(): void
    {
        $w = $this->batchWork(0);
        $other = User::factory()->create();
        $serial = 'SYN-PRIVATE-DRIVE-15';
        $retained = $this->device($other, 'standalone_part', 'hdd', ['identifier_kind' => 'serial', 'identifier' => $serial]);
        foreach (['draft', 'commit', 'discrepancy'] as $kind) {
            $this->batchRecord($w, $kind);
        }
        $w = $this->typedWork($w, 'discovery_supplement');
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->typedProposal($w, $case, ['count' => 1, 'units' => [['id' => (string) Str::uuid(), 'parent_id' => $w['ids'][0], 'media_kind' => 'ssd', 'mode' => 'unaccounted', 'identifier' => strtolower(str_replace('-', ' ', $serial))]]]);
        $before = [];
        foreach (['subjects', 'items', 'custody_current', 'first_receipts', 'batch_outputs', 'batch_records', 'media_origins', 'storage_assets', 'restriction_records', 'commands', 'domain_events', 'notification_outbox'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        try {
            $this->restrictionRecord($w, $case, 'approve');
            $this->fail('Global identifier uniqueness must include newly discovered media.');
        } catch (ValidationException $e) {
            $this->assertSame(Identifiers::DUPLICATE_MESSAGE, $e->errors()['details.units'][0]);
            $this->assertStringNotContainsString($retained->subject_id, $e->getMessage());
        }
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
        }
        $this->assertDatabaseCount('batch_media_discoveries', 0);
    }

    public function test_restored_actor_scope_requires_fresh_obligation_signatures_without_rewriting_history(): void
    {
        $this->travelTo(now('UTC')->startOfMinute()); // Keep pre-upgrade fixtures inside the old observation window.
        $w = $this->batchWork(0);
        foreach (['draft', 'commit', 'discrepancy'] as $kind) {
            $this->batchRecord($w, $kind);
        }
        $w = $this->typedWork($w, 'discovery_error');
        $this->approveTyped($w, ['accounted_media' => []]);
        $cases = $this->continuities($w);
        $this->fulfillContinuities($cases);
        $upgrade = database_path('migrations/2026_10_21_000002_seal_obligation_signatures.php');
        if (is_file($upgrade)) {
            $beforeUpgrade = [];
            foreach (['restriction_cases', 'restriction_records', 'restriction_impacts', 'restriction_impact_reviews', 'media_review_effects', 'media_review_commits', 'batch_records', 'dismantling_batches'] as $table) {
                $beforeUpgrade[$table] = DB::table($table)->get()->toJson();
            }
            (require $upgrade)->up();
            foreach (glob(database_path('migrations/*.php')) as $later) {
                if (basename($later) > basename($upgrade)) {
                    (require $later)->up();
                }
            }
            $this->handoverBoundary();
            foreach ($beforeUpgrade as $table => $rows) {
                $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
            }
        }
        $epoch = DB::selectOne('SELECT restriction_epoch(?) n', [$w['batch']])->n;
        $this->assertFalse(DB::selectOne('SELECT restriction_dependent(?) blocked', [$w['batch']])->blocked);
        foreach (['receiver', 'admin'] as $role) {
            $member = DB::table('memberships')->where('user_id', $w[$role]->id)->first();
            DB::table('memberships')->where('id', $member->id)->update(['active' => false, 'version' => $member->version + 1]);
            $this->assertTrue(DB::selectOne('SELECT restriction_dependent(?) blocked', [$w['batch']])->blocked);
            DB::table('memberships')->where('id', $member->id)->update(['active' => true, 'version' => $member->version + 2]);
            $this->assertTrue(DB::selectOne('SELECT restriction_dependent(?) blocked', [$w['batch']])->blocked, 'Restored scope cannot revive old signed proof.');
            $before = DB::table('restriction_impact_reviews')->where('stage', 'fulfilled')->get()->toJson();
            $cases = $this->continuities($w);
            $this->fulfillContinuities($cases);
            $this->assertFalse(DB::selectOne('SELECT restriction_dependent(?) blocked', [$w['batch']])->blocked);
            $this->assertSame($epoch, DB::selectOne('SELECT restriction_epoch(?) n', [$w['batch']])->n);
            $retained = json_decode($before, true);
            foreach ($retained as $record) {
                $this->assertEquals((object) $record, DB::table('restriction_impact_reviews')->where('record_id', $record['record_id'])->first());
            }
        }
        $this->assertDatabaseCount('batch_outputs', 0);
        $this->assertDatabaseCount('batch_detachments', 0);
    }

    public function test_opening_observation_can_precede_case_creation_but_not_its_source(): void
    {
        $this->travelTo(now('UTC')->subMinutes(5));
        try {
            $w = $this->batchWork(0);
            foreach (['draft', 'commit', 'discrepancy'] as $kind) {
                $this->batchRecord($w, $kind);
            }
            $w = $this->typedWork($w, 'discovery_error');
        } finally {
            $this->travelBack();
        }
        $observed = CarbonImmutable::parse($w['source']->source_time)->format('Y-m-d\\TH:i');
        $fields = array_replace($this->restrictionFields($w, 'open'), ['observed_at' => $observed]);
        $case = app(ReviewRestrictions::class)->open($w['admin'], $w['source']->source_kind, $w['source']->source_id, $w['source']->fact_key, $fields);
        $this->handoverBoundary();
        $this->assertTrue(CarbonImmutable::parse(DB::table('restriction_cases')->where('id', $case)->value('created_at'))->gt(CarbonImmutable::parse($observed)));
        $this->correctionDenied(fn () => $this->restrictionRecord($w, $case, 'assign', ['observed_at' => $observed]));
        $this->restrictionRecord($w, $case, 'close');
        $fields = array_replace($this->restrictionFields($w, 'open'), ['observed_at' => CarbonImmutable::parse($observed)->subMinute()->format('Y-m-d\\TH:i')]);
        $this->correctionDenied(fn () => app(ReviewRestrictions::class)->open($w['admin'], $w['source']->source_kind, $w['source']->source_id, $w['source']->fact_key, $fields));
    }
}
