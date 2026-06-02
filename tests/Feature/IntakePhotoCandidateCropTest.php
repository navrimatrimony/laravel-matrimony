<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\User;
use App\Services\Intake\IntakePhotoCandidateCropService;
use App\Services\Intake\IntakePhotoCandidateSuggestionService;
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

    private function createImageIntake(User $user, ?string $binary = null): BiodataIntake
    {
        $relativePath = 'intakes/photo-candidate-tests/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($relativePath, $binary ?? $this->jpegBinary(320, 240));

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

    private function biodataWithPhotoBlockBinary(): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is required for candidate crop tests.');
        }

        $image = imagecreatetruecolor(800, 1000);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 40, 40, 40);
        $blue = imagecolorallocate($image, 70, 130, 210);
        $green = imagecolorallocate($image, 80, 170, 120);
        imagefill($image, 0, 0, $white);

        for ($i = 0; $i < 18; $i++) {
            $y = 90 + ($i * 34);
            imageline($image, 70, $y, 470, $y, $black);
        }
        imagefilledrectangle($image, 540, 80, 689, 279, $blue);
        imagefilledrectangle($image, 570, 130, 660, 240, $green);

        ob_start();
        imagejpeg($image, null, 90);
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
        $info = getimagesize(app(IntakePhotoCandidateCropService::class)->absolutePath($intake));
        $this->assertSame([600, 800], [$info[0] ?? null, $info[1] ?? null]);
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

    public function test_candidate_crop_ui_uses_existing_biodata_image_without_duplicate_full_image(): void
    {
        $user = User::factory()->create();
        $intake = $this->createImageIntake($user);

        AdminSetting::setValue('intake_photo_crop_enabled', '1');

        $response = $this->actingAs($user)
            ->get(route('intake.preview', $intake))
            ->assertOk()
            ->assertSee('id="intake-manual-crop-img"', false)
            ->assertSee('id="intake-photo-candidate-box"', false)
            ->assertSee('Save profile photo crop', false)
            ->assertDontSee('id="intake-photo-candidate-crop-section"', false)
            ->assertDontSee('id="intake-photo-candidate-img"', false);

        $content = $response->getContent() ?: '';
        $this->assertSame(1, substr_count($content, 'id="intake-manual-crop-img"'));
        $this->assertSame(0, substr_count($content, 'id="intake-photo-candidate-img"'));
    }

    public function test_auto_suggestion_finds_colored_profile_photo_block(): void
    {
        $user = User::factory()->create();
        $intake = $this->createImageIntake($user, $this->biodataWithPhotoBlockBinary());

        $suggestion = app(IntakePhotoCandidateSuggestionService::class)->suggest($intake);

        $this->assertTrue($suggestion['available']);
        $this->assertGreaterThanOrEqual(0.55, $suggestion['confidence']);
        $box = $suggestion['box'];
        $this->assertIsArray($box);
        $this->assertEqualsWithDelta(540, $box['x'], 35);
        $this->assertGreaterThan(450, $box['x']);
        $this->assertEqualsWithDelta(80, $box['y'], 35);
        $this->assertEqualsWithDelta(150, $box['width'], 45);
        $this->assertEqualsWithDelta(200, $box['height'], 55);
    }

    public function test_server_can_save_candidate_crop_from_suggested_original_image_box(): void
    {
        $user = User::factory()->create();
        $intake = $this->createImageIntake($user, $this->biodataWithPhotoBlockBinary());

        $suggestion = app(IntakePhotoCandidateSuggestionService::class)->suggest($intake);
        $this->assertTrue($suggestion['available']);
        $this->assertIsArray($suggestion['box']);

        app(IntakePhotoCandidateCropService::class)->saveFromOriginalBox($intake, $suggestion['box']);

        $this->assertTrue(Storage::disk('local')->exists('intake-photo-candidates/'.$intake->id.'/candidate.jpg'));
        $info = getimagesize(app(IntakePhotoCandidateCropService::class)->absolutePath($intake));
        $this->assertSame([600, 800], [$info[0] ?? null, $info[1] ?? null]);
    }

    public function test_auto_suggestion_low_confidence_for_blank_text_like_image(): void
    {
        $user = User::factory()->create();
        $intake = $this->createImageIntake($user, $this->jpegBinary(800, 1000));

        $suggestion = app(IntakePhotoCandidateSuggestionService::class)->suggest($intake);

        $this->assertFalse($suggestion['available']);
        $this->assertLessThan(0.55, $suggestion['confidence']);
        $this->assertStringContainsString('No reliable', $suggestion['message']);
    }

    public function test_preview_does_not_claim_auto_detect_for_blank_image(): void
    {
        $user = User::factory()->create();
        $intake = $this->createImageIntake($user, $this->jpegBinary(800, 1000));

        AdminSetting::setValue('intake_photo_crop_enabled', '1');
        AdminSetting::setValue('intake_photo_show_in_normalized_preview', '1');

        $this->actingAs($user)
            ->get(route('intake.preview', $intake))
            ->assertOk()
            ->assertSee('Could not auto-detect profile photo. Please adjust crop manually.', false)
            ->assertDontSee('Auto-cropped from biodata image. Adjust and save again if needed.', false)
            ->assertDontSee('Detected candidate photo area. Adjust if needed, then save.', false);

        $this->assertFalse(Storage::disk('local')->exists('intake-photo-candidates/'.$intake->id.'/candidate.jpg'));
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

    public function test_candidate_crop_save_normalizes_free_ratio_upload_to_standard_profile_ratio(): void
    {
        $user = User::factory()->create();
        $intake = $this->createImageIntake($user);

        AdminSetting::setValue('intake_photo_crop_enabled', '1');

        $this->actingAs($user)
            ->postJson(route('intake.photo-candidate-crop-save', $intake), [
                'candidate_image' => UploadedFile::fake()->image('wide-candidate.jpg', 300, 120),
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $info = getimagesize(app(IntakePhotoCandidateCropService::class)->absolutePath($intake));
        $this->assertSame([
            IntakePhotoCandidateCropService::PROFILE_CROP_EXPORT_WIDTH,
            IntakePhotoCandidateCropService::PROFILE_CROP_EXPORT_HEIGHT,
        ], [$info[0] ?? null, $info[1] ?? null]);
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

    public function test_preview_auto_saves_high_confidence_candidate_and_renders_normalized_draft_thumbnail(): void
    {
        $user = User::factory()->create();
        $intake = $this->createImageIntake($user, $this->biodataWithPhotoBlockBinary());

        AdminSetting::setValue('intake_photo_crop_enabled', '1');
        AdminSetting::setValue('intake_photo_show_in_normalized_preview', '1');

        $this->assertFalse(Storage::disk('local')->exists('intake-photo-candidates/'.$intake->id.'/candidate.jpg'));

        $this->actingAs($user)
            ->get(route('intake.preview', $intake))
            ->assertOk()
            ->assertSee('Auto-cropped from biodata image. Adjust and save again if needed.', false)
            ->assertSee('Biodata photo candidate preview', false)
            ->assertSee('Preview only. Not saved as profile photo yet.', false);

        $this->assertTrue(Storage::disk('local')->exists('intake-photo-candidates/'.$intake->id.'/candidate.jpg'));
        $info = getimagesize(app(IntakePhotoCandidateCropService::class)->absolutePath($intake));
        $this->assertSame([600, 800], [$info[0] ?? null, $info[1] ?? null]);
    }
}
