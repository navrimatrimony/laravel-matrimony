<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakPolicy;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakCustomerPlanService;
use App\Modules\Suchak\Services\SuchakPackageCatalogService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Modules\Suchak\Support\SuchakDefaultPlans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A meeting held online is separately priced work, not a variant of an offline
 * visit. The product rule is that the two per-meeting fees are FULLY
 * INDEPENDENT amounts: no ratio, no percentage, no derived default. A two-hour
 * online counselling session may legitimately be quoted ABOVE a house visit,
 * and the reverse is just as valid — so every test here pins the absence of a
 * relationship as hard as it pins the values themselves.
 *
 * Like the offline fee, this is a DISCLOSED NOTE (PRODUCT_MAP §3a): it is shown
 * so the family knows up front and is never summed into amount_due.
 */
class SuchakOnlineMeetingFeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_tables_carry_the_online_fee_column(): void
    {
        // The plan is the reusable preset; the package is the frozen record of
        // what one customer was actually offered. A fee that only existed on the
        // plan would be rewritten for past customers every time a rate changed.
        $this->assertTrue(Schema::hasColumn('suchak_customer_plans', 'per_meeting_online_fee_amount'));
        $this->assertTrue(Schema::hasColumn('suchak_service_packages', 'per_meeting_online_fee_amount'));
    }

    public function test_online_fee_may_exceed_the_offline_fee(): void
    {
        [, $account] = $this->verifiedSuchakActor();

        $plan = app(SuchakCustomerPlanService::class)->create($account, [
            'name' => 'Counselling-heavy plan',
            'price_amount' => '9000',
            'duration' => SuchakCustomerPlan::DURATION_ONE_YEAR,
            'services' => [['name' => 'Video counselling']],
            'per_meeting_fee_amount' => '500',
            'per_meeting_online_fee_amount' => '2500',
        ]);

        // The whole point of the rule: nothing capped, scaled or rejected the
        // online amount for being five times the offline one.
        $this->assertSame('500.00', $plan->per_meeting_fee_amount);
        $this->assertSame('2500.00', $plan->per_meeting_online_fee_amount);
    }

    public function test_each_meeting_fee_can_be_set_without_the_other(): void
    {
        [, $account] = $this->verifiedSuchakActor();
        $service = app(SuchakCustomerPlanService::class);

        $onlineOnly = $service->create($account, [
            'name' => 'Remote-only plan',
            'price_amount' => '4000',
            'duration' => SuchakCustomerPlan::DURATION_SIX_MONTHS,
            'services' => [['name' => 'Video shortlisting']],
            'per_meeting_online_fee_amount' => '1200',
        ]);

        // A Suchak who only ever meets online must not be forced to invent an
        // offline rate, so NULL keeps meaning "not offered", not zero.
        $this->assertNull($onlineOnly->per_meeting_fee_amount);
        $this->assertSame('1200.00', $onlineOnly->per_meeting_online_fee_amount);

        $offlineOnly = $service->create($account, [
            'name' => 'In-person-only plan',
            'price_amount' => '4000',
            'duration' => SuchakCustomerPlan::DURATION_SIX_MONTHS,
            'services' => [['name' => 'Home visit']],
            'per_meeting_fee_amount' => '800',
        ]);

        $this->assertSame('800.00', $offlineOnly->per_meeting_fee_amount);
        $this->assertNull($offlineOnly->per_meeting_online_fee_amount);
    }

    public function test_updating_one_meeting_fee_leaves_the_other_untouched(): void
    {
        [, $account] = $this->verifiedSuchakActor();
        $service = app(SuchakCustomerPlanService::class);

        $plan = $service->create($account, [
            'name' => 'Both-modes plan',
            'price_amount' => '6000',
            'duration' => SuchakCustomerPlan::DURATION_ONE_YEAR,
            'services' => [['name' => 'Matchmaking']],
            'per_meeting_fee_amount' => '700',
            'per_meeting_online_fee_amount' => '900',
        ]);

        $plan = $service->update($plan, ['per_meeting_online_fee_amount' => '1800']);
        $this->assertSame('1800.00', $plan->per_meeting_online_fee_amount);
        $this->assertSame('700.00', $plan->per_meeting_fee_amount, 'Raising the online rate must not move the offline one.');

        $plan = $service->update($plan, ['per_meeting_fee_amount' => '750']);
        $this->assertSame('750.00', $plan->per_meeting_fee_amount);
        $this->assertSame('1800.00', $plan->per_meeting_online_fee_amount, 'Raising the offline rate must not move the online one.');

        // Withdrawing online meetings is its own edit and must not disturb the
        // offline fee the customer still sees.
        $plan = $service->update($plan, ['per_meeting_online_fee_amount' => null]);
        $this->assertNull($plan->per_meeting_online_fee_amount);
        $this->assertSame('750.00', $plan->per_meeting_fee_amount);
    }

    public function test_api_create_and_update_accept_the_online_fee(): void
    {
        [$user] = $this->verifiedSuchakActor();
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/suchak/customer-plans', [
            'name' => 'API plan',
            'price_amount' => '5000',
            'duration' => SuchakCustomerPlan::DURATION_ONE_YEAR,
            'services' => [['name' => 'Coordination']],
            'per_meeting_fee_amount' => '300',
            'per_meeting_online_fee_amount' => '3400',
        ]);
        $create->assertCreated();

        $planId = $create->json('data.plan_id');
        $this->assertDatabaseHas('suchak_customer_plans', [
            'id' => $planId,
            'per_meeting_fee_amount' => '300.00',
            'per_meeting_online_fee_amount' => '3400.00',
        ]);

        $update = $this->putJson("/api/v1/suchak/customer-plans/{$planId}", [
            'per_meeting_online_fee_amount' => '4100',
        ]);
        $update->assertOk();

        $this->assertDatabaseHas('suchak_customer_plans', [
            'id' => $planId,
            'per_meeting_fee_amount' => '300.00',
            'per_meeting_online_fee_amount' => '4100.00',
        ]);
    }

    public function test_a_negative_online_fee_is_rejected(): void
    {
        [$user] = $this->verifiedSuchakActor();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/suchak/customer-plans', [
            'name' => 'Bad plan',
            'price_amount' => '5000',
            'duration' => SuchakCustomerPlan::DURATION_ONE_YEAR,
            'services' => [['name' => 'Coordination']],
            'per_meeting_online_fee_amount' => '-1',
        ])->assertStatus(422)->assertJsonValidationErrors('per_meeting_online_fee_amount');
    }

    public function test_both_carousel_readers_surface_the_online_fee(): void
    {
        [, $account] = $this->verifiedSuchakActor();
        $service = app(SuchakCustomerPlanService::class);

        $custom = $service->create($account, [
            'name' => 'Custom carousel plan',
            'price_amount' => '7000',
            'duration' => SuchakCustomerPlan::DURATION_ONE_YEAR,
            'services' => [['name' => 'Counselling']],
            'per_meeting_fee_amount' => '400',
            'per_meeting_online_fee_amount' => '1600',
        ]);

        // The preset reader is a different code path (it reads the OVERRIDE row,
        // not the code preset), so it gets its own row to read from.
        $override = $service->upsertPresetOverride($account, SuchakDefaultPlans::KEY_PREMIUM, ['price_amount' => '8000']);
        $override->forceFill(['per_meeting_online_fee_amount' => '2200.00'])->save();

        $carousel = collect($service->resolveCarousel($account));

        $customEntry = $carousel->firstWhere('id', $custom->id);
        $this->assertNotNull($customEntry);
        $this->assertSame('400.00', $customEntry['per_meeting_fee_amount']);
        $this->assertSame('1600.00', $customEntry['per_meeting_online_fee_amount']);

        $presetEntry = $carousel->firstWhere('preset_key', SuchakDefaultPlans::KEY_PREMIUM);
        $this->assertNotNull($presetEntry);
        $this->assertSame('2200.00', $presetEntry['per_meeting_online_fee_amount']);

        // A preset with no override row fixes no fee at all — the key is present
        // (so the app can read it unconditionally) but null, which is what keeps
        // the fee row opt-in and unchecked.
        $untouched = $carousel->firstWhere('preset_key', SuchakDefaultPlans::KEY_BASIC);
        $this->assertNotNull($untouched);
        $this->assertArrayHasKey('per_meeting_online_fee_amount', $untouched);
        $this->assertNull($untouched['per_meeting_online_fee_amount']);

        // Management view is the second reader of the same entries.
        $managed = collect($service->resolveForManagement($account))->firstWhere('id', $custom->id);
        $this->assertSame('1600.00', $managed['per_meeting_online_fee_amount']);
    }

    public function test_payment_request_options_put_the_online_fee_on_the_wire(): void
    {
        $rep = $this->bootRepresentedCandidate();

        $plan = app(SuchakCustomerPlanService::class)->create($rep->suchakAccount, [
            'name' => 'Wire plan',
            'price_amount' => '7500',
            'duration' => SuchakCustomerPlan::DURATION_TILL_MARRIAGE,
            'services' => [['name' => 'Dedicated counselor']],
            'per_meeting_fee_amount' => '1500',
            'per_meeting_online_fee_amount' => '2600',
        ]);

        $response = $this->getJson("/api/v1/suchak/customers/{$rep->id}/payment-request-options");
        $response->assertOk();

        $wired = collect($response->json('data.default_plans'))
            ->firstWhere('plan_key', 'custom_'.$plan->id);

        $this->assertNotNull($wired, 'the custom plan must appear in default_plans');
        // The send screen seeds the online fee row from this key alone; without
        // it the Suchak retypes the rate on every single request.
        $this->assertSame('1500.00', $wired['per_meeting_fee_amount']);
        $this->assertSame('2600.00', $wired['per_meeting_online_fee_amount']);

        $preset = collect($response->json('data.default_plans'))
            ->firstWhere('plan_key', SuchakDefaultPlans::KEY_BASIC);
        $this->assertArrayHasKey('per_meeting_online_fee_amount', $preset);
        $this->assertNull($preset['per_meeting_online_fee_amount']);
    }

    public function test_materialised_package_carries_the_online_fee(): void
    {
        $package = $this->publishedPackage([
            'package_name' => 'Materialised package',
            'price_amount' => '15000',
            'currency' => 'INR',
            'per_meeting_fee_amount' => '600',
            'per_meeting_online_fee_amount' => '2900',
        ]);

        $this->assertSame('600.00', $package->per_meeting_fee_amount);
        $this->assertSame('2900.00', $package->per_meeting_online_fee_amount);
    }

    public function test_online_fee_materialises_even_when_no_offline_fee_was_quoted(): void
    {
        // Separate normalisation calls, so an absent offline fee must not drag
        // the online one to null with it.
        $package = $this->publishedPackage([
            'package_name' => 'Remote package',
            'price_amount' => '12000',
            'currency' => 'INR',
            'per_meeting_online_fee_amount' => '1750',
        ]);

        $this->assertNull($package->per_meeting_fee_amount);
        $this->assertSame('1750.00', $package->per_meeting_online_fee_amount);
    }

    public function test_changing_only_the_online_fee_invalidates_an_agreement_snapshot(): void
    {
        [$suchakUser] = $this->verifiedSuchakActor();
        $package = $this->publishedPackage([
            'package_name' => 'Agreement package',
            'price_amount' => '15000',
            'currency' => 'INR',
            'per_meeting_fee_amount' => '600',
            'per_meeting_online_fee_amount' => '1000',
        ], $suchakUser);

        $agreementService = app(SuchakAgreementService::class);
        $agreement = $agreementService->createAgreementForPackage($package, $suchakUser, [
            'agreement_title' => 'Online fee agreement',
            'agreement_body' => 'Customer confirms the quoted meeting rates.',
        ]);

        $this->assertTrue(
            $agreementService->isPackageSnapshotCurrent($agreement->fresh(['servicePackage.stages', 'servicePackage.deliverables.servicePackageStage'])),
            'Pre-condition: an untouched package must read as current.',
        );

        $package->forceFill(['per_meeting_online_fee_amount' => '3000.00'])->save();

        // The online rate is money the customer agreed to, so moving it has to
        // invalidate the snapshot exactly like moving the price or offline fee.
        $this->assertFalse(
            $agreementService->isPackageSnapshotCurrent($agreement->fresh(['servicePackage.stages', 'servicePackage.deliverables.servicePackageStage'])),
            'Raising the online fee must force a new agreement revision.',
        );
    }

    public function test_backfill_migration_leaves_stored_digests_matching_the_new_payload(): void
    {
        // Every existing row has a NULL online fee, so the fact did not change —
        // only the digest shape did. If the re-digest pass had not run, these
        // agreements would read as stale and refuse payment requests.
        [$suchakUser] = $this->verifiedSuchakActor();
        $package = $this->publishedPackage([
            'package_name' => 'Legacy package',
            'price_amount' => '9000',
            'currency' => 'INR',
        ], $suchakUser);

        $agreement = app(SuchakAgreementService::class)->createAgreementForPackage($package, $suchakUser);

        $this->assertNull($package->fresh()->per_meeting_online_fee_amount);
        $this->assertSame(SuchakCustomerAgreement::TERMS_PENDING, $agreement->terms_status);
        $this->assertTrue(
            app(SuchakAgreementService::class)->isPackageSnapshotCurrent(
                $agreement->fresh(['servicePackage.stages', 'servicePackage.deliverables.servicePackageStage'])
            ),
        );
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function publishedPackage(array $attributes, ?User $suchakUser = null): SuchakServicePackage
    {
        if ($suchakUser === null) {
            [$suchakUser] = $this->verifiedSuchakActor();
        }

        $account = $suchakUser->suchakAccount;

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE],
            [
                'policy_value' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Auto publish packages for the online-fee fixture.',
                'is_active' => true,
            ],
        );

        $package = app(SuchakPackageCatalogService::class)->createCustomPackage(
            $account,
            $suchakUser,
            $attributes,
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

        return $package->fresh(['suchakAccount.user', 'stages', 'deliverables.servicePackageStage']);
    }

    private function bootRepresentedCandidate(): SuchakProfileRepresentation
    {
        [$user, $account] = $this->verifiedSuchakActor();

        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Online Fee Candidate',
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

        return $rep;
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
            'registration_completed_at' => now(),
        ]);

        return [$user->fresh(), $account];
    }
}
