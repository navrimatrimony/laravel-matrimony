<?php

namespace Tests\Feature\Admin;

use App\Models\BiodataIntake;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSuggestionReviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeIntake(User $user, MatrimonyProfile $profile): BiodataIntake
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
            'parsed_json' => ['confidence_map' => ['father_name' => 0.9]],
        ]);
    }

    /**
     * @param  array<string, array{decision: string, expected_current: string}>  $decisions
     */
    private function reviewPayload(array $decisions): string
    {
        return json_encode(['decisions' => $decisions], JSON_UNESCAPED_UNICODE);
    }

    public function test_review_page_ok_for_admin_with_pending(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'father_name' => 'A',
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
            ],
        ]);
        $intake = $this->makeIntake($user, $profile);

        $this->actingAs($admin)
            ->get(route('admin.suggestions.review', $intake))
            ->assertOk()
            ->assertSee('Current', false)
            ->assertSee('Incoming', false)
            ->assertSee('father_name', false)
            ->assertSee('Apply reviewed changes', false);
    }

    public function test_apply_accept_uses_mutation_and_clears_pending_when_expected_matches_db(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'father_name' => 'A',
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
            ],
        ]);
        $intake = $this->makeIntake($user, $profile);

        $this->actingAs($admin)
            ->from(route('admin.suggestions.review', $intake))
            ->post(route('admin.suggestions.review.apply', $intake), [
                'review_payload' => $this->reviewPayload([
                    'core::father_name' => ['decision' => 'accept', 'expected_current' => 'A'],
                ]),
            ])
            ->assertRedirect(route('admin.suggestions.review', $intake));

        $profile->refresh();
        $this->assertSame('B', $profile->father_name);
        $this->assertTrue(
            $profile->pending_intake_suggestions_json === null
            || empty($profile->pending_intake_suggestions_json['core']['father_name'] ?? null)
        );
    }

    public function test_apply_reject_removes_pending_without_changing_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'father_name' => 'A',
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
            ],
        ]);
        $intake = $this->makeIntake($user, $profile);

        $this->actingAs($admin)
            ->post(route('admin.suggestions.review.apply', $intake), [
                'review_payload' => $this->reviewPayload([
                    'core::father_name' => ['decision' => 'reject', 'expected_current' => 'A'],
                ]),
            ])
            ->assertRedirect(route('admin.suggestions.review', $intake));

        $profile->refresh();
        $this->assertSame('A', $profile->father_name);
        $this->assertNull($profile->pending_intake_suggestions_json);
    }

    public function test_apply_accept_with_stale_expected_creates_system_conflict_and_keeps_pending(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'father_name' => 'A',
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
            ],
        ]);
        $intake = $this->makeIntake($user, $profile);

        $this->actingAs($admin)
            ->post(route('admin.suggestions.review.apply', $intake), [
                'review_payload' => $this->reviewPayload([
                    'core::father_name' => ['decision' => 'accept', 'expected_current' => 'StaleWrong'],
                ]),
            ])
            ->assertRedirect(route('admin.suggestions.review', $intake));

        $profile->refresh();
        $this->assertSame('A', $profile->father_name);
        $this->assertSame('B', $profile->pending_intake_suggestions_json['core']['father_name'] ?? null);

        $this->assertDatabaseHas('conflict_records', [
            'profile_id' => $profile->id,
            'field_name' => 'father_name',
            'source' => 'SYSTEM',
            'resolution_status' => 'PENDING',
        ]);
    }

    public function test_apply_reject_with_stale_expected_creates_system_conflict_and_keeps_pending(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'father_name' => 'A',
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
            ],
        ]);
        $intake = $this->makeIntake($user, $profile);

        $this->actingAs($admin)
            ->post(route('admin.suggestions.review.apply', $intake), [
                'review_payload' => $this->reviewPayload([
                    'core::father_name' => ['decision' => 'reject', 'expected_current' => 'NotA'],
                ]),
            ])
            ->assertRedirect(route('admin.suggestions.review', $intake));

        $profile->refresh();
        $this->assertSame('A', $profile->father_name);
        $this->assertSame('B', $profile->pending_intake_suggestions_json['core']['father_name'] ?? null);

        $this->assertDatabaseHas('conflict_records', [
            'profile_id' => $profile->id,
            'field_name' => 'father_name',
            'source' => 'SYSTEM',
        ]);
    }

    public function test_apply_flag_creates_admin_conflict_and_clears_pending(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'father_name' => 'A',
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
            ],
        ]);
        $intake = $this->makeIntake($user, $profile);

        $this->actingAs($admin)
            ->post(route('admin.suggestions.review.apply', $intake), [
                'review_payload' => $this->reviewPayload([
                    'core::father_name' => ['decision' => 'flag', 'expected_current' => 'A'],
                ]),
            ])
            ->assertRedirect(route('admin.suggestions.review', $intake));

        $profile->refresh();
        $this->assertSame('A', $profile->father_name);
        $this->assertNull($profile->pending_intake_suggestions_json);

        $this->assertDatabaseHas('conflict_records', [
            'profile_id' => $profile->id,
            'field_name' => 'father_name',
            'source' => 'ADMIN',
            'resolution_status' => 'PENDING',
        ]);
    }

    public function test_apply_payload_missing_expected_current_returns_422(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'father_name' => 'A',
            'pending_intake_suggestions_json' => ['core' => ['father_name' => 'B']],
        ]);
        $intake = $this->makeIntake($user, $profile);

        $this->actingAs($admin)
            ->post(route('admin.suggestions.review.apply', $intake), [
                'review_payload' => json_encode([
                    'decisions' => [
                        'core::father_name' => ['decision' => 'accept'],
                    ],
                ]),
            ])
            ->assertStatus(422);
    }

    public function test_apply_payload_invalid_decision_returns_422(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'father_name' => 'A',
            'pending_intake_suggestions_json' => ['core' => ['father_name' => 'B']],
        ]);
        $intake = $this->makeIntake($user, $profile);

        $this->actingAs($admin)
            ->post(route('admin.suggestions.review.apply', $intake), [
                'review_payload' => json_encode([
                    'decisions' => [
                        'core::father_name' => ['decision' => 'maybe', 'expected_current' => 'A'],
                    ],
                ]),
            ])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_access_review(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $owner = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $owner->id,
            'pending_intake_suggestions_json' => ['core' => ['father_name' => 'B']],
        ]);
        $intake = $this->makeIntake($owner, $profile);

        $this->actingAs($user)
            ->get(route('admin.suggestions.review', $intake))
            ->assertForbidden();
    }
}
