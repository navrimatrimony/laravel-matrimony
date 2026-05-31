<?php

namespace Tests\Unit\Parsing;

use App\Services\BiodataParserService;
use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use App\Services\Parsing\IntakeNormalizedDraftToParsedJsonMapper;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use App\Services\Parsing\Parsers\RulesOnlyBiodataParser;
use Tests\TestCase;

class NormalizedDraftParserParityTest extends TestCase
{
    protected function tearDown(): void
    {
        config(['intake.use_normalized_draft_parser' => false]);
        parent::tearDown();
    }

    public function test_yuvraj_normalized_improves_known_fields_without_byte_parity(): void
    {
        [$legacy, $normalized] = $this->buildLegacyAndNormalized($this->yuvrajText());

        $this->assertContains('7350953384', $this->contactPhones($normalized));
        $this->assertContains('9673350078', $this->contactPhones($normalized));
        $this->assertSame('7350953384', (string) ($normalized['core']['primary_contact_number'] ?? ''));
        $this->assertNull($normalized['core']['gender'] ?? null);
        $this->assertSame('हिंदू', $normalized['core']['religion'] ?? null);
        $this->assertSame('मराठा', $normalized['core']['caste'] ?? null);
        $this->assertNotSame($legacy, $normalized);
    }

    public function test_swapnil_normalized_avoids_fake_sibling_rows_and_relative_bleed(): void
    {
        [, $normalized] = $this->buildLegacyAndNormalized($this->swapnilText());

        $this->assertSame('male', $normalized['core']['gender'] ?? null);
        $this->assertSame(0, $normalized['core']['brother_count'] ?? null);
        $this->assertSame(1, $normalized['core']['sister_count'] ?? null);

        foreach ($normalized['siblings'] ?? [] as $sibling) {
            $this->assertNotContains(trim((string) ($sibling['name'] ?? '')), ['नाही', 'एक']);
        }

        foreach ($normalized['relatives'] ?? [] as $relative) {
            $blob = implode(' ', array_map('strval', is_array($relative) ? $relative : []));
            $this->assertStringNotContainsString('घरचा पत्ता', $blob);
            $this->assertStringNotContainsString('मोबाइल नंबर', $blob);
        }
    }

    public function test_mahesh_normalized_keeps_candidate_name_distinct_from_father(): void
    {
        [, $normalized] = $this->buildLegacyAndNormalized($this->maheshText());

        $this->assertSame('महेशकुमार मोहन जगताप', $normalized['core']['full_name'] ?? null);
        $this->assertSame('मोहनराव गणपतराव जगताप', $normalized['core']['father_name'] ?? null);
        $this->assertNotSame($normalized['core']['father_name'], $normalized['core']['full_name']);

        $addressBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $normalized['addresses'] ?? []
        ));
        $this->assertStringNotContainsString('मोबाईल', $addressBlob);
    }

    public function test_flag_false_keeps_rules_only_legacy_output_for_html_table_sample(): void
    {
        config(['intake.use_normalized_draft_parser' => false]);

        $text = <<<'HTML'
<table>
<tr><td>मुलाचे नाव</td><td>चि. टेस्ट नाव शिंदे</td></tr>
<tr><td>मोबाईल</td><td>9876543210</td></tr>
</table>
HTML;

        $expected = app(IntakeParsedSnapshotSkeleton::class)->ensure(
            app(BiodataParserService::class)->parse($text)
        );

        $out = app(RulesOnlyBiodataParser::class)->parse($text);

        $this->assertSame($expected, $out);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function buildLegacyAndNormalized(string $text): array
    {
        $legacy = app(IntakeParsedSnapshotSkeleton::class)->ensure(
            app(BiodataParserService::class)->parse($text)
        );
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);
        $normalized = app(IntakeParsedSnapshotSkeleton::class)->ensure(
            app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft)
        );

        return [$legacy, $normalized];
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
