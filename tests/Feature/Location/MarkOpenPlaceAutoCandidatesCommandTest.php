<?php

namespace Tests\Feature\Location;

use App\Models\City;
use App\Models\LocationOpenPlaceSuggestion;
use App\Models\User;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkOpenPlaceAutoCandidatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_marks_only_pending_unresolved_above_threshold(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();

        $toMark = LocationOpenPlaceSuggestion::query()->create([
            'raw_input' => 'AutoCandidate One',
            'normalized_input' => 'autocandidate one',
            'status' => 'pending',
            'usage_count' => 6,
            'suggested_by' => $user->id,
        ]);
        $belowThreshold = LocationOpenPlaceSuggestion::query()->create([
            'raw_input' => 'Stay Pending',
            'normalized_input' => 'stay pending',
            'status' => 'pending',
            'usage_count' => 2,
            'suggested_by' => $user->id,
        ]);
        $cityId = (int) City::query()->value('id');
        $resolved = LocationOpenPlaceSuggestion::query()->create([
            'raw_input' => 'Resolved Pending',
            'normalized_input' => 'resolved pending',
            'status' => 'pending',
            'usage_count' => 9,
            'resolved_city_id' => $cityId,
            'suggested_by' => $user->id,
        ]);
        $alreadyApproved = LocationOpenPlaceSuggestion::query()->create([
            'raw_input' => 'Already Approved',
            'normalized_input' => 'already approved',
            'status' => 'approved',
            'usage_count' => 10,
            'suggested_by' => $user->id,
        ]);

        $this->artisan('location:mark-open-place-auto-candidates', ['--threshold' => 5])
            ->assertExitCode(0);

        $toMark->refresh();
        $belowThreshold->refresh();
        $resolved->refresh();
        $alreadyApproved->refresh();

        $this->assertSame('auto_candidate', $toMark->status);
        $this->assertSame('pending', $belowThreshold->status);
        $this->assertSame('pending', $resolved->status);
        $this->assertSame('approved', $alreadyApproved->status);
    }
}
