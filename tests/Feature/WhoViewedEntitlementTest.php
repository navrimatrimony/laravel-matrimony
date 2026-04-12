<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WhoViewedEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_who_viewed_json_locked_when_no_valid_entitlement(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $user = User::factory()->create();
        MatrimonyProfile::factory()->for($user)->create(['lifecycle_state' => 'active']);

        $this->actingAs($user)
            ->getJson(route('who-viewed.index'))
            ->assertOk()
            ->assertJson([
                'locked' => true,
                'message' => __('who_viewed.locked_json_message'),
            ])
            ->assertJsonPath('teaser_unique_count', 0);
    }

    public function test_who_viewed_json_locked_includes_teaser_count_when_views_exist(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $owner = User::factory()->create();
        $viewerUser = User::factory()->create();
        $ownerProfile = MatrimonyProfile::factory()->for($owner)->create([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $viewerProfile = MatrimonyProfile::factory()->for($viewerUser)->create([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        ProfileView::query()->create([
            'viewer_profile_id' => $viewerProfile->id,
            'viewed_profile_id' => $ownerProfile->id,
        ]);

        $this->actingAs($owner)
            ->getJson(route('who-viewed.index'))
            ->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('teaser_unique_count', 1);
    }

    public function test_who_viewed_filters_by_entitlement_window_days(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $owner = User::factory()->create();
        $viewerUser = User::factory()->create();
        $ownerProfile = MatrimonyProfile::factory()->for($owner)->create([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $viewerProfile = MatrimonyProfile::factory()->for($viewerUser)->create([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        $gold = Plan::query()->where('slug', 'gold')->firstOrFail();
        $price = PlanPrice::query()
            ->where('plan_id', $gold->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->firstOrFail();
        app(SubscriptionService::class)->subscribe($owner, $gold, null, $price->id);

        $old = ProfileView::query()->create([
            'viewer_profile_id' => $viewerProfile->id,
            'viewed_profile_id' => $ownerProfile->id,
        ]);
        DB::table('profile_views')->where('id', $old->id)->update([
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ]);

        $recent = ProfileView::query()->create([
            'viewer_profile_id' => $viewerProfile->id,
            'viewed_profile_id' => $ownerProfile->id,
        ]);
        DB::table('profile_views')->where('id', $recent->id)->update([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->actingAs($owner)
            ->getJson(route('who-viewed.index'))
            ->assertOk()
            ->assertJsonPath('locked', false)
            ->assertJsonPath('unique_count', 1)
            ->assertJsonPath('window_days', 7);
    }

    public function test_free_plan_shows_five_viewers_and_overflow_json(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $owner = User::factory()->create();
        $ownerProfile = MatrimonyProfile::factory()->for($owner)->create([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        $free = Plan::query()->where('slug', 'free')->firstOrFail();
        app(SubscriptionService::class)->subscribe($owner, $free, null, null);

        $viewerProfiles = [];
        for ($i = 0; $i < 6; $i++) {
            $u = User::factory()->create();
            $viewerProfiles[] = MatrimonyProfile::factory()->for($u)->create([
                'lifecycle_state' => 'active',
                'is_suspended' => false,
            ]);
        }

        foreach ($viewerProfiles as $vp) {
            ProfileView::query()->create([
                'viewer_profile_id' => $vp->id,
                'viewed_profile_id' => $ownerProfile->id,
            ]);
        }

        $res = $this->actingAs($owner)
            ->getJson(route('who-viewed.index'))
            ->assertOk()
            ->assertJsonPath('locked', false)
            ->assertJsonPath('partial_mode', true)
            ->assertJsonPath('overflow_count', 1)
            ->assertJsonPath('preview_limit', 5);

        $recent = $res->json('recent');
        $this->assertIsArray($recent);
        $this->assertCount(5, $recent);
    }

    public function test_who_viewed_unlimited_window_includes_old_views(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $owner = User::factory()->create();
        $viewerUser = User::factory()->create();
        $ownerProfile = MatrimonyProfile::factory()->for($owner)->create([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $viewerProfile = MatrimonyProfile::factory()->for($viewerUser)->create([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        $platinum = Plan::query()->where('slug', 'platinum')->firstOrFail();
        $price = PlanPrice::query()
            ->where('plan_id', $platinum->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->firstOrFail();
        app(SubscriptionService::class)->subscribe($owner, $platinum, null, $price->id);

        $old = ProfileView::query()->create([
            'viewer_profile_id' => $viewerProfile->id,
            'viewed_profile_id' => $ownerProfile->id,
        ]);
        DB::table('profile_views')->where('id', $old->id)->update([
            'created_at' => now()->subDays(400),
            'updated_at' => now()->subDays(400),
        ]);

        ProfileView::query()->create([
            'viewer_profile_id' => $viewerProfile->id,
            'viewed_profile_id' => $ownerProfile->id,
        ]);

        $this->actingAs($owner)
            ->getJson(route('who-viewed.index'))
            ->assertOk()
            ->assertJsonPath('locked', false)
            ->assertJsonPath('unique_count', 1)
            ->assertJsonPath('window_days', null);
    }

    public function test_own_profile_show_hides_who_viewed_strip_when_no_eligible_views(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->for($user)->create([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        $this->actingAs($user)
            ->get(route('matrimony.profile.show', $profile->id))
            ->assertOk()
            ->assertDontSee(__('profile.feature_gate_who_viewed_title'), false)
            ->assertDontSee(__('profile.feature_gate_cta_who_viewed'), false);
    }

    public function test_own_profile_show_shows_who_viewed_strip_when_eligible_view_exists(): void
    {
        $owner = User::factory()->create();
        $viewerUser = User::factory()->create();
        $ownerProfile = MatrimonyProfile::factory()->for($owner)->create([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $viewerProfile = MatrimonyProfile::factory()->for($viewerUser)->create([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        ProfileView::query()->create([
            'viewer_profile_id' => $viewerProfile->id,
            'viewed_profile_id' => $ownerProfile->id,
        ]);

        $this->actingAs($owner)
            ->get(route('matrimony.profile.show', $ownerProfile->id))
            ->assertOk()
            ->assertSee(__('profile.feature_gate_who_viewed_title'), false);
    }
}
