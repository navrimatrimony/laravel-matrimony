<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakPolicy;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/v1/suchak/customer-agreements/{agreement}/acceptance-link
 *
 * The customer-facing acceptance page shipped without anything able to mint its
 * token, so the whole feature was unreachable in production. These tests pin the
 * three things that closing that gap must be true of: the Suchak can send a link
 * for HIS OWN agreement, he cannot reach anyone else's, and an agreement that is
 * past the point of acceptance says so in Marathi instead of minting a link
 * nobody can use.
 */
class SuchakAgreementLinkIssuanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_issues_a_link_that_opens_the_public_page_and_leaves_an_audit_row(): void
    {
        [$user, $agreement] = $this->pendingAgreementFixture();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/suchak/customer-agreements/{$agreement->id}/acceptance-link")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.agreement_id', (int) $agreement->id)
            ->assertJsonPath('data.terms_status', SuchakCustomerAgreement::TERMS_PENDING);

        $url = (string) $response->json('data.acceptance_url');
        $message = (string) $response->json('data.forward_message');

        // Absolute, and the token in it is the one that was hashed onto the row.
        $this->assertStringStartsWith('http', $url);
        $this->assertMatchesRegularExpression('#/suchak/agreement/[A-Za-z0-9]{64}$#', $url);
        $this->assertNotNull($response->json('data.expires_at'));

        $stored = $agreement->fresh();
        $this->assertNotNull($stored->acceptance_token_hash);
        $this->assertNull($stored->acceptance_token_used_at);
        $this->assertTrue($stored->acceptance_token_expires_at->isFuture());

        // The link the Suchak was handed actually reaches the customer page.
        preg_match('#/suchak/agreement/([A-Za-z0-9]{64})$#', $url, $matches);
        $this->assertSame(hash('sha256', $matches[1]), $stored->acceptance_token_hash);
        $this->get($url)->assertOk()->assertSee('₹15,000', false);

        // The message: who is asking, what it is, the amount, the link. Marathi
        // text, Latin digits, Indian grouping through MoneyFormat.
        $this->assertStringContainsString('नमस्कार', $message);
        $this->assertStringContainsString('Family Office', $message);
        $this->assertStringContainsString('₹15,000', $message);
        $this->assertStringContainsString($url, $message);
        $this->assertStringNotContainsString('०', $message);
        $this->assertStringNotContainsString('१', $message);

        // Its own action — not borrowed from consent, portal or acceptance.
        $this->assertDatabaseHas('suchak_activity_logs', [
            'actor_user_id' => $user->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_LINK_ISSUED,
            'target_type' => 'suchak_customer_agreement',
            'target_id' => $agreement->id,
        ]);

        // Nothing may have recorded an acceptance that did not happen.
        $this->assertSame(
            0,
            SuchakActivityLog::query()
                ->where('target_id', $agreement->id)
                ->where('action_type', SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_TERMS_ACCEPTED)
                ->count(),
        );
    }

    public function test_re_issuing_kills_the_previous_link_and_logs_a_second_issuance(): void
    {
        [$user, $agreement] = $this->pendingAgreementFixture();
        Sanctum::actingAs($user);

        $first = (string) $this->postJson("/api/v1/suchak/customer-agreements/{$agreement->id}/acceptance-link")
            ->assertOk()
            ->json('data.acceptance_url');

        $second = (string) $this->postJson("/api/v1/suchak/customer-agreements/{$agreement->id}/acceptance-link")
            ->assertOk()
            ->json('data.acceptance_url');

        $this->assertNotSame($first, $second);

        // A stale WhatsApp forward must stop working the moment a replacement
        // is sent — otherwise two live links exist for one single-use token.
        $this->get($first)->assertOk()->assertSee('ही link योग्य नाही', false);
        $this->get($second)->assertOk()->assertSee('₹15,000', false);

        $this->assertSame(
            2,
            SuchakActivityLog::query()
                ->where('target_id', $agreement->id)
                ->where('action_type', SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_LINK_ISSUED)
                ->count(),
        );
    }

    public function test_another_suchak_cannot_issue_a_link_for_someone_elses_agreement(): void
    {
        [, $agreement] = $this->pendingAgreementFixture();
        [$intruder] = $this->pendingAgreementFixture('9876512002', 'Rival Office');

        Sanctum::actingAs($intruder);

        $this->postJson("/api/v1/suchak/customer-agreements/{$agreement->id}/acceptance-link")
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->assertNull($agreement->fresh()->acceptance_token_hash);
        $this->assertSame(
            0,
            SuchakActivityLog::query()
                ->where('target_id', $agreement->id)
                ->where('action_type', SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_LINK_ISSUED)
                ->count(),
        );
    }

    public function test_an_already_accepted_agreement_is_refused_in_marathi_and_keeps_its_spent_token(): void
    {
        [$user, $agreement] = $this->pendingAgreementFixture();

        $link = app(SuchakAgreementService::class)->issueAcceptanceLink($agreement, $user);
        $this->post(
            route('suchak.agreements.public.decision', ['token' => $link['raw_token']]),
            ['accepted_by_name' => 'सुनिता पवार'],
        )->assertOk();

        $accepted = $agreement->fresh();
        $this->assertSame(SuchakCustomerAgreement::TERMS_ACCEPTED, $accepted->terms_status);
        $spentAt = $accepted->acceptance_token_used_at;

        Sanctum::actingAs($user);

        // Marathi is ASKED FOR, not assumed. This refusal used to be a Marathi
        // literal in the controller, so it read the same whatever the caller
        // wanted; now it follows Accept-Language, and this test says which
        // language it is testing.
        $this->postJson(
            "/api/v1/suchak/customer-agreements/{$agreement->id}/acceptance-link",
            [],
            ['Accept-Language' => 'mr'],
        )
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'हा करार ग्राहकाने आधीच स्वीकारला आहे.');

        // The frozen acceptance is untouched: no fresh token, no cleared marker.
        $after = $agreement->fresh();
        $this->assertEquals($spentAt, $after->acceptance_token_used_at);
        $this->assertSame(hash('sha256', $link['raw_token']), $after->acceptance_token_hash);
    }

    public function test_a_superseded_agreement_is_refused_and_names_the_next_move(): void
    {
        [$user, $agreement] = $this->pendingAgreementFixture();

        SuchakCustomerAgreement::query()
            ->whereKey($agreement->id)
            ->update([
                'terms_status' => SuchakCustomerAgreement::TERMS_SUPERSEDED,
                'superseded_at' => now(),
            ]);

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/v1/suchak/customer-agreements/{$agreement->id}/acceptance-link",
            [],
            ['Accept-Language' => 'mr'],
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'हा करार आता वापरात नाही. नवीन करार तयार करून पाठवा.');

        $this->assertNull($agreement->fresh()->acceptance_token_hash);
    }

    public function test_a_user_without_a_suchak_account_is_refused(): void
    {
        [, $agreement] = $this->pendingAgreementFixture();

        Sanctum::actingAs(User::factory()->create());

        // EnsureSuchakAccount stops this before the controller; either way the
        // caller never reaches an agreement he does not own.
        $this->postJson("/api/v1/suchak/customer-agreements/{$agreement->id}/acceptance-link")
            ->assertStatus(403);

        $this->assertNull($agreement->fresh()->acceptance_token_hash);
    }

    /**
     * Mirrors SuchakPublicAgreementAcceptanceTest's fixture: verified account
     * (registration_completed_at is REQUIRED — SuchakAccessService::canOperate
     * refuses without it and the factory does not set it), auto-publish policy,
     * all four fees set before the snapshot is taken.
     *
     * @return array{0: User, 1: SuchakCustomerAgreement}
     */
    private function pendingAgreementFixture(
        string $mobile = '9876512001',
        string $officeName = 'Family Office',
    ): array {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'mobile_number' => $mobile,
            'office_name_mr' => $officeName,
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
                'description' => 'Auto publish packages for agreement link issuance fixture.',
                'is_active' => true,
            ],
        );

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
        );

        $this->assertSame(SuchakCustomerAgreement::TERMS_PENDING, $agreement->terms_status);

        return [$user, $agreement];
    }
}
