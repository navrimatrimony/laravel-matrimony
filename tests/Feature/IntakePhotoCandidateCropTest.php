<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\User;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntakePhotoCandidateCropTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('intakes/photo-candidate-tests');
        Storage::disk('local')->deleteDirectory('intake-photo-candidates');

        parent::tearDown();
    }

    private function createParsedIntake(User $user, array $overrides = []): BiodataIntake
    {
        $parsed = app(IntakeParsedSnapshotSkeleton::class)->ensure([
            'core' => [
                'full_name' => 'Test Candidate',
                'gender' => 'male',
                'date_of_birth' => '1995-01-01',
                'religion' => 'Hindu',
                'caste' => 'Maratha',
                'sub_caste' => '96 Kuli',
                'primary_contact_number' => '9876543210',
            ],
            'contacts' => [
                ['phone_number' => '9876543210', 'is_primary' => true],
            ],
        ]);

        return BiodataIntake::create(array_merge([
            'raw_ocr_text' => 'Raw OCR text remains immutable.',
            'last_parse_input_text' => 'Test Candidate biodata text for normalized draft preview.',
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
            'parsed_json' => $parsed,
            'approval_snapshot_json' => [
                'snapshot_schema_version' => 1,
                'core' => [
                    'full_name' => 'Test Candidate',
                    'gender' => 'male',
                ],
                'contacts' => [],
            ],
        ], $overrides));
    }

    private function createImageIntake(User $user): BiodataIntake
    {
        $relativePath = 'intakes/photo-candidate-tests/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($relativePath, $this->jpegBinary(320, 240));

        return $this->createParsedIntake($user, [
            'file_path' => $relativePath,
            'original_filename' => 'biodata.jpg',
            'file_type' => 'image/jpeg',
        ]);
    }

    private function candidateUpload(): UploadedFile
    {
        return UploadedFile::fake()->image('candidate.jpg', 160, 160);
    }

    private function jpegBinary(int $width, int $height): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is required for candidate crop tests.');
        }

        $image = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($image, 230, 240, 255);
        imagefill($image, 0, 0, $bg);

        ob_start();
        imagejpeg($image, null, 88);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $binary;
    }

    public function test_settings_disabled_hides_candidate_crop_ui(): void
    {
        $user = User::factory()->create();
        $intake = $this->createImageIntake($user);

        AdminSetting::setValue('intake_photo_crop_enabled', '0');
        AdminSetting::setValue('intake_photo_show_in_normalized_preview', '1');

        $this->actingAs($user)
            ->get(route('intake.preview', $intake))
            ->assertOk()
            ->assertDontSee('Profile photo candidate crop', false)
            ->assertDontSee('Save candidate crop', false);
    }

    public function test_crop_enabled_without_image_intake_shows_safe_unavailable_state(): void
    {
        $user = User::factory()->create();
        $intake = $this->createParsedIntake($user);

        AdminSetting::setValue('intake_photo_crop_enabled', '1');
        AdminSetting::setValue('intake_photo_show_in_normalized_preview', '1');

        $this->actingAs($user)
            ->get(route('intake.preview', $intake))
            ->assertOk()
            ->assertSee('No uploaded image biodata available.', false)
            ->assertDontSee('Profile photo candidate crop', false);
    }

    public function test_owner_can_save_candidate_crop_for_image_intake(): void
    {
        $user = User::factory()->create();
        $intake = $this->createImageIntake($user);

        AdminSetting::setValue('intake_photo_crop_enabled', '1');

        $this->actingAs($user)
            ->postJson(route('intake.photo-candidate-crop-save', $intake), [
                'candidate_image' => $this->candidateUpload(),
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertTrue(Storage::disk('local')->exists('intake-photo-candidates/'.$intake->id.'/candidate.jpg'));
    }

    public function test_admin_can_save_candidate_crop_for_same_intake(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $intake = $this->createImageIntake($owner);

        AdminSetting::setValue('intake_photo_crop_enabled', '1');

        $this->actingAs($admin)
            ->postJson(route('intake.photo-candidate-crop-save', $intake), [
                'candidate_image' => $this->candidateUpload(),
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertTrue(Storage::disk('local')->exists('intake-photo-candidates/'.$intake->id.'/candidate.jpg'));
    }

    public function test_unauthorized_user_cannot_save_or_view_candidate_crop(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $intake = $this->createImageIntake($owner);

        AdminSetting::setValue('intake_photo_crop_enabled', '1');

        $this->actingAs($owner)
            ->postJson(route('intake.photo-candidate-crop-save', $intake), [
                'candidate_image' => $this->candidateUpload(),
            ])
            ->assertOk();

        $this->actingAs($other)
            ->postJson(route('intake.photo-candidate-crop-save', $intake), [
                'candidate_image' => $this->candidateUpload(),
            ])
            ->assertForbidden();

        $this->actingAs($other)
            ->get(route('intake.photo-candidate-image', $intake))
            ->assertForbidden();
    }

    public function test_saving_candidate_crop_does_not_mutate_intake_or_profile_photos(): void
    {
        $user = User::factory()->create();
        $intake = $this->createImageIntake($user);

        AdminSetting::setValue('intake_photo_crop_enabled', '1');

        $parsedBefore = json_encode($intake->parsed_json);
        $approvalBefore = json_encode($intake->approval_snapshot_json);
        $rawBefore = $intake->raw_ocr_text;
        $profilePhotoRowsBefore = DB::table('profile_photos')->count();

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'update `biodata_intakes`') || str_contains($sql, 'update biodata_intakes')) {
                $queries[] = $query->sql;
            }
        });

        $this->actingAs($user)
            ->postJson(route('intake.photo-candidate-crop-save', $intake), [
                'candidate_image' => $this->candidateUpload(),
            ])
            ->assertOk();

        $intake->refresh();
        $this->assertSame($parsedBefore, json_encode($intake->parsed_json));
        $this->assertSame($approvalBefore, json_encode($intake->approval_snapshot_json));
        $this->assertSame($rawBefore, $intake->raw_ocr_text);
        $this->assertSame($profilePhotoRowsBefore, DB::table('profile_photos')->count());
        $this->assertSame([], $queries);
    }

    public function test_normalized_draft_photo_section_shows_thumbnail_after_candidate_crop_exists(): void
    {
        $user = User::factory()->create();
        $intake = $this->createImageIntake($user);

        AdminSetting::setValue('intake_photo_crop_enabled', '1');
        AdminSetting::setValue('intake_photo_show_in_normalized_preview', '1');

        $this->actingAs($user)
            ->postJson(route('intake.photo-candidate-crop-save', $intake), [
                'candidate_image' => $this->candidateUpload(),
            ])
            ->assertOk();

        $this->actingAs($user)
            ->get(route('intake.preview', $intake))
            ->assertOk()
            ->assertSee('Biodata photo candidate preview', false)
            ->assertSee('Preview only. Not saved as profile photo yet.', false);
    }
}
