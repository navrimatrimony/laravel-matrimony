<?php

namespace Tests\Unit\Parsing;

use App\Services\ExternalAiParsingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalAiParsingServiceSarvamModelGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_sarvam_structured_parse_forces_sarvam_m_even_if_configured_model_is_bulbul(): void
    {
        config()->set('services.sarvam.subscription_key', 'test-key');
        config()->set('intake.sarvam_structured.chat_completions_url', 'https://api.sarvam.ai/v1/chat/completions');
        config()->set('intake.sarvam_structured.model', 'bulbul-v3');

        Http::fake(function ($request) {
            $payload = $request->data();
            $this->assertSame('sarvam-m', $payload['model'] ?? null);

            return Http::response([
                'choices' => [[
                    'message' => [
                        // minimal SSOT v2 shape: skeleton->ensure() will fill missing keys
                        'content' => json_encode(['core' => [], 'confidence_map' => []], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200);
        });

        $out = app(ExternalAiParsingService::class)->parseToSsotV2WithProvider("नाव :- कु. टेस्ट", 'sarvam');
        $this->assertIsArray($out);
        $this->assertArrayHasKey('core', $out);
    }
}

