<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakPolicy;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SuchakPublicAgreementAcceptanceTest extends TestCase
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

    public function test_acceptance_token_columns_exist_and_token_is_stored_hashed(): void
    {
        foreach ([
            'acceptance_token_hash',
            'acceptance_token_expires_at',
            'acceptance_token_used_at',
            'accepted_ip_address',
            'accepted_user_agent',
            'accepted_by_name',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_agreements', $column), $column);
        }

        [$suchakUser, $agreement] = $this->pendingAgreementFixture();

        $link = app(SuchakAgreementService::class)->issueAcceptanceLink($agreement, $suchakUser);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $link['raw_token']);
        $this->assertStringContainsString($link['raw_token'], $link['acceptance_url']);

        $stored = $agreement->fresh();
        $this->assertSame(hash('sha256', $link['raw_token']), $stored->acceptance_token_hash);
        $this->assertNotSame($link['raw_token'], $stored->acceptance_token_hash);
        $this->assertNull($stored->acceptance_token_used_at);
        $this->assertTrue($stored->acceptance_token_expires_at->isFuture());
        $this->assertFalse($stored->isAcceptanceTokenExpired());
    }

    public function test_public_page_shows_all_four_fees_and_the_freeze_note_in_latin_digits(): void
    {
        [$suchakUser, $agreement] = $this->pendingAgreementFixture();
        $link = app(SuchakAgreementService::class)->issueAcceptanceLink($agreement, $suchakUser);

        $response = $this->get(route('suchak.agreements.public.show', ['token' => $link['raw_token']]));

        $response->assertOk();
        $response->assertSee('नोंदणी शुल्क', false);
        $response->assertSee('₹15,000', false);
        $response->assertSee('प्रत्यक्ष भेटीचे शुल्क (प्रति भेट)', false);
        $response->assertSee('₹500', false);
        $response->assertSee('ऑनलाइन भेटीचे शुल्क (प्रति भेट)', false);
        $response->assertSee('₹300', false);
        $response->assertSee('विवाह ठरल्यानंतरचे शुल्क', false);
        $response->assertSee('₹25,000', false);
        $response->assertSee('तुम्ही स्वीकारल्यानंतर वरील रक्कम कायम होतील', false);

        // The page must never claim a check that does not exist yet.
        $response->assertSee('या पानावर OTP पडताळणी होत नाही', false);
        $response->assertDontSee('OTP verified', false);

        // FROZEN rule: every numeral a reader sees is Latin 0-9.
        $response->assertDontSee('०', false);
        $response->assertDontSee('५', false);
    }

    /**
     * The tranche plan is inside the digest this page freezes. A customer who
     * accepts without being shown it has agreed to a payment schedule nobody
     * showed them — which is the precise failure the freeze exists to prevent.
     */
    public function test_the_page_shows_the_success_fee_split_the_customer_is_agreeing_to(): void
    {
        [$suchakUser, $agreement] = $this->pendingAgreementFixture([
            'success_fee_tranches' => [
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED, 'share_percent' => 10],
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_ENGAGEMENT, 'share_percent' => 40],
                ['trigger_stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE, 'share_percent' => 50],
            ],
        ]);
        $link = app(SuchakAgreementService::class)->issueAcceptanceLink($agreement, $suchakUser);

        // Served by the WEB group: its locale comes from `?locale=` / session,
        // not from Accept-Language, so Marathi is asked for that way.
        $response = $this->get(route('suchak.agreements.public.show', [
            'token' => $link['raw_token'],
            'locale' => 'mr',
        ]));

        $response->assertOk();
        $response->assertSee('लग्न ठरल्यावर', false);
        $response->assertSee('साखरपुड्यानंतर', false);
        $response->assertSee('विवाहानंतर', false);

        // Each row carries its own rupee figure — a family should not have to
        // compute 10% of a number printed elsewhere on the page.
        $response->assertSee('₹2,500', false);
        $response->assertSee('₹10,000', false);
        $response->assertSee('₹12,500', false);

        // T2: the last tranche is the remainder, never a percentage.
        $response->assertSee('उर्वरित रक्कम', false);
        $response->assertDontSee('50%', false);
    }

    public function test_public_acceptance_records_evidence_without_a_user_and_leaves_the_row_immutable(): void
    {
        [$suchakUser, $agreement] = $this->pendingAgreementFixture();
        $link = app(SuchakAgreementService::class)->issueAcceptanceLink($agreement, $suchakUser);

        $response = $this->post(
            route('suchak.agreements.public.decision', ['token' => $link['raw_token']]),
            ['accepted_by_name' => 'सुनिता पवार'],
        );

        $response->assertOk();
        $response->assertSee('तुमचा स्वीकार नोंदवला आहे', false);

        $accepted = $agreement->fresh();
        $this->assertSame(SuchakCustomerAgreement::TERMS_ACCEPTED, $accepted->terms_status);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertNotNull($accepted->acceptance_token_used_at);
        $this->assertSame('सुनिता पवार', $accepted->accepted_by_name);
        $this->assertNotNull($accepted->accepted_ip_address);
        $this->assertNotNull($accepted->accepted_user_agent);

        // Token possession replaced actor identity; no user is claimed to have accepted.
        $this->assertNull($accepted->accepted_by_user_id);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'actor_user_id' => null,
            'actor_type' => SuchakActivityLog::ACTOR_USER,
            'action_type' => SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_TERMS_ACCEPTED,
            'target_type' => 'suchak_customer_agreement',
            'target_id' => $accepted->id,
        ]);

        // Proves the acceptance went through the query builder: a model save on
        // this row throws, so this write could not have used one.
        try {
            $accepted->update(['invoice_note' => 'silent change']);
            $this->fail('Accepted agreement should stay immutable.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Suchak customer agreements are immutable after acceptance, bypass, or not-required finalization.',
                $exception->getMessage(),
            );
        }
    }

    public function test_link_is_single_use_and_second_submission_changes_nothing(): void
    {
        [$suchakUser, $agreement] = $this->pendingAgreementFixture();
        $link = app(SuchakAgreementService::class)->issueAcceptanceLink($agreement, $suchakUser);

        $this->post(
            route('suchak.agreements.public.decision', ['token' => $link['raw_token']]),
            ['accepted_by_name' => 'First Acceptor'],
        )->assertOk();

        $firstAcceptedAt = $agreement->fresh()->acceptance_token_used_at;

        $response = $this->post(
            route('suchak.agreements.public.decision', ['token' => $link['raw_token']]),
            ['accepted_by_name' => 'Second Acceptor'],
        );

        $response->assertOk();
        $response->assertSee('हा स्वीकार आता नोंदवता आला नाही', false);

        $stillFirst = $agreement->fresh();
        $this->assertSame('First Acceptor', $stillFirst->accepted_by_name);
        $this->assertEquals($firstAcceptedAt, $stillFirst->acceptance_token_used_at);
        $this->assertSame(
            1,
            SuchakActivityLog::query()
                ->where('target_type', 'suchak_customer_agreement')
                ->where('target_id', $stillFirst->id)
                ->where('action_type', SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_TERMS_ACCEPTED)
                ->count(),
        );
    }

    public function test_expired_link_cannot_accept_and_unknown_token_is_rejected(): void
    {
        [$suchakUser, $agreement] = $this->pendingAgreementFixture();
        $link = app(SuchakAgreementService::class)->issueAcceptanceLink($agreement, $suchakUser);

        // Query builder, because the model refuses ordinary saves on this table
        // once finalised and this test must not depend on that timing.
        SuchakCustomerAgreement::query()
            ->whereKey($agreement->id)
            ->update(['acceptance_token_expires_at' => now()->subDay()]);

        $this->get(route('suchak.agreements.public.show', ['token' => $link['raw_token']]))
            ->assertOk()
            ->assertSee('ही link expired झाली आहे', false);

        $this->post(
            route('suchak.agreements.public.decision', ['token' => $link['raw_token']]),
            ['accepted_by_name' => 'Late Acceptor'],
        )->assertOk();

        $this->assertSame(SuchakCustomerAgreement::TERMS_PENDING, $agreement->fresh()->terms_status);

        $this->get(route('suchak.agreements.public.show', ['token' => str_repeat('z', 64)]))
            ->assertOk()
            ->assertSee('ही link योग्य नाही', false);
    }

    public function test_acceptance_requires_a_typed_name(): void
    {
        [$suchakUser, $agreement] = $this->pendingAgreementFixture();
        $link = app(SuchakAgreementService::class)->issueAcceptanceLink($agreement, $suchakUser);

        $this->post(
            route('suchak.agreements.public.decision', ['token' => $link['raw_token']]),
            ['accepted_by_name' => ''],
        )->assertSessionHasErrors('accepted_by_name');

        $this->assertSame(SuchakCustomerAgreement::TERMS_PENDING, $agreement->fresh()->terms_status);
    }

    /**
     * @return array{0: User, 1: SuchakCustomerAgreement}
     */
    private function pendingAgreementFixture(array $agreementAttributes = []): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for public acceptance fixture.',
                'is_active' => true,
            ],
        );

        // All four fees are set before the agreement snapshot is taken, so the
        // snapshot hash covers them and acceptance is not blocked as stale.
        $package = app(SuchakPackageCatalogService::class)->createCustomPackage(
            $account,
            $user,
            [
                'package_name' => 'Family Coordination',
                'price_amount' => '15000',
                'currency' => 'INR',
                'per_meeting_fee_amount' => '500',
                'per_meeting_online_fee_amount' => '300',
                'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
                'post_marriage_fee_amount' => '25000',
            ],
            [[
                'stage_key' => 'intake_and_shortlist',
                'stage_name' => 'Intake and shortlist',
                'sort_order' => 10,
                'expected_days' => 7,
            ]],
            [[
                'stage_key' => 'intake_and_shortlist',
                'deliverable_key' => 'shortlist_report',
                'deliverable_name' => 'Shortlist report',
                'sort_order' => 10,
            ]],
            null,
            null,
            null,
            true,
        );

        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage(
            $package->fresh(['suchakAccount', 'stages', 'deliverables.servicePackageStage']),
            $user,
            $agreementAttributes,
        );

        $this->assertSame(SuchakCustomerAgreement::TERMS_PENDING, $agreement->terms_status);

        return [$user, $agreement];
    }
}
