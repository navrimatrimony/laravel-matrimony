<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\PlanTerm;
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

    public function test_paid_silver_counts_distinct_viewers_across_months_with_null_window_days(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $owner = User::factory()->create();
        $viewerUserA = User::factory()->create();
        $viewerUserB = User::factory()->create();
        $ownerProfile = MatrimonyProfile::factory()->for($owner)->create([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $viewerProfileA = MatrimonyProfile::factory()->for($viewerUserA)->create([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);
        $viewerProfileB = MatrimonyProfile::factory()->for($viewerUserB)->create([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        $silver = Plan::query()->where('slug', 'silver_male')->firstOrFail();
        $term = PlanTerm::query()
            ->where('plan_id', $silver->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->firstOrFail();
        app(SubscriptionService::class)->subscribe($owner, $silver, (int) $term->id, null);

        $old = ProfileView::query()->create([
            'viewer_profile_id' => $viewerProfileA->id,
            'viewed_profile_id' => $ownerProfile->id,
        ]);
        DB::table('profile_views')->where('id', $old->id)->update([
            'created_at' => now()->subDays(50),
            'updated_at' => now()->subDays(50),
        ]);

        $recent = ProfileView::query()->create([
            'viewer_profile_id' => $viewerProfileB->id,
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
            ->assertJsonPath('unique_count', 2)
            ->assertJsonPath('window_days', null);
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

        $free = Plan::query()->where('slug', 'free_male')->firstOrFail();
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

    public function test_gold_paid_includes_very_old_views_with_null_window_days(): void
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

        $gold = Plan::query()->where('slug', 'gold_male')->firstOrFail();
        $term = PlanTerm::query()
            ->where('plan_id', $gold->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->firstOrFail();
        app(SubscriptionService::class)->subscribe($owner, $gold, (int) $term->id, null);

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
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $user = User::factory()->create();
        $free = Plan::query()->where('slug', 'free_male')->firstOrFail();
        app(SubscriptionService::class)->subscribe($user, $free, null, null);

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
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $owner = User::factory()->create();
        $free = Plan::query()->where('slug', 'free_male')->firstOrFail();
        app(SubscriptionService::class)->subscribe($owner, $free, null, null);

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
            ->assertSee(__('nav.who_viewed_me'), false);
    }
}
