<?php

namespace Tests\Unit;

use App\Models\BiodataIntake;
use App\Services\AiVisionExtractionService;
use App\Services\Parsing\ParserStrategyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiVisionExtractV1PipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_openai_provider_extraction_then_ai_first_parse_produces_ssot_without_touching_raw_ocr_text(): void
    {
        config()->set('intake.testing_active_parser', 'ai_vision_extract_v1');
        config()->set('intake.ai_vision_extract.provider', 'openai');
        config()->set('services.openai.key', 'test-key');
        config()->set('services.openai.url', 'https://api.openai.com/v1/chat/completions');

        // Keep sanity gate permissive for this test payload.
        config()->set('intake.ai_vision_extract.min_extracted_chars', 40);
        config()->set('intake.ai_vision_extract.min_extracted_non_space', 25);
        config()->set('intake.ai_vision_extract.min_extracted_lines', 2);

        // Create a tiny fake image in private storage so base64 encode works.
        $rel = 'intakes/test-ai-vision.jpg';
        $abs = storage_path('app/private/'.$rel);
        if (! is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0777, true);
        }
        @file_put_contents($abs, "FAKEJPEGDATA");

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => function ($request) {
                $payload = $request->data();
                $messages = $payload['messages'] ?? [];
                $user = $messages[1]['content'] ?? null;
                $isVision = is_array($user);

                if ($isVision) {
                    return Http::response([
                        'choices' => [
                            ['message' => ['content' => "विवाह परिचय पत्र\nनाव: अमोल पाटील\nजन्म तारीख: 12/03/1996\nधर्म: हिंदू\nजात: मराठा\n"]],
                        ],
                    ], 200);
                }

                return Http::response([
                    'choices' => [
                        ['message' => ['content' => json_encode([
                            'core' => [
                                'full_name' => 'Amol Patil',
                                'date_of_birth' => '1996-03-12',
                                'gender' => 'male',
                                'religion' => 'हिंदू',
                                'caste' => 'मराठा',
                                'sub_caste' => null,
                                'marital_status' => 'unmarried',
                            ],
                            'contacts' => [],
                            'children' => [],
                            'education_history' => [],
                            'career_history' => [],
                            'addresses' => [],
                            'siblings' => [],
                            'relatives' => [],
                            'property_summary' => [],
                            'property_assets' => [],
                            'horoscope' => [],
                            'preferences' => [],
                            'extended_narrative' => [
                                'narrative_about_me' => null,
                                'narrative_expectations' => null,
                                'additional_notes' => null,
                            ],
                            'confidence_map' => [],
                        ], JSON_UNESCAPED_UNICODE)]],
                    ],
                ], 200);
            },
        ]);

        $intake = new BiodataIntake([
            'file_path' => $rel,
            'original_filename' => 'test.jpg',
            'raw_ocr_text' => '',
        ]);

        $svc = app(AiVisionExtractionService::class);
        $ext = $svc->extractTextForIntake($intake);
        $this->assertTrue((bool) ($ext['meta']['ok'] ?? false));
        $this->assertSame('openai', $ext['meta']['provider'] ?? null);
        $this->assertNotSame('', trim((string) ($ext['text'] ?? '')));

        $quality = $svc->evaluateExtractedTextQuality((string) $ext['text']);
        $this->assertTrue($quality['ok']);

        $parser = app(ParserStrategyResolver::class)->makeParser('ai_vision_extract_v1');
        $ssot = $parser->parse((string) $ext['text'], [
            'parser_mode' => 'ai_vision_extract_v1',
            'intake_id' => null,
        ]);

        $this->assertIsArray($ssot);
        $this->assertSame('Amol Patil', $ssot['core']['full_name'] ?? null);
        $this->assertSame('', (string) $intake->raw_ocr_text);
    }
}

