<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Suchak\Support\CreatesSuchakAdmin;
use Tests\TestCase;

class SuchakRouteAccessTest extends TestCase
{
    use CreatesSuchakAdmin;
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

    public function test_suchak_user_is_redirected_from_member_dashboard_even_when_member_profile_exists(): void
    {
        $this->seed(MinimalLocationSeeder::class);

        $user = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Abhiruchi',
            'lifecycle_state' => 'draft',
        ]);
        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;
        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $leafId]);
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
        }
        $profile->update(['lifecycle_state' => 'active']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('suchak.dashboard'));
    }

    public function test_suchak_user_is_redirected_from_member_dashboard_even_when_member_onboarding_is_incomplete(): void
    {
        $user = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);
        MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Abhiruchi',
            'card_onboarding_resume_step' => 2,
            'lifecycle_state' => 'draft',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('suchak.dashboard'));
    }

    public function test_member_onboarding_lock_does_not_block_suchak_workspace(): void
    {
        $user = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);
        MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Abhiruchi',
            'card_onboarding_resume_step' => 2,
            'lifecycle_state' => 'draft',
        ]);

        $this->actingAs($user)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('Suchak Dashboard', false);
    }

    public function test_suchak_workspace_uses_suchak_navigation_instead_of_member_menu(): void
    {
        $user = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('data-suchak-nav', false)
            ->assertSee('data-suchak-subnav', false)
            ->assertSee('Work', false)
            ->assertDontSee('Network', false)
            ->assertDontSee('Tools', false)
            ->assertSee('Profile setup', false)
            ->assertSee('Today', false)
            ->assertSee('Customers', false)
            ->assertSee('Money', false)
            ->assertDontSee('Sharing', false)
            ->assertSee('Records', false)
            ->assertSee(route('suchak.dashboard', ['dashboard_tab' => 'profile']), false)
            ->assertSee(route('suchak.dashboard', ['dashboard_tab' => 'profiles']), false)
            ->assertSee('Upload / Paste', false)
            ->assertSee('Manual Form', false)
            ->assertSee('Collaborations', false)
            ->assertDontSee('Offline Camps', false)
            ->assertSee('Suchak privacy & public listing', false)
            ->assertSee('Contact numbers', false)
            ->assertSee('Account & Security', false)
            ->assertSee('Notification preferences', false)
            ->assertSee('Notification inbox', false)
            ->assertSee(route('user.settings.security'), false)
            ->assertSee(route('user.settings.notifications'), false)
            ->assertSee(route('notifications.index'), false)
            ->assertDontSee('Candidate profile settings', false)
            ->assertDontSee('id="connect-main-badge"', false)
            ->assertDontSee('id="activity-main-badge"', false)
            ->assertDontSee('id="mobile-sticky-quick-nav"', false);
    }

    public function test_suchak_intake_page_keeps_suchak_navigation_and_not_member_menu(): void
    {
        $user = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.intakes.create'))
            ->assertOk()
            ->assertSee('data-suchak-nav', false)
            ->assertSee('data-suchak-subnav', false)
            ->assertSee('Work', false)
            ->assertSee('Upload / Paste', false)
            ->assertSee('Manual Form', false)
            ->assertSee('Find Matches', false)
            ->assertDontSee('id="connect-main-badge"', false)
            ->assertDontSee('id="activity-main-badge"', false)
            ->assertDontSee('id="mobile-sticky-quick-nav"', false)
            ->assertDontSee('nav.personal_menu_profile_section', false)
            ->assertDontSee('My Profile', false);
    }

    public function test_suchak_manual_profile_page_keeps_suchak_navigation_and_not_member_menu(): void
    {
        $user = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('suchak.manual-profiles.create'))
            ->assertOk()
            ->assertSee('data-suchak-nav', false)
            ->assertSee('data-suchak-subnav', false)
            ->assertSee('Manual Form', false)
            ->assertSee('Manual candidate profile', false)
            ->assertDontSee('id="connect-main-badge"', false)
            ->assertDontSee('id="activity-main-badge"', false)
            ->assertDontSee('id="mobile-sticky-quick-nav"', false)
            ->assertDontSee('nav.personal_menu_profile_section', false)
            ->assertDontSee('My Profile', false);
    }

    public function test_suchak_account_settings_pages_keep_suchak_navigation(): void
    {
        $user = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
        ]);

        foreach ([
            route('suchak.account-settings.edit') => 'Account contacts',
            route('user.settings.security') => 'Account & Security',
            route('user.settings.notifications') => 'Notification preferences',
            route('notifications.index') => 'Notification inbox',
        ] as $url => $expectedText) {
            $this->actingAs($user)
                ->get($url)
                ->assertOk()
                ->assertSee('data-suchak-nav', false)
                ->assertSee('data-suchak-subnav', false)
                ->assertSee('Suchak privacy & public listing', false)
                ->assertSee('Contact numbers', false)
                ->assertSee('Account & Security', false)
                ->assertSee('Notification preferences', false)
                ->assertSee('Notification inbox', false)
                ->assertSee($expectedText, false)
                ->assertDontSee('id="connect-main-badge"', false)
                ->assertDontSee('id="activity-main-badge"', false)
                ->assertDontSee('id="mobile-sticky-quick-nav"', false)
                ->assertDontSee('nav.personal_menu_profile_section', false);
        }
    }

    public function test_admin_suchak_dashboard_still_resolves_under_admin_middleware(): void
    {
        $admin = $this->createSuchakSuperAdmin();

        $this->actingAs($admin)
            ->get(route('admin.suchak.dashboard'))
            ->assertOk()
            ->assertSee('Suchak Admin Dashboard', false);
    }
}
