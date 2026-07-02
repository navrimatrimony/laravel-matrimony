<?php

namespace Tests\Feature\Intake;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Intake\IntakeHumanReviewSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class IntakeAdminHumanReviewSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_reviewed_snapshot_without_mutating_profile_or_evidence(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);
        $member = User::factory()->create([
            'is_admin' => false,
            'admin_role' => null,
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $member->id,
            'full_name' => 'Profile Before Review',
        ]);

        $parsed = [
            'core' => [
                'full_name' => 'Parsed Candidate',
                'date_of_birth' => '1996-05-04',
                'birth_time' => '10:15',
            ],
            'contacts' => [
                [
                    'phone_number' => '9876543210',
                    'relation_type' => 'self',
                    'contact_name' => 'Self',
                    'is_primary' => 1,
                ],
            ],
            'siblings' => [],
        ];

        $intake = BiodataIntake::create([
            'uploaded_by' => $member->id,
            'matrimony_profile_id' => $profile->id,
            'raw_ocr_text' => 'Original OCR text',
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
            'parsed_json' => $parsed,
            'approved_by_user' => false,
            'intake_locked' => false,
            'snapshot_schema_version' => 1,
        ]);

        $attempt = BiodataIntakeOcrAttempt::create([
            'intake_id' => $intake->id,
            'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
            'source' => 'mobile_app',
            'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
            'raw_text' => 'ML Kit evidence text',
            'normalized_text' => 'ML Kit evidence text',
        ]);

        $response = $this->actingAs($admin)->patch(route('admin.biodata-intakes.review-snapshot.update', $intake), [
            'snapshot' => [
                'core' => [
                    'full_name' => 'Corrected Candidate',
                    'date_of_birth' => '1996-05-04',
                    'birth_time' => '10:15',
                ],
                'contacts' => [
                    [
                        'phone_number' => '9876543210',
                        'relation_type' => 'self',
                        'contact_name' => 'Self',
                        'is_primary' => '1',
                    ],
                ],
                'unexpected_new_section' => [
                    'full_name' => 'Should be ignored',
                ],
            ],
        ]);

        $response
            ->assertRedirect(route('admin.biodata-intakes.show', $intake))
            ->assertSessionHas('success');

        $intake->refresh();
        $attempt->refresh();
        $profile->refresh();

        $this->assertSame('Corrected Candidate', data_get($intake->approval_snapshot_json, 'core.full_name'));
        $this->assertSame(IntakeHumanReviewSnapshotService::ACTOR_ADMIN, $intake->review_actor_type);
        $this->assertSame(IntakeHumanReviewSnapshotService::SURFACE_ADMIN_PANEL, $intake->review_surface);
        $this->assertSame(IntakeHumanReviewSnapshotService::STATUS_REVIEWED, $intake->approval_status);
        $this->assertSame((int) $admin->id, (int) $intake->reviewed_by_user_id);
        $this->assertNotNull($intake->reviewed_at);

        $this->assertSame('Parsed Candidate', data_get($intake->parsed_json, 'core.full_name'));
        $this->assertSame('Original OCR text', $intake->raw_ocr_text);
        $this->assertArrayNotHasKey('unexpected_new_section', $intake->approval_snapshot_json);
        $this->assertFalse((bool) $intake->approved_by_user);
        $this->assertNull($intake->approved_at);

        $this->assertSame(1, BiodataIntakeOcrAttempt::query()->where('intake_id', $intake->id)->count());
        $this->assertSame('ML Kit evidence text', $attempt->raw_text);
        $this->assertSame('Profile Before Review', $profile->full_name);
        $this->assertTrue(Route::has('admin.biodata-intakes.apply'));
    }
}
