<?php

namespace Tests\Feature\Admin;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanFeatureRouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_feature_update_requires_authenticated_admin(): void
    {
        $plan = $this->plan();

        $this->putJson(route('admin.plans.features.update', $plan), [
            'features' => [
                'chat_can_read' => true,
            ],
        ])->assertUnauthorized();
    }

    public function test_plan_feature_update_blocks_non_admin_user(): void
    {
        $plan = $this->plan();
        $user = User::factory()->create([
            'is_admin' => false,
            'admin_role' => null,
        ]);

        $this->actingAs($user)
            ->putJson(route('admin.plans.features.update', $plan), [
                'features' => [
                    'chat_can_read' => true,
                ],
            ])
            ->assertForbidden();
    }

    public function test_plan_feature_update_blocks_admin_without_commerce_section(): void
    {
        $plan = $this->plan();
        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'data_admin',
        ]);

        $this->actingAs($admin)
            ->putJson(route('admin.plans.features.update', $plan), [
                'features' => [
                    'chat_can_read' => true,
                ],
            ])
            ->assertForbidden();
    }

    public function test_super_admin_can_update_plan_features(): void
    {
        $plan = $this->plan();
        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);

        $this->actingAs($admin)
            ->putJson(route('admin.plans.features.update', $plan), [
                'features' => [
                    'chat_can_read' => true,
                    'chat_send_limit' => 12,
                ],
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'plan_id' => $plan->id,
            ]);

        $this->assertDatabaseHas('plan_features', [
            'plan_id' => $plan->id,
            'key' => 'chat_can_read',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('plan_features', [
            'plan_id' => $plan->id,
            'key' => 'chat_send_limit',
            'value' => '12',
        ]);
    }

    private function plan(): Plan
    {
        return Plan::query()->create([
            'name' => 'Security Plan',
            'slug' => 'security-plan',
            'price' => 100,
            'duration_days' => 30,
            'grace_period_days' => 3,
            'is_active' => true,
        ]);
    }
}
