<?php

namespace Tests\Feature;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchingTest extends TestCase
{
    use RefreshDatabase;

    private function seedGenders(): array
    {
        $male = MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true]
        );
        $female = MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true]
        );

        return [(int) $male->id, (int) $female->id];
    }

    public function test_matches_page_requires_auth(): void
    {
        $this->get(route('matches.index'))->assertRedirect();
    }

    public function test_my_matches_returns_opposite_gender_active_profile(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        MatrimonyProfile::factory()->create([
            'user_id' => $userA->id,
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_demo' => false,
            'date_of_birth' => now()->subYears(28),
            'full_name' => 'Seeker Male',
        ]);

        MatrimonyProfile::factory()->create([
            'user_id' => $userB->id,
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_demo' => false,
            'date_of_birth' => now()->subYears(26),
            'full_name' => 'Candidate Female',
        ]);

        $this->actingAs($userA)
            ->get(route('matches.index'))
            ->assertOk()
            ->assertSee('Candidate Female', false)
            ->assertSee('Match score', false);
    }

    public function test_show_matches_forbidden_for_other_user(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $owner->id,
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_demo' => false,
        ]);

        $this->actingAs($other)
            ->get(route('matches.show', ['matrimony_profile_id' => $profile->id]))
            ->assertForbidden();
    }
}
