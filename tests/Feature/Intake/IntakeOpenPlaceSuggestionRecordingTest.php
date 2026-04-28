<?php

namespace Tests\Feature\Intake;

use App\Models\LocationOpenPlaceSuggestion;
use App\Models\User;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeOpenPlaceSuggestionRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unmatched_birth_place_records_open_suggestion_when_actor_user_id_set(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();

        $snapshot = [
            'core' => [
                'birth_place' => 'Totally Unknown Hamlet 999',
            ],
        ];

        app(IntakeControlledFieldNormalizer::class)->normalizeSnapshot($snapshot, (int) $user->id);

        $row = LocationOpenPlaceSuggestion::query()
            ->where('suggested_by', $user->id)
            ->where('raw_input', 'Totally Unknown Hamlet 999')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->usage_count);
        $this->assertSame('pending', (string) $row->status);
    }

    public function test_same_unknown_place_bumps_usage_on_second_normalize(): void
    {
        $this->seed(MinimalLocationSeeder::class);
        $user = User::factory()->create();
        $normalizer = app(IntakeControlledFieldNormalizer::class);

        $snapshot = [
            'core' => ['birth_place' => 'Karvenagar Fringe'],
        ];
        $normalizer->normalizeSnapshot($snapshot, (int) $user->id);
        $normalizer->normalizeSnapshot($snapshot, (int) $user->id);

        $row = LocationOpenPlaceSuggestion::query()
            ->where('suggested_by', $user->id)
            ->where('raw_input', 'Karvenagar Fringe')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->fresh()->usage_count);
    }

    public function test_without_suggested_by_user_no_row_is_created(): void
    {
        $this->seed(MinimalLocationSeeder::class);

        $snapshot = [
            'core' => [
                'birth_place' => 'Another Unknown Spot',
            ],
        ];

        app(IntakeControlledFieldNormalizer::class)->normalizeSnapshot($snapshot, null);

        $this->assertSame(0, LocationOpenPlaceSuggestion::query()->count());
    }
}
