<?php

namespace Tests\Unit\Support;

use App\Models\BiodataIntake;
use App\Models\User;
use App\Support\IntakePreviewDiagnosticsPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakePreviewDiagnosticsPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_fallback_raw_ocr_labels(): void
    {
        $user = User::factory()->create();
        $intake = BiodataIntake::create([
            'raw_ocr_text' => str_repeat("x\n", 50),
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $meta = [
            'active_parser_mode' => 'ai_vision_extract_v1',
            'parse_input_source' => 'explicit_fallback_raw_ocr_text',
            'parse_input_provider' => null,
            'parse_input_ok' => true,
            'parse_input_fallback_reason' => 'no_canonical_transcript_for_reparse',
            'parse_input_ai_extraction_skipped' => true,
        ];

        $out = IntakePreviewDiagnosticsPresenter::summarize($intake, $meta);
        $s = $out['summary'];

        $this->assertStringContainsString('OCR', $s['autofill_source_label']);
        $this->assertStringContainsString('Not used', $s['ai_provider_label']);
        $this->assertSame('Yes', $s['fallback_used_label']);
    }

    public function test_sarvam_ai_vision_success_labels(): void
    {
        $user = User::factory()->create();
        $intake = BiodataIntake::create([
            'raw_ocr_text' => str_repeat("x\n", 50),
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $meta = [
            'active_parser_mode' => 'ai_vision_extract_v1',
            'parse_input_source' => 'ai_vision_extract_v1',
            'parse_input_provider' => 'sarvam',
            'parse_input_ok' => true,
            'parse_input_paid_extraction_api_called' => true,
        ];

        $out = IntakePreviewDiagnosticsPresenter::summarize($intake, $meta);
        $s = $out['summary'];

        $this->assertStringContainsString('Sarvam', $s['autofill_source_label']);
        $this->assertSame('Sarvam', $s['ai_provider_label']);
        $this->assertStringContainsString('Fresh', $s['transcript_used_label']);
        $this->assertSame('No', $s['fallback_used_label']);
    }
}
