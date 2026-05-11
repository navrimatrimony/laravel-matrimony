<?php

namespace Tests\Feature\Admin;

use App\Models\AdminSetting;
use App\Models\User;
use App\Services\WhoViewed\WhoViewedTeaserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhoViewedTeaserSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_who_viewed_teaser_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.who-viewed-teaser-settings.index'))
            ->assertOk()
            ->assertSee('Who viewed — locked teaser cards', false);
    }

    public function test_admin_can_save_who_viewed_teaser_policy_json(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->from(route('admin.who-viewed-teaser-settings.index'))
            ->post(route('admin.who-viewed-teaser-settings.update'), [
                'location_granularity' => 'state_only',
                'show_age_mode' => 'off',
                'name_display' => 'first_only',
                'locked_teaser_rows' => 3,
                'teaser_avatar_style' => 'silhouette',
                'teaser_viewed_time' => 'bucket',
                'show_occupation' => '1',
                'show_education' => '1',
                'show_marital_status' => '1',
            ])
            ->assertRedirect(route('admin.who-viewed-teaser-settings.index'));

        $raw = (string) AdminSetting::getValue(WhoViewedTeaserPolicy::SETTING_KEY, '');
        $this->assertNotSame('', $raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertSame('state_only', $decoded['location_granularity']);
        $this->assertSame('off', $decoded['show_age_mode']);
        $this->assertSame('first_only', $decoded['name_display']);
        $this->assertSame(3, $decoded['locked_teaser_rows']);
        $this->assertSame('silhouette', $decoded['teaser_avatar_style']);
        $this->assertSame('bucket', $decoded['teaser_viewed_time']);
        $this->assertTrue($decoded['show_occupation']);
        $this->assertTrue($decoded['show_education']);
        $this->assertTrue($decoded['show_marital_status']);
    }
}
