<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use App\Services\Image\ImageModerationService;
use App\Services\Image\ImageOptimizationService;
use App\Services\Intake\IntakePhotoCandidateApplyService;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntakePhotoCandidateApplyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('intake-photo-candidates');
        Storage::disk('public')->deleteDirectory('matrimony_photos');

        parent::tearDown();
    }

    private function createIntake(User $user, ?MatrimonyProfile $profile = null): BiodataIntake
    {
        $parsed = app(IntakeParsedSnapshotSkeleton::class)->ensure([
            'core' => [
                'full_name' => 'Candidate Apply Test',
                'gender' => 'male',
                'date_of_birth' => '1995-01-01',
                'religion' => 'Hindu',
                'caste' => 'Maratha',
                'primary_contact_number' => '9876543210',
            ],
            'contacts' => [
                ['phone_number' => '9876543210', 'is_primary' => true],
            ],
        ]);

        return BiodataIntake::create([
            'raw_ocr_text' => 'Raw OCR text remains immutable.',
            'last_parse_input_text' => 'Candidate Apply Test biodata.',
            'uploaded_by' => $user->id,
            'matrimony_profile_id' => $profile?->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
            'parsed_json' => $parsed,
            'approval_snapshot_json' => [
                'snapshot_schema_version' => 1,
                'core' => ['full_name' => 'Candidate Apply Test'],
                'contacts' => [],
            ],
        ]);
    }

    private function putCandidate(BiodataIntake $intake): void
    {
        Storage::disk('local')->put('intake-photo-candidates/'.$intake->id.'/candidate.jpg', $this->jpegBinary(180, 180));
    }

    private function jpegBinary(int $width, int $height): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is required for intake photo candidate apply tests.');
        }

        $image = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($image, 210, 230, 250);
        imagefill($image, 0, 0, $bg);

        ob_start();
        imagejpeg($image, null, 88);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $binary;
    }

    private function fakePhotoPipeline(): void
    {
        config(['photo_processing.force_direct_handle' => true]);

        $this->mock(ImageModerationService::class, function ($mock): void {
            $mock->shouldReceive('moderateProfilePhoto')
                ->andReturn(['status' => 'approved', 'reason' => null]);
        });

        $this->mock(ImageOptimizationService::class, function ($mock): void {
            $mock->shouldReceive('optimizeAndStoreProfilePhoto')
                ->andReturnUsing(function (string $path, string $base): array {
                    Storage::disk('public')->put('matrimony_photos/'.$base.'.webp', 'optimized');

                    return [
                        'filename' => $base.'.webp',
                        'relative_path' => 'matrimony_photos/'.$base.'.webp',
                        'bytes' => 9,
                    ];
                });
        });
    }

    public function test_apply_noops_when_candidate_crop_is_missing(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
        $intake = $this->createIntake($user, $profile);

        AdminSetting::setValue('intake_photo_apply_as_profile_photo', '1');

        $result = app(IntakePhotoCandidateApplyService::class)
            ->applyAfterSuccessfulIntakeMutation($intake, (int) $profile->id);

        $this->assertNull($result);
        $this->assertSame(0, ProfilePhoto::query()->where('profile_id', $profile->id)->count());
    }

    public function test_candidate_crop_enters_photo_pipeline_and_becomes_primary_when_profile_has_no_photos(): void
    {
        $this->fakePhotoPipeline();

        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
        $intake = $this->createIntake($user, $profile);
        $this->putCandidate($intake);

        AdminSetting::setValue('intake_photo_apply_as_profile_photo', '1');

        app(IntakePhotoCandidateApplyService::class)
            ->applyAfterSuccessfulIntakeMutation($intake, (int) $profile->id);

        $photo = ProfilePhoto::query()->where('profile_id', $profile->id)->first();

        $this->assertNotNull($photo);
        $this->assertSame('intake_crop', $photo->uploaded_via);
        $this->assertTrue((bool) $photo->is_primary);
        $profile->refresh();
        $this->assertSame($photo->file_path, $profile->profile_photo);
    }

    public function test_candidate_crop_does_not_replace_existing_user_primary_photo(): void
    {
        $this->fakePhotoPipeline();

        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'profile_photo' => 'existing-user.webp',
        ]);
        $existing = ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'existing-user.webp',
            'is_primary' => true,
            'uploaded_via' => 'user_web',
            'approved_status' => 'approved',
            'watermark_detected' => false,
        ]);
        $intake = $this->createIntake($user, $profile);
        $this->putCandidate($intake);

        AdminSetting::setValue('intake_photo_apply_as_profile_photo', '1');

        app(IntakePhotoCandidateApplyService::class)
            ->applyAfterSuccessfulIntakeMutation($intake, (int) $profile->id);

        $existing->refresh();
        $candidate = ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->where('uploaded_via', 'intake_crop')
            ->first();

        $this->assertTrue((bool) $existing->is_primary);
        $this->assertNotNull($candidate);
        $this->assertFalse((bool) $candidate->is_primary);
        $profile->refresh();
        $this->assertSame('existing-user.webp', $profile->profile_photo);
    }

    public function test_later_user_upload_replaces_intake_crop_primary_when_policy_allows_it(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'profile_photo' => 'intake-crop.webp',
        ]);
        $intakePrimary = ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'intake-crop.webp',
            'is_primary' => true,
            'uploaded_via' => 'intake_crop',
            'approved_status' => 'approved',
            'watermark_detected' => false,
        ]);

        AdminSetting::setValue('intake_photo_later_upload_primary_policy', 'new_upload_primary');
        $this->mock(ImageModerationService::class, function ($mock): void {
            $mock->shouldReceive('moderateProfilePhoto')->andReturn(['status' => 'approved']);
        });

        $this->actingAs($user)
            ->post(route('matrimony.profile.upload-photo'), [
                'profile_photo' => UploadedFile::fake()->image('later.jpg', 200, 200),
            ])
            ->assertRedirect();

        $intakePrimary->refresh();
        $userPhoto = ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->where('uploaded_via', 'user_web')
            ->first();

        $this->assertFalse((bool) $intakePrimary->is_primary);
        $this->assertNotNull($userPhoto);
        $this->assertTrue((bool) $userPhoto->is_primary);
        $profile->refresh();
        $this->assertSame($userPhoto->file_path, $profile->profile_photo);
    }

    public function test_later_user_upload_keeps_intake_crop_primary_when_policy_says_keep(): void
    {
        $user = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'profile_photo' => 'intake-crop.webp',
        ]);
        $intakePrimary = ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'intake-crop.webp',
            'is_primary' => true,
            'uploaded_via' => 'intake_crop',
            'approved_status' => 'approved',
            'watermark_detected' => false,
        ]);

        AdminSetting::setValue('intake_photo_later_upload_primary_policy', 'keep_intake_primary');
        $this->mock(ImageModerationService::class, function ($mock): void {
            $mock->shouldReceive('moderateProfilePhoto')->andReturn(['status' => 'approved']);
        });

        $this->actingAs($user)
            ->post(route('matrimony.profile.upload-photo'), [
                'profile_photo' => UploadedFile::fake()->image('later.jpg', 200, 200),
            ])
            ->assertRedirect();

        $intakePrimary->refresh();
        $userPhoto = ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->where('uploaded_via', 'user_web')
            ->first();

        $this->assertTrue((bool) $intakePrimary->is_primary);
        $this->assertNotNull($userPhoto);
        $this->assertFalse((bool) $userPhoto->is_primary);
        $profile->refresh();
        $this->assertSame('intake-crop.webp', $profile->profile_photo);
    }
}
