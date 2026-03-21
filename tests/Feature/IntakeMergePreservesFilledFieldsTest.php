<?php

namespace Tests\Feature;

use App\Models\BiodataIntake;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\MutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeMergePreservesFilledFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_intake_apply_does_not_overwrite_existing_core_full_name_and_stores_suggestion(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Profile Original Name',
            'height_cm' => null,
        ]);

        $snapshot = [
            'snapshot_schema_version' => 1,
            'core' => [
                'full_name' => 'Intake Parsed Name',
                'height_cm' => 172,
            ],
            'contacts' => [],
            'children' => [],
        ];

        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'test',
            'uploaded_by' => $user->id,
            'matrimony_profile_id' => $profile->id,
            'parse_status' => 'parsed',
            'intake_status' => 'approved',
            'approved_by_user' => true,
            'approved_at' => now(),
            'approval_snapshot_json' => $snapshot,
            'snapshot_schema_version' => 1,
            'intake_locked' => false,
        ]);

        $result = app(MutationService::class)->applyApprovedIntake($intake->id);

        $this->assertTrue($result['mutation_success'] ?? false, 'mutation should succeed');
        $this->assertSame($profile->id, $result['profile_id']);

        $profile->refresh();
        $this->assertSame('Profile Original Name', $profile->full_name);
        $this->assertSame(172, (int) $profile->height_cm);

        $pending = $profile->pending_intake_suggestions_json;
        $this->assertIsArray($pending);
        $this->assertArrayHasKey('core', $pending);
        $this->assertSame('Intake Parsed Name', $pending['core']['full_name'] ?? null);

        $intake->refresh();
        $this->assertTrue((bool) $intake->intake_locked);
        $this->assertSame('applied', $intake->intake_status);
    }
}
