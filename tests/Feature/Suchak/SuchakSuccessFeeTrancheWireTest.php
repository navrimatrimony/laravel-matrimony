<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\SuchakSuccessFeeTranche;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The wire between the app's success-fee split and the page a family accepts.
 *
 * Everything either end of that wire was already built and tested: the app posts
 * `success_fee_tranches` on both branches of preparePaymentSetup,
 * SuchakSuccessFeeTrancheService owns blueprint 7.4's four rules, the agreement
 * digest hashes the plan, and the acceptance page renders every row with its own
 * rupee figure. Only `/customers/{rep}/payment-setup` did not accept the key, so
 * validated() dropped it, the agreement was created without a split, and the
 * family was shown no instalments at all — a schedule quoted in WhatsApp and
 * frozen nowhere.
 *
 * These tests hold the wire itself: that a posted split survives the trip to the
 * page, that it may only be quoted against a fixed success fee, that re-quoting
 * it behaves exactly as re-quoting a fee or the price already does, and that an
 * accepted split is never rewritten.
 *
 * @see SuchakMeetingFeeQuotingTest for the same journey taken by the four fees.
 */
class SuchakSuccessFeeTrancheWireTest extends TestCase
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

    // ------------------------------------------------------------- end to end

    public function test_a_split_posted_with_the_send_reaches_the_page_the_family_accepts(): void
    {
        $rep = $this->bootSuchak();

        $data = $this->prepare($rep->id, $this->sendWithSplit());

        // 1. It survived validation and was written against the agreement.
        $agreement = SuchakCustomerAgreement::query()->findOrFail($data['customer_agreement_id']);
        $this->assertSame(
            ['10.00', '40.00', '50.00'],
            $agreement->successFeeTranches()->pluck('share_percent')->map(strval(...))->all(),
        );
        $this->assertSame(
            [
                SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
                SuchakCollaborationStageEvent::STAGE_ENGAGEMENT,
                SuchakCollaborationStageEvent::STAGE_MARRIAGE,
            ],
            $agreement->successFeeTranches()->pluck('trigger_stage_key')->all(),
        );

        // 2. The digest was taken WITH the split on it. Had the plan been written
        //    after the hash, the agreement would read as stale the moment it was
        //    created and the acceptance link below would refuse to issue.
        $this->assertTrue(
            app(SuchakAgreementService::class)->isPackageSnapshotCurrent($agreement),
            'The split must be inside the snapshot the customer freezes.',
        );

        // 3. It reaches the page — three Marathi stage lines, each carrying its own
        //    rupee figure of the ₹25,000 success fee.
        $response = $this->acceptancePage($agreement);

        $response->assertSee('लग्न ठरल्यावर', false);
        $response->assertSee('₹2,500', false);
        $response->assertSee('साखरपुड्यानंतर', false);
        $response->assertSee('₹10,000', false);
        $response->assertSee('विवाहानंतर', false);
        $response->assertSee('₹12,500', false);

        // T2 on the page: the last tranche is the remainder, never a percentage.
        $response->assertSee('उर्वरित रक्कम', false);

        // FROZEN rule: every numeral the family reads is Latin 0-9.
        $response->assertDontSee('०', false);
        $response->assertDontSee('५', false);
    }

    public function test_a_send_that_quotes_no_split_freezes_none(): void
    {
        $rep = $this->bootSuchak();

        $data = $this->prepare($rep->id, $this->sendWithSplit(null));

        // An undivided success fee is a real answer, and the commonest one. The
        // wire must carry a split, never invent one.
        $this->assertSame(
            0,
            SuchakSuccessFeeTranche::query()
                ->where('customer_agreement_id', $data['customer_agreement_id'])
                ->count(),
        );
    }

    // -------------------------------------------------- a split needs a figure

    public function test_a_split_without_a_fixed_success_fee_is_refused_in_marathi(): void
    {
        $rep = $this->bootSuchak();

        // A percentage of "तुमच्या इच्छेनुसार" is not a number. The service already
        // says so; what matters here is that the Suchak gets that sentence as a
        // 422 instead of the endpoint falling over with a 500.
        $response = $this->postJson("/api/v1/suchak/customers/{$rep->id}/payment-setup", [
            'package_name' => 'No fixed success fee',
            'services' => ['Introductions only'],
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_AS_WISHED,
            'success_fee_tranches' => $this->workedExampleSplit(),
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(
            'ठरलेले यशस्वी विवाह शुल्क नसताना हप्ते ठरवता येणार नाहीत.',
            (string) $response->json('message'),
        );

        // Nothing at all was written: the whole send rolls back, so a Suchak never
        // ends up with a package quoting a fee no agreement covers.
        $this->assertSame(0, SuchakServicePackage::query()->count());
        $this->assertSame(0, SuchakSuccessFeeTranche::query()->count());
    }

    public function test_a_split_with_no_success_fee_at_all_is_refused_the_same_way(): void
    {
        $rep = $this->bootSuchak();

        $response = $this->postJson("/api/v1/suchak/customers/{$rep->id}/payment-setup", [
            'package_name' => 'Silent on the success fee',
            'services' => ['Introductions only'],
            'success_fee_tranches' => $this->workedExampleSplit(),
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(
            'ठरलेले यशस्वी विवाह शुल्क नसताना हप्ते ठरवता येणार नाहीत.',
            (string) $response->json('message'),
        );
    }

    // ------------------------------------------------------------- vocabulary

    public function test_the_trigger_stage_must_come_from_the_stage_ladder(): void
    {
        $rep = $this->bootSuchak();

        $this->postJson("/api/v1/suchak/customers/{$rep->id}/payment-setup", array_merge(
            $this->sendWithSplit([
                ['trigger_stage_key' => 'whenever_the_client_felt_like_it', 'share_percent' => 100],
            ]),
            ['package_name' => 'Invented stage'],
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('success_fee_tranches.0.trigger_stage_key');

        // ...and a real ladder stage is accepted, so nothing was narrowed while
        // adding the guard.
        $this->postJson("/api/v1/suchak/customers/{$rep->id}/payment-setup", array_merge(
            $this->sendWithSplit([
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 100],
            ]),
            ['package_name' => 'Ladder stage'],
        ))->assertCreated();
    }

    public function test_the_arithmetic_rules_stay_with_their_one_owner(): void
    {
        $rep = $this->bootSuchak();

        // T3 is the service's rule, not a second copy in the controller: 10/40/40
        // leaves 10% of the fee belonging to nobody, and the Suchak is told so in
        // his own language, with the shortfall named.
        $response = $this->postJson("/api/v1/suchak/customers/{$rep->id}/payment-setup", $this->sendWithSplit([
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => 10],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => 40],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 40],
        ]));

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString(
            'हप्त्यांची टक्केवारी एकूण 100% असणे आवश्यक आहे',
            (string) $response->json('message'),
        );
        $this->assertStringContainsString('90%', (string) $response->json('message'));

        // Ladder order is the service's rule too — "the first tranche" and "the
        // final tranche" stop meaning anything if the rows arrive out of order.
        $this->postJson("/api/v1/suchak/customers/{$rep->id}/payment-setup", $this->sendWithSplit([
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 50],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => 50],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'हप्त्यांचा क्रम टप्प्यांच्या क्रमाप्रमाणेच असावा.');
    }

    // ------------------------------------------------------------- re-sending

    public function test_a_resend_with_an_edited_split_supersedes_the_pending_revision(): void
    {
        $rep = $this->bootSuchak();

        $first = $this->prepare($rep->id, $this->sendWithSplit());
        $second = $this->prepare($rep->id, $this->sendWithSplit([
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => 20],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 80],
        ]));

        // Same package — re-cutting the schedule is not a new plan. Exactly what an
        // edited fee and an edited price already do (commit c874e6ca).
        $this->assertSame($first['service_package_id'], $second['service_package_id']);

        // ...but a different agreement: nobody had accepted, so the pending
        // revision is superseded by one carrying the new schedule.
        $this->assertNotSame($first['customer_agreement_id'], $second['customer_agreement_id']);
        $this->assertSame(
            SuchakCustomerAgreement::TERMS_SUPERSEDED,
            SuchakCustomerAgreement::query()->findOrFail($first['customer_agreement_id'])->terms_status,
        );

        $current = SuchakCustomerAgreement::query()->findOrFail($second['customer_agreement_id']);
        $this->assertSame(2, (int) $current->agreement_revision);
        $this->assertSame(
            ['20.00', '80.00'],
            $current->successFeeTranches()->pluck('share_percent')->map(strval(...))->all(),
        );
        $this->assertTrue(
            app(SuchakAgreementService::class)->isPackageSnapshotCurrent($current),
            'The fresh revision must describe the re-cut split, or acceptance will refuse it.',
        );

        // And the family sees the new schedule, not the old one.
        $page = $this->acceptancePage($current);
        $page->assertSee('₹5,000', false);
        $page->assertSee('₹20,000', false);
        $page->assertDontSee('₹2,500', false);
    }

    public function test_a_resend_that_repeats_the_same_split_reuses_the_agreement_untouched(): void
    {
        $rep = $this->bootSuchak();

        $first = $this->prepare($rep->id, $this->sendWithSplit());
        $this->acceptTerms($first['customer_agreement_id']);

        // Re-sharing the same quote to a customer who already accepted is an
        // ordinary thing to do and must keep working.
        $second = $this->prepare($rep->id, $this->sendWithSplit());

        $this->assertSame($first['customer_agreement_id'], $second['customer_agreement_id']);
        $this->assertSame(SuchakCustomerAgreement::TERMS_ACCEPTED, $second['terms_status']);
        $this->assertSame(
            3,
            SuchakSuccessFeeTranche::query()
                ->where('customer_agreement_id', $first['customer_agreement_id'])
                ->count(),
        );
    }

    public function test_a_resend_may_not_recut_a_split_the_customer_has_already_accepted(): void
    {
        $rep = $this->bootSuchak();

        $first = $this->prepare($rep->id, $this->sendWithSplit());
        $this->acceptTerms($first['customer_agreement_id']);

        $response = $this->postJson(
            "/api/v1/suchak/customers/{$rep->id}/payment-setup",
            $this->sendWithSplit([
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => 60],
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 40],
            ]),
        );

        $response->assertStatus(422)->assertJsonPath('success', false);

        // Marathi, and it names what the customer actually holds — the Suchak is
        // looking at a message quoting 60/40 and has to know what was agreed.
        $message = (string) $response->json('message');
        $this->assertStringContainsString('लग्न ठरल्यावर: 10%', $message);
        $this->assertStringContainsString('साखरपुड्यानंतर: 40%', $message);
        $this->assertStringContainsString('विवाहानंतर: उर्वरित रक्कम', $message);

        // The accepted agreement is exactly as accepted.
        $agreement = SuchakCustomerAgreement::query()->findOrFail($first['customer_agreement_id']);
        $this->assertSame(SuchakCustomerAgreement::TERMS_ACCEPTED, $agreement->terms_status);
        $this->assertSame(1, (int) $agreement->agreement_revision);
        $this->assertSame(
            ['10.00', '40.00', '50.00'],
            $agreement->successFeeTranches()->pluck('share_percent')->map(strval(...))->all(),
        );

        // No second revision was manufactured on the way to the refusal.
        $this->assertSame(
            1,
            SuchakCustomerAgreement::query()->where('service_package_id', $first['service_package_id'])->count(),
        );
    }

    public function test_quoting_a_split_onto_an_accepted_agreement_that_had_none_is_refused_too(): void
    {
        $rep = $this->bootSuchak();

        $first = $this->prepare($rep->id, $this->sendWithSplit(null));
        $this->acceptTerms($first['customer_agreement_id']);

        $response = $this->postJson(
            "/api/v1/suchak/customers/{$rep->id}/payment-setup",
            $this->sendWithSplit(),
        );

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('हप्ते ठरलेले नाहीत', (string) $response->json('message'));
        $this->assertSame(0, SuchakSuccessFeeTranche::query()->count());
    }

    public function test_a_fee_requote_carries_an_unmentioned_split_forward(): void
    {
        $rep = $this->bootSuchak();

        $first = $this->prepare($rep->id, $this->sendWithSplit());

        // A screen that edits the meeting fee says nothing about the schedule.
        // Silence is not "remove the split" — dropping it here would make the
        // whole success fee fall due at one stage without anyone deciding that.
        $second = $this->prepare($rep->id, array_merge(
            $this->sendWithSplit(null),
            ['per_meeting_fee_amount' => '999'],
        ));

        $this->assertNotSame($first['customer_agreement_id'], $second['customer_agreement_id']);

        $current = SuchakCustomerAgreement::query()->findOrFail($second['customer_agreement_id']);
        $this->assertSame(
            ['10.00', '40.00', '50.00'],
            $current->successFeeTranches()->pluck('share_percent')->map(strval(...))->all(),
        );
        $this->assertTrue(app(SuchakAgreementService::class)->isPackageSnapshotCurrent($current));
    }

    // ----------------------------------------------------------------- helpers

    private function bootSuchak(): SuchakProfileRepresentation
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Tranche Wire Candidate',
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $rep = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($user);

        return $rep->load('suchakAccount');
    }

    /**
     * A custom send quoting a fixed ₹25,000 success fee, optionally divided.
     *
     * The registration price is deliberately ₹12,000 so no fee on the page shares
     * a rendered string with a tranche figure.
     *
     * @param  ?list<array<string, mixed>>  $tranches  null omits the key entirely,
     *                                                 which is what the app does
     *                                                 for an undivided fee
     * @return array<string, mixed>
     */
    private function sendWithSplit(?array $tranches = []): array
    {
        return array_merge([
            'package_name' => 'Tranche wire plan',
            'services' => ['Matchmaking'],
            'price_amount' => '12000',
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
            'post_marriage_fee_amount' => '25000',
        ], $tranches === null ? [] : [
            'success_fee_tranches' => $tranches === [] ? $this->workedExampleSplit() : $tranches,
        ]);
    }

    /**
     * Blueprint 7.4's worked example: 10 / 40 / remainder of ₹25,000.
     *
     * @return list<array<string, mixed>>
     */
    private function workedExampleSplit(): array
    {
        return [
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => 10],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => 40],
            ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 50],
        ];
    }

    private function acceptancePage(SuchakCustomerAgreement $agreement): \Illuminate\Testing\TestResponse
    {
        $link = app(SuchakAgreementService::class)->issueAcceptanceLink(
            $agreement,
            $agreement->suchakAccount->user,
        );

            // This page is served by the WEB group, whose locale comes from
            // `?locale=` / session (SetLocaleFromQuery) and NOT from
            // Accept-Language — so Marathi is requested the way that page
            // actually accepts it.
        return $this->get(route('suchak.agreements.public.show', [
            'token' => $link['raw_token'],
            'locale' => 'mr',
        ]))->assertOk();
    }

    /**
     * Moves an agreement to TERMS_ACCEPTED through the real service, so the
     * "already accepted" guard is tested against the state production reaches.
     */
    private function acceptTerms(int $agreementId): void
    {
        $agreement = SuchakCustomerAgreement::query()->findOrFail($agreementId);
        app(SuchakAgreementService::class)->acceptTerms($agreement, $agreement->suchakAccount->user);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function prepare(int $repId, array $payload): array
    {
        $response = $this->postJson("/api/v1/suchak/customers/{$repId}/payment-setup", $payload);
        if ($response->status() !== 201) {
            fwrite(STDERR, "\n[PREPARE] status={$response->status()} body=".$response->getContent()."\n");
        }
        $response->assertCreated();

        return $response->json('data');
    }
}
