<?php

namespace Tests\Feature\Admin;

use App\Models\AdminSetting;
use App\Models\PhotoLearningDataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModerationLearningPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_moderation_learning_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        PhotoLearningDataset::query()->create([
            'profile_photo_id' => null,
            'moderation_scan_json' => [
                'status' => 'review',
                'confidence' => 0.65,
                'detections' => [
                    ['class' => 'CHEST_COVERED', 'score' => 0.62],
                ],
            ],
            'final_decision' => 'approved',
            'admin_id' => $admin->id,
        ]);

        AdminSetting::setValue('moderation_nsfw_score_min', '0.5');

        $this->actingAs($admin)
            ->get(route('admin.moderation-learning.index'))
            ->assertOk()
            ->assertSee('CHEST_COVERED', false)
            ->assertSee('Moderation learning analytics', false);
    }
}
