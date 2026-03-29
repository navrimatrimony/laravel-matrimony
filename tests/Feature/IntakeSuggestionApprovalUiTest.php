<?php

namespace Tests\Feature;

use App\Models\BiodataIntake;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeSuggestionApprovalUiTest extends TestCase
{
    use RefreshDatabase;

    private function makeApprovedIntakeForUser(User $user, MatrimonyProfile $profile): BiodataIntake
    {
        return BiodataIntake::create([
            'raw_ocr_text' => 'test',
            'uploaded_by' => $user->id,
            'matrimony_profile_id' => $profile->id,
            'parse_status' => 'parsed',
            'intake_status' => 'approved',
            'approved_by_user' => true,
            'approved_at' => now(),
            'approval_snapshot_json' => ['snapshot_schema_version' => 1, 'core' => [], 'contacts' => []],
            'snapshot_schema_version' => 1,
            'intake_locked' => false,
        ]);
    }

    public function test_reject_clears_pending_and_does_not_change_profile(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'father_name' => 'A',
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
                'core_field_suggestions' => [
                    ['field' => 'father_name', 'old_value' => 'A', 'new_value' => 'B', 'source_intake_id' => 99],
                ],
            ],
        ]);
        $intake = $this->makeApprovedIntakeForUser($user, $profile);

        $this->actingAs($user)
            ->from(route('intake.status', $intake))
            ->post(route('intake.reject-suggestion', $intake), [
                'scope' => 'core',
                'field_key' => 'father_name',
            ])
            ->assertRedirect(route('intake.status', $intake))
            ->assertSessionHas('success', __('intake.suggestion_rejected_success'));

        $profile->refresh();
        $this->assertSame('A', $profile->father_name);
        $this->assertNull($profile->pending_intake_suggestions_json);
    }

    public function test_approve_applies_via_mutation_and_clears_pending(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'father_name' => 'A',
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
                'core_field_suggestions' => [
                    ['field' => 'father_name', 'old_value' => 'A', 'new_value' => 'B', 'source_intake_id' => 1],
                ],
            ],
        ]);
        $intake = $this->makeApprovedIntakeForUser($user, $profile);

        $this->actingAs($user)
            ->post(route('intake.apply-suggestion', $intake), [
                'scope' => 'core',
                'field_key' => 'father_name',
            ])
            ->assertRedirect(route('intake.status', $intake))
            ->assertSessionHas('success', __('intake.suggestion_approved_success'));

        $profile->refresh();
        $this->assertSame('B', $profile->father_name);
        $this->assertTrue(
            $profile->pending_intake_suggestions_json === null
            || empty($profile->pending_intake_suggestions_json['core']['father_name'] ?? null)
        );
    }

    public function test_reject_forbidden_for_other_users_intake(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $owner->id,
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
            ],
        ]);
        $intake = $this->makeApprovedIntakeForUser($owner, $profile);

        $this->actingAs($other)
            ->post(route('intake.reject-suggestion', $intake), [
                'scope' => 'core',
                'field_key' => 'father_name',
            ])
            ->assertForbidden();
    }

    public function test_status_page_shows_suggested_updates_copy_for_pending_core(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'father_name' => 'A',
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
                'core_field_suggestions' => [
                    ['field' => 'father_name', 'old_value' => 'A', 'new_value' => 'B', 'source_intake_id' => 1],
                ],
            ],
        ]);
        $intake = $this->makeApprovedIntakeForUser($user, $profile);

        $html = $this->actingAs($user)
            ->get(route('intake.status', $intake))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(__('intake.suggested_updates_section_title')), $html);
        $this->assertStringContainsString('A', $html);
        $this->assertStringContainsString('B', $html);
        $this->assertStringContainsString(e(__('intake.approve_suggestion_button')), $html);
        $this->assertStringContainsString(e(__('intake.reject_suggestion_button')), $html);
    }

    public function test_status_page_shows_no_pending_message_when_bucket_empty(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'pending_intake_suggestions_json' => null,
        ]);
        $intake = $this->makeApprovedIntakeForUser($user, $profile);

        $this->actingAs($user)
            ->get(route('intake.status', $intake))
            ->assertOk()
            ->assertSee(__('intake.no_pending_suggestions'), false);
    }
}
