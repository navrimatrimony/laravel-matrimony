<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakPolicy;
use App\Models\SuchakServicePackage;
use App\Models\SuchakSuccessFeeTranche;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Modules\Suchak\Services\SuchakSuccessFeeTrancheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Matchmaker marketplace blueprint section 7.4 and decision D25 — the success-fee split.
 */
class SuchakSuccessFeeTrancheTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These tests assert MARATHI wording, so they ask for Marathi.
     *
     * They did not have to before: the sentences they pin were hardcoded
     * Marathi literals, which read the same whatever the caller wanted — the
     * defect, not the contract. Now the wording follows the request, so the
     * language under test is stated rather than inherited from whatever the
     * suite's default locale happens to be (Symfony's test client sends
     * `Accept-Language: en-us`, so the default is English).
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('mr');
        $this->withHeader('Accept-Language', 'mr');
    }

    public function test_tranche_table_carries_the_plan_and_the_m9_ledger(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_success_fee_tranches'));

        foreach ([
            'customer_agreement_id',
            'sort_order',
            'trigger_stage_key',
            'share_percent',
            'is_final_tranche',
            // M9: attribution and payment state live per tranche, so a broken settlement
            // never takes a paid tranche with it and a later match reads only the unpaid rows.
            'released_by_collaboration_request_id',
            'released_by_stage_event_id',
            'released_at',
            'customer_payment_id',
            'settled_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_success_fee_tranches', $column), $column);
        }

        // The rupee figure is derived from the frozen fee and the shares, never stored beside them.
        $this->assertFalse(Schema::hasColumn('suchak_success_fee_tranches', 'amount'));
        $this->assertFalse(Schema::hasColumn('suchak_success_fee_tranches', 'tranche_amount'));
        $this->assertFalse(Schema::hasColumn('suchak_success_fee_tranches', 'success_fee_amount'));
    }

    public function test_worked_example_freezes_the_split_and_the_parts_sum_to_the_whole(): void
    {
        [$suchakUser, , $package] = $this->publishedPackageFixture();

        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage(
            $package,
            $suchakUser,
            [
                'agreement_title' => 'Success fee tranche agreement',
                'success_fee_tranches' => $this->workedExamplePlan(),
            ],
        );

        $tranches = $agreement->successFeeTranches;
        $this->assertCount(3, $tranches);
        $this->assertSame(
            [
                SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
                SuchakCollaborationStageEvent::STAGE_ENGAGEMENT,
                SuchakCollaborationStageEvent::STAGE_MARRIAGE,
            ],
            $tranches->pluck('trigger_stage_key')->all(),
        );
        $this->assertSame(['10.00', '40.00', '50.00'], $tranches->pluck('share_percent')->all());
        $this->assertSame([false, false, true], $tranches->pluck('is_final_tranche')->all());

        // T1: each share is a percentage OF THE TOTAL — 40% of 1,00,000 is 40,000, not 36,000.
        // T2: the last tranche is the remainder, and the three parts are exactly the whole.
        $amounts = app(SuchakSuccessFeeTrancheService::class)->amounts('100000.00', $tranches);
        $this->assertSame(['10000.00', '40000.00', '50000.00'], $amounts);
        $this->assertSame(
            10000000,
            array_sum(array_map(static fn (string $value): int => (int) round(((float) $value) * 100), $amounts)),
        );
    }

    public function test_t2_remainder_absorbs_the_rounding_so_no_paisa_goes_missing(): void
    {
        $service = app(SuchakSuccessFeeTrancheService::class);

        $plan = $service->normalizePlan([
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => 33.33],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => 33.33],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 33.34],
        ]);

        // A figure chosen because a percentage of it does not divide cleanly.
        $amounts = $service->amounts('1000.03', $plan);

        $this->assertSame(['333.31', '333.31', '333.41'], $amounts);
        $this->assertSame(
            100003,
            array_sum(array_map(static fn (string $value): int => (int) round(((float) $value) * 100), $amounts)),
        );
    }

    public function test_t3_shares_that_do_not_sum_to_one_hundred_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('हप्त्यांची टक्केवारी एकूण 100% असणे आवश्यक आहे. सध्या ती 90% आहे.');

        app(SuchakSuccessFeeTrancheService::class)->normalizePlan([
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => 10],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => 40],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 40],
        ]);
    }

    public function test_t2_only_the_last_tranche_may_be_the_remainder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"उर्वरित रक्कम" हा शेवटचाच हप्ता असला पाहिजे.');

        app(SuchakSuccessFeeTrancheService::class)->normalizePlan([
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => 10],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => 40, 'is_final_tranche' => true],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 50],
        ]);
    }

    public function test_t2_two_remainder_tranches_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('फक्त एकच हप्ता "उर्वरित रक्कम" असू शकतो.');

        app(SuchakSuccessFeeTrancheService::class)->normalizePlan([
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => 50, 'is_final_tranche' => true],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 50, 'is_final_tranche' => true],
        ]);
    }

    public function test_trigger_must_come_from_the_stage_ladder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('हप्ता ज्या टप्प्यावर द्यायचा तो टप्पा वैध नाही.');

        app(SuchakSuccessFeeTrancheService::class)->normalizePlan([
            ['trigger_stage_key' => 'when_the_family_feels_ready', 'share_percent' => 100],
        ]);
    }

    public function test_tranches_must_follow_the_ladder_order(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('हप्त्यांचा क्रम टप्प्यांच्या क्रमाप्रमाणेच असावा.');

        app(SuchakSuccessFeeTrancheService::class)->normalizePlan([
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 50],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => 50],
        ]);
    }

    public function test_t4_a_large_first_tranche_is_advised_against_but_never_blocked(): void
    {
        [$suchakUser, , $package] = $this->publishedPackageFixture();
        $service = app(SuchakSuccessFeeTrancheService::class);

        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage(
            $package,
            $suchakUser,
            [
                'agreement_title' => 'Front-loaded split',
                'success_fee_tranches' => [
                    ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => 60],
                    ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 40],
                ],
            ],
        );

        // Saved, because T4 is a "should" in the blueprint.
        $this->assertCount(2, $agreement->successFeeTranches);
        $this->assertSame(
            ['पहिला हप्ता सर्वात लहान ठेवणे योग्य — तो सर्वात कमी पुराव्यावर मिळतो.'],
            $service->advisories($agreement->successFeeTranches),
        );

        // And the worked-example shape raises nothing.
        $this->assertSame([], $service->advisories($service->normalizePlan($this->workedExamplePlan())));
    }

    public function test_a_split_needs_a_fixed_success_fee_to_be_a_split_of(): void
    {
        [$suchakUser, , $package] = $this->publishedPackageFixture('As-wished success fee', [
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_AS_WISHED,
            'post_marriage_fee_amount' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ठरलेले यशस्वी विवाह शुल्क नसताना हप्ते ठरवता येणार नाहीत.');

        app(SuchakAgreementService::class)->createAgreementForPackage(
            $package,
            $suchakUser,
            [
                'agreement_title' => 'Split without a total',
                'success_fee_tranches' => $this->workedExamplePlan(),
            ],
        );
    }

    public function test_editing_a_tranche_after_acceptance_invalidates_the_snapshot(): void
    {
        [$suchakUser, , $package] = $this->publishedPackageFixture();
        $service = app(SuchakAgreementService::class);

        $agreement = $service->createAgreementForPackage(
            $package,
            $suchakUser,
            [
                'agreement_title' => 'Frozen split',
                'success_fee_tranches' => $this->workedExamplePlan(),
            ],
        );
        $agreement = $service->acceptTerms($agreement, $suchakUser);

        $this->assertTrue($service->isPackageSnapshotCurrent($agreement->fresh()));

        // Re-cut the split behind the engine's back, exactly as an out-of-band edit would.
        DB::table('suchak_success_fee_tranches')
            ->where('customer_agreement_id', $agreement->id)
            ->where('trigger_stage_key', SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED)
            ->update(['share_percent' => '30.00']);

        $this->assertFalse($service->isPackageSnapshotCurrent($agreement->fresh()));
    }

    public function test_releasing_a_tranche_does_not_invalidate_the_snapshot(): void
    {
        [$suchakUser, , $package] = $this->publishedPackageFixture();
        $service = app(SuchakAgreementService::class);

        $agreement = $service->createAgreementForPackage(
            $package,
            $suchakUser,
            [
                'agreement_title' => 'Ledger movement is not a terms change',
                'success_fee_tranches' => $this->workedExamplePlan(),
            ],
        );
        $agreement = $service->acceptTerms($agreement, $suchakUser);

        $agreement->successFeeTranches()->first()->forceFill([
            'released_at' => now(),
            'settled_at' => now(),
        ])->save();

        $this->assertTrue($service->isPackageSnapshotCurrent($agreement->fresh()));
    }

    public function test_m9_a_paid_tranche_survives_a_revision_and_the_split_cannot_be_recut(): void
    {
        [$suchakUser, , $package] = $this->publishedPackageFixture();
        $service = app(SuchakAgreementService::class);

        $agreement = $service->createAgreementForPackage(
            $package,
            $suchakUser,
            [
                'agreement_title' => 'M9 exposure ceiling',
                'success_fee_tranches' => $this->workedExamplePlan(),
            ],
        );

        /** @var SuchakSuccessFeeTranche $settled */
        $settled = $agreement->successFeeTranches()->first();
        $settled->forceFill(['released_at' => now(), 'settled_at' => now()])->save();

        $revision = $service->createRevisionForPackageChange($agreement, $suchakUser, [
            'agreement_title' => 'M9 exposure ceiling',
        ]);

        // The split carried forward untouched, and so did the fact that its first tranche is paid.
        $this->assertSame(
            ['10.00', '40.00', '50.00'],
            $revision->successFeeTranches->pluck('share_percent')->all(),
        );
        $this->assertNotNull($revision->successFeeTranches->first()->settled_at);
        $this->assertTrue($revision->successFeeTranches->first()->isCommitted());
        $this->assertNull($revision->successFeeTranches->last()->settled_at);

        // A new revision may not re-cut a split that has already been drawn on. The proposed plan
        // below deletes the PAID `marriage_settled` instalment outright, and that is what is
        // refused.
        //
        // The refusal now NAMES the rung. It used to be one blanket sentence for the whole plan,
        // which was harmless only while nothing could commit a tranche; once
        // SuchakSuccessFeeTrancheService::release() exists, a whole-plan veto would also refuse
        // the legitimate revision — re-shaping the instalments that have NOT happened — that §7.4
        // explicitly allows ("the paid tranche stands, only the UNPAID tranches fire on the new
        // match"). Same rule, applied per committed row, and phrased so a Suchak looking at three
        // rows knows which one he may not touch.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"लग्न ठरल्यावर" या टप्प्याचा हप्ता आधीच लागू झाला आहे; तो नव्या विभागणीतून काढून टाकता येणार नाही.');

        $service->createRevisionForPackageChange($revision, $suchakUser, [
            'agreement_title' => 'M9 exposure ceiling',
            'success_fee_tranches' => [
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 100],
            ],
        ]);
    }

    public function test_backfill_migration_leaves_no_stale_or_null_digest(): void
    {
        [$suchakUser, , $withSplit] = $this->publishedPackageFixture('Package with a split');
        [$otherUser, , $withoutSplit] = $this->publishedPackageFixture('Package without a split');
        $service = app(SuchakAgreementService::class);

        $splitAgreement = $service->createAgreementForPackage($withSplit, $suchakUser, [
            'agreement_title' => 'Backfill subject with a split',
            'success_fee_tranches' => $this->workedExamplePlan(),
        ]);
        $plainAgreement = $service->createAgreementForPackage($withoutSplit, $otherUser, [
            'agreement_title' => 'Backfill subject without a split',
        ]);
        $splitAgreement = $service->acceptTerms($splitAgreement, $suchakUser);

        $this->assertCount(0, $plainAgreement->successFeeTranches);

        // Stand in for a production row digested before the tranche payload existed.
        DB::table('suchak_customer_agreements')
            ->whereIn('id', [$splitAgreement->id, $plainAgreement->id])
            ->update(['agreement_snapshot_hash' => str_repeat('0', 64)]);

        $this->assertFalse($service->isPackageSnapshotCurrent($splitAgreement->fresh()));
        $this->assertFalse($service->isPackageSnapshotCurrent($plainAgreement->fresh()));

        $migration = require database_path(
            'migrations/2026_08_01_171000_backfill_agreement_snapshot_hashes_for_success_fee_tranches.php'
        );
        $migration->up();

        $stale = 0;
        $null = 0;
        foreach (SuchakCustomerAgreement::query()->get() as $agreement) {
            if ($agreement->agreement_snapshot_hash === null || $agreement->agreement_snapshot_hash === '') {
                $null++;

                continue;
            }

            if (! $service->isPackageSnapshotCurrent($agreement)) {
                $stale++;
            }
        }

        $this->assertSame(0, $null);
        $this->assertSame(0, $stale);
        $this->assertNotSame(str_repeat('0', 64), $splitAgreement->fresh()->agreement_snapshot_hash);

        // Re-running is a no-op: the second pass finds every digest already correct.
        $before = SuchakCustomerAgreement::query()->pluck('agreement_snapshot_hash', 'id')->all();
        $migration->up();
        $this->assertSame($before, SuchakCustomerAgreement::query()->pluck('agreement_snapshot_hash', 'id')->all());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function workedExamplePlan(): array
    {
        return [
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => 10],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => 40],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 50],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: SuchakAccount, 2: SuchakServicePackage}
     */
    private function publishedPackageFixture(string $packageName = 'Success fee package', array $overrides = []): array
    {
        [$suchakUser, $account] = $this->verifiedSuchakActor();

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for success-fee tranche fixture.',
                'is_active' => true,
            ],
        );

        $package = app(SuchakPackageCatalogService::class)->createCustomPackage(
            $account,
            $suchakUser,
            array_merge([
                'package_name' => $packageName,
                'price_amount' => '5000',
                'currency' => 'INR',
                'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
                'post_marriage_fee_amount' => '100000',
            ], $overrides),
            [[
                'stage_key' => 'intake_and_shortlist',
                'stage_name' => 'Intake and shortlist',
                'sort_order' => 10,
                'expected_days' => 7,
            ]],
            [[
                'deliverable_key' => 'shortlist_pack',
                'deliverable_name' => 'Shortlist pack',
                'stage_key' => 'intake_and_shortlist',
                'sort_order' => 10,
            ]],
            null,
            null,
            null,
            true,
        );

        return [$suchakUser, $account, $package->fresh(['suchakAccount.user', 'stages', 'deliverables.servicePackageStage'])];
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakActor(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            // SuchakAccessService::canOperate() gates on isRegistrationComplete() before it
            // looks at verification, and the factory does not set this column.
            'registration_completed_at' => now(),
        ]);

        return [$user, $account];
    }
}
