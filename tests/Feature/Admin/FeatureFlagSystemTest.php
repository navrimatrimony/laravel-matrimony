<?php

namespace Tests\Feature\Admin;

use App\Models\FeatureFlag;
use App\Models\FeatureFlagAudit;
use App\Models\User;
use App\Services\FeatureFlagService;
use App\Support\FeatureFlagKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureFlagSystemTest extends TestCase
{
    use RefreshDatabase;

    private function seedShowcaseFlag(bool $enabled = true): FeatureFlag
    {
        return FeatureFlag::query()->create([
            'key' => FeatureFlagKey::SHOWCASE_PROFILES,
            'display_name' => 'Showcase Profiles',
            'description' => 'Test flag',
            'enabled' => $enabled,
        ]);
    }

    private function makeSuperAdmin(): User
    {
        return User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);
    }

    public function test_feature_flag_service_is_single_source_of_truth(): void
    {
        $this->seedShowcaseFlag(true);
        $service = app(FeatureFlagService::class);

        $this->assertTrue($service->isEnabled(FeatureFlagKey::SHOWCASE_PROFILES));

        FeatureFlag::query()
            ->where('key', FeatureFlagKey::SHOWCASE_PROFILES)
            ->update(['enabled' => false]);
        $service->clearCache(FeatureFlagKey::SHOWCASE_PROFILES);

        $this->assertFalse($service->isEnabled(FeatureFlagKey::SHOWCASE_PROFILES));
    }

    public function test_toggle_writes_audit_and_clears_cache_immediately(): void
    {
        $flag = $this->seedShowcaseFlag(true);
        $admin = $this->makeSuperAdmin();
        $service = app(FeatureFlagService::class);

        $this->assertTrue($service->isEnabled(FeatureFlagKey::SHOWCASE_PROFILES));

        $service->setEnabled(
            FeatureFlagKey::SHOWCASE_PROFILES,
            false,
            $admin,
            'Play Store Review',
            '127.0.0.1',
            'PHPUnit'
        );

        $this->assertFalse($service->isEnabled(FeatureFlagKey::SHOWCASE_PROFILES));

        $audit = FeatureFlagAudit::query()->first();
        $this->assertNotNull($audit);
        $this->assertTrue($audit->old_value);
        $this->assertFalse($audit->new_value);
        $this->assertSame($admin->id, $audit->changed_by);
        $this->assertSame('Play Store Review', $audit->reason);
        $this->assertSame($flag->id, $audit->feature_flag_id);
    }

    public function test_super_admin_can_toggle_via_admin_panel(): void
    {
        $flag = $this->seedShowcaseFlag(true);
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)
            ->post(route('admin.feature-flags.update', $flag), [
                'enabled' => '0',
                'reason' => 'Temporary Maintenance',
            ])
            ->assertRedirect(route('admin.feature-flags.index'));

        $this->assertFalse((bool) $flag->fresh()->enabled);
        $this->assertDatabaseHas('feature_flag_audits', [
            'key' => FeatureFlagKey::SHOWCASE_PROFILES,
            'reason' => 'Temporary Maintenance',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_non_super_admin_cannot_toggle(): void
    {
        $flag = $this->seedShowcaseFlag(true);
        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'data_admin',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.feature-flags.update', $flag), [
                'enabled' => '0',
            ])
            ->assertForbidden();

        $this->assertTrue((bool) $flag->fresh()->enabled);
    }

    public function test_showcase_admin_route_returns_404_when_flag_off(): void
    {
        $this->seedShowcaseFlag(false);
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)
            ->get(route('admin.showcase-dashboard.index'))
            ->assertNotFound();
    }

    public function test_showcase_admin_route_ok_when_flag_on(): void
    {
        $this->seedShowcaseFlag(true);
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)
            ->get(route('admin.showcase-dashboard.index'))
            ->assertOk();
    }

    public function test_showcase_command_exits_immediately_when_flag_off(): void
    {
        $this->seedShowcaseFlag(false);

        $this->artisan('showcase:random-views')
            ->expectsOutput('Showcase Profiles feature is disabled; skipping.')
            ->assertSuccessful();
    }

    public function test_seeder_does_not_overwrite_existing_row(): void
    {
        $this->seedShowcaseFlag(false);

        $this->seed(\Database\Seeders\FeatureFlagSeeder::class);

        $this->assertFalse((bool) FeatureFlag::query()
            ->where('key', FeatureFlagKey::SHOWCASE_PROFILES)
            ->value('enabled'));
    }

    public function test_absent_row_falls_back_to_environment_default(): void
    {
        $service = app(FeatureFlagService::class);
        // phpunit APP_ENV=testing → non-production → true
        $this->assertTrue($service->isEnabled(FeatureFlagKey::SHOWCASE_PROFILES));
        $this->assertTrue($service->environmentDefault());
    }
}
