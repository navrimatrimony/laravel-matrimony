<?php

namespace Tests\Unit;

use App\Models\AdminSetting;
use App\Models\User;
use App\Notifications\PlanExpiringSoonNotification;
use App\Services\NotificationPlatformSettingsService;
use App\Services\UserNotificationPreferencesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserNotificationPreferencesServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_all_enabled(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);

        $prefs = app(UserNotificationPreferencesService::class)->forUser($user);

        $this->assertTrue($prefs[UserNotificationPreferencesService::KEY_EMAIL_ALERTS]);
        $this->assertTrue($prefs[UserNotificationPreferencesService::KEY_ENGAGEMENT_INACTIVE]);
        $this->assertTrue($prefs[UserNotificationPreferencesService::KEY_ENGAGEMENT_MATCHES_DIGEST]);
    }

    public function test_email_alerts_respects_member_opt_out_and_platform(): void
    {
        config(['notifications.mail.enabled' => true]);
        AdminSetting::setValue(NotificationPlatformSettingsService::KEY_MAIL_ENABLED, '1');

        $user = User::factory()->create(['email' => 'member@example.com']);
        app(UserNotificationPreferencesService::class)->saveForUser($user, [
            UserNotificationPreferencesService::KEY_EMAIL_ALERTS => false,
        ]);

        $service = app(UserNotificationPreferencesService::class);
        $this->assertFalse($service->emailAlertsEnabled($user->fresh()));

        $channels = (new PlanExpiringSoonNotification('gold', 3, '2026-06-01'))->via($user->fresh());
        $this->assertSame(['database'], $channels);
    }

    public function test_email_alerts_includes_mail_when_enabled(): void
    {
        config(['notifications.mail.enabled' => true]);
        AdminSetting::setValue(NotificationPlatformSettingsService::KEY_MAIL_ENABLED, '1');

        $user = User::factory()->create(['email' => 'member@example.com']);

        $channels = (new PlanExpiringSoonNotification('gold', 3, '2026-06-01'))->via($user);
        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);
    }
}
