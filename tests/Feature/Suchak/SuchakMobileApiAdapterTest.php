<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuchakMobileApiAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_me_dashboard_and_customers_adapters_expose_existing_services(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876500011',
            'mobile_verified_at' => now(),
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'suchak_name' => 'Adapter Suchak',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/suchak/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account.id', $account->id)
            ->assertJsonPath('data.access.can_operate', true)
            ->assertJsonStructure([
                'data' => [
                    'mvp_surface' => [
                        'nav',
                        'nav_subitems',
                        'dashboard_tabs',
                        'visible_dashboard_tabs',
                    ],
                ],
            ]);

        $this->getJson('/api/v1/suchak/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_id', $account->id)
            ->assertJsonStructure(['data' => ['worklist', 'generated_at']]);

        $this->getJson('/api/v1/suchak/customers')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_id', $account->id)
            ->assertJsonStructure(['data' => ['customers']]);
    }

    public function test_suchak_mobile_adapters_require_suchak_account(): void
    {
        $user = User::factory()->create([
            'mobile' => '9876500012',
            'mobile_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/suchak/me')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }
}
