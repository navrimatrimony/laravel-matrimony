<?php

namespace Tests\Unit\Parsing;

use App\Services\BiodataParserService;
use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use App\Services\Parsing\Parsers\RulesOnlyBiodataParser;
use Tests\TestCase;

class RulesOnlyBiodataParserNormalizedFlagTest extends TestCase
{
    protected function tearDown(): void
    {
        config(['intake.use_normalized_draft_parser' => false]);
        parent::tearDown();
    }

    public function test_flag_false_uses_legacy_rules_parser_path(): void
    {
        config(['intake.use_normalized_draft_parser' => false]);

        $text = $this->swapnilText();
        $expected = app(IntakeParsedSnapshotSkeleton::class)->ensure(
            app(BiodataParserService::class)->parse($text)
        );

        $out = app(RulesOnlyBiodataParser::class)->parse($text);

        $this->assertSame($expected, $out);
        $this->assertNoDraftMetadataKeys($out);
    }

    public function test_flag_true_uses_builder_mapper_path_for_golden_samples(): void
    {
        config(['intake.use_normalized_draft_parser' => true]);

        foreach ([
            'yuvraj' => [$this->yuvrajText(), '7350953384', '9673350078'],
            'swapnil' => [$this->swapnilText(), '9860956022', '8668270153'],
            'mahesh' => [$this->maheshText(), '9870879727', '9137793371'],
        ] as $label => [$text, $primary, $secondary]) {
            $out = app(RulesOnlyBiodataParser::class)->parse($text);
            $core = $out['core'] ?? [];

            $this->assertArrayHasKey('core', $out, $label);
            $this->assertArrayHasKey('contacts', $out, $label);
            $this->assertArrayHasKey('confidence_map', $out, $label);
            $this->assertNotSame('', (string) ($core['primary_contact_number'] ?? ''), $label);
            $this->assertContains($primary, $this->contactPhones($out), $label);
            $this->assertContains($secondary, $this->contactPhones($out), $label);
            $this->assertNoDraftMetadataKeys($out, $label);
        }

        $mahesh = app(RulesOnlyBiodataParser::class)->parse($this->maheshText());
        $core = $mahesh['core'] ?? [];
        $this->assertSame('महेशकुमार मोहन जगताप', $core['full_name'] ?? null);
        $this->assertSame('मोहनराव गणपतराव जगताप', $core['father_name'] ?? null);
        $this->assertNotSame($core['father_name'], $core['full_name']);
        $this->assertSame('9870879727', (string) ($core['primary_contact_number'] ?? ''));
    }

    public function test_legacy_rules_only_context_forces_legacy_even_when_flag_true(): void
    {
        config(['intake.use_normalized_draft_parser' => true]);

        $text = $this->maheshText();
        $expected = app(IntakeParsedSnapshotSkeleton::class)->ensure(
            app(BiodataParserService::class)->parse($text)
        );

        $out = app(RulesOnlyBiodataParser::class)->parse($text, ['legacy_rules_only' => true]);

        $this->assertSame($expected, $out);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function assertNoDraftMetadataKeys(array $parsed, string $label = ''): void
    {
        $suffix = $label !== '' ? " ({$label})" : '';
        foreach (['normalized_biodata_draft', 'cleaned_text', 'sections', 'meta', 'review_flags'] as $key) {
            $this->assertArrayNotHasKey($key, $parsed, "Unexpected draft key {$key}{$suffix}");
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    private function contactPhones(array $parsed): array
    {
        $phones = [];
        foreach ($parsed['contacts'] ?? [] as $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $phone = OcrNormalize::normalizePhone((string) ($contact['phone_number'] ?? $contact['number'] ?? ''));
            if (is_string($phone) && preg_match('/^[6-9]\d{9}$/', $phone)) {
                $phones[] = $phone;
            }
        }

        return array_values(array_unique($phones));
    }

    private function yuvrajText(): string
    {
        return <<<'TXT'
वैयक्तिक माहिती
नाव : कु. युवराज नामदेव घाटेगस्ती.
जात : हिंदू मराठा {96 कुळी}
वेतन/उत्पन्न : 3.6 LAC वार्षिक
आईचे नाव : सौ. सुनंदा नामदेव घाटेगस्ती. { गृहिणी }
मोबाईल नं : 73509 53384/ 96733 50078
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
}
