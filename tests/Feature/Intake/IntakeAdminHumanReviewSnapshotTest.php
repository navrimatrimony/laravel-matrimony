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

    public function test_admin_show_page_renders_smart_routing_dry_run_panel_from_stored_json(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);
        $member = User::factory()->create([
            'is_admin' => false,
            'admin_role' => null,
        ]);

        $routingRecommendation = [
            'mode' => 'dry_run',
            'recommended_action' => 'manual_review',
            'reason_codes' => [
                'critical_field_parser_proposal_available',
                'paid_vision_not_required_due_to_parser_proposal',
            ],
            'confidence' => 0.82,
            'would_skip_paid_vision' => false,
            'would_call_paid_vision' => false,
            'policy' => [
                'enabled' => false,
                'dry_run_only' => true,
                'allowed_live_action' => null,
                'blocked_reason' => 'routing_disabled',
            ],
            'signals' => [
                'field_confidence_routing_severity' => 'critical',
                'low_confidence_critical_fields' => ['primary_contact_number', 'date_of_birth'],
                'low_confidence_important_fields' => ['highest_education'],
                'critical_field_parser_proposal_outcome' => 'parser_improvement_candidate',
                'estimated_paid_vision_avoidable' => true,
                'missing_critical_fields_resolved_by_proposal' => true,
                'has_ambiguous_critical_proposal' => false,
                'critical_field_parser_raw_evidence_absent_fields' => ['full_name'],
                'raw_ocr_text' => 'SECRET RAW OCR LINE 9876543210',
                'primary_contact_number' => '9876543210',
                'candidate_name' => 'Secret Candidate Name',
                'full_address' => '123 Secret Full Address',
                'provider_payload' => '{"api_key":"provider-secret-value"}',
                'content_hash' => 'abcdef1234567890abcdef1234567890',
            ],
        ];

        $intake = BiodataIntake::create([
            'uploaded_by' => $member->id,
            'raw_ocr_text' => 'Safe OCR placeholder',
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
            'parsed_json' => [
                'core' => [
                    'date_of_birth' => null,
                ],
            ],
            'quality_summary_json' => [
                'score' => 0.91,
            ],
            'failure_codes_json' => [],
            'field_confidence_json' => [
                'primary_contact_number' => [
                    'score' => 0.2,
                    'present' => false,
                    'reason' => 'missing',
                ],
            ],
            'routing_recommendation_json' => $routingRecommendation,
            'approved_by_user' => false,
            'intake_locked' => false,
            'snapshot_schema_version' => 1,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.biodata-intakes.show', $intake))
            ->assertOk()
            ->assertSee('data-testid="routing-dry-run-panel"', false)
            ->assertSee('Smart Routing Dry Run')
            ->assertSee('data-testid="routing-recommended-action"', false)
            ->assertSee('manual_review')
            ->assertSee('critical_field_parser_proposal_available')
            ->assertSee('paid_vision_not_required_due_to_parser_proposal')
            ->assertSee('data-testid="routing-parser-proposal-outcome"', false)
            ->assertSee('parser_improvement_candidate')
            ->assertSee('data-testid="routing-paid-vision-avoidable"', false)
            ->assertSee('data-testid="routing-parser-proposal-resolved"', false)
            ->assertSee('Review parser proposal')
            ->assertSee('data-testid="routing-policy-enabled"', false)
            ->assertSee('data-testid="routing-policy-dry-run-only"', false)
            ->assertSee('data-testid="routing-policy-blocked-reason"', false)
            ->assertSee('routing_disabled')
            ->assertSee('data-testid="routing-field-confidence-severity"', false)
            ->assertSee('critical')
            ->assertSee('primary_contact_number')
            ->assertSee('highest_education')
            ->assertSee('full_name');

        $response
            ->assertDontSee('SECRET RAW OCR LINE')
            ->assertDontSee('9876543210')
            ->assertDontSee('Secret Candidate Name')
            ->assertDontSee('123 Secret Full Address')
            ->assertDontSee('provider-secret-value')
            ->assertDontSee('abcdef1234567890abcdef1234567890');

        $intake->refresh();

        $this->assertSame($routingRecommendation, $intake->routing_recommendation_json);
        $this->assertSame('Safe OCR placeholder', $intake->raw_ocr_text);
        $this->assertSame('parsed', $intake->parse_status);
        $this->assertSame(0, BiodataIntakeOcrAttempt::query()->where('intake_id', $intake->id)->count());
    }

    public function test_admin_show_page_still_works_when_routing_json_is_missing(): void
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
            'raw_ocr_text' => '',
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
            'parsed_json' => [
                'core' => [
                    'full_name' => 'Parsed Candidate',
                ],
            ],
            'routing_recommendation_json' => null,
            'approved_by_user' => false,
            'intake_locked' => false,
            'snapshot_schema_version' => 1,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.biodata-intakes.show', $intake))
            ->assertOk()
            ->assertSee('data-testid="routing-dry-run-panel"', false)
            ->assertSee('data-testid="routing-dry-run-empty"', false)
            ->assertSee('No stored smart routing dry-run recommendation.');
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
