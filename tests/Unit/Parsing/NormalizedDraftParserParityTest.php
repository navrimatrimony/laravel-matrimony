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

    public function test_akshada_normalized_improves_salary_family_property_and_horoscope_fields(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($this->akshadaText());
        $normalized = app(IntakeParsedSnapshotSkeleton::class)->ensure(
            app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft)
        );

        $this->assertSame('Bajaj Electricals', $normalized['core']['company_name'] ?? null);
        $this->assertSame('नवी मुंबई', $normalized['core']['work_location_text'] ?? null);
        $this->assertSame(1675000, $normalized['core']['annual_income'] ?? null);
        $this->assertSame('शेती/व्यावसायिक', $normalized['core']['father_occupation'] ?? null);
        $this->assertSame('B.Com', $normalized['core']['father_extra_info'] ?? null);
        $this->assertSame('A+', $normalized['core']['blood_group'] ?? null);

        $this->assertCount(2, $normalized['parents_addresses'] ?? []);
        $this->assertSame('San Francisco (USA)', $normalized['siblings'][0]['address_line'] ?? null);
        $this->assertSame('शिक्षण IIT चेन्नई M.tech', $normalized['siblings'][0]['notes'] ?? null);
        $this->assertSame('जेजुरीचा खंडोबा', $normalized['horoscope'][0]['kuldaivat'] ?? null);
        $this->assertSame('मिन', $normalized['horoscope'][0]['rashi'] ?? null);
        $this->assertSame('बज्ठ', $normalized['horoscope'][0]['yog'] ?? null);
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

    private function akshadaText(): string
    {
        return <<<'TXT'
मुलीचे नाव : कु.अक्षदा अनिल कामठे
पत्ता : १.ईशा बेला विस्टा, डी-वींग,फ्लॅट नं.१०३
२.यमुना निवास,अल्कोन आकाशिया जवळ,
कोंढवा बु.।। ता.हवेली जि.पुणे-४११०४८
जन्म तारीख : १४ नोव्हेंबर १९९४
जन्म वेळ : सकाळी ७ वाजून ० मि.
जन्म ठिकाण : पुणे
उंची : ५ फुट ४ इंच
वर्ण : गोरा
शिक्षण : B.E.MECHANICAL / M.Tech (Design)
नोकरी/व्यवसाय : Bajaj Electricals नवी मुंबई
16,75,000 P/A
जात : ९६ कुळी मराठा
कुल दैवत : जेजुरीचा खंडोबा
वडिलांचे नाव : श्री.अनिल बबन कामठे
( शेती/व्यावसायिक ) (B.Com)
मो.नं. : 9881459325 / 9307777812
स्वता:ची मालमत्ता : शेती/फ्लॅट/शॉप(भाडे)/रुम भाडे/स्वतःचे घर
आईचे नाव : सौ.राधिका अनिल कामठे (गृहिणी)
भाऊ : कु.अनिकेत अनिल कामठे (शिक्षण IIT चेन्नई M.tech)
नोकरी : San Francisco (USA)
मामा : श्री.सुरेश तुकाराम वटारे (घोरपडी, पुणे)
नाते संबंध : लिपाने, हरपळे, तापकिर, तारु
देवक : वडाचे पान | जन्मनक्षत्र : उत्तरा भाद्र पदा | नाडी : मध्य | चरण : तिसरे
गण : मनुष्य | रास : मिन | योग : बज्ठ | रक्तगट : A+
TXT;
    }
}
