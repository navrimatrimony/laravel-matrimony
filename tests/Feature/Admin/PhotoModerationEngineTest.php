<?php

namespace Tests\Feature\Admin;

use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Services\Admin\PhotoModerationAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoModerationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_profile_photos_for_moderation(): void
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

        $response = $this->actingAs($admin)->get(route('admin.photo-moderation.index'));

        $response->assertOk();
        $response->assertSee('Photo moderation engine', false);
        $response->assertSee((string) $profile->id, false);
    }

    public function test_index_shows_nudenet_detection_text_in_table(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $profile = MatrimonyProfile::factory()->create([
            'profile_photo' => 'primary.webp',
            'photo_approved' => false,
            'photo_rejected_at' => null,
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
            ->get(route('admin.photo-moderation.index'))
            ->assertOk()
            ->assertSee('FACE_FEMALE', false)
            ->assertSee('0.88', false);
    }

    public function test_preview_route_returns_404_without_file(): void
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
            ->get(route('admin.photo-moderation.preview', ['profile' => $profile, 'galleryPhoto' => $gp]))
            ->assertNotFound();
    }

    public function test_moderation_scan_treats_legacy_status_unsafe_as_unsafe(): void
    {
        $this->assertTrue(PhotoModerationAdminService::moderationScanIndicatesUnsafe([
            'status' => 'unsafe',
            'confidence' => 0.9,
            'detections' => [],
        ]));
    }

    public function test_cannot_approve_photo_when_scan_marked_unsafe(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $profile = MatrimonyProfile::factory()->create();
        $gp = ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'x.webp',
            'is_primary' => false,
            'uploaded_via' => 'user_web',
            'approved_status' => 'pending',
            'watermark_detected' => false,
            'moderation_scan_json' => [
                'api_status' => 'unsafe',
                'pipeline_confidence' => 0.88,
                'detections' => [['class' => 'FEMALE_GENITALIA_EXPOSED', 'score' => 0.9]],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.photo-moderation.action', $gp), [
                'action' => 'approve',
                'reason' => 'cannot approve unsafe — admin',
            ])
            ->assertSessionHasErrors();
    }

    public function test_panel_fragment_returns_audit_html(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $profile = MatrimonyProfile::factory()->create([
            'profile_photo' => 'primary.webp',
            'photo_approved' => false,
            'photo_rejected_at' => null,
        ]);
        $gp = ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'primary.webp',
            'is_primary' => true,
            'uploaded_via' => 'user_web',
            'approved_status' => 'pending',
            'watermark_detected' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.photo-moderation.panel', $gp))
            ->assertOk()
            ->assertSee('Photo #'.$gp->id, false)
            ->assertSee('Audit trail', false);
    }

    public function test_bulk_moderation_requires_reason_min_10_characters(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $profile = MatrimonyProfile::factory()->create();
        $gp = ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'x.webp',
            'is_primary' => false,
            'uploaded_via' => 'user_web',
            'approved_status' => 'pending',
            'watermark_detected' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.photo-moderation.bulk'), [
                'photo_ids' => [$gp->id],
                'action' => 'move_to_review',
                'reason' => 'short',
                'include_approved' => '0',
                'per_page' => 30,
            ])
            ->assertSessionHasErrors('reason');
    }
}
