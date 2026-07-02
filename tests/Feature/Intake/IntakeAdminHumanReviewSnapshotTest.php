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

    public function test_admin_show_page_renders_quality_signals_and_low_confidence_marker(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);
        $member = User::factory()->create([
            'is_admin' => false,
            'admin_role' => null,
        ]);

        $intake = BiodataIntake::create([
            'uploaded_by' => $member->id,
            'raw_ocr_text' => 'Original OCR text',
            'last_parse_input_text' => "नाव : Parsed Candidate\nमोबाईल : 9876543210",
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
            'parsed_json' => [
                'core' => [
                    'full_name' => 'Parsed Candidate',
                    'date_of_birth' => '1996-05-04',
                ],
            ],
            'quality_summary_json' => [
                'score' => 0.42,
                'is_low' => true,
                'layout_score' => 0.5,
            ],
            'failure_codes_json' => [
                BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED,
            ],
            'field_confidence_json' => [
                'full_name' => [
                    'score' => 0.4,
                    'present' => true,
                    'source_path' => 'core.full_name',
                    'reason' => 'parsed_value_present',
                ],
                'date_of_birth' => [
                    'score' => 0.9,
                    'present' => true,
                    'source_path' => 'core.date_of_birth',
                    'reason' => 'parsed_value_present',
                ],
            ],
            'routing_recommendation_json' => [
                'mode' => 'dry_run',
                'recommended_action' => 'cheap_ocr_only',
                'reason_codes' => [
                    'high_quality_cheap_ocr',
                ],
                'confidence' => 0.91,
                'would_skip_paid_vision' => true,
                'would_call_paid_vision' => false,
                'signals' => [
                    'parse_status' => 'parsed',
                    'quality_score' => 0.91,
                    'cheap_ocr_attempt_count' => 2,
                    'sarvam_attempt_count' => 0,
                    'failed_provider_count' => 0,
                    'reuse_candidate_found' => false,
                ],
            ],
            'routing_telemetry_json' => [
                'mode' => 'dry_run',
                'sarvam_attempt_count' => 0,
                'cheap_ocr_attempt_count' => 2,
                'failed_provider_count' => 0,
                'reuse_candidate_found' => false,
                'last_quality_score' => 0.91,
                'last_layout_score' => 0.74,
                'duration_ms' => 345,
                'cost_units' => 0,
            ],
            'approved_by_user' => false,
            'intake_locked' => false,
            'snapshot_schema_version' => 1,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.biodata-intakes.show', $intake))
            ->assertOk()
            ->assertSee('data-testid="quality-signals-panel"', false)
            ->assertSee(BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED)
            ->assertSee('data-testid="low-confidence-field-core-full-name"', false)
            ->assertSee('Low confidence')
            ->assertSee('data-testid="routing-dry-run-panel"', false)
            ->assertSee('data-testid="routing-recommended-action"', false)
            ->assertSee('cheap_ocr_only')
            ->assertSee('data-testid="routing-reason-codes"', false)
            ->assertSee('high_quality_cheap_ocr')
            ->assertSee('data-testid="routing-would-skip-paid-vision"', false)
            ->assertSee('data-testid="routing-would-call-paid-vision"', false)
            ->assertSee('data-testid="routing-telemetry-cheap-ocr-attempt-count"', false)
            ->assertSee('Provider failures');
    }

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
            'quality_summary_json' => [
                'score' => 0.58,
                'is_low' => false,
            ],
            'failure_codes_json' => [
                BiodataIntakeOcrAttempt::FAILURE_LABEL_VALUE_SPLIT,
            ],
            'field_confidence_json' => [
                'full_name' => [
                    'score' => 0.4,
                    'present' => true,
                    'source_path' => 'core.full_name',
                    'reason' => 'parsed_value_present',
                ],
            ],
            'routing_recommendation_json' => [
                'mode' => 'dry_run',
                'recommended_action' => 'manual_review',
                'reason_codes' => [
                    'provider_error',
                ],
                'confidence' => 0.68,
                'would_skip_paid_vision' => false,
                'would_call_paid_vision' => false,
                'signals' => [
                    'parse_status' => 'parsed',
                    'failed_provider_count' => 1,
                ],
            ],
            'routing_telemetry_json' => [
                'mode' => 'dry_run',
                'sarvam_attempt_count' => 1,
                'cheap_ocr_attempt_count' => 1,
                'failed_provider_count' => 1,
                'reuse_candidate_found' => false,
                'last_provider_failure_code' => BiodataIntakeOcrAttempt::FAILURE_PROVIDER_TIMEOUT,
            ],
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
        $this->assertSame([BiodataIntakeOcrAttempt::FAILURE_LABEL_VALUE_SPLIT], $intake->failure_codes_json);
        $this->assertSame('manual_review', data_get($intake->routing_recommendation_json, 'recommended_action'));
        $this->assertSame(BiodataIntakeOcrAttempt::FAILURE_PROVIDER_TIMEOUT, data_get($intake->routing_telemetry_json, 'last_provider_failure_code'));
        $this->assertArrayNotHasKey('unexpected_new_section', $intake->approval_snapshot_json);
        $this->assertFalse((bool) $intake->approved_by_user);
        $this->assertNull($intake->approved_at);

        $this->assertSame(1, BiodataIntakeOcrAttempt::query()->where('intake_id', $intake->id)->count());
        $this->assertSame('ML Kit evidence text', $attempt->raw_text);
        $this->assertSame('Profile Before Review', $profile->full_name);
        $this->assertTrue(Route::has('admin.biodata-intakes.apply'));
    }
}
