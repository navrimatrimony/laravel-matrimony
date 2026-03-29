<?php

namespace Tests\Unit\Parsing;

use App\Services\AiVisionExtractionService;
use Tests\TestCase;

class SarvamTranscriptionSanitizeTest extends TestCase
{
    public function test_drops_photo_and_deity_description_lines(): void
    {
        $raw = "वधूवर सूचक केंद्र\n"
            ."एका महिलाचा फोटो दिसत आहे.\n"
            ."हे चित्र भगवान गणेशाचे आहे.\n"
            ."मुलीचे नांव : कु. दिव्या हेमंत जाधव\n"
            ."रक्तगट : A+\n";

        $clean = AiVisionExtractionService::sanitizeTransientParseInputText($raw);

        $this->assertStringContainsString('मुलीचे नांव', $clean);
        $this->assertStringContainsString('रक्तगट', $clean);
        $this->assertStringNotContainsString('महिलाचा फोटो', $clean);
        $this->assertStringNotContainsString('भगवान गणेशाचे', $clean);
    }

    public function test_drops_bureau_header_lines(): void
    {
        $raw = "विवाह सूचक\nमुलीचे नांव : कु. दिव्या हेमंत जाधव\n";
        $clean = AiVisionExtractionService::sanitizeTransientParseInputText($raw);
        $this->assertStringNotContainsString('विवाह सूचक', $clean);
        $this->assertStringContainsString('मुलीचे नांव', $clean);
    }
}
