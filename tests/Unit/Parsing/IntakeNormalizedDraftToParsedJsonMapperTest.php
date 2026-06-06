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

    public function test_mapper_adds_section_contract_in_canonical_top_level_order(): void
    {
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map([
            'normalized' => [
                'core' => [
                    'full_name' => 'Asha Patil',
                    'brother_count' => 1,
                    'sister_count' => 2,
                ],
            ],
        ]);

        $this->assertSame([
            'section_order',
            'sectioned',
            'missing_map',
            'core',
            'contacts',
            'birth_place',
            'native_place',
            'children',
            'marriages',
            'education_history',
            'career_history',
            'addresses',
            'parents_addresses',
            'siblings',
            'relatives',
            'relatives_parents_family',
            'relatives_maternal_family',
            'relatives_sectioned',
            'alliance_networks',
            'property_summary',
            'property_assets',
            'horoscope',
            'legal_cases',
            'preferences',
            'extended_narrative',
            'confidence_map',
        ], array_slice(array_keys($parsed), 0, 26));
        $this->assertSame(1, $parsed['core']['brothers_count']);
        $this->assertSame(2, $parsed['core']['sisters_count']);
        $this->assertSame(1, $parsed['core']['brother_count']);
        $this->assertSame(2, $parsed['core']['sister_count']);
        $this->assertSame('derived', $parsed['sectioned']['family-details']['brothers_count']['status']);
        $this->assertSame('derived', $parsed['sectioned']['family-details']['sisters_count']['status']);
        $this->assertSame([], $parsed['legal_cases']);
        $this->assertArrayHasKey('basic-info', $parsed['sectioned']);
        $this->assertArrayHasKey('physical', $parsed['sectioned']);
        $this->assertArrayHasKey('education-career', $parsed['sectioned']);
        $this->assertArrayHasKey('family-details', $parsed['sectioned']);
        $this->assertArrayHasKey('legal-cases', $parsed['sectioned']);
        $this->assertArrayHasKey('basic-info.sub_caste', $parsed['missing_map']);
    }

    public function test_mapper_preserves_core_ids_from_normalized_draft(): void
    {
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map([
            'normalized' => [
                'core' => [
                    'gender' => 'female',
                    'gender_id' => 2,
                    'religion' => 'हिंदू',
                    'religion_id' => 5,
                    'caste' => 'मराठा',
                    'caste_id' => 8,
                    'sub_caste' => '96 कुळी',
                    'sub_caste_id' => 13,
                    'marital_status' => 'unmarried',
                    'marital_status_id' => 1,
                    'mother_tongue_id' => 4,
                    'birth_city_id' => 101,
                    'birth_taluka_id' => 102,
                    'birth_district_id' => 103,
                    'birth_state_id' => 104,
                    'country_id' => 91,
                    'state_id' => 27,
                    'district_id' => 28,
                    'taluka_id' => 29,
                    'city_id' => 30,
                    'complexion_id' => 6,
                    'blood_group_id' => 7,
                    'physical_build_id' => 9,
                    'working_with_type_id' => 10,
                    'profession_id' => 11,
                    'family_type_id' => 12,
                    'serious_intent_id' => 14,
                ],
            ],
        ]);

        $core = $parsed['core'];

        $this->assertSame(2, $core['gender_id'] ?? null);
        $this->assertSame(5, $core['religion_id'] ?? null);
        $this->assertSame(8, $core['caste_id'] ?? null);
        $this->assertSame(13, $core['sub_caste_id'] ?? null);
        $this->assertSame(1, $core['marital_status_id'] ?? null);
        $this->assertSame(4, $core['mother_tongue_id'] ?? null);
        $this->assertSame(101, $core['birth_city_id'] ?? null);
        $this->assertSame(102, $core['birth_taluka_id'] ?? null);
        $this->assertSame(103, $core['birth_district_id'] ?? null);
        $this->assertSame(104, $core['birth_state_id'] ?? null);
        $this->assertSame(91, $core['country_id'] ?? null);
        $this->assertSame(27, $core['state_id'] ?? null);
        $this->assertSame(28, $core['district_id'] ?? null);
        $this->assertSame(29, $core['taluka_id'] ?? null);
        $this->assertSame(30, $core['city_id'] ?? null);
        $this->assertSame(6, $core['complexion_id'] ?? null);
        $this->assertSame(7, $core['blood_group_id'] ?? null);
        $this->assertSame(9, $core['physical_build_id'] ?? null);
        $this->assertSame(10, $core['working_with_type_id'] ?? null);
        $this->assertSame(11, $core['profession_id'] ?? null);
        $this->assertSame(12, $core['family_type_id'] ?? null);
        $this->assertSame(14, $core['serious_intent_id'] ?? null);
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

    public function test_mapper_splits_nakshatra_charan_and_navras_name_from_normalized_horoscope(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
जन्मरास :- वृषभ
जन्मनक्षत्र :- रोहिणी ४ नावरस नाव : वू
TXT);
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);
        $row = $parsed['horoscope'][0] ?? [];

        $this->assertSame('वृषभ', $row['rashi'] ?? null);
        $this->assertSame('रोहिणी', $row['nakshatra'] ?? null);
        $this->assertSame('४', $row['charan'] ?? null);
        $this->assertSame('वू', $row['navras_name'] ?? null);
        $this->assertArrayHasKey('mangal_dosh_type_id', $row);
        $this->assertArrayHasKey('yoni_id', $row);
        $this->assertSame(null, $row['mangal_dosh_type_id']);
        $this->assertSame(null, $row['yoni_id']);
    }

    public function test_mapper_keeps_mahesh_more_normalized_draft_fields_in_parsed_json(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
।। श्री खंडोबा प्रसन्न ।। ।। गजानन प्रसन्न ।। ।। श्री जोतिर्लिंग प्रसन्न ।।

## मुलाची माहिती

- मुलाचे नांव :- कु. महेश बळवंत मोरे
- जन्म तारीख :- १३/०५/१९९४
- जन्मवेळ व वार :- शुक्रवार रात्री १२ वा. १५ मि.
- उंची :- ५ फुट ६ इंच
- जन्मरास :- वृषभ
- जन्मनक्षत्र :- रोहिणी ४ नावरस नाव : वू
- जात :- हिंदू-मराठा (९६ कुळी)
- शिक्षण :- B.E. Civil
- नोकरी :- चौगुले होसमानी बिझनेस असोसिएट्स, कोल्हापूर
- पगार :- 42,000/- (प्रति महिना)
- व्यवसाय :- गव्हर्नमेंट कॉन्ट्रॅक्टर, प्रायव्हेट बिल्डींग प्लॅनिंग अॅण्ड इस्टीमेटींग
- शेती :- ३ एकर

## कौटुंबिक माहिती

- वडीलांचे नांव :- श्री. बळवंत पांडुरंग मोरे
- (सेवानिवृत्त केन यार्ड सुपरवायझर कुंभी-कासारी सह. साखर कारखाना,
- कुडित्रे)
- आईचे नांव :- सौ. मुक्ता बळवंत मोरे (गृहिणी)
- भाऊ :- एक - अविवाहित कु. पवन बळवंत मोरे (B.A)
- (व्यवसाय - श्री पांडुरंग ट्रेडर्स,प्लंबींग ॲण्ड हार्डवेअर्स, खुपीरे)
- बहिण :- दोन - विवाहित १.सौ. शितल उत्तम पाटील (वाळोली, ता. पन्हाळा)
- सौ. गिता सतिश निर्मळ (कंदलगाव, ता. करवीर)
- आत्या :- सौ. सुमन अशोक कापडे (रा. सांगरुळ, ता. करवीर)
- मामा :- १.श्री. कृष्णात बापू हुजरे- पाटील
- श्री. सर्जेराव बापू हुजरे-पाटील (गव्हर्नमेंट कॉन्ट्रॅक्टर)
- श्री. यशवंत बापू हुजरे - पाटील (प्राध्यापक)
- सर्व रा. खुपिरे, ता. करवीर, जि. कोल्हापूर.
- इतर पाहुणे :- पाटील, निर्मळ, शिंदे, हुजरे, नाळे, खाडे, खोत, जांभळे, केंबळेकर,
- भोसले, रामाने , पोवार .
- घरचा पत्ता :- मु. वाकरे, ता. करवीर, जि. कोल्हापूर.
- संपर्क :- ८६००२३३७४७/ ९४०३५५४२९३
TXT);
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);

        $core = $parsed['core'] ?? [];
        $this->assertSame('शुक्रवार रात्री १२ वा. १५ मि.', $core['birth_time'] ?? null);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) ($core['sub_caste'] ?? '')));
        $this->assertSame(504000, $core['annual_income'] ?? null);
        $this->assertStringContainsString('पोवार', (string) ($core['other_relatives_text'] ?? ''));

        $maternalUncles = array_values(array_filter(
            $parsed['relatives'] ?? [],
            static fn ($row) => ($row['relation_type'] ?? null) === 'maternal_uncle'
        ));

        $this->assertCount(3, $maternalUncles);
        $this->assertSame('खुपिरे, ता. करवीर, जि. कोल्हापूर.', $maternalUncles[0]['address_line'] ?? null);
        $this->assertSame('खुपिरे, ता. करवीर, जि. कोल्हापूर.', $maternalUncles[1]['address_line'] ?? null);
        $this->assertSame('खुपिरे, ता. करवीर, जि. कोल्हापूर.', $maternalUncles[2]['address_line'] ?? null);
        $this->assertStringNotContainsString('\"name\":\"सर्व\"', json_encode($parsed['relatives'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('\"name\":\"इतर\"', json_encode($parsed['relatives'] ?? [], JSON_UNESCAPED_UNICODE));
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

    public function test_mapper_populates_sectionwise_maternal_relatives_and_expectations_from_apurva_sample(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
|| श्री ||
नाव - अपूर्वा सुधीर डोंगरे
जन्मतारीख - ४-०३-२०००
जन्मवेळ - सकाळी ७ वाजता
शिक्षण- B.com, MBA(Finance)
नोकरी - Capgemini- SAP consultant (Sr. Analyst)
ऊंची-५.६
वर्ण-गोरा
रक्तगट - B+
देवक - आरखड
नाडी - मध्य
कौटुंबिक माहिती
वडील – सुधीर रामचंद्र डोंगरे (सेवानिवृत)
आई – उज्वला सुधीर डोंगरे (प्राध्यापिका)
भाऊ – प्रज्वल सुधीर डोंगरे (विवाहित) (IT engineer)
वाहिनी – मानसी प्रज्वल डोंगरे (Civil engineer )
बहीण – स्नेहल मयूर शेंडकर (विवाहित) (IT engineer)
जावई -मयूर बाळू शेंडकर (व्यवसाईक)
मूळ गाव – मु.पोस्ट आर्वी नारायणगाव,पुणे
निवास – पंतनगर,घाटकोपर(e),मुंबई
मामा – राजेश गणपत पोखरकर
नातेसंबंध – पोखरकर,वर्पे,मुळे,ढोबळे,इंदोरे,तट्टू,ढमाले,घंघाले,डुंबरे,शेंडकर,तापकिर,दांगट,औटी
अपेक्षा – निर्व्यसनी,उच्च शिक्षित,नोकरी,सुसंस्कृत
भ्रमणध्वनी – ९५९४२३७११७, ९६९९७३८८२२, ८६५५२११७२८
TXT);
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);

        $this->assertSame(
            'निर्व्यसनी,उच्च शिक्षित,नोकरी,सुसंस्कृत',
            $parsed['preferences']['expectations'] ?? null
        );
        $this->assertSame(
            'निर्व्यसनी,उच्च शिक्षित,नोकरी,सुसंस्कृत',
            $parsed['extended_narrative']['narrative_expectations'] ?? null
        );

        $maternal = $parsed['relatives_maternal_family'] ?? [];
        $this->assertNotEmpty($maternal);
        $this->assertSame('maternal_uncle', $maternal[0]['relation_type'] ?? null);
        $this->assertSame('राजेश गणपत पोखरकर', $maternal[0]['name'] ?? null);

        $sectionedMama = $parsed['relatives_sectioned']['maternal']['mama'] ?? [];
        $this->assertNotEmpty($sectionedMama);
        $this->assertSame('maternal_uncle', $sectionedMama[0]['relation_type'] ?? null);
        $this->assertSame('राजेश गणपत पोखरकर', $sectionedMama[0]['name'] ?? null);
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
        $this->assertArrayHasKey('marital_status', $parsed['siblings'][1] ?? []);
        $this->assertNull($parsed['siblings'][1]['marital_status'] ?? null);
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

    public function test_akshada_draft_maps_sectionwise_salary_horoscope_family_property_and_sibling_fields(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($this->akshadaText());
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);

        $core = $parsed['core'];
        $this->assertSame('Bajaj Electricals', $core['company_name'] ?? null);
        $this->assertSame('नवी मुंबई', $core['work_location_text'] ?? null);
        $this->assertSame(1675000, $core['annual_income'] ?? null);
        $this->assertSame('शेती/व्यावसायिक', $core['father_occupation'] ?? null);
        $this->assertSame('B.Com', $core['father_extra_info'] ?? null);
        $this->assertSame('A+', $core['blood_group'] ?? null);

        $parentsAddresses = $parsed['parents_addresses'] ?? [];
        $this->assertCount(2, $parentsAddresses);
        $this->assertSame('ईशा बेला विस्टा, डी-वींग,फ्लॅट नं.१०३', $parentsAddresses[0]['address_line'] ?? null);
        $this->assertSame('यमुना निवास,अल्कोन आकाशिया जवळ; कोंढवा बु.।। ता.हवेली जि.पुणे-४११०४८', $parentsAddresses[1]['address_line'] ?? null);

        $propertySummary = $parsed['property_summary'] ?? [];
        $this->assertTrue((bool) ($propertySummary['owns_house'] ?? false));
        $this->assertTrue((bool) ($propertySummary['owns_flat'] ?? false));
        $this->assertTrue((bool) ($propertySummary['owns_agriculture'] ?? false));
        $this->assertStringContainsString('शॉप(भाडे)', (string) ($propertySummary['summary_notes'] ?? ''));
        $this->assertStringNotContainsString('( शेती/व्यावसायिक )', (string) ($propertySummary['summary_notes'] ?? ''));

        $propertyBlob = json_encode($parsed['property_assets'] ?? [], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('शॉप(भाडे)', (string) $propertyBlob);
        $this->assertStringContainsString('रुम भाडे', (string) $propertyBlob);

        $siblings = $parsed['siblings'] ?? [];
        $this->assertCount(1, $siblings);
        $this->assertSame('San Francisco (USA)', $siblings[0]['address_line'] ?? null);
        $this->assertSame('शिक्षण IIT चेन्नई M.tech', $siblings[0]['notes'] ?? null);

        $horoscope = $parsed['horoscope'][0] ?? [];
        $this->assertSame('जेजुरीचा खंडोबा', $horoscope['kuldaivat'] ?? null);
        $this->assertSame('वडाचे पान', $horoscope['devak'] ?? null);
        $this->assertSame('उत्तरा भाद्र पदा', $horoscope['nakshatra'] ?? null);
        $this->assertSame('मध्य', $horoscope['nadi'] ?? null);
        $this->assertSame('तिसरे', $horoscope['charan'] ?? null);
        $this->assertSame('मनुष्य', $horoscope['gan'] ?? null);
        $this->assertSame('मिन', $horoscope['rashi'] ?? null);
        $this->assertSame('बज्ठ', $horoscope['yog'] ?? null);
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
