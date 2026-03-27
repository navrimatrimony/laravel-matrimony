<?php

namespace Tests\Unit;

use App\Services\AiVisionExtractionService;
use Tests\TestCase;

class AiVisionPromptContractTest extends TestCase
{
    public function test_openai_vision_prompt_keeps_critical_transcription_safeguards(): void
    {
        $prompt = AiVisionExtractionService::openAiVisionTranscriptionPrompt();
        $text = strtolower(($prompt['system'] ?? '').' '.($prompt['user'] ?? ''));

        // 1) pure text transcription
        $this->assertStringContainsString('pure document text transcriber', $text);
        $this->assertStringContainsString('transcribe only visible written document text', $text);

        // 2) separator preservation
        $this->assertStringContainsString('preserve separators exactly', $text);
        $this->assertStringContainsString('":-"', $text);

        // 3) ignore photos / non-text visuals
        $this->assertStringContainsString('ignore all non-text visual elements', $text);
        $this->assertStringContainsString('photos', $text);

        // 4) no markdown / no base64
        $this->assertStringContainsString('no markdown', $text);
        $this->assertStringContainsString('no base64', $text);

        // 5) no image description
        $this->assertStringContainsString('not an image describer', $text);
        $this->assertStringNotContainsString('describe the image', $text);
        $this->assertStringNotContainsString('describe the scene', $text);
        $this->assertStringNotContainsString('what you see', $text);

        // 6) no two-column collapse
        $this->assertStringContainsString('never collapse both columns', $text);

        // 7) separate blocks allowed when alignment is difficult
        $this->assertStringContainsString('separate blocks', $text);
        $this->assertStringContainsString('side-by-side spacing is difficult', $text);

        // Negative guards: should not encourage structured/encoded output.
        $this->assertStringNotContainsString('return json', $text);
        $this->assertStringNotContainsString('respond in json', $text);
        $this->assertStringNotContainsString('markdown format', $text);
        $this->assertStringNotContainsString('base64-encoded', $text);
        $this->assertStringNotContainsString('encode', $text);
    }
}

