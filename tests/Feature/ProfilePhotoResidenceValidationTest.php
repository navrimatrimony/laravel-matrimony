<?php

namespace Tests\Feature;

use App\Jobs\ProcessProfilePhoto;
use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Observers\MatrimonyProfileObserver;
use App\Services\Image\ImageModerationService;
use App\Services\Image\ImageProcessingService;
use App\Services\Image\ImageOptimizationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_normal_non_photo_save_without_residence_still_requires_residence(): void
    {
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Original Name',
        ]);
        $profile->forceFill([
            'lifecycle_state' => 'active',
            'location_id' => null,
        ])->saveQuietly();

        $profile->full_name = 'Changed Name';

        try {
            $profile->save();
            $this->fail('Normal profile save without residence should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Residence location is required.'],
                $exception->errors()['location_id'] ?? []
            );
        }
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

    public function test_process_profile_photo_allows_photo_only_moderation_save_without_residence(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Legacy Active Profile',
            'profile_photo' => 'pending/legacy-upload.jpg',
            'photo_approved' => false,
            'photo_rejected_at' => null,
            'photo_rejection_reason' => null,
        ]);
        $profile->forceFill([
            'lifecycle_state' => 'active',
            'location_id' => null,
        ])->saveQuietly();

        $tempPath = tempnam(sys_get_temp_dir(), 'profile-photo-test-');
        file_put_contents($tempPath, 'fake image bytes');

        $moderation = \Mockery::mock(ImageModerationService::class);
        $moderation->shouldReceive('moderateProfilePhoto')
            ->once()
            ->with($tempPath)
            ->andReturn([
                'status' => 'approved',
                'reason' => null,
                'meta' => [
                    'nudenet' => [
                        'safe' => true,
                        'confidence' => 0.99,
                        'raw' => [
                            'api_status' => 'safe',
                            'pipeline_confidence' => 0.99,
                            'detections' => [],
                        ],
                    ],
                ],
            ]);

        $optimizer = \Mockery::mock(ImageOptimizationService::class);
        $optimizer->shouldReceive('optimizeAndStoreProfilePhoto')
            ->once()
            ->with($tempPath, \Mockery::type('string'))
            ->andReturnUsing(function (): array {
                Storage::disk('public')->put('matrimony_photos/processed-no-residence.webp', 'processed');

                return [
                    'filename' => 'processed-no-residence.webp',
                    'relative_path' => 'matrimony_photos/processed-no-residence.webp',
                    'bytes' => 9,
                    'quality' => 82,
                ];
            });

        (new ProcessProfilePhoto($tempPath, (int) $profile->id))->handle($moderation, $optimizer);

        $profile->refresh();

        $this->assertSame('processed-no-residence.webp', $profile->profile_photo);
        $this->assertTrue((bool) $profile->photo_approved);
        $this->assertNull($profile->photo_rejected_at);
        $this->assertNull($profile->photo_rejection_reason);
        $this->assertSame('Legacy Active Profile', $profile->full_name);
        $this->assertSame('active', $profile->lifecycle_state);
        $this->assertNull($profile->location_id);
        $this->assertIsArray($profile->photo_moderation_snapshot);
        $this->assertSame('safe', $profile->photo_moderation_snapshot['api_status'] ?? null);

        $this->assertDatabaseHas('profile_photos', [
            'profile_id' => $profile->id,
            'file_path' => 'processed-no-residence.webp',
            'is_primary' => true,
            'approved_status' => 'approved',
        ]);
        $this->assertSame(1, ProfilePhoto::query()->where('profile_id', $profile->id)->count());
        Storage::disk('public')->assertExists('matrimony_photos/processed-no-residence.webp');
    }
}
