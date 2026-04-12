<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Services\Image\MatrimonyPhotoStoragePathService;
use App\Services\ProfilePhotoAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePhotoOwnProfileAlbumTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_primary_slot_when_core_photo_approved_is_false_pending_review(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->for($user)->create([
            'profile_photo' => 'pending/legacy.jpg',
            'photo_approved' => false,
            'photo_rejected_at' => null,
        ]);

        $rel = MatrimonyPhotoStoragePathService::nestedRelativePathForNewFile((int) $profile->id, 'leaf.webp');
        $dir = storage_path('app/public/matrimony_photos/'.$rel);
        if (! is_dir(dirname($dir))) {
            mkdir(dirname($dir), 0755, true);
        }
        file_put_contents($dir, 'x');

        try {
            ProfilePhoto::query()->create([
                'profile_id' => $profile->id,
                'file_path' => $rel,
                'is_primary' => true,
                'sort_order' => 0,
                'uploaded_via' => 'user_web',
                'approved_status' => 'approved',
                'watermark_detected' => false,
            ]);

            $pres = app(ProfilePhotoAccessService::class)->buildAlbumPresentation(
                $user,
                $profile->fresh(),
                true,
                collect()
            );

            $this->assertNotEmpty($pres['slots'], 'owner should get at least one album slot while photo is pending admin');
            $this->assertStringContainsString('storage/matrimony_photos/'.$rel, $pres['slots'][0]['url']);
        } finally {
            if (is_file($dir)) {
                @unlink($dir);
            }
            $p = dirname($dir);
            for ($i = 0; $i < 6 && $p !== false && str_contains($p, 'matrimony_photos'); $i++) {
                @rmdir($p);
                $p = dirname($p);
            }
        }
    }
}
