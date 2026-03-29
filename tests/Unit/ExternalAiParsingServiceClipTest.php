<?php

namespace Tests\Unit;

use App\Services\ExternalAiParsingService;
use ReflectionMethod;
use Tests\TestCase;

class ExternalAiParsingServiceClipTest extends TestCase
{
    public function test_clip_prefers_slice_with_more_devanagari_when_prefix_is_english_noise(): void
    {
        $prefix = str_repeat('The quick brown fox jumps. ', 120);
        // Marathi block must exceed $maxChars so a full window can sit entirely in Devanagari.
        $good = "परिचय पत्र\nनाव: कु. प्रीती राजेंद्र पाटील\nजन्म तारीख: 24/10/1998\n".str_repeat("ओळ माहिती अतिरिक्त मजकूर\n", 120);
        $text = $prefix.$good;

        $svc = app(ExternalAiParsingService::class);
        $m = new ReflectionMethod(ExternalAiParsingService::class, 'clipRawTextForStructuredExtraction');
        $m->setAccessible(true);
        $clip = $m->invoke($svc, $text, 800);

        $this->assertStringNotContainsString('quick brown fox', $clip);
        $this->assertStringContainsString('ओळ माहिती अतिरिक्त', $clip);
    }
}
