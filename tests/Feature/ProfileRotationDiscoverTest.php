<?php

namespace Tests\Feature;

use App\Models\Interest;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileRotationDiscoverTest extends TestCase
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

    public function test_discover_excludes_recently_viewed_profile(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $viewer = User::factory()->create();
        $viewerProfile = MatrimonyProfile::factory()->for($viewer)->create([
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'full_name' => 'Viewer Person',
            'visibility_override' => true,
        ]);

        $otherUser = User::factory()->create();
        $seen = MatrimonyProfile::factory()->for($otherUser)->create([
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'full_name' => 'Recently Seen Candidate',
            'visibility_override' => true,
        ]);

        $otherUserB = User::factory()->create();
        $fresh = MatrimonyProfile::factory()->for($otherUserB)->create([
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'full_name' => 'Fresh Candidate Only',
            'visibility_override' => true,
        ]);

        \Illuminate\Support\Facades\DB::table('profile_views')->insert([
            'viewer_profile_id' => $viewerProfile->id,
            'viewed_profile_id' => $seen->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($viewer)->get(route('matrimony.profiles.index', ['sort' => 'discover']));
        $response->assertOk();
        $response->assertDontSee('Recently Seen Candidate', false);
        $response->assertSee('Fresh Candidate Only', false);
    }

    public function test_discover_excludes_profile_with_interest(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $viewer = User::factory()->create();
        $viewerProfile = MatrimonyProfile::factory()->for($viewer)->create([
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'visibility_override' => true,
        ]);

        $otherUser = User::factory()->create();
        $withInterest = MatrimonyProfile::factory()->for($otherUser)->create([
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'full_name' => 'Interest Pair Member',
            'visibility_override' => true,
        ]);

        $otherUserB = User::factory()->create();
        $noInterest = MatrimonyProfile::factory()->for($otherUserB)->create([
            'gender_id' => $femaleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'full_name' => 'No Interest Yet',
            'visibility_override' => true,
        ]);

        Interest::create([
            'sender_profile_id' => $viewerProfile->id,
            'receiver_profile_id' => $withInterest->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($viewer)->get(route('matrimony.profiles.index', ['sort' => 'discover']));
        $response->assertOk();
        $response->assertDontSee('Interest Pair Member', false);
        $response->assertSee('No Interest Yet', false);
    }

    public function test_discover_falls_back_to_latest_for_guest(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $u = User::factory()->create();
        MatrimonyProfile::factory()->for($u)->create([
            'gender_id' => $maleGid,
            'lifecycle_state' => 'active',
            'is_suspended' => false,
            'full_name' => 'Listed Profile',
            'visibility_override' => true,
        ]);

        $response = $this->get(route('matrimony.profiles.index', ['sort' => 'discover']));
        $response->assertRedirect();
    }
}
