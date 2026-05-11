<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\PlanTerm;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\SubscriptionService;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WhoViewedEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MinimalLocationSeeder::class);
        $this->seed(MasterLookupSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    /**
     * Observer requires canonical residence when {@code lifecycle_state} is not draft; create draft first,
     * attach {@code location_id}, then activate.
     *
     * @param  array<string, mixed>  $factoryAttributes
     */
    private function createActiveProfileWithResidence(User $user, array $factoryAttributes = []): MatrimonyProfile
    {
        $p = MatrimonyProfile::factory()->for($user)->create(array_merge([
            'lifecycle_state' => 'draft',
        ], $factoryAttributes));
        $tbl = $p->getTable();
        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;
        if (Schema::hasColumn($tbl, 'location_id')) {
            DB::table($tbl)->where('id', $p->id)->update(['location_id' => $leafId]);
            $p->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $p->id, $leafId, null, true, false);
        }
        $p->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $p->fresh();
    }

    public function test_who_viewed_json_locked_when_no_valid_entitlement(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $user = User::factory()->create();
        $this->createActiveProfileWithResidence($user);

        $this->actingAs($user)
            ->getJson(route('who-viewed.index'))
            ->assertOk()
            ->assertJson([
                'locked' => true,
                'message' => __('who_viewed.locked_json_message'),
            ])
            ->assertJsonPath('teaser_unique_count', 0)
            ->assertJsonPath('teaser_cards', [])
            ->assertJsonPath('rows', []);
    }

    public function test_who_viewed_json_locked_includes_teaser_count_when_views_exist(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $owner = User::factory()->create();
        $viewerUser = User::factory()->create();
        $ownerProfile = $this->createActiveProfileWithResidence($owner);
        $viewerProfile = $this->createActiveProfileWithResidence($viewerUser);

        ProfileView::query()->create([
            'viewer_profile_id' => $viewerProfile->id,
            'viewed_profile_id' => $ownerProfile->id,
        ]);

        $res = $this->actingAs($owner)
            ->getJson(route('who-viewed.index'))
            ->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('teaser_unique_count', 1);

        $cards = $res->json('teaser_cards');
        $this->assertIsArray($cards);
        $this->assertCount(1, $cards);
        $this->assertArrayHasKey('headline', $cards[0]);
        $this->assertArrayHasKey('lines', $cards[0]);
        $this->assertArrayHasKey('viewed_summary', $cards[0]);
        $this->assertArrayHasKey('photo_url', $cards[0]);
        $this->assertArrayHasKey('avatar_style', $cards[0]);
        $this->assertIsArray($cards[0]['lines']);
        $this->assertArrayNotHasKey('viewed_at', $cards[0]);
        $this->assertArrayNotHasKey('viewer_profile_id', $cards[0]);

        $rows = $res->json('rows');
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('teaser', $rows[0]['mode']);
        $this->assertIsArray($rows[0]['teaser']);
    }

    public function test_paid_silver_with_zero_preview_limit_is_locked_even_when_views_exist(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $owner = User::factory()->create();
        $viewerUserA = User::factory()->create();
        $viewerUserB = User::factory()->create();
        $ownerProfile = $this->createActiveProfileWithResidence($owner);
        $viewerProfileA = $this->createActiveProfileWithResidence($viewerUserA);
        $viewerProfileB = $this->createActiveProfileWithResidence($viewerUserB);

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
            ->assertJsonPath('locked', true)
            ->assertJsonPath('teaser_unique_count', 2);
    }

    public function test_free_plan_shows_five_viewers_and_overflow_json(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $owner = User::factory()->create();
        $ownerProfile = $this->createActiveProfileWithResidence($owner);

        $free = Plan::query()->where('slug', 'free_male')->firstOrFail();
        app(SubscriptionService::class)->subscribe($owner, $free, null, null);

        $viewerProfiles = [];
        for ($i = 0; $i < 6; $i++) {
            $u = User::factory()->create();
            $viewerProfiles[] = $this->createActiveProfileWithResidence($u);
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

        $rows = $res->json('rows');
        $this->assertIsArray($rows);
        $this->assertCount(6, $rows);
        $this->assertSame('full', $rows[0]['mode']);
        $this->assertSame('full', $rows[4]['mode']);
        $this->assertSame('teaser', $rows[5]['mode']);
        $this->assertArrayHasKey('teaser', $rows[5]);
        $this->assertArrayHasKey('headline', $rows[5]['teaser']);
    }

    public function test_gold_paid_includes_very_old_views_with_null_window_days(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $owner = User::factory()->create();
        $viewerUser = User::factory()->create();
        $ownerProfile = $this->createActiveProfileWithResidence($owner);
        $viewerProfile = $this->createActiveProfileWithResidence($viewerUser);

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

        $profile = $this->createActiveProfileWithResidence($user);

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
        $ownerProfile = $this->createActiveProfileWithResidence($owner);
        $viewerProfile = $this->createActiveProfileWithResidence($viewerUser);

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
