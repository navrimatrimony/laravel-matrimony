<?php

namespace Tests\Feature\Admin;

use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_shows_primary_row_and_separate_gallery_pending_row(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $profile = MatrimonyProfile::factory()->create([
            'profile_photo' => 'primary.webp',
            'photo_approved' => false,
            'photo_rejected_at' => null,
        ]);

        ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'primary.webp',
            'is_primary' => true,
            'uploaded_via' => 'user_web',
            'approved_status' => 'pending',
            'watermark_detected' => false,
        ]);

        ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'extra.webp',
            'is_primary' => false,
            'uploaded_via' => 'user_web',
            'approved_status' => 'pending',
            'watermark_detected' => false,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.photo-review-queue.index'));

        $response->assertOk();
        $response->assertSee('Primary');
        $response->assertSee('Gallery');
    }

    public function test_primary_row_shows_nudenet_scan_from_primary_gallery_row_when_profile_snapshot_empty(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $profile = MatrimonyProfile::factory()->create([
            'profile_photo' => 'primary.webp',
            'photo_approved' => false,
            'photo_rejected_at' => null,
            'photo_moderation_snapshot' => null,
        ]);

        $scanPayload = [
            'scanner' => 'nudenet',
            'api_status' => 'safe',
            'pipeline_safe' => true,
            'pipeline_confidence' => 0.9123,
            'detections' => [['class' => 'FACE_FEMALE', 'score' => 0.88]],
        ];

        ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'primary.webp',
            'is_primary' => true,
            'uploaded_via' => 'user_web',
            'approved_status' => 'pending',
            'watermark_detected' => false,
            'moderation_scan_json' => $scanPayload,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.photo-review-queue.index'))
            ->assertOk()
            ->assertSee('FACE_FEMALE', false)
            ->assertSee('0.8800', false);
    }

    public function test_gallery_preview_route_returns_404_without_file(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $profile = MatrimonyProfile::factory()->create([
            'profile_photo' => 'primary.webp',
            'photo_approved' => false,
        ]);
        $gp = ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'missing.webp',
            'is_primary' => false,
            'uploaded_via' => 'user_web',
            'approved_status' => 'pending',
            'watermark_detected' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.photo-review-queue.preview', ['profile' => $profile, 'galleryPhoto' => $gp]))
            ->assertNotFound();
    }
}
