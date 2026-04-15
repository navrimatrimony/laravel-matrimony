<?php

namespace Tests\Feature;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchingEngineAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_engine_overview_redirects_guests(): void
    {
        $this->get(route('admin.matching-engine.overview'))
            ->assertRedirect();
    }

    public function test_matching_engine_overview_ok_for_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.matching-engine.overview'))
            ->assertOk()
            ->assertSee('Central matching engine', false);
    }

    public function test_matches_json_includes_explain_when_requested(): void
    {
        $maleGid = (int) MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true]
        )->id;
        $femaleGid = (int) MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true]
        )->id;

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        MatrimonyProfile::factory()->create([
            'user_id' => $userA->id,
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(28),
        ]);
        MatrimonyProfile::factory()->create([
            'user_id' => $userB->id,
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(26),
        ]);

        $url = route('matches.index').'?explain=1';

        $this->actingAs($userA)
            ->getJson($url)
            ->assertOk()
            ->assertJsonStructure([
                'profile_id',
                'matches' => [
                    '*' => ['profile_id', 'full_name', 'score', 'reasons', 'explain'],
                ],
            ]);
    }
}
