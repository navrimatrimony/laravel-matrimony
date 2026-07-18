<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Thin adapter smoke: Suchak-only login rejects member users; password login works for Suchak.
 */
class SuchakMobileAuthAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_login_rejects_member_without_suchak_account(): void
    {
        $user = User::factory()->create([
            'mobile' => '9888777666',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/v1/suchak/login/password', [
            'login' => '9888777666',
            'password' => 'password123',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'suchak_not_found');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_password_login_succeeds_for_suchak_account(): void
    {
        $user = User::factory()->create([
            'mobile' => '9888777667',
            'password' => Hash::make('password123'),
        ]);

        SuchakAccount::query()->create([
            'user_id' => $user->id,
            'suchak_name' => 'Test Suchak',
            'business_type' => SuchakAccount::BUSINESS_TYPE_INDIVIDUAL,
            'mobile_number' => '9888777667',
            'whatsapp_number' => '9888777667',
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
        ]);

        $this->postJson('/api/v1/suchak/login/password', [
            'login' => '9888777667',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['token']);
    }

    public function test_login_otp_send_rejects_unknown_mobile(): void
    {
        $this->postJson('/api/v1/suchak/login/otp/send', [
            'mobile' => '9111222333',
            'terms_accepted' => true,
            'privacy_accepted' => true,
            'terms_version' => '2026-06-24',
            'privacy_version' => '2026-06-24',
        ])
            ->assertNotFound()
            ->assertJsonPath('code', 'suchak_not_found');
    }

    public function test_register_status_requires_suchak_session(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/suchak/register/status')
            ->assertForbidden();
    }
}
