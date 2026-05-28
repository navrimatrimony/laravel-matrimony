<?php

namespace Tests\Feature;

use App\Models\Interest;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\Matching\MatchingService;
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
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(28),
            'full_name' => 'Seeker Male',
        ]);

        MatrimonyProfile::factory()->create([
            'user_id' => $userB->id,
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(26),
            'full_name' => 'Candidate Female',
        ]);

        $this->actingAs($userA)
            ->get(route('matches.index'))
            ->assertOk()
            ->assertSee('Candidate Female', false)
            ->assertSee('% match', false);
    }

    public function test_matches_list_dedupes_when_same_user_has_multiple_matrimony_profiles(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $userA = User::factory()->create();
        $userDup = User::factory()->create();

        MatrimonyProfile::factory()->create([
            'user_id' => $userA->id,
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(28),
            'full_name' => 'Seeker Male',
        ]);

        $dupAttrs = [
            'user_id' => $userDup->id,
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(26),
            'full_name' => 'Duplicate Row Same User',
        ];
        MatrimonyProfile::factory()->create($dupAttrs);
        MatrimonyProfile::factory()->create($dupAttrs);

        $html = $this->actingAs($userA)
            ->get(route('matches.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Duplicate Row Same User'));
    }

    public function test_matches_list_dedupes_same_display_identity_for_different_user_accounts(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $userA = User::factory()->create();
        $userB1 = User::factory()->create();
        $userB2 = User::factory()->create();

        MatrimonyProfile::factory()->create([
            'user_id' => $userA->id,
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(28),
            'full_name' => 'Seeker Male',
        ]);

        $dob = now()->subYears(26)->format('Y-m-d');
        $dupName = 'कु. प्रीती राजेंद्र पाटील';

        MatrimonyProfile::factory()->create([
            'user_id' => $userB1->id,
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => $dob,
            'full_name' => $dupName,
        ]);
        MatrimonyProfile::factory()->create([
            'user_id' => $userB2->id,
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => $dob,
            'full_name' => $dupName,
        ]);

        $html = $this->actingAs($userA)
            ->get(route('matches.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, $dupName));
    }

    public function test_perfect_tab_puts_viewed_profiles_after_unviewed(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userC = User::factory()->create();

        $seeker = MatrimonyProfile::factory()->create([
            'user_id' => $userA->id,
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(28),
            'full_name' => 'Seeker Male',
        ]);

        $unviewed = MatrimonyProfile::factory()->create([
            'user_id' => $userB->id,
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(26),
            'full_name' => 'Unviewed Candidate',
            'updated_at' => now()->subDay(),
        ]);

        $viewed = MatrimonyProfile::factory()->create([
            'user_id' => $userC->id,
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(27),
            'full_name' => 'Viewed Candidate',
            'updated_at' => now(),
        ]);

        ProfileView::query()->create([
            'viewer_profile_id' => $seeker->id,
            'viewed_profile_id' => $viewed->id,
        ]);

        Interest::query()->create([
            'sender_profile_id' => $seeker->id,
            'receiver_profile_id' => $viewed->id,
            'status' => 'pending',
        ]);

        $rows = app(MatchingService::class)->findMatchesForTab($seeker, MatchingService::TAB_PERFECT, 20);
        $ids = $rows->map(fn (array $row) => (int) $row['profile']->id)->values()->all();

        $this->assertContains($unviewed->id, $ids);
        $this->assertContains($viewed->id, $ids);
        $this->assertLessThan(array_search($viewed->id, $ids, true), array_search($unviewed->id, $ids, true));
    }

    public function test_perfect_tab_excludes_opened_without_interest_second_chance_pool(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $seeker = MatrimonyProfile::factory()->create([
            'user_id' => $userA->id,
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(28),
            'full_name' => 'Seeker Male',
        ]);

        $secondChance = MatrimonyProfile::factory()->create([
            'user_id' => $userB->id,
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'is_showcase' => false,
            'date_of_birth' => now()->subYears(26),
            'full_name' => 'Second Chance Only',
        ]);

        ProfileView::query()->create([
            'viewer_profile_id' => $seeker->id,
            'viewed_profile_id' => $secondChance->id,
        ]);

        $perfectIds = app(MatchingService::class)
            ->findMatchesForTab($seeker, MatchingService::TAB_PERFECT, 20)
            ->map(fn (array $row) => (int) $row['profile']->id)
            ->all();

        $secondIds = app(MatchingService::class)
            ->findMatchesForTab($seeker, MatchingService::TAB_SECOND_CHANCE, 20)
            ->map(fn (array $row) => (int) $row['profile']->id)
            ->all();

        $this->assertNotContains($secondChance->id, $perfectIds);
        $this->assertContains($secondChance->id, $secondIds);
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
            'is_showcase' => false,
        ]);

        $this->actingAs($other)
            ->get(route('matches.show', ['matrimony_profile_id' => $profile->id]))
            ->assertForbidden();
    }
}
