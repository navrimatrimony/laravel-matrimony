<?php

namespace Tests\Feature\Intake;

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\User;
use App\Services\AiVisionExtractionService;
use App\Services\Intake\IntakeExtractionReuseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ParseIntakeJobCanonicalTranscriptTest extends TestCase
{
    use RefreshDatabase;

    private function longMarathiBiodataText(): string
    {
        return str_repeat(
            "मुलीचे नांव : कु. टेस्ट परसे\nजन्मतारीख : 12/03/1996\nमो 9876543210\nशिक्षण बी.कॉम\nनोकरी खाजगी\nजन्म\nनाव\nधर्म हिंदू\n",
            8
        );
    }

    public function test_reparse_prefers_last_parse_input_text_over_cache_and_raw_ocr(): void
    {
        Cache::flush();
        config(['intake.testing_active_parser' => 'rules_only']);
        config(['intake.testing_parse_job_uses_ai_vision' => true]);
        config(['intake.ai_vision_extract.min_extracted_chars' => 40]);
        config(['intake.ai_vision_extract.min_extracted_non_space' => 25]);
        config(['intake.ai_vision_extract.min_extracted_lines' => 2]);

        $user = User::factory()->create();
        $canonical = $this->longMarathiBiodataText();
        $differentRaw = str_replace('9876543210', '9111111111', $canonical);

        $intake = BiodataIntake::create([
            'raw_ocr_text' => $differentRaw,
            'last_parse_input_text' => $canonical,
            'uploaded_by' => $user->id,
            'parse_status' => 'pending',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        Cache::put('intake.parse_input_text.'.$intake->id, 'CACHE_SHOULD_NOT_WIN', 3600);

        $this->partialMock(AiVisionExtractionService::class, function ($m) {
            $m->shouldNotReceive('extractTextForIntake');
        });

        IntakeExtractionReuseResolver::flagNextParseJobAsParseInputOnly((int) $intake->id);

        (new ParseIntakeJob((int) $intake->id, true))->handle();

        $intake->refresh();
        $this->assertSame('parsed', $intake->parse_status);
        $dbg = Cache::get('intake.parse_input_debug.'.$intake->id);
        $this->assertSame('canonical_transcript_reparse', $dbg['parse_input_source'] ?? null);
        $this->assertSame('last_parse_input_text', $dbg['canonical_transcript_source'] ?? null);
        $this->assertStringContainsString('9876543210', (string) $intake->last_parse_input_text);
        $this->assertStringNotContainsString('9111111111', (string) $intake->last_parse_input_text);
    }

    public function test_reparse_explicit_raw_ocr_when_no_canonical_is_labeled_in_debug(): void
    {
        Cache::flush();
        config(['intake.testing_active_parser' => 'rules_only']);
        config(['intake.testing_parse_job_uses_ai_vision' => true]);
        config(['intake.ai_vision_extract.min_extracted_chars' => 40]);
        config(['intake.ai_vision_extract.min_extracted_non_space' => 25]);
        config(['intake.ai_vision_extract.min_extracted_lines' => 2]);

        $user = User::factory()->create();
        $text = $this->longMarathiBiodataText();

        $intake = BiodataIntake::create([
            'raw_ocr_text' => $text,
            'uploaded_by' => $user->id,
            'parse_status' => 'pending',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $this->partialMock(AiVisionExtractionService::class, function ($m) {
            $m->shouldNotReceive('extractTextForIntake');
        });

        IntakeExtractionReuseResolver::flagNextParseJobAsParseInputOnly((int) $intake->id);

        (new ParseIntakeJob((int) $intake->id, true))->handle();

        $intake->refresh();
        $this->assertSame('parsed', $intake->parse_status);
        $dbg = Cache::get('intake.parse_input_debug.'.$intake->id);
        $this->assertSame('explicit_fallback_raw_ocr_text', $dbg['parse_input_source'] ?? null);
        $this->assertSame('no_canonical_transcript_for_reparse', $dbg['fallback_reason'] ?? null);
    }

    public function test_ai_vision_expected_but_empty_extraction_does_not_parse_silently_as_success(): void
    {
        Cache::flush();
        config(['intake.testing_active_parser' => 'rules_only']);
        config(['intake.testing_parse_job_uses_ai_vision' => true]);
        config(['intake.ai_vision_extract.min_extracted_chars' => 40]);
        config(['intake.ai_vision_extract.min_extracted_non_space' => 25]);
        config(['intake.ai_vision_extract.min_extracted_lines' => 2]);

        $user = User::factory()->create();
        $text = $this->longMarathiBiodataText();

        $intake = BiodataIntake::create([
            'raw_ocr_text' => $text,
            'uploaded_by' => $user->id,
            'parse_status' => 'pending',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $this->partialMock(AiVisionExtractionService::class, function ($m) {
            $m->shouldReceive('extractTextForIntake')->once()->andReturn([
                'text' => '',
                'meta' => [
                    'ok' => false,
                    'provider' => 'openai',
                    'reason' => 'empty_vision_response',
                ],
            ]);
        });

        (new ParseIntakeJob((int) $intake->id, false))->handle();

        $intake->refresh();
        $this->assertSame('error', $intake->parse_status);
        $dbg = Cache::get('intake.parse_input_debug.'.$intake->id);
        $this->assertIsArray($dbg);
        $this->assertSame('ai_vision_extract_failed', $dbg['parse_input_source'] ?? null);
        $this->assertFalse((bool) ($dbg['ok'] ?? true));
    }
}
