<?php

namespace Tests\Unit\Parsing;

use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use App\Services\Parsing\IntakeNormalizedDraftToParsedJsonMapper;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakeNormalizedDraftToParsedJsonMapperTest extends TestCase
{
    public function test_yuvraj_draft_maps_to_parsed_json_safely(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($this->yuvrajText());
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);

        foreach (['core', 'contacts', 'addresses', 'property_summary', 'horoscope', 'confidence_map'] as $key) {
            $this->assertArrayHasKey($key, $parsed);
        }

        $core = $parsed['core'];
        $this->assertSame(
            'कु. युवराज नामदेव घाटेगस्ती',
            rtrim((string) ($core['full_name'] ?? ''), '.')
        );
        $this->assertNull($core['gender']);
        $this->assertSame(0.0, (float) ($parsed['confidence_map']['core.gender'] ?? -1));
        $this->assertSame('हिंदू', $core['religion']);
        $this->assertSame('मराठा', $core['caste']);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) $core['sub_caste']));
        if (($core['annual_income'] ?? null) !== null) {
            $this->assertSame(360000, (int) $core['annual_income']);
        }
        $this->assertSame('गृहिणी', $core['mother_occupation']);

        $phones = $this->contactPhones($parsed);
        $this->assertContains('7350953384', $phones);
        $this->assertContains('9673350078', $phones);
        $this->assertSame(1, $this->primaryContactCount($parsed));
        $this->assertSame('7350953384', (string) $core['primary_contact_number']);
        $this->assertArrayNotHasKey('normalized_biodata_draft', $parsed);
        $this->assertSame([], $queries);
    }

    public function test_swapnil_draft_maps_siblings_contact_and_relative_boundaries_safely(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($this->swapnilText());
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);

        $core = $parsed['core'];
        $this->assertSame('male', $core['gender']);
        $this->assertSame(0, $core['brother_count']);
        $this->assertSame(1, $core['sister_count']);

        foreach ($parsed['siblings'] as $sibling) {
            $this->assertNotContains(trim((string) ($sibling['name'] ?? '')), ['नाही', 'एक']);
        }

        $phones = $this->contactPhones($parsed);
        $this->assertContains('9860956022', $phones);
        $this->assertContains('8668270153', $phones);
        $this->assertSame(1, $this->primaryContactCount($parsed));
        $this->assertSame('9860956022', (string) $core['primary_contact_number']);

        foreach ($parsed['relatives'] as $relative) {
            $blob = implode(' ', array_map('strval', array_filter([
                $relative['name'] ?? null,
                $relative['notes'] ?? null,
                $relative['raw_note'] ?? null,
                $relative['address_line'] ?? null,
                $relative['location'] ?? null,
            ])));
            $this->assertStringNotContainsString('घरचा पत्ता', $blob);
            $this->assertStringNotContainsString('पाहुणे', $blob);
            $this->assertStringNotContainsString('मोबाइल नंबर', $blob);
        }
    }

    public function test_mahesh_draft_maps_candidate_father_contact_and_property_safely(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($this->maheshText());
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);

        $core = $parsed['core'];
        $this->assertSame('महेशकुमार मोहन जगताप', $core['full_name']);
        $this->assertNotSame($core['father_name'], $core['full_name']);
        $this->assertSame('मोहनराव गणपतराव जगताप', $core['father_name']);
        $this->assertSame('मराठा', $core['caste']);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) $core['sub_caste']));

        $phones = $this->contactPhones($parsed);
        $this->assertContains('9870879727', $phones);
        $this->assertContains('9137793371', $phones);
        $this->assertSame(1, $this->primaryContactCount($parsed));
        $this->assertSame('9870879727', (string) $core['primary_contact_number']);

        $property = $parsed['property_summary'];
        $this->assertTrue(is_array($property));
        $this->assertStringContainsString('Flat', (string) ($property['summary_notes'] ?? ''));

        $addressBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $parsed['addresses']
        ));
        $this->assertStringNotContainsString('मोबाईल', $addressBlob);
        $this->assertStringNotContainsString('संपर्क', $addressBlob);
        $this->assertStringNotContainsString('कौटुंबिक', $addressBlob);
    }

    public function test_mapper_output_shape_is_compatible_with_existing_parsed_json(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($this->yuvrajText());
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);

        foreach ([
            'core', 'contacts', 'children', 'education_history', 'career_history', 'addresses',
            'birth_place', 'native_place', 'property_summary', 'property_assets', 'horoscope',
            'preferences', 'extended_narrative', 'confidence_map', 'relatives',
        ] as $key) {
            $this->assertArrayHasKey($key, $parsed);
        }

        $core = $parsed['core'];
        foreach (['religion_id', 'caste_id', 'sub_caste_id', 'city_id', 'gender_id', 'birth_city_id'] as $idField) {
            $this->assertNull($core[$idField] ?? null, "Expected {$idField} to remain null");
        }
    }

    public function test_builder_and_mapper_do_not_persist_to_database(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($this->swapnilText());
        app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);

        $this->assertSame([], $queries);
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
            $phone = (string) ($contact['phone_number'] ?? $contact['number'] ?? '');
            $normalized = OcrNormalize::normalizePhone($phone);
            if (is_string($normalized) && preg_match('/^[6-9]\d{9}$/', $normalized)) {
                $phones[] = $normalized;
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function primaryContactCount(array $parsed): int
    {
        $count = 0;
        foreach ($parsed['contacts'] ?? [] as $contact) {
            if (is_array($contact) && ! empty($contact['is_primary'])) {
                $count++;
            }
        }

        return $count;
    }

    private function yuvrajText(): string
    {
        return <<<'TXT'
*प्रतिमा: decorative logo*
:■:
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
पाहुणे :- तातुगडे - देशमुख
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
