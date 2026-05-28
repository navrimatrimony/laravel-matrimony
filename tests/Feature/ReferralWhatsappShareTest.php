<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\User;
use App\Services\ReferralService;
use App\Services\SiteIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReferralWhatsappShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'referral.enabled' => true,
            'referral.referred_checkout' => [
                'enabled' => true,
                'percent_off' => 15,
                'extra_days' => 0,
            ],
            'app.name' => 'Laravel',
        ]);
    }

    public function test_share_tools_include_whatsapp_url_with_offer_message(): void
    {
        $user = User::factory()->create(['referral_code' => 'WAshare1']);

        $tools = app(ReferralService::class)->shareToolsForReferrer($user);

        $this->assertNotNull($tools);
        $this->assertStringContainsString('ref=WASHARE1', $tools['share_url']);
        $this->assertStringContainsString('utm_source=member_referral', $tools['share_url']);
        $this->assertStringContainsString('utm_content=link', $tools['share_url']);
        $this->assertStringContainsString('utm_medium=whatsapp', $tools['message']);
        $this->assertStringContainsString('utm_content=whatsapp', $tools['message']);
        $this->assertStringStartsWith('https://api.whatsapp.com/send?', $tools['whatsapp_url']);
        $this->assertStringContainsString('15', $tools['message']);
        $this->assertStringContainsString('WASHARE1', $tools['message']);
        $this->assertStringNotContainsString('Join me on Laravel', $tools['message']);
    }

    public function test_whatsapp_share_uses_marathi_site_name_when_locale_is_marathi(): void
    {
        AdminSetting::setValue('site_identity_site_name_mr', 'नवरी मिळे नवऱ्याला');
        AdminSetting::setValue('site_identity_site_name_en', 'Navri Mile Navryala');
        Cache::forget(SiteIdentityService::CACHE_KEY);

        app()->setLocale('mr');

        $user = User::factory()->create(['referral_code' => 'SITENAME1']);

        $message = app(ReferralService::class)->shareToolsForReferrer($user)['message'] ?? '';

        $this->assertStringContainsString('नवरी मिळे नवऱ्याला', $message);
        $this->assertStringNotContainsString('Navri Mile Navryala', $message);
        $this->assertStringNotContainsString('Laravel', $message);
    }

    public function test_whatsapp_share_uses_english_site_name_when_locale_is_english(): void
    {
        AdminSetting::setValue('site_identity_site_name_mr', 'नवरी मिळे नवऱ्याला');
        AdminSetting::setValue('site_identity_site_name_en', 'Navri Mile Navryala');
        Cache::forget(SiteIdentityService::CACHE_KEY);

        app()->setLocale('en');

        $user = User::factory()->create(['referral_code' => 'SITENAME2']);

        $message = app(ReferralService::class)->shareToolsForReferrer($user)['message'] ?? '';

        $this->assertStringContainsString('Join me on Navri Mile Navryala', $message);
        $this->assertStringNotContainsString('नवरी', $message);
    }

    public function test_referrals_page_shows_whatsapp_share_button(): void
    {
        $user = User::factory()->create(['referral_code' => 'WAshare2']);

        $this->actingAs($user)
            ->get(route('referrals.index'))
            ->assertOk()
            ->assertSee(__('referrals.share_whatsapp'), false);
    }
}
