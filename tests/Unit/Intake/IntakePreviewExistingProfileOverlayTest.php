<?php

namespace Tests\Unit\Intake;

use App\Models\MatrimonyProfile;
use App\Services\Intake\IntakePreviewExistingProfileOverlay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakePreviewExistingProfileOverlayTest extends TestCase
{
    use RefreshDatabase;

    public function test_keeps_profile_full_name_and_surfaces_intake_as_suggestion(): void
    {
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Profile Name Existing',
            'date_of_birth' => '1990-01-15',
        ]);

        $coreData = [
            'full_name' => 'Intake Parsed Name',
            'date_of_birth' => '1993-12-15',
        ];
        $suggestionMap = [];
        $intakeParsed = ['core' => [
            'full_name' => 'Intake Parsed Name',
            'date_of_birth' => '1993-12-15',
        ]];
        $snapshot = [];
        $sections = ['core' => ['data' => &$coreData]];

        $result = app(IntakePreviewExistingProfileOverlay::class)->apply(
            $profile,
            $coreData,
            $suggestionMap,
            $intakeParsed,
            $snapshot,
            $sections
        );

        $this->assertSame('Profile Name Existing', $coreData['full_name']);
        $this->assertSame('1990-01-15', $coreData['date_of_birth']);
        $this->assertTrue($suggestionMap['full_name']['profile_existing'] ?? false);
        $this->assertNotEmpty($result['field_suggestions']);
        $this->assertSame('Intake Parsed Name', $result['field_suggestions'][0]['intake_display'] ?? null);
    }

    public function test_prefills_empty_profile_field_from_intake_without_conflict_flag(): void
    {
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Only Name Set',
            'date_of_birth' => null,
        ]);

        $coreData = [
            'full_name' => 'Only Name Set',
            'date_of_birth' => '1993-12-15',
        ];
        $suggestionMap = [];
        $intakeParsed = ['core' => [
            'full_name' => 'Only Name Set',
            'date_of_birth' => '1993-12-15',
        ]];
        $snapshot = [];
        $sections = ['core' => ['data' => &$coreData]];

        $result = app(IntakePreviewExistingProfileOverlay::class)->apply(
            $profile,
            $coreData,
            $suggestionMap,
            $intakeParsed,
            $snapshot,
            $sections
        );

        $this->assertSame('1993-12-15', $coreData['date_of_birth']);
        $this->assertSame([], array_filter(
            $result['field_suggestions'],
            fn ($r) => ($r['key'] ?? '') === 'date_of_birth'
        ));
    }

    public function test_profile_birth_city_id_stays_visible_when_approval_snapshot_differs(): void
    {
        $profile = MatrimonyProfile::factory()->create();
        $profile->birth_city_id = 1001;

        $coreData = ['birth_city_id' => 6316];
        $suggestionMap = [];
        $intakeParsed = ['core' => ['birth_place' => 'वरकुटे मलवडी']];
        $snapshot = ['core' => ['birth_city_id' => 6316, 'birth_place_suggestion_applied' => true]];
        $sections = ['core' => ['data' => &$coreData]];

        app(IntakePreviewExistingProfileOverlay::class)->apply(
            $profile,
            $coreData,
            $suggestionMap,
            $intakeParsed,
            $snapshot,
            $sections
        );

        $this->assertSame(1001, (int) $coreData['birth_city_id']);
    }
}
