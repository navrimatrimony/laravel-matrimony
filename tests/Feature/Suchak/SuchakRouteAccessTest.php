<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuchakRouteAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_suchak_dashboard_by_auth(): void
    {
        $this->get(route('suchak.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_without_suchak_account_cannot_access_suchak_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('suchak.dashboard'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_authenticated_user_with_suchak_account_can_access_dashboard(): void
    {
        $user = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('Suchak Dashboard', false);
    }

    public function test_admin_suchak_dashboard_still_resolves_under_admin_middleware(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.suchak.dashboard'))
            ->assertOk()
            ->assertSee('Suchak Admin Dashboard', false);
    }
}
