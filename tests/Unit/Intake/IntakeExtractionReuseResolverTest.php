<?php

namespace Tests\Unit\Intake;

use App\Models\BiodataIntake;
use App\Services\AiVisionExtractionService;
use App\Services\Intake\IntakeBiodataIdentityFingerprint;
use App\Services\Intake\IntakeExtractionReuseResolver;
use App\Services\Ocr\OcrQualityEvaluator;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IntakeExtractionReuseResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_fingerprint_cache_keeps_higher_quality_score(): void
    {
        $resolver = new IntakeExtractionReuseResolver(
            new IntakeBiodataIdentityFingerprint,
            new OcrQualityEvaluator,
        );

        $text = <<<'TXT'
मुलीचे नांव : कु. टेस्ट
जन्मतारीख : 10/05/1998
मो 9822222222
शिक्षण बी.कॉम
नोकरी खाजगी
जन्म
नाव
TXT;

        $intake = new \App\Models\BiodataIntake;
        $intake->id = 101;
        $resolver->recordSuccessfulPaidExtraction($intake, 'openai', $text, 0.55);

        $first = $resolver->getBestReusablePaidExtract('openai', $text);
        $this->assertSame(0.55, $first['quality_score']);

        $intake2 = new \App\Models\BiodataIntake;
        $intake2->id = 102;
        $resolver->recordSuccessfulPaidExtraction($intake2, 'openai', $text, 0.88);

        $second = $resolver->getBestReusablePaidExtract('openai', $text);
        $this->assertSame(0.88, $second['quality_score']);
        $this->assertSame(102, $second['source_intake_id']);
    }

    public function test_parse_input_only_flag_is_consumed_once(): void
    {
        $resolver = app(IntakeExtractionReuseResolver::class);
        IntakeExtractionReuseResolver::flagNextParseJobAsParseInputOnly(55);
        $this->assertTrue($resolver->consumeParseInputOnlyFlag(55));
        $this->assertFalse($resolver->consumeParseInputOnlyFlag(55));
    }

    public function test_force_fresh_paid_extraction_flag_is_consumed_once(): void
    {
        $resolver = app(IntakeExtractionReuseResolver::class);
        IntakeExtractionReuseResolver::flagNextParseJobAsReExtract(56);
        $this->assertTrue($resolver->consumeForceFreshPaidExtractionFlag(56));
        $this->assertFalse($resolver->consumeForceFreshPaidExtractionFlag(56));
    }

    public function test_force_fresh_skips_reuse_and_requests_paid_api_when_not_parse_only(): void
    {
        $resolver = new IntakeExtractionReuseResolver(
            new IntakeBiodataIdentityFingerprint,
            new OcrQualityEvaluator,
        );
        $ai = $this->mock(AiVisionExtractionService::class);
        $ai->shouldNotReceive('evaluateExtractedTextQuality');

        $intake = new BiodataIntake;
        $intake->id = 501;
        $intake->raw_ocr_text = "मुलीचे नांव : कु. टेस्ट\nजन्मतारीख : 12/03/1996\nमो 9876543210\n".str_repeat("फिलर.\n", 20);

        $out = $resolver->resolvePaidVisionInput($intake, 'openai', $ai, false, true);

        $this->assertTrue($out['call_paid_api']);
        $this->assertSame('', $out['text']);
        $this->assertNull($out['reused_from']);
    }
}
