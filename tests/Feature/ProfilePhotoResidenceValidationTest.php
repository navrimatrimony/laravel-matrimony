<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Observers\MatrimonyProfileObserver;
use App\Services\Image\ImageProcessingService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProfilePhotoResidenceValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ProfileCanonicalResidenceService::forgetCachedMasters();
        MatrimonyProfile::$bypassGovernanceEnforcement = false;
    }

    protected function tearDown(): void
    {
        MatrimonyProfile::$bypassGovernanceEnforcement = false;
        ProfileCanonicalResidenceService::forgetCachedMasters();

        parent::tearDown();
    }

    public function test_active_profile_still_requires_residence_without_bypass(): void
    {
        $profile = MatrimonyProfile::factory()->create();
        $profile->forceFill(['lifecycle_state' => 'active'])->saveQuietly();

        $this->expectException(ValidationException::class);

        app(MatrimonyProfileObserver::class)->saving($profile);
    }

    public function test_bypass_does_not_skip_residence_for_non_photo_changes(): void
    {
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Original Name',
        ]);
        $profile->forceFill(['lifecycle_state' => 'active'])->saveQuietly();
        $profile->full_name = 'Changed Name';

        MatrimonyProfile::$bypassGovernanceEnforcement = true;

        $this->expectException(ValidationException::class);

        app(MatrimonyProfileObserver::class)->saving($profile);
    }

    public function test_photo_primary_update_does_not_require_residence_when_save_is_bypassed(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'profile_photo' => 'old-primary.webp',
            'photo_approved' => true,
        ]);
        $profile->forceFill(['lifecycle_state' => 'active'])->saveQuietly();

        $photo = ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'new-primary.webp',
            'is_primary' => false,
            'uploaded_via' => 'user_web',
            'approved_status' => 'approved',
            'watermark_detected' => false,
        ]);

        $this->actingAs($user)
            ->post(route('matrimony.profile.photos.make-primary', $photo))
            ->assertRedirect(route('matrimony.profile.upload-photo'))
            ->assertSessionDoesntHaveErrors(['location_id']);

        $profile->refresh();
        $photo->refresh();

        $this->assertSame('new-primary.webp', $profile->profile_photo);
        $this->assertTrue((bool) $profile->photo_approved);
        $this->assertTrue((bool) $photo->is_primary);
    }

    public function test_first_photo_upload_does_not_require_residence_for_photo_only_profile_save(): void
    {
        $this->mock(ImageProcessingService::class, function ($mock): void {
            $mock->shouldReceive('enqueueProfilePhotoProcessing')
                ->once()
                ->andReturn('pending/no-residence-first-upload.jpg');
        });

        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
        $profile->forceFill(['lifecycle_state' => 'active'])->saveQuietly();

        $this->actingAs($user)
            ->post(route('matrimony.profile.store-photo'), [
                'profile_photo' => UploadedFile::fake()->image('first-upload.jpg', 640, 640),
            ])
            ->assertRedirect(route('matrimony.profile.upload-photo'))
            ->assertSessionDoesntHaveErrors(['location_id']);

        $profile->refresh();

        $this->assertSame('pending/no-residence-first-upload.jpg', $profile->profile_photo);
    }
}
