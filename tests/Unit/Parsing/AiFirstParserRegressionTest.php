<?php

namespace Tests\Unit\Parsing;

use App\Services\ExternalAiParsingService;
use App\Services\Parsing\Parsers\AiFirstBiodataParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * AI-first path with mocked external parse: rules merge + repairs for Sarvam-style sample (no live API).
 */
class AiFirstParserRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function sampleText(): string
    {
        return <<<'TXT'
वधूवर सूचक केंद्र संपर्क 9876543210
मुलीचे नांव : कु. दिव्या हेमंत जाधव
जन्मवेळ : सोमवार पहाटे 02 वा. 01 मि.
रक्तगट : A+
गण : राक्षस
नाडी : आध्य
वडिलांचे नांव : श्री. हेमंत आनंदराव जाधव, मो. 9123456789
भाऊ/बहिण : अविवाहित
मुलीचे चुलते
श्री. प्रविण राम पाटील (रा. मु.पो. कोळे) / श्री. सचिन दिनकर नलवडे B
मुलीची मावशी-काका श्री. अजित दिनकर नलवडे B
आजोळ : रा. मु.पो. सांगोला, ता. माळशिरस, जि. सोलापूर
मामा : श्री. दिनकर रामचंद्र कदम, मु
TXT;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_ai_first_v1_merges_rules_when_ai_core_and_structure_are_partially_wrong(): void
    {
        $badAi = [
            'core' => [
                'full_name' => null,
                'primary_contact_number' => null,
                'father_contact_1' => null,
                'blood_group' => null,
                'birth_time' => null,
                'father_name' => 'हेमंत आनंदराव जाधव, मो',
            ],
            'confidence_map' => [],
            'horoscope' => [
                ['gan' => 'ेशाचे आहे', 'blood_group' => '99'],
            ],
            'siblings' => [
                ['relation_type' => 'sister', 'name' => 'भाऊ/बहिण : अविवाहित'],
            ],
            'relatives' => [
                ['relation_type' => 'चुलते', 'name' => null, 'raw_note' => 'मुलीचे चुलते'],
            ],
        ];

        $this->mock(ExternalAiParsingService::class, function ($m) use ($badAi) {
            $m->shouldReceive('parseToSsot')->once()->andReturn($badAi);
        });

        $parser = app(AiFirstBiodataParser::class);
        $out = $parser->parse($this->sampleText(), ['parser_mode' => 'ai_first_v1']);

        $core = $out['core'] ?? [];
        $this->assertStringContainsString('दिव्या', (string) ($core['full_name'] ?? ''));
        $this->assertSame('A+', $core['blood_group'] ?? null);
        $this->assertNotNull($core['birth_time'] ?? null);
        $this->assertStringNotContainsString(', मो', (string) ($core['father_name'] ?? ''));
        $this->assertSame('9123456789', $core['father_contact_1'] ?? null);

        $this->assertSame('राक्षस', ($out['horoscope'][0]['gan'] ?? null));
        $this->assertSame('A+', ($out['horoscope'][0]['blood_group'] ?? null));

        foreach ($out['siblings'] ?? [] as $s) {
            $n = (string) ($s['name'] ?? '');
            $this->assertStringNotContainsString('अविवाहित', $n);
        }

        $mavshiKakaMale = false;
        foreach ($out['relatives'] ?? [] as $r) {
            if (($r['relation_type'] ?? '') === 'other_maternal' && str_contains((string) ($r['raw_note'] ?? ''), 'मावशी')) {
                $mavshiKakaMale = true;
            }
        }
        $this->assertTrue($mavshiKakaMale);

        $relTypes = array_map(fn ($r) => (string) ($r['relation_type'] ?? ''), $out['relatives'] ?? []);
        $this->assertContains('आजोळ', $relTypes);
    }
}
