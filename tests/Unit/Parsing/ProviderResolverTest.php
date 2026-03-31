<?php

namespace Tests\Unit\Parsing;

use App\Models\AdminSetting;
use App\Services\Parsing\ParserStrategyResolver;
use App\Services\Parsing\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_end_to_end_sarvam_resolves_same_provider_for_structured_and_vision(): void
    {
        AdminSetting::setValue('intake_processing_mode', 'end_to_end');
        AdminSetting::setValue('intake_primary_ai_provider', 'sarvam');
        AdminSetting::setValue('intake_active_parser', 'ai_vision_extract_v1');

        $r = app(ProviderResolver::class);
        $this->assertSame('sarvam', $r->structuredParserProvider());
        $this->assertSame('sarvam', $r->visionTranscriptionProvider());
        $this->assertTrue($r->parseJobUsesAiVisionExtraction());
    }

    public function test_end_to_end_openai_resolves_openai_for_both(): void
    {
        AdminSetting::setValue('intake_processing_mode', 'end_to_end');
        AdminSetting::setValue('intake_primary_ai_provider', 'openai');
        AdminSetting::setValue('intake_active_parser', 'ai_vision_extract_v1');

        $r = app(ProviderResolver::class);
        $this->assertSame('openai', $r->structuredParserProvider());
        $this->assertSame('openai', $r->visionTranscriptionProvider());
    }

    public function test_hybrid_resolves_extraction_and_parser_independently(): void
    {
        AdminSetting::setValue('intake_processing_mode', 'hybrid');
        AdminSetting::setValue('intake_active_parser', 'hybrid_v1');
        AdminSetting::setValue('intake_hybrid_extraction_provider', 'openai');
        AdminSetting::setValue('intake_hybrid_parser_provider', 'sarvam');

        $r = app(ProviderResolver::class);
        $this->assertSame('sarvam', $r->structuredParserProvider());
        $this->assertSame('openai', $r->visionTranscriptionProvider());
        $this->assertTrue($r->parseJobUsesAiVisionExtraction());
    }

    public function test_hybrid_tesseract_skips_ai_vision_extraction(): void
    {
        AdminSetting::setValue('intake_processing_mode', 'hybrid');
        AdminSetting::setValue('intake_active_parser', 'hybrid_v1');
        AdminSetting::setValue('intake_hybrid_extraction_provider', 'tesseract');
        AdminSetting::setValue('intake_hybrid_parser_provider', 'openai');

        $r = app(ProviderResolver::class);
        $this->assertFalse($r->parseJobUsesAiVisionExtraction());
    }

    public function test_legacy_hybrid_v1_maps_to_hybrid_processing_mode(): void
    {
        AdminSetting::setValue('intake_processing_mode', '');
        AdminSetting::setValue('intake_active_parser', ParserStrategyResolver::MODE_HYBRID_V1);

        $r = app(ProviderResolver::class);
        $this->assertSame(ProviderResolver::MODE_HYBRID, $r->processingMode());
    }

    /**
     * Admin intake-settings "File text extraction provider" writes intake_ai_vision_provider;
     * vision transcription must read the same key when primary is unset.
     */
    public function test_intake_ai_vision_provider_sarvam_matches_runtime_vision_transcription(): void
    {
        AdminSetting::setValue('intake_processing_mode', 'end_to_end');
        AdminSetting::setValue('intake_primary_ai_provider', '');
        AdminSetting::setValue('intake_ai_vision_provider', 'sarvam');
        AdminSetting::setValue('intake_active_parser', 'ai_vision_extract_v1');

        $r = app(ProviderResolver::class);
        $this->assertSame('sarvam', $r->visionTranscriptionProvider());

        $resolved = app(\App\Services\AiVisionExtractionService::class)->resolveExtractionProvider();
        $this->assertSame('sarvam', $resolved['provider']);
    }
}
