<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Services\Image\ProfilePhotoUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePhotoStalePendingUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_url_uses_primary_gallery_when_core_column_is_stale_pending(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->for($user)->create([
            'profile_photo' => 'pending/31f9df6b-04b3-484a-a56d-a58fd57d83e9.jpg',
            'photo_approved' => true,
        ]);

        $final = 'bf30fe42-b22b-4699-a4cc-ec441402427f.webp';
        $dir = storage_path('app/public/matrimony_photos');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $abs = $dir.DIRECTORY_SEPARATOR.$final;
        file_put_contents($abs, 'fake-webp');

        try {
            ProfilePhoto::query()->create([
                'profile_id' => $profile->id,
                'file_path' => $final,
                'is_primary' => true,
                'sort_order' => 0,
                'uploaded_via' => 'user_web',
                'approved_status' => 'approved',
                'watermark_detected' => false,
            ]);

            $url = app(ProfilePhotoUrlService::class)->publicUrl($profile->profile_photo, $profile);

            $this->assertStringContainsString('storage/matrimony_photos/'.$final, $url);
        } finally {
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
    }
}
