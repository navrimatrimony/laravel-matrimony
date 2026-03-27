<?php

namespace Tests\Unit;

use App\Services\AiVisionExtractionService;
use Tests\TestCase;

class AiVisionExtractionServiceQualityTest extends TestCase
{
    public function test_blank_text_is_rejected(): void
    {
        $svc = app(AiVisionExtractionService::class);
        $q = $svc->evaluateExtractedTextQuality("   \n\n  ");
        $this->assertFalse($q['ok']);
        $this->assertSame('ai_vision_text_blank', $q['reason']);
    }

    public function test_too_short_text_is_rejected(): void
    {
        config()->set('intake.ai_vision_extract.min_extracted_chars', 50);
        config()->set('intake.ai_vision_extract.min_extracted_non_space', 35);
        config()->set('intake.ai_vision_extract.min_extracted_lines', 2);
        $svc = app(AiVisionExtractionService::class);
        $q = $svc->evaluateExtractedTextQuality("नाव: अमोल\nउंची: 5'7\"");
        $this->assertFalse($q['ok']);
        $this->assertSame('ai_vision_text_too_short', $q['reason']);
    }

    public function test_garbage_like_text_is_rejected(): void
    {
        config()->set('intake.ai_vision_extract.min_extracted_chars', 10);
        config()->set('intake.ai_vision_extract.min_extracted_non_space', 10);
        config()->set('intake.ai_vision_extract.min_extracted_lines', 1);
        $svc = app(AiVisionExtractionService::class);
        $q = $svc->evaluateExtractedTextQuality(str_repeat("###$$$%%% ", 30));
        $this->assertFalse($q['ok']);
        $this->assertSame('ai_vision_text_unusable', $q['reason']);
    }

    public function test_reasonable_text_is_accepted(): void
    {
        config()->set('intake.ai_vision_extract.min_extracted_chars', 50);
        config()->set('intake.ai_vision_extract.min_extracted_non_space', 35);
        config()->set('intake.ai_vision_extract.min_extracted_lines', 2);
        $svc = app(AiVisionExtractionService::class);
        $text = "परिचय पत्र\nनाव: कु. प्राची पाटील\nजन्म तारीख: 12/03/1996\nशिक्षण: BE Computer Engineering\nनोकरी: Amdocs, Pune\nधर्म: हिंदू\nजात: मराठा\nमोबाईल: 98xxxxxxxx\n";
        $q = $svc->evaluateExtractedTextQuality($text);
        $this->assertTrue($q['ok']);
        $this->assertNull($q['reason']);
        $this->assertGreaterThan(0.18, $q['alpha_ratio']);
    }

    public function test_sanitize_transcription_strips_markdown_fences(): void
    {
        $svc = app(AiVisionExtractionService::class);
        $raw = "```plaintext\nनाव: राहुल\n```";
        $out = $svc->sanitizeTranscriptionResponse($raw);
        $this->assertStringNotContainsString('```', $out);
        $this->assertStringContainsString('नाव: राहुल', $out);
    }
}

