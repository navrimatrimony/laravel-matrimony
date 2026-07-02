<?php

namespace Tests\Feature\Suchak;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Services\Intake\IntakeHumanReviewSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuchakBiodataReviewSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_suchak_can_save_reviewed_snapshot_for_linked_intake_without_mutating_profile_or_evidence(): void
    {
        [$user, $account] = $this->verifiedSuchak();
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Profile Before Review',
        ]);
        $parsed = [
            'snapshot_schema_version' => 1,
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
        ];
        $intake = $this->intakeForUser($user, [
            'matrimony_profile_id' => $profile->id,
            'parsed_json' => $parsed,
        ]);
        SuchakBiodataIntakeLink::query()->create([
            'suchak_account_id' => $account->id,
            'biodata_intake_id' => $intake->id,
            'matrimony_profile_id' => $profile->id,
            'source_status' => SuchakBiodataIntakeLink::STATUS_REVIEW_PENDING,
            'created_by_user_id' => $user->id,
        ]);
        $attempt = BiodataIntakeOcrAttempt::create([
            'intake_id' => $intake->id,
            'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
            'source' => 'mobile_app',
            'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
            'raw_text' => 'ML Kit evidence text',
            'normalized_text' => 'ML Kit evidence text',
        ]);

        $response = $this->actingAs($user)->patchJson(route('suchak.intakes.review-snapshot.update', $intake), [
            'reviewed_snapshot' => [
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
                        'is_primary' => 1,
                    ],
                ],
                'unsupported_section' => [
                    'full_name' => 'Should be ignored',
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('intake_id', $intake->id)
            ->assertJsonPath('approval_status', IntakeHumanReviewSnapshotService::STATUS_REVIEWED)
            ->assertJsonPath('review_actor_type', IntakeHumanReviewSnapshotService::ACTOR_SUCHAK)
            ->assertJsonPath('review_surface', IntakeHumanReviewSnapshotService::SURFACE_WEBSITE)
            ->assertJsonPath('approval_snapshot.core.full_name', 'Corrected Candidate');

        $intake->refresh();
        $attempt->refresh();
        $profile->refresh();

        $this->assertSame('Corrected Candidate', data_get($intake->approval_snapshot_json, 'core.full_name'));
        $this->assertSame(IntakeHumanReviewSnapshotService::ACTOR_SUCHAK, $intake->review_actor_type);
        $this->assertSame(IntakeHumanReviewSnapshotService::SURFACE_WEBSITE, $intake->review_surface);
        $this->assertSame((int) $user->id, (int) $intake->reviewed_by_user_id);
        $this->assertSame(IntakeHumanReviewSnapshotService::POLICY_PHASE2D_SUCHAK_REVIEW_V1, $intake->approval_policy);
        $this->assertSame(IntakeHumanReviewSnapshotService::STATUS_REVIEWED, $intake->approval_status);
        $this->assertNotNull($intake->reviewed_at);
        $this->assertSame('Parsed Candidate', data_get($intake->parsed_json, 'core.full_name'));
        $this->assertSame('Original OCR text', $intake->raw_ocr_text);
        $this->assertArrayNotHasKey('unsupported_section', $intake->approval_snapshot_json);
        $this->assertFalse((bool) $intake->approved_by_user);
        $this->assertNull($intake->approved_at);
        $this->assertSame(1, BiodataIntakeOcrAttempt::query()->where('intake_id', $intake->id)->count());
        $this->assertSame('ML Kit evidence text', $attempt->raw_text);
        $this->assertSame('Profile Before Review', $profile->full_name);
    }

    public function test_authorized_suchak_can_save_reviewed_snapshot_for_represented_intake(): void
    {
        [$user, $account] = $this->verifiedSuchak();
        $profile = MatrimonyProfile::factory()->create();
        $intake = $this->intakeForUser($user);
        SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'biodata_intake_id' => $intake->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
        ]);

        $this->actingAs($user)->patchJson(route('suchak.intakes.review-snapshot.update', $intake), [
            'reviewed_snapshot' => [
                'core' => [
                    'full_name' => 'Represented Candidate',
                ],
            ],
        ])->assertOk();

        $intake->refresh();
        $this->assertSame('Represented Candidate', data_get($intake->approval_snapshot_json, 'core.full_name'));
        $this->assertSame(IntakeHumanReviewSnapshotService::ACTOR_SUCHAK, $intake->review_actor_type);
    }

    public function test_unrelated_suchak_cannot_save_reviewed_snapshot(): void
    {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchak();
        [$otherUser] = $this->verifiedSuchak();
        $intake = $this->intakeForUser($ownerUser);
        SuchakBiodataIntakeLink::query()->create([
            'suchak_account_id' => $ownerAccount->id,
            'biodata_intake_id' => $intake->id,
            'source_status' => SuchakBiodataIntakeLink::STATUS_REVIEW_PENDING,
            'created_by_user_id' => $ownerUser->id,
        ]);

        $this->actingAs($otherUser)->patchJson(route('suchak.intakes.review-snapshot.update', $intake), [
            'reviewed_snapshot' => [
                'core' => [
                    'full_name' => 'Wrong Suchak Edit',
                ],
            ],
        ])->assertForbidden();

        $intake->refresh();
        $this->assertNull($intake->approval_snapshot_json);
        $this->assertNull($intake->review_actor_type);
        $this->assertSame('Parsed Candidate', data_get($intake->parsed_json, 'core.full_name'));
        $this->assertSame('Original OCR text', $intake->raw_ocr_text);
    }

    public function test_normal_profile_user_cannot_use_suchak_review_snapshot_route(): void
    {
        $owner = User::factory()->create();
        $intake = $this->intakeForUser($owner);

        $this->actingAs($owner)->patchJson(route('suchak.intakes.review-snapshot.update', $intake), [
            'reviewed_snapshot' => [
                'core' => [
                    'full_name' => 'Normal User Edit',
                ],
            ],
        ])->assertForbidden();

        $intake->refresh();
        $this->assertNull($intake->approval_snapshot_json);
        $this->assertNull($intake->review_actor_type);
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchak(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'verified_at' => now(),
        ]);

        return [$user, $account];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function intakeForUser(User $user, array $overrides = []): BiodataIntake
    {
        return BiodataIntake::create(array_merge([
            'uploaded_by' => $user->id,
            'raw_ocr_text' => 'Original OCR text',
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
            'parsed_json' => [
                'snapshot_schema_version' => 1,
                'core' => [
                    'full_name' => 'Parsed Candidate',
                    'date_of_birth' => '1996-05-04',
                ],
            ],
            'parser_version' => 'rules_only',
            'snapshot_schema_version' => 1,
            'approved_by_user' => false,
            'intake_locked' => false,
        ], $overrides));
    }
}
