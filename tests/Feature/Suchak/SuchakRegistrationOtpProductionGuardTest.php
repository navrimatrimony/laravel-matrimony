<?php

namespace Tests\Feature\Suchak;

use App\Http\Middleware\EnsureSuchakLegacyOtpEnabled;
use App\Models\AdminSetting;
use App\Modules\Suchak\Services\SuchakRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * U5 — plaintext OTP must not leak from SuchakRegistrationService in production
 * when AdminSetting alone is set to `dev_show`.
 */
class SuchakRegistrationOtpProductionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_u5_production_dev_show_does_not_return_plaintext_otp(): void
    {
        AdminSetting::setValue('mobile_verification_mode', 'dev_show');
        $this->app->detectEnvironment(fn (): string => 'production');

        $result = app(SuchakRegistrationService::class)->startMobileRegistration('9876505555');

        $this->assertSame('dev_show', $result['delivery']);
        $this->assertNull($result['otp']);
    }

    public function test_u5_testing_env_dev_show_still_returns_otp(): void
    {
        AdminSetting::setValue('mobile_verification_mode', 'dev_show');
        $this->assertFalse(app()->isProduction());

        $result = app(SuchakRegistrationService::class)->startMobileRegistration('9876505556');

        $this->assertSame('dev_show', $result['delivery']);
        $this->assertNotNull($result['otp']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $result['otp']);
    }

    public function test_u5_legacy_otp_middleware_defaults_off_in_production(): void
    {
        config(['firebase_auth.legacy_suchak_otp' => null]);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertFalse(
            EnsureSuchakLegacyOtpEnabled::enabled(),
            'legacy Suchak OTP must stay off in production when config is unset'
        );
    }
}
