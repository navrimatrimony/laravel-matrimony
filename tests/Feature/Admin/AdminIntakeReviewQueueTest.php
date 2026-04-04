<?php

namespace Tests\Feature\Admin;

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminIntakeReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_index_requires_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)
            ->get(route('admin.intake.index'))
            ->assertForbidden();
    }

    public function test_queue_index_lists_profile_with_pending(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        MatrimonyProfile::factory()->create([
            'user_id' => $owner->id,
            'full_name' => 'Queue Test',
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.intake.index'))
            ->assertOk()
            ->assertSee('Queue Test', false)
            ->assertSee('Intake suggestions queue', false);
    }

    public function test_approve_selected_applies_and_clears_pending(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $owner->id,
            'father_name' => 'A',
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
            ],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.intake.show', $profile))
            ->post(route('admin.intake.approve', $profile), [
                'fields' => ['core::father_name'],
            ])
            ->assertRedirect(route('admin.intake.show', $profile));

        $profile->refresh();
        $this->assertSame('B', $profile->father_name);
        $this->assertNull($profile->pending_intake_suggestions_json);
    }

    public function test_reject_selected_removes_pending_without_apply(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $owner->id,
            'father_name' => 'A',
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.intake.reject', $profile), [
                'fields' => ['core::father_name'],
            ])
            ->assertRedirect(route('admin.intake.index'));

        $profile->refresh();
        $this->assertSame('A', $profile->father_name);
        $this->assertNull($profile->pending_intake_suggestions_json);
    }

    public function test_clear_all_wipes_pending(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $owner->id,
            'pending_intake_suggestions_json' => [
                'core' => ['father_name' => 'B'],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.intake.clear', $profile))
            ->assertRedirect(route('admin.intake.index'));

        $profile->refresh();
        $this->assertNull($profile->pending_intake_suggestions_json);
    }
}
