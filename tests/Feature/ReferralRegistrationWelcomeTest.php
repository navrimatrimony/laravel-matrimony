<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserReferral;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralRegistrationWelcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'referral.enabled' => true,
            'referral.referred_checkout' => [
                'enabled' => true,
                'percent_off' => 10,
                'extra_days' => 0,
            ],
        ]);
    }

    public function test_registration_with_valid_ref_stashes_welcome_session(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'WELCOME01']);

        $this->post(route('register'), [
            'name' => 'Referred Member',
            'mobile' => '9876543210',
            'password' => 'password',
            'password_confirmation' => 'password',
            'registering_for' => 'self',
            'invite_code' => 'WELCOME01',
        ])->assertRedirect();

        $buyer = User::query()->where('mobile', '9876543210')->first();
        $this->assertNotNull($buyer);
        $this->assertTrue(session()->has(ReferralService::SESSION_REGISTRATION_WELCOME));
        $this->assertDatabaseHas('user_referrals', [
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
        ]);
    }

    public function test_dashboard_shows_welcome_banner_for_referred_new_member(): void
    {
        $referrer = User::factory()->create([
            'referral_code' => 'WELCOME02',
            'name' => 'Anand Patil',
        ]);
        $buyer = User::factory()->create();

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
        ]);

        app(ReferralService::class)->stashRegistrationWelcomeSession($buyer);

        $this->actingAs($buyer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('referrals.registration_welcome_title'), false)
            ->assertSee(__('referrals.registration_welcome_invited_by', ['name' => 'Anand P.']), false);
    }

    public function test_registration_welcome_banner_includes_referrer_display_name(): void
    {
        $referrer = User::factory()->create([
            'referral_code' => 'WELCOME03',
            'name' => 'Priya Sharma',
        ]);
        $buyer = User::factory()->create();

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
        ]);

        app(ReferralService::class)->stashRegistrationWelcomeSession($buyer);

        $banner = app(ReferralService::class)->registrationWelcomeBanner($buyer);

        $this->assertNotNull($banner);
        $this->assertSame('Priya S.', $banner['referrer_display_name']);
    }

    public function test_dismiss_clears_welcome_session(): void
    {
        $buyer = User::factory()->create();
        app(ReferralService::class)->stashRegistrationWelcomeSession($buyer);

        $this->actingAs($buyer)
            ->post(route('referrals.welcome.dismiss'))
            ->assertRedirect();

        $this->assertFalse(session()->has(ReferralService::SESSION_REGISTRATION_WELCOME));
        $this->assertNull(app(ReferralService::class)->registrationWelcomeBanner($buyer));
    }
}
