<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\ProfileVisibilitySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuchakContactRoutingModeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_can_choose_suchak_only_contact_routing_mode_in_privacy_settings(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_state' => 'draft',
        ]);

        $this
            ->actingAs($user)
            ->get(route('user.settings.privacy'))
            ->assertOk()
            ->assertSee(__('settings_privacy.contact_routing_suchak_only'), false);

        $this
            ->actingAs($user)
            ->post(route('user.settings.privacy.update'), [
                'visibility_scope' => 'public',
                'show_photo_to' => 'all',
                'contact_visibility_rule' => 'anyone',
                'contact_visibility_strictness' => 'balanced',
                'contact_visibility_id_verified_only' => '0',
                'contact_visibility_photo_only' => '0',
                'contact_visibility_require_contact_request' => '0',
                'contact_visibility_approval_required' => '0',
                'contact_routing_mode' => ProfileVisibilitySetting::CONTACT_ROUTING_SUCHAK_ONLY,
            ])
            ->assertRedirect(route('user.settings.privacy'));

        $this->assertDatabaseHas('profile_visibility_settings', [
            'profile_id' => $profile->id,
            'contact_routing_mode' => ProfileVisibilitySetting::CONTACT_ROUTING_SUCHAK_ONLY,
        ]);
    }
}
