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
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuchakCustomerPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_plan_lifecycle_list_hide_and_preset_price_override(): void
    {
        $account = $this->suchakAccount();
        $service = app(SuchakCustomerPlanService::class);

        // create a custom plan
        $custom = $service->create($account, [
            'name' => 'Deluxe matchmaking',
            'name_mr' => 'डिलक्स जुळवणी',
            'price_amount' => '7500',
            'currency' => 'INR',
            'duration' => SuchakCustomerPlan::DURATION_ONE_YEAR,
            'services' => [
                ['name' => 'Dedicated counselor', 'name_mr' => 'समर्पित सल्लागार'],
                ['name' => 'Unlimited meetings'],
            ],
            'per_meeting_fee_amount' => '200',
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_AS_WISHED,
            'private_note' => 'push premium upsell',
        ]);

        $this->assertNull($custom->preset_key);
        $this->assertSame('Deluxe matchmaking', $custom->name);
        $this->assertCount(2, $custom->services_json);
        $this->assertSame('7500.00', $custom->price_amount);

        // management list = 2 presets + 1 custom, with private_note visible
        $management = $service->resolveForManagement($account);
        $this->assertCount(3, $management);
        $customEntry = collect($management)->firstWhere('id', $custom->id);
        $this->assertSame('push premium upsell', $customEntry['private_note']);

        // carousel = 2 presets + 1 visible custom, NEVER exposes private_note
        $carousel = $service->resolveCarousel($account);
        $this->assertCount(3, $carousel);
        foreach ($carousel as $entry) {
            $this->assertArrayNotHasKey('private_note', $entry);
        }
        // default order: basic, premium, then the custom
        $this->assertSame(SuchakDefaultPlans::KEY_BASIC, $carousel[0]['preset_key']);
        $this->assertSame(SuchakDefaultPlans::KEY_PREMIUM, $carousel[1]['preset_key']);
        $this->assertFalse($carousel[2]['is_preset']);
        $this->assertSame('Deluxe matchmaking', $carousel[2]['name']);

        // override a preset's price (basic 2000 -> 2500)
        $service->upsertPresetOverride($account, SuchakDefaultPlans::KEY_BASIC, [
            'price_amount' => '2500',
        ]);
        $carousel = $service->resolveCarousel($account);
        $basic = collect($carousel)->firstWhere('preset_key', SuchakDefaultPlans::KEY_BASIC);
        $this->assertSame('2500.00', $basic['price_amount']);
        // premium untouched: still its code-defined price
        $premium = collect($carousel)->firstWhere('preset_key', SuchakDefaultPlans::KEY_PREMIUM);
        $this->assertSame('5000.00', $premium['price_amount']);

        // The management list must reflect the SAME override — the reported bug
        // was an edited preset price "not showing", so lock BOTH resolve paths in.
        $management = $service->resolveForManagement($account);
        $basicManage = collect($management)->firstWhere('preset_key', SuchakDefaultPlans::KEY_BASIC);
        $this->assertSame('2500.00', $basicManage['price_amount']);
        $premiumManage = collect($management)->firstWhere('preset_key', SuchakDefaultPlans::KEY_PREMIUM);
        $this->assertSame('5000.00', $premiumManage['price_amount']);

        // hide the custom plan -> drops from carousel, stays in management
        $service->toggleVisibility($custom, false);
        $carousel = $service->resolveCarousel($account);
        $this->assertCount(2, $carousel);
        $this->assertNull(collect($carousel)->firstWhere('id', $custom->id));
        $this->assertCount(3, $service->resolveForManagement($account));
    }

    public function test_reorder_assigns_sort_order_and_reflows_the_carousel(): void
    {
        $account = $this->suchakAccount();
        $service = app(SuchakCustomerPlanService::class);

        $planA = $service->create($account, $this->customInput('Plan A'));
        $planB = $service->create($account, $this->customInput('Plan B'));

        // Presets need override rows (hence ids) to participate in a reorder.
        $basic = $service->upsertPresetOverride($account, SuchakDefaultPlans::KEY_BASIC, []);
        $premium = $service->upsertPresetOverride($account, SuchakDefaultPlans::KEY_PREMIUM, []);

        $service->reorder($account, [$planB->id, $planA->id, $premium->id, $basic->id]);

        $carousel = $service->resolveCarousel($account);
        $labels = array_map(
            static fn (array $entry): string => $entry['is_preset'] ? (string) $entry['preset_key'] : (string) $entry['name'],
            $carousel,
        );

        $this->assertSame(['Plan B', 'Plan A', 'premium', 'basic'], $labels);
    }

    public function test_last_visible_plan_cannot_be_hidden_or_deleted(): void
    {
        $account = $this->suchakAccount();
        $service = app(SuchakCustomerPlanService::class);

        $only = $service->create($account, $this->customInput('Only Plan'));
        $service->upsertPresetOverride($account, SuchakDefaultPlans::KEY_BASIC, ['is_visible' => false]);
        $service->upsertPresetOverride($account, SuchakDefaultPlans::KEY_PREMIUM, ['is_visible' => false]);

        $this->assertCount(1, $service->resolveCarousel($account));

        try {
            $service->toggleVisibility($only, false);
            $this->fail('Hiding the last visible plan should be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsStringIgnoringCase('at least one plan', $exception->getMessage());
        }

        try {
            $service->delete($only);
            $this->fail('Deleting the last visible plan should be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsStringIgnoringCase('at least one plan', $exception->getMessage());
        }

        $this->assertDatabaseHas('suchak_customer_plans', ['id' => $only->id]);
        $this->assertTrue($only->fresh()->is_visible);
    }

    public function test_preset_override_rows_cannot_be_deleted(): void
    {
        $account = $this->suchakAccount();
        $service = app(SuchakCustomerPlanService::class);

        // Keep a visible custom around so the guard is not what blocks the delete.
        $service->create($account, $this->customInput('Keeper'));
        $override = $service->upsertPresetOverride($account, SuchakDefaultPlans::KEY_BASIC, ['price_amount' => '2500']);

        try {
            $service->delete($override);
            $this->fail('Preset override rows cannot be deleted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
        }
    }

    public function test_payment_request_options_carousel_merges_presets_and_customs(): void
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        $service = app(SuchakCustomerPlanService::class);
        $custom = $service->create($account, [
            'name' => 'Deluxe matchmaking',
            'name_mr' => 'डिलक्स जुळवणी',
            'price_amount' => '7500',
            'duration' => SuchakCustomerPlan::DURATION_ONE_YEAR,
            'services' => [['name' => 'Dedicated counselor']],
            'private_note' => 'internal only',
        ]);

        $profile = MatrimonyProfile::factory()->create();
        $rep = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/suchak/customers/{$rep->id}/payment-request-options");
        $response->assertOk();

        $plans = $response->json('data.default_plans');
        $keys = array_column($plans, 'plan_key');

        // Both code presets AND the custom plan are present, custom keyed by id.
        $this->assertContains(SuchakDefaultPlans::KEY_BASIC, $keys);
        $this->assertContains(SuchakDefaultPlans::KEY_PREMIUM, $keys);
        $this->assertContains('custom_'.$custom->id, $keys);

        $customPayload = collect($plans)->firstWhere('plan_key', 'custom_'.$custom->id);
        $this->assertNotNull($customPayload);
        $this->assertSame('Deluxe matchmaking', $customPayload['name']);
        // The carousel never leaks the Suchak-only private note.
        $this->assertArrayNotHasKey('private_note', $customPayload);
        // services_json is mapped into deliverables the app already consumes.
        $this->assertContains('Dedicated counselor', array_column($customPayload['deliverables'], 'name'));

        // Every mapped item keeps the shape the app consumes today.
        foreach ($plans as $plan) {
            $this->assertArrayHasKey('plan_key', $plan);
            $this->assertArrayHasKey('name', $plan);
            $this->assertArrayHasKey('price_amount', $plan);
            $this->assertArrayHasKey('currency', $plan);
            $this->assertArrayHasKey('deliverables', $plan);
        }

        // Hiding the custom plan drops it from the carousel payload.
        $service->toggleVisibility($custom, false);
        $hiddenResponse = $this->getJson("/api/v1/suchak/customers/{$rep->id}/payment-request-options");
        $hiddenResponse->assertOk();
        $hiddenKeys = array_column($hiddenResponse->json('data.default_plans'), 'plan_key');
        $this->assertNotContains('custom_'.$custom->id, $hiddenKeys);
        $this->assertContains(SuchakDefaultPlans::KEY_BASIC, $hiddenKeys);
    }

    public function test_editing_a_preset_price_over_http_reflects_in_management_and_carousel(): void
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        // The app sends the PRESET KEY (not a numeric id) plus the new price —
        // exactly what PlanEditorScreen._buildBody() posts for a preset.
        $response = $this->putJson(
            '/api/v1/suchak/customer-plans/'.SuchakDefaultPlans::KEY_BASIC,
            ['name' => 'Basic matchmaking', 'price_amount' => '1500'],
        );
        $response->assertOk();

        // Both lists in the returned snapshot must show Basic at the new ₹1500:
        // the management list (resolveForManagement) AND the carousel
        // (resolveCarousel, which the payment options endpoint reuses).
        $planPrice = collect($response->json('data.plans'))
            ->firstWhere('preset_key', SuchakDefaultPlans::KEY_BASIC)['price_amount'];
        $carouselPrice = collect($response->json('data.carousel'))
            ->firstWhere('preset_key', SuchakDefaultPlans::KEY_BASIC)['price_amount'];
        $this->assertSame('1500.00', $planPrice);
        $this->assertSame('1500.00', $carouselPrice);

        // A fresh GET (what the management screen re-fetches on return) still shows it.
        $reload = $this->getJson('/api/v1/suchak/customer-plans');
        $reload->assertOk();
        $reloaded = collect($reload->json('data.plans'))
            ->firstWhere('preset_key', SuchakDefaultPlans::KEY_BASIC)['price_amount'];
        $this->assertSame('1500.00', $reloaded);
    }

    /**
     * @return array<string, mixed>
     */
    private function customInput(string $name): array
    {
        return [
            'name' => $name,
            'price_amount' => '5000',
            'duration' => SuchakCustomerPlan::DURATION_SIX_MONTHS,
            'services' => [['name' => 'Service X']],
        ];
    }

    private function suchakAccount(): SuchakAccount
    {
        $user = User::factory()->create();

        return SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
    }
}
