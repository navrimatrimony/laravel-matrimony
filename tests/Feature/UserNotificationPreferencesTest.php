<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\User;
use App\Services\NotificationPlatformSettingsService;
use App\Services\UserNotificationPreferencesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_view_and_update_notification_preferences(): void
    {
        config(['notifications.mail.enabled' => true]);
        AdminSetting::setValue(NotificationPlatformSettingsService::KEY_MAIL_ENABLED, '1');
        AdminSetting::setValue(NotificationPlatformSettingsService::KEY_INACTIVE_REMINDER_ENABLED, '1');
        AdminSetting::setValue(NotificationPlatformSettingsService::KEY_NEW_MATCHES_DIGEST_ENABLED, '1');

        $user = User::factory()->create(['email' => 'prefs@example.com']);

        $this->actingAs($user)
            ->get(route('user.settings.notifications'))
            ->assertOk()
            ->assertSee(__('user_settings_notifications.title'), false);

        $this->actingAs($user)
            ->post(route('user.settings.notifications.update'), [
                'email_alerts' => '0',
                'engagement_inactive_reminder' => '0',
                'engagement_new_matches_digest' => '1',
            ])
            ->assertRedirect(route('user.settings.notifications'));

        $prefs = app(UserNotificationPreferencesService::class)->forUser($user->fresh());
        $this->assertFalse($prefs[UserNotificationPreferencesService::KEY_EMAIL_ALERTS]);
        $this->assertFalse($prefs[UserNotificationPreferencesService::KEY_ENGAGEMENT_INACTIVE]);
        $this->assertTrue($prefs[UserNotificationPreferencesService::KEY_ENGAGEMENT_MATCHES_DIGEST]);
    }
}
