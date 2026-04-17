<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\UserActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminDashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_dashboard_metrics(): void
    {
        $this->get(route('admin.dashboard-metrics.overview'))->assertRedirect();
    }

    public function test_non_admin_cannot_access_dashboard_metrics(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'admin_role' => null]);
        $this->actingAs($user)->get(route('admin.dashboard-metrics.overview'))->assertForbidden();
    }

    public function test_admin_can_fetch_overview_json(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->getJson(route('admin.dashboard-metrics.overview'));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'current' => [
                        'total_users',
                        'active_users_today',
                        'new_registrations_today',
                        'paid_users_count',
                        'free_users_count',
                        'total_revenue',
                        'monthly_revenue',
                        'conversion_rate_percent',
                        'cache_ttl_seconds',
                        'generated_at',
                    ],
                    'previous',
                    'change',
                    'compare',
                ],
            ]);
    }

    public function test_admin_can_post_insight_feedback(): void
    {
        if (! Schema::hasTable('user_activities')) {
            $this->markTestSkipped('user_activities table not present');
        }

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->postJson(route('admin.dashboard-metrics.insights.feedback'), [
            'insight_key' => 'revenue_drop',
            'sentiment' => 'up',
            'insight_message' => 'Revenue dropped significantly',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('user_activities', [
            'user_id' => $admin->id,
            'type' => 'insight_feedback',
        ]);
    }

    public function test_insights_json_includes_insight_key(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->getJson(route('admin.dashboard-metrics.insights'));

        $response->assertOk();
        $insights = $response->json('data.insights');
        $this->assertIsArray($insights);
        foreach ($insights as $row) {
            $this->assertArrayHasKey('insight_key', $row);
        }
    }

    public function test_evaluate_action_effects_command_runs(): void
    {
        $this->artisan('admin:evaluate-action-effects')->assertSuccessful();
    }

    public function test_admin_action_effect_logged_after_window(): void
    {
        if (! Schema::hasTable('user_activities')) {
            $this->markTestSkipped('user_activities table not present');
        }

        $admin = User::factory()->create(['is_admin' => true]);
        $click = UserActivity::query()->create([
            'user_id' => $admin->id,
            'type' => 'admin_action_click',
            'meta' => [
                'url' => '/admin/plans',
                'label' => 'Edit plan pricing',
                'insight_key' => 'revenue_drop',
            ],
            'created_at' => now()->subDays(5),
        ]);

        $this->artisan('admin:evaluate-action-effects')->assertSuccessful();

        $this->assertDatabaseHas('user_activities', [
            'user_id' => $admin->id,
            'type' => 'admin_action_effect',
        ]);

        $effect = UserActivity::query()->where('type', 'admin_action_effect')->first();
        $this->assertNotNull($effect);
        $meta = $effect->meta;
        $this->assertIsArray($meta);
        $this->assertSame($click->id, $meta['related_click_id'] ?? null);
        $this->assertArrayHasKey('change_percent', $meta);
        $this->assertArrayHasKey('metric', $meta);
    }
}
