<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\NotificationLocalization;
use Tests\TestCase;

class NotificationLocalizationTest extends TestCase
{
    public function test_pair_returns_different_languages_for_referral_invite(): void
    {
        $pair = NotificationLocalization::pair('notifications.referral_invite_registered_message', [
            'name' => 'Anita',
        ]);

        $this->assertNotSame($pair['message'], $pair['message_mr']);
        $this->assertStringContainsString('Anita', $pair['message']);
        $this->assertStringContainsString('Anita', $pair['message_mr']);
    }

    public function test_display_message_english_never_falls_back_to_marathi(): void
    {
        $text = NotificationLocalization::displayMessage([
            'message' => 'English only',
            'message_mr' => 'फक्त मराठी',
        ], NotificationLocalization::LOCALE_EN);

        $this->assertSame('English only', $text);
    }

    public function test_display_message_marathi_uses_message_mr(): void
    {
        $text = NotificationLocalization::displayMessage([
            'message' => 'English only',
            'message_mr' => 'फक्त मराठी',
        ], NotificationLocalization::LOCALE_MR);

        $this->assertSame('फक्त मराठी', $text);
    }

    public function test_preferred_locale_from_user_record(): void
    {
        $user = User::factory()->make(['preferred_locale' => 'mr']);

        $this->assertSame('mr', NotificationLocalization::preferredLocaleForUser($user));
    }
}
