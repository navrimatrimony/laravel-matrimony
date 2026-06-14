<?php

namespace Tests\Feature\Admin;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminProfilesIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_profiles_index_renders_gender_label_and_edit_link(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);

        DB::table('master_genders')->updateOrInsert(
            ['key' => 'male'],
            [
                'label' => 'Male',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $genderId = (int) DB::table('master_genders')->where('key', 'male')->value('id');
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Codex Candidate',
            'gender_id' => $genderId,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.profiles.index'));

        $response->assertOk();
        $response->assertSee('Codex Candidate');
        $response->assertSee('Male');
        $response->assertDontSee('"key":"male"');
        $response->assertDontSee('&quot;key&quot;:&quot;male&quot;', false);
        $response->assertSee('Matrimony');
        $response->assertSee('User');
        $response->assertSee('Suchak');
        $response->assertSee('Direct user');
        $response->assertSee('View Profile');
        $response->assertSee('Edit Profile');
        $response->assertSee(route('matrimony.profile.wizard.section', [
            'section' => 'full',
            'all' => 1,
            'profile_id' => $profile->id,
        ]));
    }

    public function test_admin_profile_edit_query_redirects_to_full_wizard_for_normal_profile(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Full Form Candidate',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.profiles.show', $profile->id).'?edit=1');

        $response->assertRedirect(route('matrimony.profile.wizard.section', [
            'section' => 'full',
            'all' => 1,
            'profile_id' => $profile->id,
        ]));

        $this->assertSame((int) $profile->id, (int) session('admin_edit_profile_id'));
    }

    public function test_admin_can_open_normal_profile_in_full_wizard(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Wizard Candidate',
        ]);

        $response = $this->actingAs($admin)->get(route('matrimony.profile.wizard.section', [
            'section' => 'full',
            'all' => 1,
            'profile_id' => $profile->id,
        ]));

        $response->assertOk();
        $response->assertSee('Wizard Candidate');
        $response->assertSee('name="profile_id" value="'.$profile->id.'"', false);
    }

    public function test_admin_profiles_index_filters_suchak_profiles_and_renders_suchak_identity(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);

        $directProfile = MatrimonyProfile::factory()->create([
            'full_name' => 'Direct Candidate',
        ]);
        $suchakProfile = MatrimonyProfile::factory()->create([
            'full_name' => 'Suchak Candidate',
        ]);
        $suchakAccount = SuchakAccount::factory()->create([
            'suchak_name' => 'Nava Suchak Office',
        ]);

        SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $suchakAccount->id,
            'matrimony_profile_id' => $suchakProfile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.profiles.index', [
            'source' => 'suchak',
            'sort' => 'matrimony_id_asc',
        ]));

        $response->assertOk();
        $response->assertSee('Suchak Candidate');
        $response->assertDontSee('Direct Candidate');
        $response->assertSee('Suchak-managed');
        $response->assertSee('Nava Suchak Office');
        $response->assertSee('Suchak</span> #'.$suchakAccount->id, false);
        $response->assertSee('Search admin tools');
        $response->assertSee('<option value="suchak" selected>Suchak-managed</option>', false);
        $response->assertSee('<option value="matrimony_id_asc" selected>Matrimony ID ↑</option>', false);
        $this->assertNotSame($directProfile->id, $suchakProfile->id);
    }
}
