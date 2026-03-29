<?php

namespace Tests\Feature;

use App\Models\BiodataIntake;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\MutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeFieldSuggestionEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_applies_full_name_when_profile_full_name_empty(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => '',
            'height_cm' => null,
        ]);

        $snapshot = $this->baseSnapshot($profile, $user->id, [
            'full_name' => 'प्रतिक पाटील',
            'height_cm' => null,
        ]);

        $this->runApply($snapshot, $user->id, $profile->id);

        $profile->refresh();
        $this->assertSame('प्रतिक पाटील', $profile->full_name);
        $pending = $profile->pending_intake_suggestions_json;
        $this->assertTrue($pending === null || empty($pending['core']['full_name'] ?? null));
    }

    public function test_same_father_name_is_noop_without_suggestion(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'father_name' => 'राम पाटील',
        ]);

        $snapshot = $this->baseSnapshot($profile, $user->id, [
            'father_name' => 'राम पाटील',
        ]);

        $this->runApply($snapshot, $user->id, $profile->id);

        $profile->refresh();
        $this->assertSame('राम पाटील', $profile->father_name);
        $pending = $profile->pending_intake_suggestions_json;
        $this->assertEmpty($pending['core']['father_name'] ?? null);
        $this->assertTrue(empty($pending['core_field_suggestions']) || $this->noSuggestionForField($pending, 'father_name'));
    }

    public function test_different_father_name_stays_and_stores_structured_suggestion(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'father_name' => 'A',
        ]);

        $snapshot = $this->baseSnapshot($profile, $user->id, [
            'father_name' => 'B',
        ]);

        $intake = $this->runApply($snapshot, $user->id, $profile->id);

        $profile->refresh();
        $this->assertSame('A', $profile->father_name);
        $pending = $profile->pending_intake_suggestions_json;
        $this->assertSame('B', $pending['core']['father_name'] ?? null);
        $this->assertIsArray($pending['core_field_suggestions'] ?? null);
        $row = collect($pending['core_field_suggestions'])->firstWhere('field', 'father_name');
        $this->assertNotNull($row);
        $this->assertSame('A', $row['old_value']);
        $this->assertSame('B', $row['new_value']);
        $this->assertSame($intake->id, $row['source_intake_id']);
    }

    public function test_empty_mother_occupation_filled_from_intake(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'mother_occupation' => null,
        ]);

        $snapshot = $this->baseSnapshot($profile, $user->id, [
            'mother_occupation' => 'गृहिणी',
        ]);

        $this->runApply($snapshot, $user->id, $profile->id);

        $profile->refresh();
        $this->assertSame('गृहिणी', $profile->mother_occupation);
    }

    public function test_different_highest_education_is_suggestion_only(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'highest_education' => 'B.E.',
        ]);

        $snapshot = $this->baseSnapshot($profile, $user->id, [
            'highest_education' => 'M.Tech',
        ]);

        $this->runApply($snapshot, $user->id, $profile->id);

        $profile->refresh();
        $this->assertSame('B.E.', $profile->highest_education);
        $pending = $profile->pending_intake_suggestions_json;
        $this->assertSame('M.Tech', $pending['core']['highest_education'] ?? null);
        $row = collect($pending['core_field_suggestions'] ?? [])->firstWhere('field', 'highest_education');
        $this->assertNotNull($row);
        $this->assertStringContainsString('B.E.', $row['old_value']);
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function baseSnapshot(MatrimonyProfile $profile, int $userId, array $core): array
    {
        return [
            'snapshot_schema_version' => 1,
            'core' => array_merge([
                'full_name' => $profile->full_name ?: 'Placeholder',
            ], $core),
            'contacts' => [],
            'children' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function runApply(array $snapshot, int $userId, int $profileId): BiodataIntake
    {
        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'test',
            'uploaded_by' => $userId,
            'matrimony_profile_id' => $profileId,
            'parse_status' => 'parsed',
            'intake_status' => 'approved',
            'approved_by_user' => true,
            'approved_at' => now(),
            'approval_snapshot_json' => $snapshot,
            'snapshot_schema_version' => 1,
            'intake_locked' => false,
        ]);

        $result = app(MutationService::class)->applyApprovedIntake($intake->id);
        $this->assertTrue($result['mutation_success'] ?? false, json_encode($result));

        return $intake->fresh();
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function noSuggestionForField(array $pending, string $field): bool
    {
        foreach ($pending['core_field_suggestions'] ?? [] as $row) {
            if (is_array($row) && ($row['field'] ?? '') === $field) {
                return false;
            }
        }

        return true;
    }
}
