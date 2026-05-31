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

    private function maheshParseInputText(): string
    {
        return <<<'TXT'
कास्ट :- ९६ कुळी मराठा
पित्याचे नाव :-मोहनराव गणपतराव जगताप
प्रोपर्टी :- 1BHK Flat (1) 2 BHK Flat (2)
गावचा पत्ता :- चंद्रेश बिल्डिंग, ठाणे

## महेशकुमार मोहन जगताप

मोबाईल नंबर :- महेश मोहन जगताप (९८७०८७९७२७)
:- मोहन जगताप (९१३७७९३३७१)
TXT;
    }

    public function test_preview_page_shows_normalized_draft_section_without_mutating_intake_json(): void
    {
        $user = User::factory()->create();
        $parsed = app(IntakeParsedSnapshotSkeleton::class)->ensure([
            'core' => [
                'full_name' => 'महेशकुमार मोहन जगताप',
                'gender' => 'male',
                'date_of_birth' => '1995-01-01',
                'religion' => 'हिंदू',
                'caste' => 'मराठा',
                'sub_caste' => '96 कुळी',
                'primary_contact_number' => '9870879727',
            ],
            'contacts' => [
                ['phone_number' => '9870879727', 'is_primary' => true],
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
            'last_parse_input_text' => $this->maheshParseInputText(),
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
        $response->assertSee('Preview only', false);
        $response->assertSee(__('intake.normalized_draft_disclaimer'), false);
        $response->assertSee(__('intake.normalized_draft_needs_review_badge'), false);
        $response->assertSee(__('intake.normalized_draft_full_name_fallback_hint'), false);
        $response->assertSee('महेशकुमार', false);

        $intake->refresh();
        $this->assertSame($parsedBefore, json_encode($intake->parsed_json));
        $this->assertSame($approvalBefore, json_encode($intake->approval_snapshot_json));
        $this->assertSame([], $queries);
    }
}
