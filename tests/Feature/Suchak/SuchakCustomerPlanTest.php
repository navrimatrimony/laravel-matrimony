<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakCustomerPlan;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCustomerPlanService;
use App\Modules\Suchak\Support\SuchakDefaultPlans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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
