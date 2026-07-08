<?php

namespace Tests\Unit\Parsing;

use App\Services\BiodataParserService;
use App\Services\ExternalAiParsingService;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use App\Services\Parsing\Parsers\AiFirstBiodataParser;
use App\Services\Parsing\Parsers\RulesOnlyBiodataParser;
use Mockery;
use Tests\TestCase;

class AiFirstRulesMergeUsesLegacyTest extends TestCase
{
    protected function tearDown(): void
    {
        config(['intake.use_normalized_draft_parser' => false]);
        Mockery::close();
        parent::tearDown();
    }

    public function test_ai_first_merge_calls_rules_parser_with_legacy_rules_only_when_flag_true(): void
    {
        config(['intake.use_normalized_draft_parser' => true]);

        $text = $this->maheshText();
        $legacyRules = app(IntakeParsedSnapshotSkeleton::class)->ensure(
            app(BiodataParserService::class)->parse($text)
        );

        $rulesContexts = [];
        $rulesSpy = Mockery::spy(RulesOnlyBiodataParser::class)->makePartial();
        $rulesSpy->shouldReceive('parse')
            ->andReturnUsing(function (string $rawText, array $context) use (&$rulesContexts, $legacyRules) {
                $rulesContexts[] = $context;

                return $legacyRules;
            });
        $this->app->instance(RulesOnlyBiodataParser::class, $rulesSpy);

        $this->mock(ExternalAiParsingService::class, function ($mock): void {
            $mock->shouldReceive('parseToSsot')->once()->andReturn([
                'core' => [
                    'full_name' => null,
                    'father_name' => null,
                    'primary_contact_number' => null,
                ],
                'confidence_map' => [],
            ]);
        });

        $out = app(AiFirstBiodataParser::class)->parse($text, ['parser_mode' => 'ai_first_v1']);

        $this->assertNotEmpty($rulesContexts);
        foreach ($rulesContexts as $context) {
            $this->assertTrue($context['legacy_rules_only'] ?? false);
        }
        $this->assertArrayNotHasKey('normalized_biodata_draft', $out);
        $this->assertArrayNotHasKey('cleaned_text', $out);
        $this->assertArrayNotHasKey('sections', $out);
    }

    public function test_ai_first_fallback_uses_legacy_rules_when_flag_true(): void
    {
        config(['intake.use_normalized_draft_parser' => true]);

        $text = $this->swapnilText();
        $expected = app(RulesOnlyBiodataParser::class)->parse($text, ['legacy_rules_only' => true]);

        $this->mock(ExternalAiParsingService::class, function ($mock): void {
            $mock->shouldReceive('parseToSsot')->once()->andThrow(new \RuntimeException('ai unavailable'));
        });

        $out = app(AiFirstBiodataParser::class)->parse($text, ['parser_mode' => 'ai_first_v1']);

        $this->assertSame($expected, $out);
        $this->assertSame('स्वप्नील सतिश शिंदे', ($out['core']['full_name'] ?? null));
        $this->assertSame('male', ($out['core']['gender'] ?? null));
    }

    private function maheshText(): string
    {
        return <<<'TXT'
कास्ट :- ९६ कुळी मराठा
पित्याचे नाव :-मोहनराव गणपतराव जगताप
प्रोपर्टी :- 1BHK Flat (1) 2 BHK Flat (2)
गावचा पत्ता :- चंद्रेश बिल्डिंग, ठाणे

## महेशकुमार मोहन जगताप

मोबाईल नंबर :- महेश मोहन जगताप (९८७०८७९७२७)
:- मोहन जगताप (९१३७७९३३७१)
TXT;
    }

    private function swapnilText(): string
    {
        return <<<'TXT'
बायोडाटा
मुलाचे नांव :- चि. स्वप्नील सतिश शिंदे
भाऊ :- नाही
बहीण :- एक ( अविवाहित )
आत्या :- श्री. भाऊसो कृष्णाजी मोरे रा. इस्लामपूर
घरचा पत्ता :- मु. पो. समडोळी , ता. मिरज , जि. सांगली.
मोबाइल नंबर :- 9860956022 / 8668270153
TXT;
    }
}
