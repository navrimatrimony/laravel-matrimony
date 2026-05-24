<?php

namespace Tests\Feature\Admin;

use App\Models\AdminSetting;
use App\Models\User;
use App\Services\Chat\ChatTeaserPolicy;
use App\Services\Interest\ReceivedInterestTeaserPolicy;
use App\Services\WhoViewed\WhoViewedTeaserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhoViewedTeaserSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_who_viewed_settings_url_redirects(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.who-viewed-teaser-settings.index'))
            ->assertRedirect(route('admin.teaser-settings.index', ['tab' => 'who-viewed']));
    }

    public function test_admin_can_open_teaser_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.teaser-settings.index', ['tab' => 'who-viewed']))
            ->assertOk()
            ->assertSee(__('admin.teaser_settings_title'), false);
    }

    public function test_admin_can_save_who_viewed_teaser_policy_json(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->from(route('admin.teaser-settings.index', ['tab' => 'who-viewed']))
            ->post(route('admin.teaser-settings.who-viewed.update'), [
                'location_granularity' => 'state_only',
                'show_age_mode' => 'off',
                'name_display' => 'first_only',
                'locked_teaser_rows' => 3,
                'teaser_avatar_style' => 'silhouette',
                'teaser_blur_strength' => 'strong',
                'teaser_viewed_time' => 'bucket',
                'masked_name_dots' => 6,
                'show_repeat_view_teaser' => '1',
                'show_match_teaser' => '0',
                'match_teaser_min_score' => 80,
                'show_occupation' => '1',
                'show_education' => '1',
                'show_marital_status' => '1',
                'apply_who_viewed_locked' => '0',
                'partial_plan_list_order' => 'recent_activity_first',
                'who_viewed_per_page' => 12,
            ])
            ->assertRedirect(route('admin.teaser-settings.index', ['tab' => 'who-viewed']));

        $raw = (string) AdminSetting::getValue(WhoViewedTeaserPolicy::SETTING_KEY, '');
        $this->assertNotSame('', $raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertSame('state_only', $decoded['location_granularity']);
        $this->assertSame('off', $decoded['show_age_mode']);
        $this->assertSame('first_only', $decoded['name_display']);
        $this->assertSame(3, $decoded['locked_teaser_rows']);
        $this->assertSame('silhouette', $decoded['teaser_avatar_style']);
        $this->assertSame('strong', $decoded['teaser_blur_strength']);
        $this->assertSame('bucket', $decoded['teaser_viewed_time']);
        $this->assertSame(6, $decoded['masked_name_dots']);
        $this->assertTrue($decoded['show_repeat_view_teaser']);
        $this->assertFalse($decoded['show_match_teaser']);
        $this->assertSame(80, $decoded['match_teaser_min_score']);
        $this->assertTrue($decoded['show_occupation']);
        $this->assertTrue($decoded['show_education']);
        $this->assertTrue($decoded['show_marital_status']);
        $this->assertFalse($decoded['apply_who_viewed_locked']);
        $this->assertSame('recent_activity_first', $decoded['partial_plan_list_order']);
        $this->assertSame(12, $decoded['who_viewed_per_page']);
    }

    public function test_admin_can_save_received_interest_teaser_policy_json(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->from(route('admin.teaser-settings.index', ['tab' => 'received-interests']))
            ->post(route('admin.teaser-settings.received-interests.update'), [
                'rich_teaser_enabled' => '1',
                'location_granularity' => 'taluka_and_above',
                'show_age_mode' => 'decade',
                'name_display' => 'courtesy_from_place',
                'teaser_avatar_style' => 'blur',
                'teaser_blur_strength' => 'gentle',
                'teaser_viewed_time' => 'human',
                'masked_name_dots' => 4,
                'show_match_teaser' => '1',
                'match_teaser_min_score' => 82,
                'show_occupation' => '0',
                'show_education' => '1',
                'show_marital_status' => '0',
                'card_layout' => 'two_column',
                'received_inbox_row_order' => 'newest_first',
                'received_inbox_per_page' => 10,
            ])
            ->assertRedirect(route('admin.teaser-settings.index', ['tab' => 'received-interests']));

        $raw = (string) AdminSetting::getValue(ReceivedInterestTeaserPolicy::SETTING_KEY, '');
        $this->assertNotSame('', $raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['rich_teaser_enabled']);
        $this->assertSame('taluka_and_above', $decoded['location_granularity']);
        $this->assertSame('decade', $decoded['show_age_mode']);
        $this->assertSame('courtesy_from_place', $decoded['name_display']);
        $this->assertSame('blur', $decoded['teaser_avatar_style']);
        $this->assertSame('gentle', $decoded['teaser_blur_strength']);
        $this->assertSame('human', $decoded['teaser_viewed_time']);
        $this->assertSame(4, $decoded['masked_name_dots']);
        $this->assertTrue($decoded['show_match_teaser']);
        $this->assertSame(82, $decoded['match_teaser_min_score']);
        $this->assertFalse($decoded['show_occupation']);
        $this->assertTrue($decoded['show_education']);
        $this->assertFalse($decoded['show_marital_status']);
        $this->assertFalse($decoded['show_repeat_view_teaser']);
        $this->assertSame('two_column', $decoded['card_layout']);
        $this->assertSame('newest_first', $decoded['received_inbox_row_order']);
        $this->assertSame(10, $decoded['received_inbox_per_page']);
    }

    public function test_admin_can_save_received_interest_photo_overlay_layout(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->from(route('admin.teaser-settings.index', ['tab' => 'received-interests']))
            ->post(route('admin.teaser-settings.received-interests.update'), [
                'rich_teaser_enabled' => '1',
                'location_granularity' => 'district_and_above',
                'show_age_mode' => 'exact',
                'name_display' => 'masked',
                'teaser_avatar_style' => 'blur',
                'teaser_blur_strength' => 'medium',
                'teaser_viewed_time' => 'human',
                'masked_name_dots' => 5,
                'show_match_teaser' => '0',
                'match_teaser_min_score' => 75,
                'show_occupation' => '0',
                'show_education' => '0',
                'show_marital_status' => '0',
                'card_layout' => 'photo_overlay',
                'received_inbox_row_order' => 'unlocked_first_recent',
                'received_inbox_per_page' => 15,
            ])
            ->assertRedirect(route('admin.teaser-settings.index', ['tab' => 'received-interests']));

        $raw = (string) AdminSetting::getValue(ReceivedInterestTeaserPolicy::SETTING_KEY, '');
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertSame('photo_overlay', $decoded['card_layout']);
    }

    public function test_admin_can_open_chat_teaser_tab(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.teaser-settings.index', ['tab' => 'chat']))
            ->assertOk()
            ->assertSee(__('admin.teaser_tab_chat'), false)
            ->assertSee('chat_teaser_policy_json', false);
    }

    public function test_admin_can_save_chat_teaser_policy_json(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->from(route('admin.teaser-settings.index', ['tab' => 'chat']))
            ->post(route('admin.teaser-settings.chat.update'), [
                'locked_message_teaser_enabled' => '1',
                'locked_message_style' => 'soft_context',
                'show_sender_hint' => '1',
                'mask_sender_name' => '0',
                'preview_line_mode' => 'relationship_safe',
                'locked_message_time' => 'bucket',
                'show_unread_count' => '0',
                'teaser_blur_strength' => 'strong',
                'locked_chat_cta' => 'open_plans',
                'max_locked_threads' => 25,
            ])
            ->assertRedirect(route('admin.teaser-settings.index', ['tab' => 'chat']));

        $raw = (string) AdminSetting::getValue(ChatTeaserPolicy::SETTING_KEY, '');
        $this->assertNotSame('', $raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['locked_message_teaser_enabled']);
        $this->assertSame('soft_context', $decoded['locked_message_style']);
        $this->assertTrue($decoded['show_sender_hint']);
        $this->assertFalse($decoded['mask_sender_name']);
        $this->assertSame('relationship_safe', $decoded['preview_line_mode']);
        $this->assertSame('bucket', $decoded['locked_message_time']);
        $this->assertFalse($decoded['show_unread_count']);
        $this->assertSame('strong', $decoded['teaser_blur_strength']);
        $this->assertSame('open_plans', $decoded['locked_chat_cta']);
        $this->assertSame(25, $decoded['max_locked_threads']);
    }
}
