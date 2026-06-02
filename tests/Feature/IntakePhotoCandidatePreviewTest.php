<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\User;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntakePhotoCandidatePreviewTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_settings_disabled_does_not_show_photo_candidate_preview_message(): void
    {
        $user = User::factory()->create();
        $intake = $this->createParsedIntake($user);

        AdminSetting::setValue('intake_photo_crop_enabled', '0');
        AdminSetting::setValue('intake_photo_show_in_normalized_preview', '0');

        $this->actingAs($user)
            ->get(route('intake.preview', $intake))
            ->assertOk()
            ->assertSee(__('intake.normalized_draft_heading'), false)
            ->assertDontSee('Candidate photo extraction is not available yet.', false)
            ->assertDontSee('No uploaded image biodata available.', false)
            ->assertDontSee('Preview only. Not saved as profile photo yet.', false);
    }

    public function test_enabled_settings_with_no_uploaded_image_shows_safe_unavailable_message_without_mutation(): void
    {
        $user = User::factory()->create();
        $intake = $this->createParsedIntake($user);

        AdminSetting::setValue('intake_photo_crop_enabled', '1');
        AdminSetting::setValue('intake_photo_show_in_normalized_preview', '1');

        $parsedBefore = json_encode($intake->parsed_json);
        $approvalBefore = json_encode($intake->approval_snapshot_json);
        $rawOcrBefore = $intake->raw_ocr_text;
        $profilePhotoRowsBefore = DB::table('profile_photos')->count();

        $this->actingAs($user)
            ->get(route('intake.preview', $intake))
            ->assertOk()
            ->assertSee('No uploaded image biodata available.', false)
            ->assertDontSee('Preview only. Not saved as profile photo yet.', false);

        $intake->refresh();
        $this->assertSame($parsedBefore, json_encode($intake->parsed_json));
        $this->assertSame($approvalBefore, json_encode($intake->approval_snapshot_json));
        $this->assertSame($rawOcrBefore, $intake->raw_ocr_text);
        $this->assertSame($profilePhotoRowsBefore, DB::table('profile_photos')->count());
    }

    public function test_enabled_settings_with_uploaded_image_shows_no_reliable_crop_engine_message(): void
    {
        Storage::disk('local')->put('intakes/candidate.jpg', 'fake image bytes');

        $user = User::factory()->create();
        $intake = $this->createParsedIntake($user, [
            'file_path' => 'intakes/candidate.jpg',
            'original_filename' => 'candidate.jpg',
            'file_type' => 'image/jpeg',
        ]);

        AdminSetting::setValue('intake_photo_crop_enabled', '1');
        AdminSetting::setValue('intake_photo_show_in_normalized_preview', '1');

        $this->actingAs($user)
            ->get(route('intake.preview', $intake))
            ->assertOk()
            ->assertSee('Candidate photo extraction is not available yet.', false)
            ->assertDontSee('Preview only. Not saved as profile photo yet.', false);
    }

    public function test_admin_can_open_same_preview_route_and_sees_safe_photo_section_behavior(): void
    {
        Storage::disk('local')->put('intakes/admin-candidate.jpg', 'fake image bytes');

        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $intake = $this->createParsedIntake($owner, [
            'file_path' => 'intakes/admin-candidate.jpg',
            'original_filename' => 'admin-candidate.jpg',
            'file_type' => 'image/jpeg',
        ]);

        AdminSetting::setValue('intake_photo_crop_enabled', '1');
        AdminSetting::setValue('intake_photo_show_in_normalized_preview', '1');

        $this->actingAs($admin)
            ->get(route('intake.preview', $intake))
            ->assertOk()
            ->assertSee('Candidate photo extraction is not available yet.', false)
            ->assertDontSee('Preview only. Not saved as profile photo yet.', false);
    }
}
