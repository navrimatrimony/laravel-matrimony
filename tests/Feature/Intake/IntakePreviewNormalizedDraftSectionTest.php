<?php

namespace Tests\Feature\Intake;

use App\Models\BiodataIntake;
use App\Models\User;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakePreviewNormalizedDraftSectionTest extends TestCase
{
    use RefreshDatabase;

    private function yuvrajParseInputText(): string
    {
        return <<<'TXT'
*प्रतिमा: decorative logo*
:■:
वैयक्तिक माहिती
नाव : कु. युवराज नामदेव घाटेगस्ती.
जात : हिंदू मराठा {96 कुळी}
आईचे नाव : सौ. सुनंदा नामदेव घाटेगस्ती. { गृहिणी }
मोबाईल नं : 73509 53384/ 96733 50078
TXT;
    }

    public function test_preview_page_shows_normalized_draft_section_without_mutating_intake_json(): void
    {
        $user = User::factory()->create();
        $parsed = app(IntakeParsedSnapshotSkeleton::class)->ensure([
            'core' => [
                'full_name' => 'कु. युवराज नामदेव घाटेगस्ती',
                'gender' => 'male',
                'date_of_birth' => '1995-01-01',
                'religion' => 'हिंदू',
                'caste' => 'मराठा',
                'sub_caste' => '96 कुळी',
                'primary_contact_number' => '7350953384',
            ],
            'contacts' => [
                ['phone_number' => '7350953384', 'is_primary' => true],
            ],
        ]);

        $approvalSnapshot = [
            'snapshot_schema_version' => 1,
            'core' => [
                'full_name' => 'Approval Snapshot Name',
                'gender' => 'male',
            ],
            'contacts' => [],
        ];

        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'stored ocr should not be primary',
            'last_parse_input_text' => $this->yuvrajParseInputText(),
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
            'parsed_json' => $parsed,
            'approval_snapshot_json' => $approvalSnapshot,
        ]);

        $parsedBefore = json_encode($intake->parsed_json);
        $approvalBefore = json_encode($intake->approval_snapshot_json);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'update `biodata_intakes`') || str_contains($sql, 'update biodata_intakes')) {
                $queries[] = $query->sql;
            }
        });

        $response = $this->actingAs($user)->get(route('intake.preview', $intake));

        $response->assertOk();
        $response->assertSee(__('intake.normalized_draft_heading'), false);
        $response->assertSee(__('intake.normalized_draft_disclaimer'), false);
        $response->assertSee('युवराज', false);

        $intake->refresh();
        $this->assertSame($parsedBefore, json_encode($intake->parsed_json));
        $this->assertSame($approvalBefore, json_encode($intake->approval_snapshot_json));
        $this->assertSame([], $queries);
    }
}
