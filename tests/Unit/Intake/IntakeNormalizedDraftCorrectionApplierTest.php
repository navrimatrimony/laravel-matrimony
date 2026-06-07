<?php

namespace Tests\Unit\Intake;

use App\Models\BiodataIntake;
use App\Models\User;
use App\Services\Intake\IntakeNormalizedDraftCorrectionApplier;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeNormalizedDraftCorrectionApplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_writes_core_field_into_parsed_json(): void
    {
        $owner = User::factory()->create();
        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'sample',
            'parsed_json' => app(IntakeParsedSnapshotSkeleton::class)->ensure([
                'core' => [
                    'full_name' => 'Test',
                    'caste' => 'मराठा',
                    'sub_caste' => null,
                ],
            ]),
            'uploaded_by' => $owner->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $result = app(IntakeNormalizedDraftCorrectionApplier::class)->apply(
            $intake,
            'core.sub_caste',
            '96 कुळी'
        );

        $intake->refresh();

        $this->assertTrue($result['ok']);
        $this->assertSame('96 कुळी', $intake->parsed_json['core']['sub_caste'] ?? null);
        $this->assertSame('मराठा', $intake->parsed_json['core']['caste'] ?? null);
    }
}
