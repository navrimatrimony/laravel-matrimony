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

    public function test_mapper_carries_other_relatives_text_from_normalized_core(): void
    {
        $text = 'इतर पाहुणे: मामा पुणे, काका सातारा';
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map([
            'normalized' => [
                'core' => [
                    'other_relatives_text' => $text,
                ],
            ],
        ]);

        $this->assertSame($text, $parsed['core']['other_relatives_text'] ?? null);
    }

    public function test_mapper_carries_horoscope_alias_fields_for_preview_and_form_parity(): void
    {
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map([
            'normalized' => [
                'horoscope' => [
                    'raw' => [
                        'स्वामी :- शनि',
                        'वैरवर्ग :- मानव',
                    ],
                    'rashi_lord' => 'शनि',
                    'vashya' => 'मानव',
                ],
            ],
        ]);

        $row = $parsed['horoscope'][0] ?? [];

        $this->assertSame('शनि', $row['rashi_lord'] ?? null);
        $this->assertSame('मानव', $row['vashya'] ?? null);
    }

    public function test_mapper_keeps_intake_457_horoscope_scalar_values_without_raw_reparse_overwrite(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
देवक :- साळुंकी, कलदैवत :-पालीचा खुंडोबा,
रास :- कन्या, योनी :- व्याघ्र,
रास नाव :- पेमदेवी, गण :- राक्षस
नक्षत्र :- चचत्रा, वर्ण :- वैश्य,
TXT);
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);
        $row = $parsed['horoscope'][0] ?? [];

        $this->assertSame('साळुंकी', $row['devak'] ?? null);
        $this->assertSame('पालीचा खुंडोबा', $row['kuldaivat'] ?? null);
        $this->assertSame('कन्या', $row['rashi'] ?? null);
        $this->assertSame('वाघ', $row['yoni'] ?? null);
        $this->assertSame('पेमदेवी', $row['navras_name'] ?? null);
        $this->assertSame('राक्षस', $row['gan'] ?? null);
        $this->assertSame('चित्रा', $row['nakshatra'] ?? null);
        $this->assertSame('वैश्य', $row['varna'] ?? null);
    }

    public function test_mapper_preserves_wizard_shaped_paternal_extended_family_fields(): void
    {
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map([
            'normalized' => [
                'relatives' => [
                    [
                        'id' => '9',
                        'relation_type' => 'paternal_uncle',
                        'name' => 'श्री. मोहन पाटील',
                        'occupation' => 'शेती',
                        'occupation_master_id' => '7',
                        'occupation_custom_id' => '8',
                        'contact_number' => '9876543210',
                        'address_line' => 'कळे, ता. पन्हाळा',
                        'location_display' => 'Kale, Maharashtra',
                        'city_id' => '101',
                        'state_id' => '104',
                        'notes' => 'मोठे चुलते',
                        'is_primary_contact' => true,
                    ],
                ],
            ],
        ]);

        $relative = $parsed['relatives'][0] ?? [];
        $paternal = $parsed['relatives_parents_family'][0] ?? [];

        $this->assertSame(9, $relative['id'] ?? null);
        $this->assertSame('paternal_uncle', $relative['relation_type'] ?? null);
        $this->assertSame('श्री. मोहन पाटील', $relative['name'] ?? null);
        $this->assertSame('शेती', $relative['occupation'] ?? null);
        $this->assertSame(7, $relative['occupation_master_id'] ?? null);
        $this->assertSame(8, $relative['occupation_custom_id'] ?? null);
        $this->assertSame('9876543210', $relative['contact_number'] ?? null);
        $this->assertSame('कळे, ता. पन्हाळा', $relative['address_line'] ?? null);
        $this->assertSame('Kale, Maharashtra', $relative['location_display'] ?? null);
        $this->assertSame(101, $relative['city_id'] ?? null);
        $this->assertSame(104, $relative['state_id'] ?? null);
        $this->assertSame('मोठे चुलते', $relative['notes'] ?? null);
        $this->assertTrue($relative['is_primary_contact'] ?? false);
        $this->assertSame($relative, $paternal);
    }

    public function test_mapper_preserves_wizard_shaped_sibling_fields(): void
    {
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map([
            'normalized' => [
                'siblings' => [
                    [
                        'id' => '12',
                        'relation_type' => 'brother',
                        'name' => 'ओंकार नितीन पोवार',
                        'gender' => 'male',
                        'marital_status' => 'married',
                        'occupation' => 'व्यवसाय',
                        'occupation_master_id' => '7',
                        'occupation_custom_id' => '8',
                        'contact_number' => '9876543210',
                        'contact_number_2' => '9765432109',
                        'contact_number_3' => '9765432110',
                        'address_line' => 'कोल्हापूर',
                        'location_display' => 'Kolhapur, Maharashtra',
                        'city_id' => '101',
                        'taluka_id' => '102',
                        'district_id' => '103',
                        'state_id' => '104',
                        'notes' => 'elder brother',
                        'sort_order' => '2',
                        'spouse' => [
                            'name' => 'सौ. नेहा ओंकार पोवार',
                            'occupation_title' => 'शिक्षिका',
                            'occupation_master_id' => '21',
                            'occupation_custom_id' => '22',
                            'contact_number' => '9123456789',
                            'address_line' => 'पुणे',
                            'location_display' => 'Pune, Maharashtra',
                            'city_id' => '201',
                            'taluka_id' => '202',
                            'district_id' => '203',
                            'state_id' => '204',
                        ],
                    ],
                    [
                        'relation_type' => 'sister',
                        'name' => 'आर्या नितीन पोवार',
                        'marital_status' => 'divorced',
                    ],
                ],
            ],
        ]);

        $sibling = $parsed['siblings'][0] ?? [];

        $this->assertSame(12, $sibling['id'] ?? null);
        $this->assertSame('brother', $sibling['relation_type'] ?? null);
        $this->assertSame('ओंकार नितीन पोवार', $sibling['name'] ?? null);
        $this->assertSame('male', $sibling['gender'] ?? null);
        $this->assertSame('married', $sibling['marital_status'] ?? null);
        $this->assertSame('व्यवसाय', $sibling['occupation'] ?? null);
        $this->assertSame(7, $sibling['occupation_master_id'] ?? null);
        $this->assertSame(8, $sibling['occupation_custom_id'] ?? null);
        $this->assertSame('9876543210', $sibling['contact_number'] ?? null);
        $this->assertSame('9765432109', $sibling['contact_number_2'] ?? null);
        $this->assertSame('9765432110', $sibling['contact_number_3'] ?? null);
        $this->assertSame('कोल्हापूर', $sibling['address_line'] ?? null);
        $this->assertSame('Kolhapur, Maharashtra', $sibling['location_display'] ?? null);
        $this->assertSame(101, $sibling['city_id'] ?? null);
        $this->assertSame(102, $sibling['taluka_id'] ?? null);
        $this->assertSame(103, $sibling['district_id'] ?? null);
        $this->assertSame(104, $sibling['state_id'] ?? null);
        $this->assertSame('elder brother', $sibling['notes'] ?? null);
        $this->assertSame(2, $sibling['sort_order'] ?? null);
        $this->assertSame('सौ. नेहा ओंकार पोवार', $sibling['spouse']['name'] ?? null);
        $this->assertSame('शिक्षिका', $sibling['spouse']['occupation_title'] ?? null);
        $this->assertSame(21, $sibling['spouse']['occupation_master_id'] ?? null);
        $this->assertSame(22, $sibling['spouse']['occupation_custom_id'] ?? null);
        $this->assertSame('9123456789', $sibling['spouse']['contact_number'] ?? null);
        $this->assertSame('पुणे', $sibling['spouse']['address_line'] ?? null);
        $this->assertSame('Pune, Maharashtra', $sibling['spouse']['location_display'] ?? null);
        $this->assertSame(201, $sibling['spouse']['city_id'] ?? null);
        $this->assertSame(202, $sibling['spouse']['taluka_id'] ?? null);
        $this->assertSame(203, $sibling['spouse']['district_id'] ?? null);
        $this->assertSame(204, $sibling['spouse']['state_id'] ?? null);
        $this->assertArrayNotHasKey('marital_status', $parsed['siblings'][1] ?? []);
    }

    public function test_mapper_carries_parent_addresses_without_self_address_overwrite(): void
    {
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map([
            'normalized' => [
                'parents_addresses' => [
                    [
                        'type' => 'parents',
                        'address_line' => 'मु. पो. विटा, ता. खानापूर, जि. सांगली',
                        'raw' => 'घरचा पत्ता: मु. पो. विटा, ता. खानापूर, जि. सांगली',
                    ],
                ],
            ],
        ]);

        $this->assertSame('parents', $parsed['parents_addresses'][0]['type'] ?? null);
        $this->assertSame('मु. पो. विटा, ता. खानापूर, जि. सांगली', $parsed['parents_addresses'][0]['address_line'] ?? null);
        $this->assertStringContainsString('घरचा पत्ता:', (string) ($parsed['parents_addresses'][0]['raw'] ?? ''));
        $this->assertSame([], $parsed['addresses']);
        $this->assertNull($parsed['core']['address_line'] ?? null);
    }

    public function test_mapper_skips_empty_or_invalid_parent_address_rows(): void
    {
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map([
            'normalized' => [
                'parents_addresses' => [
                    [],
                    ['address_line' => '', 'raw' => ''],
                    'not-array',
                ],
            ],
        ]);

        $this->assertSame([], $parsed['parents_addresses']);
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
