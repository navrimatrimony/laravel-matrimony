<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\User;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPlanUsageSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_plan_usage_section_for_member_with_profile(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $user = User::factory()->create(['is_admin' => false]);
        MatrimonyProfile::factory()->for($user)->create(['lifecycle_state' => 'active']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('dashboard.usage_section_title'), false)
            ->assertSee(__('dashboard.usage_row_contact_reveals'), false);
    }

    public function test_matrimony_page_includes_compact_usage_strip_for_member_with_profile(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $user = User::factory()->create(['is_admin' => false]);
        MatrimonyProfile::factory()->for($user)->create(['lifecycle_state' => 'active']);

        $this->actingAs($user)
            ->get(route('matrimony.profiles.index'))
            ->assertOk()
            ->assertSee(__('dashboard.usage_strip_title'), false)
            ->assertSee(__('dashboard.usage_short_profile_opens'), false);
    }

    public function test_onboarding_step_does_not_show_plan_usage_strip(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $user = User::factory()->create(['is_admin' => false]);
        MatrimonyProfile::factory()->for($user)->create(['lifecycle_state' => 'active']);

        $this->actingAs($user)
            ->get(route('matrimony.onboarding.show', ['step' => 2]))
            ->assertOk()
            ->assertDontSee(__('dashboard.usage_short_profile_opens'), false);
    }
}
