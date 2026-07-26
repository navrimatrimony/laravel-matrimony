<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCustomerPlanService;
use App\Modules\Suchak\Support\SuchakDefaultPlans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A Suchak fixes the duration and the two disclosed fees ONCE while creating a
 * reusable plan. The payment-request options endpoint must carry those saved
 * values in `default_plans`, because that payload is the ONLY thing the app's
 * send screen seeds from — without them it fell back to hardcoded defaults
 * (1 year / both fees unchecked / ₹999 / "as wished") and the Suchak had to
 * retype everything on every single request.
 *
 * These fees are DISCLOSED NOTES (PRODUCT_MAP §3a): they are rendered so the
 * family knows up front and are never summed into amount_due.
 */
class SuchakPaymentRequestOptionsPlanTermsTest extends TestCase
{
    use RefreshDatabase;

    public function test_carousel_payload_carries_the_plans_saved_duration_and_fees(): void
    {
        $rep = $this->bootActor();
        $account = $rep->suchakAccount;

        $plan = app(SuchakCustomerPlanService::class)->create($account, [
            'name' => 'Deluxe matchmaking',
            'name_mr' => 'डिलक्स जुळवणी',
            'price_amount' => '7500',
            'currency' => 'INR',
            'duration' => SuchakCustomerPlan::DURATION_TILL_MARRIAGE,
            'services' => [['name' => 'Dedicated counselor']],
            'per_meeting_fee_amount' => '1500',
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
            'post_marriage_fee_amount' => '11000',
            'private_note' => 'never leaves the Suchak',
        ]);

        $plans = $this->optionsPlans($rep->id);
        $custom = collect($plans)->firstWhere('plan_key', 'custom_'.$plan->id);

        $this->assertNotNull($custom, 'the custom plan must appear in default_plans');

        // The four keys the send screen seeds from — the whole point of the fix.
        $this->assertSame(SuchakCustomerPlan::DURATION_TILL_MARRIAGE, $custom['duration']);
        $this->assertSame('1500.00', $custom['per_meeting_fee_amount']);
        $this->assertSame(SuchakCustomerPlan::MODE_FIXED, $custom['post_marriage_fee_mode']);
        $this->assertSame('11000.00', $custom['post_marriage_fee_amount']);

        // Strictly additive: nothing the shipped app already reads changed.
        $this->assertSame('7500.00', $custom['price_amount']);
        $this->assertSame('INR', $custom['currency']);
        $this->assertSame([['name' => 'Dedicated counselor', 'description' => null]], $custom['deliverables']);

        // private_note is Suchak-only and must never reach a customer payload.
        $this->assertArrayNotHasKey('private_note', $custom);
    }

    public function test_as_wished_post_marriage_mode_survives_to_the_payload(): void
    {
        $rep = $this->bootActor();

        $row = app(SuchakCustomerPlanService::class)->create($rep->suchakAccount, [
            'name' => 'Goodwill plan',
            'price_amount' => '3000',
            'duration' => SuchakCustomerPlan::DURATION_SIX_MONTHS,
            'services' => [['name' => 'Shortlist']],
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_AS_WISHED,
        ]);

        $plan = collect($this->optionsPlans($rep->id))
            ->firstWhere('plan_key', 'custom_'.$row->id);
        $this->assertNotNull($plan);

        $this->assertSame(SuchakCustomerPlan::DURATION_SIX_MONTHS, $plan['duration']);
        $this->assertSame(SuchakCustomerPlan::MODE_AS_WISHED, $plan['post_marriage_fee_mode']);
        // No fixed amount and no meeting fee were set — they stay null, which is
        // what tells the app "this plan did not opt that fee in".
        $this->assertNull($plan['post_marriage_fee_amount']);
        $this->assertNull($plan['per_meeting_fee_amount']);
    }

    public function test_code_presets_expose_the_keys_as_null_so_the_fee_rows_stay_opt_out(): void
    {
        $rep = $this->bootActor();

        $basic = collect($this->optionsPlans($rep->id))
            ->firstWhere('plan_key', SuchakDefaultPlans::KEY_BASIC);

        $this->assertNotNull($basic);
        // Presets fix no duration and no fees, so the keys are present (the app
        // can read them unconditionally) but null — the fee blocks stay opt-in
        // and unchecked exactly as before.
        $this->assertArrayHasKey('duration', $basic);
        $this->assertNull($basic['duration']);
        $this->assertNull($basic['per_meeting_fee_amount']);
        $this->assertNull($basic['post_marriage_fee_mode']);
        $this->assertNull($basic['post_marriage_fee_amount']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function optionsPlans(int $representationId): array
    {
        $response = $this->getJson("/api/v1/suchak/customers/{$representationId}/payment-request-options");
        $response->assertOk();

        return $response->json('data.default_plans');
    }

    private function bootActor(): SuchakProfileRepresentation
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
            'full_name' => 'Plan Terms Candidate',
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
}
