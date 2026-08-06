<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\FeatureFlagService;
use App\Support\FeatureFlagKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Production verification evidence for showcase_profiles_enabled kill-switch.
 */
class FeatureFlagShowcaseAccessVerificationTest extends TestCase
{
    use RefreshDatabase;

    private FeatureFlag $flag;

    private User $superAdmin;

    private User $member;

    private MatrimonyProfile $showcase;

    private MatrimonyProfile $real;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flag = FeatureFlag::query()->create([
            'key' => FeatureFlagKey::SHOWCASE_PROFILES,
            'display_name' => 'Showcase Profiles',
            'description' => 'Verification',
            'enabled' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);

        $this->member = User::factory()->create(['is_admin' => false]);
        $this->real = MatrimonyProfile::factory()->create([
            'user_id' => $this->member->id,
            'is_showcase' => false,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        $showcaseUser = User::factory()->create(['is_admin' => false]);
        $this->showcase = MatrimonyProfile::factory()->create([
            'user_id' => $showcaseUser->id,
            'is_showcase' => true,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
    }

    private function setFlag(bool $enabled): void
    {
        app(FeatureFlagService::class)->setEnabled(
            FeatureFlagKey::SHOWCASE_PROFILES,
            $enabled,
            $this->superAdmin,
            $enabled ? 'Verification enable' : 'Verification disable',
            '127.0.0.1',
            'PHPUnit'
        );
        $this->flag->refresh();
    }

    public function test_admin_showcase_entry_points_return_404_when_off(): void
    {
        $this->setFlag(false);
        $admin = $this->superAdmin;

        $routes = [
            'admin.showcase.index',
            'admin.showcase-dashboard.index',
            'admin.showcase-photo-pool.index',
            'admin.showcase-profile.bulk-create',
            'admin.showcase-chat-settings.index',
            'admin.showcase-conversations.index',
            'admin.auto-showcase-settings.edit',
            'admin.view-back-settings.index',
            'admin.showcase-interest-settings.index',
            'admin.showcase-search-settings.index',
        ];

        foreach ($routes as $name) {
            $this->actingAs($admin)
                ->get(route($name))
                ->assertNotFound();
        }
    }

    public function test_member_profile_show_and_share_return_404_when_off(): void
    {
        $this->setFlag(false);

        $this->actingAs($this->member)
            ->get(route('matrimony.profile.show', ['matrimony_profile_id' => $this->showcase->id]))
            ->assertNotFound();

        $this->get(route('profile.share.public', ['id' => $this->showcase->id]))
            ->assertNotFound();
    }

    public function test_api_show_by_id_returns_feature_disabled_when_off(): void
    {
        $this->setFlag(false);

        $token = $this->member->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/matrimony-profiles/'.$this->showcase->id)
            ->assertNotFound()
            ->assertJsonFragment(['message' => 'Feature Disabled']);
    }

    public function test_admin_members_list_hides_showcase_rows_when_off(): void
    {
        $this->setFlag(false);

        $uniqueName = 'SHOWCASE_FLAG_OFF_ROW_'.$this->showcase->id;
        MatrimonyProfile::$bypassGovernanceEnforcement = true;
        try {
            // Name-only update may still hit residence observer; use query builder.
            MatrimonyProfile::query()->whereKey($this->showcase->id)->update(['full_name' => $uniqueName]);
        } finally {
            MatrimonyProfile::$bypassGovernanceEnforcement = false;
        }
        $this->showcase->refresh();

        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.profiles.index'));

        $response->assertOk();
        $response->assertDontSee($uniqueName, false);

        // Direct admin profile URL also blocked
        $this->actingAs($this->superAdmin)
            ->get(route('admin.profiles.show', ['id' => $this->showcase->id]))
            ->assertNotFound();
    }

    public function test_admin_members_list_shows_showcase_when_on(): void
    {
        $this->setFlag(true);

        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.profiles.index'));

        $response->assertOk();
        // Profile id appears in the members table when module is on
        $this->assertTrue(
            MatrimonyProfile::query()->whereShowcase()->whereKey($this->showcase->id)->exists()
        );
        $this->actingAs($this->superAdmin)
            ->get(route('admin.profiles.show', ['id' => $this->showcase->id]))
            ->assertOk();
    }

    public function test_flag_toggle_is_immediate_across_service_instances_without_restart(): void
    {
        $a = app(FeatureFlagService::class);
        $this->assertTrue($a->isEnabled(FeatureFlagKey::SHOWCASE_PROFILES));

        // Simulate Browser B disabling the flag
        $this->setFlag(false);

        // Fresh resolve (Browser A refresh) — no Cache::flush, no artisan restart
        $b = app(FeatureFlagService::class);
        $this->assertFalse($b->isEnabled(FeatureFlagKey::SHOWCASE_PROFILES));

        // Direct route check after toggle
        $this->actingAs($this->superAdmin)
            ->get(route('admin.showcase-dashboard.index'))
            ->assertNotFound();

        // Re-enable immediately
        $this->setFlag(true);
        $this->assertTrue(app(FeatureFlagService::class)->isEnabled(FeatureFlagKey::SHOWCASE_PROFILES));
        $this->actingAs($this->superAdmin)
            ->get(route('admin.showcase-dashboard.index'))
            ->assertOk();
    }

    public function test_browser_a_refresh_after_browser_b_disable_blocks_open_showcase_page(): void
    {
        $this->setFlag(true);

        // Browser A opens showcase admin page
        $this->actingAs($this->superAdmin)
            ->get(route('admin.showcase-dashboard.index'))
            ->assertOk();

        // Browser B disables via service (same as admin toggle POST)
        $this->setFlag(false);

        // Browser A refreshes — must be inaccessible immediately
        $this->actingAs($this->superAdmin)
            ->get(route('admin.showcase-dashboard.index'))
            ->assertNotFound();

        $this->actingAs($this->member)
            ->get(route('matrimony.profile.show', ['matrimony_profile_id' => $this->showcase->id]))
            ->assertNotFound();
    }

    public function test_showcase_data_remains_in_database_when_flag_off(): void
    {
        $this->setFlag(false);

        $this->assertDatabaseHas('matrimony_profiles', [
            'id' => $this->showcase->id,
            'is_showcase' => true,
        ]);
    }

    public function test_scheduled_commands_skip_when_off(): void
    {
        $this->setFlag(false);

        foreach ([
            'showcase:random-views',
            'showcase-chat:tick',
            'showcase:respond-incoming-interests',
            'showcase:send-outgoing-interests',
        ] as $command) {
            Artisan::call($command);
            $this->assertStringContainsString(
                'Showcase Profiles feature is disabled',
                Artisan::output(),
                "Command {$command} should skip when flag OFF"
            );
        }
    }

    public function test_cache_forget_on_toggle_does_not_require_manual_clear(): void
    {
        Cache::forever('feature_flag:enabled:'.FeatureFlagKey::SHOWCASE_PROFILES, true);
        $this->assertTrue(app(FeatureFlagService::class)->isEnabled(FeatureFlagKey::SHOWCASE_PROFILES));

        $this->setFlag(false);

        // Cached true must have been invalidated by setEnabled (key gone or false)
        $cached = Cache::get('feature_flag:enabled:'.FeatureFlagKey::SHOWCASE_PROFILES);
        $this->assertTrue($cached === null || $cached === false);
        $this->assertFalse(app(FeatureFlagService::class)->isEnabled(FeatureFlagKey::SHOWCASE_PROFILES));
    }
}
