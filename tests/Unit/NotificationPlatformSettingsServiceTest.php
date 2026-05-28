<?php

namespace Tests\Unit;

use App\Models\AdminSetting;
use App\Services\NotificationPlatformSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPlatformSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_falls_back_to_config_when_db_empty(): void
    {
        config([
            'notifications.mail.enabled' => false,
            'engagement.inactive_reminder.enabled' => false,
        ]);

        $service = app(NotificationPlatformSettingsService::class);

        $this->assertFalse($service->mailEnabled());
        $this->assertFalse($service->inactiveReminderEnabled());
    }

    public function test_admin_db_overrides_config(): void
    {
        config(['notifications.mail.enabled' => false]);

        AdminSetting::setValue(NotificationPlatformSettingsService::KEY_MAIL_ENABLED, '1');
        AdminSetting::setValue(NotificationPlatformSettingsService::KEY_PLAN_EXPIRY_NOTIFY_DAYS, '14,3');

        $service = app(NotificationPlatformSettingsService::class);

        $this->assertTrue($service->mailEnabled());
        $this->assertSame([14, 3], $service->planExpiryNotifyDaysBeforeList());
    }

    public function test_persist_from_admin_form(): void
    {
        app(NotificationPlatformSettingsService::class)->persistFromAdminForm([
            'notification_mail_enabled' => false,
            'notification_inactive_reminder_enabled' => true,
            'notification_inactive_whatsapp_enabled' => true,
            'notification_inactive_after_days' => 5,
            'notification_inactive_cooldown_days' => 10,
            'notification_new_matches_digest_enabled' => false,
            'notification_plan_expiry_notify_days' => '7, 1',
            'notification_retention_days' => 120,
        ]);

        $service = app(NotificationPlatformSettingsService::class);

        $this->assertFalse($service->mailEnabled());
        $this->assertTrue($service->inactiveWhatsappEnabled());
        $this->assertSame(5, $service->inactiveAfterDays());
        $this->assertSame([7, 1], $service->planExpiryNotifyDaysBeforeList());
        $this->assertSame(120, $service->retentionDays());
        $this->assertFalse($service->newMatchesDigestEnabled());
    }
}
