<?php

namespace Tests\Feature\Intake;

use App\Models\BiodataIntake;
use App\Models\User;
use App\Services\Intake\IntakePreviewNormalizedDraftPresenter;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use App\Services\ProfileForm\ProfileFormSectionSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakePreviewNormalizedDraftSectionTest extends TestCase
{
    use RefreshDatabase;

    private function maheshParseInputText(): string
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

    public function test_preview_page_shows_normalized_draft_section_without_mutating_intake_json(): void
    {
        $user = User::factory()->create();
        $parsed = app(IntakeParsedSnapshotSkeleton::class)->ensure([
            'core' => [
                'full_name' => 'महेशकुमार मोहन जगताप',
                'gender' => 'male',
                'date_of_birth' => '1995-01-01',
                'religion' => 'हिंदू',
                'caste' => 'मराठा',
                'sub_caste' => '96 कुळी',
                'primary_contact_number' => '9870879727',
            ],
            'contacts' => [
                ['phone_number' => '9870879727', 'is_primary' => true],
            ],
        ]);

        $approvalSnapshot = [
            'snapshot_schema_version' => 1,
            'core' => [
                'full_name' => 'Approval Snapshot Name',
                'gender' => 'male',
            ],
            'contacts' => [],
        ];

        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'stored ocr should not be primary',
            'last_parse_input_text' => $this->maheshParseInputText(),
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
            'parsed_json' => $parsed,
            'approval_snapshot_json' => $approvalSnapshot,
        ]);

        $parsedBefore = json_encode($intake->parsed_json);
        $approvalBefore = json_encode($intake->approval_snapshot_json);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'update `biodata_intakes`') || str_contains($sql, 'update biodata_intakes')) {
                $queries[] = $query->sql;
            }
        });

        $response = $this->actingAs($user)->get(route('intake.preview', $intake));

        $response->assertOk();
        $response->assertSee(__('intake.normalized_draft_heading'), false);
        $response->assertDontSee('Preview only', false);
        $response->assertSee(__('intake.normalized_draft_disclaimer'), false);
        $response->assertSee(__('intake.normalized_draft_needs_review_badge'), false);
        $response->assertSee(__('intake.normalized_draft_full_name_fallback_hint'), false);
        $response->assertSee('Raw normalized draft JSON', false);
        $response->assertSee('normalized_biodata_draft_v1', false);
        $response->assertSee(__('wizard.basic_info').' JSON', false);
        $response->assertSee(__('wizard.family_details').' JSON', false);
        $response->assertSee('Confidence map JSON', false);
        $response->assertSee('Full raw Parsed JSON', false);
        $response->assertSee('birth_district_id', false);
        $response->assertSeeInOrder([
            __('wizard.basic_info').' JSON',
            __('wizard.family_details').' JSON',
            __('intake.normalized_draft_section_horoscope_religious').' JSON',
            'Confidence map JSON',
        ], false);
        $response->assertSee('महेशकुमार', false);

        $intake->refresh();
        $this->assertSame($parsedBefore, json_encode($intake->parsed_json));
        $this->assertSame($approvalBefore, json_encode($intake->approval_snapshot_json));
        $this->assertSame([], $queries);
    }

    public function test_intake_preview_renders_editable_sections_in_canonical_schema_order(): void
    {
        $user = User::factory()->create();
        $parsed = app(IntakeParsedSnapshotSkeleton::class)->ensure([
            'core' => [
                'full_name' => 'Schema Order Candidate',
                'gender' => 'male',
                'date_of_birth' => '1995-01-01',
                'religion' => 'Hindu',
                'caste' => 'Maratha',
                'sub_caste' => '96 Kuli',
                'primary_contact_number' => '9876543210',
            ],
            'contacts' => [
                ['phone_number' => '9876543210', 'is_primary' => true],
            ],
        ]);

        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'Schema order raw OCR.',
            'last_parse_input_text' => 'Schema order preview text.',
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
            'parsed_json' => $parsed,
            'approval_snapshot_json' => [
                'snapshot_schema_version' => 1,
                'core' => [
                    'full_name' => 'Schema Order Candidate',
                    'gender' => 'male',
                ],
                'contacts' => [],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('intake.preview', $intake));

        $response->assertOk();
        $response->assertSee('तपासा आणि सुधारा — फॉर्म', false);
        $this->assertSame(
            ProfileFormSectionSchema::fullFormSectionKeys(),
            array_column($response->viewData('editableFormSections'), 'key')
        );
    }

    public function test_intake_preview_keeps_intake_only_review_panels(): void
    {
        $user = User::factory()->create();
        $parsed = app(IntakeParsedSnapshotSkeleton::class)->ensure([
            'core' => [
                'full_name' => 'Review Panel Candidate',
                'gender' => 'male',
                'date_of_birth' => '1995-01-01',
                'religion' => 'Hindu',
                'caste' => 'Maratha',
                'sub_caste' => '96 Kuli',
                'primary_contact_number' => '9876543210',
            ],
            'contacts' => [
                ['phone_number' => '9876543210', 'is_primary' => true],
            ],
        ]);

        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'stored ocr text',
            'last_parse_input_text' => 'review panel preview text',
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
            'parsed_json' => $parsed,
            'approval_snapshot_json' => [],
        ]);

        $response = $this->actingAs($user)->get(route('intake.preview', $intake));

        $response->assertOk();
        $response->assertSee('Parsed JSON', false);
        $response->assertSee(__('intake.normalized_draft_heading'), false);
        $response->assertDontSee('Preview only', false);
        $response->assertSee('तपासा आणि सुधारा — फॉर्म', false);
        $this->assertNotContains('parsed_json', array_column($response->viewData('editableFormSections'), 'key'));
        $this->assertNotContains('review_needed', array_column($response->viewData('editableFormSections'), 'key'));
    }

    public function test_intake_approval_route_still_exists(): void
    {
        $this->assertNotNull(Route::getRoutes()->getByName('intake.approve'));
    }

    public function test_intake_preview_passes_rashi_ashtakoota_json_for_horoscope_autofill(): void
    {
        $user = User::factory()->create();

        $varnaId = DB::table('master_varnas')->insertGetId([
            'key' => 'test_brahmin_autofill',
            'label' => 'Brahmin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $vashyaId = DB::table('master_vashyas')->insertGetId([
            'key' => 'test_manav_autofill',
            'label' => 'Manav',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lordId = DB::table('master_rashi_lords')->insertGetId([
            'key' => 'test_shani_autofill',
            'label' => 'Shani',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rashiId = DB::table('master_rashis')->insertGetId([
            'key' => 'test_kumbha_autofill',
            'label' => 'कुंभ',
            'is_active' => true,
            'varna_id' => $varnaId,
            'vashya_id' => $vashyaId,
            'rashi_lord_id' => $lordId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $parsed = app(IntakeParsedSnapshotSkeleton::class)->ensure([
            'core' => [
                'full_name' => 'Horoscope Intake',
                'gender' => 'male',
            ],
            'horoscope' => [[
                'rashi_id' => $rashiId,
            ]],
        ]);

        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'horoscope test',
            'last_parse_input_text' => 'horoscope test',
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
            'parsed_json' => $parsed,
            'approval_snapshot_json' => [],
        ]);

        $response = $this->actingAs($user)->get(route('intake.preview', $intake));

        $response->assertOk();
        $payload = $response->viewData('rashiAshtakootaJson');
        $this->assertIsArray($payload);
        $this->assertArrayHasKey((string) $rashiId, $payload);
        $this->assertSame([
            'varna_id' => $varnaId,
            'vashya_id' => $vashyaId,
            'rashi_lord_id' => $lordId,
            'varna' => 'Brahmin',
            'vashya' => 'Manav',
            'rashi_lord' => 'Shani',
        ], $payload[(string) $rashiId]);
    }

    public function test_normalized_draft_horoscope_section_uses_form_matched_headings(): void
    {
        $user = User::factory()->create();
        $parsed = app(IntakeParsedSnapshotSkeleton::class)->ensure([
            'core' => [
                'full_name' => 'Horoscope Heading Candidate',
                'gender' => 'male',
            ],
            'horoscope' => [[
                'navras_name' => 'सीताराम',
                'devak' => 'वड',
                'kuldaivat' => 'जोतिबा',
                'nakshatra' => 'पुनर्वसू',
                'charan' => '3',
                'rashi' => 'मिथुन',
                'gan' => 'देव',
                'nadi' => 'आदि',
                'yoni' => 'मार्जार',
                'varna' => 'शुद्र',
                'vashya' => 'मानव / नर',
                'rashi_lord' => 'बुध',
            ]],
        ]);

        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'horoscope heading raw',
            'last_parse_input_text' => <<<'TXT'
नावरस :- सीताराम
देवक :- वड
कुलस्वामी :- जोतिबा
नक्षत्र :- पुनर्वसू
चरण :- ३
राशी :- मिथुन
गण :- देव
नाडी :- आदि
योनी :- मार्जार
वर्ण :- शुद्र
वश्य :- मानव / नर
स्वामी :- बुध
TXT,
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
            'parsed_json' => $parsed,
            'approval_snapshot_json' => [],
        ]);

        $response = $this->actingAs($user)->get(route('intake.preview', $intake));

        $response->assertOk();
        $response->assertSee(__('intake.normalized_draft_section_horoscope_religious'), false);
        $response->assertSee(__('intake.normalized_draft_horoscope_basic_heading'), false);
        $response->assertSee(__('intake.normalized_draft_horoscope_details_heading'), false);
        $response->assertSee('सीताराम', false);
        $response->assertSee('मिथुन', false);
        $response->assertSee('मानव / नर', false);
    }

    public function test_normalized_draft_preview_renders_normal_horoscope_rows_inline_for_intake_457_case(): void
    {
        $user = User::factory()->create();
        $parsed = app(IntakeParsedSnapshotSkeleton::class)->ensure([
            'core' => [
                'full_name' => 'Intake 457 Candidate',
                'gender' => 'female',
            ],
        ]);

        $intake = BiodataIntake::create([
            'raw_ocr_text' => 'intake 457 horoscope raw',
            'last_parse_input_text' => <<<'TXT'
देवक :- साळुंकी, कलदैवत :-पालीचा खुंडोबा,
कुंची :- ५' ३" . वर्ण :- निमगोरा,
रास :- कन्या, योनी :- व्याघ्र,
रास नाव :- पेमदेवी, गण :- राक्षस
नक्षत्र :- चचत्रा, वर्ण :- वैश्य,
TXT,
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
            'parsed_json' => $parsed,
            'approval_snapshot_json' => [],
        ]);

        $response = $this->actingAs($user)->get(route('intake.preview', $intake));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/Nakshatra:<\/span>\s*<span[^>]*>चित्रा<\/span>/u', $html);
        $this->assertMatchesRegularExpression('/Rashi:<\/span>\s*<span[^>]*>कन्या<\/span>/u', $html);
        $this->assertMatchesRegularExpression('/Yoni:<\/span>\s*<span[^>]*>वाघ<\/span>/u', $html);
    }

    public function test_property_preview_uses_wizard_asset_fields_and_notes(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
प्रोपर्टी :- 1BHK Flat (1) 2 BHK Flat (2)
शेती :- १६ एकर बागायत, मु.पो. कळे, ता. पन्हाळा, जि. कोल्हापूर
स्वतःचे घर पुणे
वडिलोपार्जित जमीन सांगली
TXT, true);

        $property = $this->sectionBlob($out['sections']['property']);

        $this->assertStringContainsString('Property Asset 1', $property);
        $this->assertStringContainsString('Asset Type Flat', $property);
        $this->assertStringContainsString('Additional Information 1 BHK Flat', $property);
        $this->assertStringContainsString('Property Asset 2', $property);
        $this->assertStringContainsString('Additional Information 2 Flats, 2 BHK', $property);
        $this->assertStringContainsString('Property Asset 3', $property);
        $this->assertStringContainsString('Location मु.पो. कळे, ता. पन्हाळा, जि. कोल्हापूर', $property);
        $this->assertStringContainsString('Additional Information Farm land, Bagayat, १६ एकर', $property);
        $this->assertStringContainsString('Property Asset 4', $property);
        $this->assertStringContainsString('Asset Type House', $property);
        $this->assertStringContainsString('Location पुणे', $property);
        $this->assertStringContainsString('Ownership Type Sole', $property);
        $this->assertStringContainsString('Property Asset 5', $property);
        $this->assertStringContainsString('Location सांगली', $property);
        $this->assertStringContainsString('Additional Information Not mentioned', $property);
        $this->assertStringNotContainsString('Owns house', $property);
        $this->assertStringNotContainsString('Total land acres', $property);
        $this->assertStringNotContainsString('Notes प्रोपर्टी :-', $property);
        $this->assertStringContainsString('Notes Not mentioned', $property);
    }

    public function test_property_preview_keeps_mixed_house_plot_and_land_details_from_single_line(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
स्थावर :- स्वतः चे घर , ५ गुंठे प्लॉट व जमीन - १ एकर ( बागायत )
TXT, true);

        $property = $this->sectionBlob($out['sections']['property']);

        $this->assertStringContainsString('Asset Type House', $property);
        $this->assertStringContainsString('Ownership Type Sole', $property);
        $this->assertStringContainsString('Asset Type Plot', $property);
        $this->assertStringContainsString('Additional Information ५ गुंठे', $property);
        $this->assertStringContainsString('Asset Type Land', $property);
        $this->assertStringContainsString('Additional Information Farm land, Bagayat, १ एकर', $property);
    }

    public function test_property_preview_does_not_create_false_house_from_address_only_text(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
नाव :- श्वेताली बाळासाहेब सुंबे
पता :- घर नुं.३७, आशियाना कॉलोनी, सावेडी, अहमदनगर - ४१४ ००३.
TXT, true);

        $property = $this->sectionBlob($out['sections']['property']);

        $this->assertStringNotContainsString('Asset Type House', $property);
        $this->assertStringContainsString('Notes Not mentioned', $property);
    }

    public function test_property_preview_keeps_kolhapur_house_plot_and_bambavade_land_details(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
स्थावर व शेती : कोल्हापूर येथे स्वतःचे घर व २ प्लॉट, चार एक्कर शेती बांबवडे/ कळंबा
स्थावर व शेती : सद्या- श्रीराम फोंड्री (झंवर ग्रुप ) सुपर वायझर (Quality Development)
TXT, true);

        $property = $this->sectionBlob($out['sections']['property']);

        $this->assertStringContainsString('Asset Type House', $property);
        $this->assertStringContainsString('Location कोल्हापूर', $property);
        $this->assertStringContainsString('Asset Type Plot', $property);
        $this->assertStringContainsString('Additional Information 2 Plots', $property);
        $this->assertStringContainsString('Location बांबवडे/ कळंबा', $property);
        $this->assertStringContainsString('Additional Information Farm land, 4 एकर', $property);
        $this->assertStringNotContainsString('श्रीराम फोंड्री', $property);
    }

    public function test_property_preview_keeps_braced_belgaav_land_location(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
शेती : 01 एकर शेती {बेळगाव.}
TXT, true);

        $property = $this->sectionBlob($out['sections']['property']);

        $this->assertStringContainsString('Asset Type Land', $property);
        $this->assertStringContainsString('Location बेळगाव', $property);
        $this->assertStringContainsString('Additional Information Farm land, 01 एकर', $property);
    }

    public function test_property_preview_inherits_self_house_descriptor_for_numbered_locations(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
स्थावर मिळकत : स्वतःचे घर - १) बाबा जरगनगर, कोल्हापूर
२) मंगळवार पेठ, कोल्हापूर
TXT, true);

        $property = $this->sectionBlob($out['sections']['property']);

        $this->assertStringContainsString('Property Asset 1', $property);
        $this->assertStringContainsString('Asset Type House', $property);
        $this->assertStringContainsString('Ownership Type Sole', $property);
        $this->assertStringContainsString('Location बाबा जरगनगर, कोल्हापूर', $property);
        $this->assertStringContainsString('Additional Information Not mentioned', $property);
        $this->assertStringContainsString('Property Asset 2', $property);
        $this->assertStringContainsString('Location मंगळवार पेठ, कोल्हापूर', $property);
        $this->assertStringContainsString('Notes Not mentioned', $property);
    }

    public function test_vishal_sample_routes_normalized_draft_to_wizard_sections(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
## बायोडेटा

- मुलाचे नाव : विशाल पांडुरंग डाकवे
- जन्म तारीख : ०२/११/१९९५
- जन्मवार आणि वेळ : गुरुवारी सकाळी ११ वा. २७ मी.
- नावरस : सीताराम
- रास : कुंभ
- देवक : वासनलिवेल
- उंची : ५ फूट 4 इंच
- कुलस्वामी : जोतिबा
- शिक्षण : BE (MECH)
- जात : हिंदू-मराठा
- नोकरी : Production Engineer
- वडिलांचे नाव : पांडुरंग लक्ष्मण डाकवे (नोकरी-9322202146)
- आईचे नाव : सुवर्णा पांडुरंग डाकवे (नोकरी-9527610122)
- पत्ता : मु. पो. डाकेवाडी काळगाव ता. पाटण जि.सातारा
- निवासी पत्ता : A/303, Wonder Residency ,fatherwadi Vasai.
- चुलते : कै. शामराव लक्ष्मण डाकवे, कृष्णा लक्ष्मण डाकवे,
- हरि लक्ष्मण डाकवे.
- आजोळ : मु. पो. कुठरे मोळावडेवाडी ता. पाटण जि.सातारा
- मामा : जितेंद्र शामराव पवार
TXT, true);

        $basic = $this->sectionBlob($out['sections']['basic-info']);
        $physical = $this->sectionBlob($out['sections']['physical']);
        $education = $this->sectionBlob($out['sections']['education-career']);
        $family = $this->sectionBlob($out['sections']['family-details']);
        $paternal = $this->sectionBlob($out['sections']['relatives']);
        $maternalAndOther = $this->sectionBlob($out['sections']['alliance']);
        $horoscope = $this->sectionBlob($out['sections']['horoscope']);
        $about = $this->sectionBlob($out['sections']['about-me']);

        $this->assertStringContainsString('विशाल पांडुरंग डाकवे', $basic);
        $this->assertStringContainsString('Male', $basic);
        $this->assertStringContainsString('०२/११/१९९५', $basic);
        $this->assertStringContainsString('गुरुवारी सकाळी ११ वा. २७ मी.', $basic);
        $this->assertStringContainsString('हिंदू', $basic);
        $this->assertStringContainsString('मराठा', $basic);
        $this->assertStringNotContainsString('Sub caste', $basic);
        $this->assertStringNotContainsString('Native / Parents address', $basic);
        $this->assertStringNotContainsString('9322202146', $basic);
        $this->assertStringContainsString('163 cm', $physical);
        $this->assertStringContainsString('BE (MECH)', $education);
        $this->assertStringContainsString('Production Engineer', $education);
        $this->assertStringContainsString('पांडुरंग लक्ष्मण डाकवे', $family);
        $this->assertStringContainsString('9322202146', $family);
        $this->assertStringContainsString('सुवर्णा पांडुरंग डाकवे', $family);
        $this->assertStringContainsString('9527610122', $family);
        $this->assertStringContainsString('Parents address 1', $family);
        $this->assertStringContainsString('मु. पो. डाकेवाडी काळगाव ता. पाटण जि.सातारा', $family);
        $this->assertStringContainsString('Parents address 2', $family);
        $this->assertStringContainsString('A/303, Wonder Residency ,fatherwadi Vasai.', $family);
        $this->assertArrayNotHasKey('contacts', $out['sections']);
        $this->assertGreaterThanOrEqual(3, substr_count($paternal, 'Paternal Uncle (chulte)'));
        $this->assertStringContainsString('कै. शामराव लक्ष्मण डाकवे', $paternal);
        $this->assertStringContainsString('कृष्णा लक्ष्मण डाकवे', $paternal);
        $this->assertStringContainsString('हरि लक्ष्मण डाकवे', $paternal);
        $this->assertStringContainsString('Maternal address (Ajol)', $maternalAndOther);
        $this->assertStringContainsString('मु. पो. कुठरे मोळावडेवाडी ता. पाटण जि.सातारा', $maternalAndOther);
        $this->assertStringContainsString('Maternal Uncle (mama)', $maternalAndOther);
        $this->assertStringContainsString('जितेंद्र शामराव पवार', $maternalAndOther);
        $this->assertStringNotContainsString('चुलते', $maternalAndOther);
        $this->assertStringNotContainsString('मामा', $paternal);
        $this->assertStringContainsString('सीताराम', $horoscope);
        $this->assertStringContainsString('कुंभ', $horoscope);
        $this->assertStringContainsString('वासनलिवेल', $horoscope);
        $this->assertStringContainsString('जोतिबा', $horoscope);
        $this->assertStringContainsString('गुरुवार', $horoscope);
        $this->assertStringNotContainsString('Horoscope line 1', $horoscope);
        $this->assertSame('', $about);
        $this->assertNoDuplicateEditableSectionValues($out['sections']);
    }

    public function test_education_career_preview_splits_company_location_and_package(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
शिक्षण :- MSC (computer science)
नोकरी :- Simplify healthcare, Magarpatta ( package = 3.55 LPA )
TXT, true);

        $education = $this->sectionBlob($out['sections']['education-career']);

        $this->assertStringContainsString('MSC (computer science)', $education);
        $this->assertStringNotContainsString('Occupation title Simplify healthcare', $education);
        $this->assertStringContainsString('Company name Simplify healthcare', $education);
        $this->assertStringContainsString('Work location Magarpatta', $education);
        $this->assertStringContainsString('Annual income 355000', $education);
        $this->assertStringNotContainsString('Salary package text', $education);
        $this->assertStringNotContainsString('package = 3.55 LPA', $education);
    }

    public function test_extended_family_preview_uses_paternal_wizard_fields_and_options(): void
    {
        $text = <<<'TXT'
वडिलांचे वडील : कै. भाऊराव पाटील
वडिलांची आई : सौ. लक्ष्मी भाऊराव पाटील (गृहिणी)
चुलते : श्री. मोहन पाटील (शेती) मो. 9876543210 पत्ता: कळे, ता. पन्हाळा
चुलती : सौ. माया मोहन पाटील (गृहिणी)
आत्या : सौ. रेखा जाधव (पुणे)
आत्यांचे यजमान : श्री. संजय जाधव (नोकरी - शिक्षक)
चुलत भाऊ : चि. रोहित मोहन पाटील (B.Com)
TXT;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);
        $relatives = $draft['normalized']['relatives'] ?? [];

        $this->assertSame('paternal_grandfather', $relatives[0]['relation_type'] ?? null);
        $this->assertSame('कै. भाऊराव पाटील', $relatives[0]['name'] ?? null);
        $this->assertSame('paternal_grandmother', $relatives[1]['relation_type'] ?? null);
        $this->assertSame('गृहिणी', $relatives[1]['occupation'] ?? null);
        $this->assertSame('paternal_uncle', $relatives[2]['relation_type'] ?? null);
        $this->assertSame('श्री. मोहन पाटील', $relatives[2]['name'] ?? null);
        $this->assertSame('शेती', $relatives[2]['occupation'] ?? null);
        $this->assertSame('9876543210', $relatives[2]['contact_number'] ?? null);
        $this->assertSame('कळे, ता. पन्हाळा', $relatives[2]['address_line'] ?? null);
        $this->assertSame('wife_paternal_uncle', $relatives[3]['relation_type'] ?? null);
        $this->assertSame('paternal_aunt', $relatives[4]['relation_type'] ?? null);
        $this->assertSame('पुणे', $relatives[4]['address_line'] ?? null);
        $this->assertSame('husband_paternal_aunt', $relatives[5]['relation_type'] ?? null);
        $this->assertSame('शिक्षक', $relatives[5]['occupation'] ?? null);
        $this->assertSame('Cousin', $relatives[6]['relation_type'] ?? null);
        $this->assertSame('B.Com', $relatives[6]['notes'] ?? null);

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($text, true);
        $extended = $this->sectionBlob($out['sections']['relatives']);

        $this->assertStringContainsString('Paternal Grandfather - कै. भाऊराव पाटील', $extended);
        $this->assertStringContainsString('Mobile 9876543210', $extended);
        $this->assertStringContainsString('Occupation शेती', $extended);
        $this->assertStringContainsString('Address कळे, ता. पन्हाळा', $extended);
        $this->assertStringContainsString('Wife of Paternal Uncle (chulti) - सौ. माया मोहन पाटील', $extended);
        $this->assertStringContainsString('Occupation शिक्षक', $extended);
        $this->assertStringContainsString('Additional info B.Com', $extended);
        $this->assertStringNotContainsString('Relative 1 Relation', $extended);
    }

    public function test_education_career_preview_treats_company_only_job_line_as_employer(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
शिक्षण :- B.E (Electrical)
नोकरी :- Vitesco Technologies Private Limited.
TXT, true);

        $education = $this->sectionBlob($out['sections']['education-career']);

        $this->assertStringContainsString('B.E (Electrical)', $education);
        $this->assertStringNotContainsString('Occupation title Vitesco Technologies Private Limited.', $education);
        $this->assertStringContainsString('Company name Vitesco Technologies Private Limited.', $education);
    }

    public function test_education_career_preview_does_not_use_brother_job_as_candidate_job(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
## ॥श्री गणेशाय नमः ॥ ॥तुळजाभवानी प्रसन्न ॥ ॥श्री खंडोबा प्रसन्न ॥
परिचय पत्र

- मुलीचे नाव :- कु. प्रीती राजेंद्र पाटील
- जन्म तारीख :- 24/10/1998 जन्म वेळ :- रात्री 09 वा.45 मि.
- जन्म ठिकाण :- माळीनगर. ता.- माळशिरस, जि.सोलापूर.
- शिक्षण :- BE – Computer Engineering.
- नोकरी :- Amdocs Company Magarpatta,Pune.
- Annual Package ( 9 Lacs.)
- जात :- हिंदु- 96 कुळी मराठा.
- उंची :- 5 फूट 4 इंच. वर्ण :- गोरा
- देवक :- वासनिचा वेल रक्त गट :- B+ve
- रास :- वृश्चिक नक्षत्र :- मृग
- नाड :- आध्य गण :- राक्षस. चरण :- ४
- वडिलांचे नाव :- श्री.राजेंद्र भाऊराव पाटील
- नोकरी :- दि.सासवड माळी शुगर फॅक्टरी,माळीनगर.
- पत्ता :- माळीनगर (गणेश कॉलनी)
- ता. माळशिरस जि. सोलापूर
- आईचे नाव :- सौ. अनिता राजेंद्र पाटील (गृहिणी)
- भाऊ :- श्री. समर्थ राजेंद्र पाटील (9145206745)
- पत्ता :- फ्लॅट नं सी-510 और्रा काउंटी उबाळे नगर,
- वाघोली, पुणे.
- नोकरी :- Bharat Forge Mundhawa,Pune.
- बहीण :- सौ. पुजा नवनाथ कन्हेरे.
- दाजी :- श्री.नवनाथ रामचंद्र कन्हेरे पत्ता. देहू रोड, पुणे.
TXT, true);

        $education = $this->sectionBlob($out['sections']['education-career']);
        $family = $this->sectionBlob($out['sections']['family-details']);
        $siblings = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString('BE – Computer Engineering.', $education);
        $this->assertStringContainsString('Company name Amdocs Company', $education);
        $this->assertStringContainsString('Work location Magarpatta, Pune.', $education);
        $this->assertStringContainsString('Annual income 900000', $education);
        $this->assertStringNotContainsString('Bharat Forge', $education);
        $this->assertStringNotContainsString('सासवड माळी शुगर', $education);
        $this->assertStringContainsString('Occupation नोकरी', $family);
        $this->assertStringContainsString('Additional दि.सासवड माळी शुगर फॅक्टरी,माळीनगर.', $family);
        $this->assertStringContainsString('Brother', $siblings);
        $this->assertStringContainsString('Bharat Forge Mundhawa,Pune.', $siblings);
        $this->assertStringContainsString('फ्लॅट नं सी-510', $siblings);
        $this->assertStringContainsString('Married married', $siblings);
        $this->assertStringContainsString("Sister's husband नवनाथ रामचंद्र कन्हेरे", $siblings);
        $this->assertStringContainsString("Address देहू रोड, पुणे", $siblings);
        $this->assertStringNotContainsString('Sibling 1 Name', $siblings);
        $this->assertStringNotContainsString('Spouse name', $siblings);
    }

    public function test_family_details_preview_routes_parent_contacts_and_nearby_address_to_family_section(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव :- चि. संकेत शंकर पाटील
शिक्षण :- B.Com
वडिलांचे नाव :- श्री. शंकर पांडुरंग पाटील (शेती)
मोबा. :- 9423831346 / 9764894006
पत्ता :- फ्लॅट नं 12, गणेश सोसायटी, लक्ष्मी रोड
उरुण इस्लामपूर, ता. वाळवा, जि. सांगली
आईचे नाव :- सौ. सुनीता शंकर पाटील (गृहिणी)
मोबाईल :- 9876543210
कुटुंब स्थिती :- मध्यमवर्गीय
कुटुंब मूल्ये :- पारंपरिक
कुटुंब उत्पन्न :- 5 लाख
TXT, true);

        $family = $this->sectionBlob($out['sections']['family-details']);
        $basic = $this->sectionBlob($out['sections']['basic-info']);

        $this->assertStringContainsString('Father - श्री. शंकर पांडुरंग पाटील', $family);
        $this->assertStringContainsString('Occupation शेती', $family);
        $this->assertStringContainsString('Contact 1 9423831346', $family);
        $this->assertStringContainsString('Contact 2 9764894006', $family);
        $this->assertStringContainsString('Mother - सौ. सुनीता शंकर पाटील', $family);
        $this->assertStringContainsString('Occupation गृहिणी', $family);
        $this->assertStringContainsString('9876543210', $family);
        $this->assertStringContainsString('Parents address 1 फ्लॅट नं 12, गणेश सोसायटी, लक्ष्मी रोड; उरुण इस्लामपूर, ता. वाळवा, जि. सांगली', $family);
        $this->assertStringContainsString('Family status मध्यमवर्गीय', $family);
        $this->assertStringContainsString('Family values पारंपरिक', $family);
        $this->assertStringContainsString('Family income 5 लाख', $family);
        $this->assertStringNotContainsString('फ्लॅट नं 12, गणेश सोसायटी', $basic);
    }

    public function test_family_details_preview_omits_sibling_counts_from_family_section(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नांव : चि. धीरज नितीन पोवार
वडिलांचे नांव : कै. श्री. नितीन विलासराव पोवार,
आईचे नांव : श्रीमती नीता नितीन पोवार (गृहिणी)
भाऊ : एक - विवाहीत - ओंकार नितीन पोवार
बहीण : नाही
TXT, true);

        $family = $this->sectionBlob($out['sections']['family-details']);
        $siblings = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString('Father - कै. श्री. नितीन विलासराव पोवार', $family);
        $this->assertStringContainsString('Mother - श्रीमती नीता नितीन पोवार', $family);
        $this->assertStringNotContainsString('Sister count', $family);
        $this->assertStringNotContainsString('Brother count', $family);
        $this->assertStringContainsString('Brother', $siblings);
    }

    public function test_siblings_preview_uses_wizard_fields_and_options(): void
    {
        $text = <<<'TXT'
मुलाचे नांव : चि. धीरज नितीन पोवार
भाऊ : एक - विवाहीत - ओंकार नितीन पोवार (व्यवसाय) 9876543210
पत्ता : कोल्हापूर
बहीण : अविवाहीत - कु. आर्या नितीन पोवार 9765432109
TXT;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);
        $siblings = $draft['normalized']['siblings'] ?? [];

        $this->assertSame('brother', $siblings[0]['relation_type'] ?? null);
        $this->assertSame('married', $siblings[0]['marital_status'] ?? null);
        $this->assertSame('ओंकार नितीन पोवार', $siblings[0]['name'] ?? null);
        $this->assertSame('व्यवसाय', $siblings[0]['occupation'] ?? null);
        $this->assertSame('9876543210', $siblings[0]['contact_number'] ?? null);
        $this->assertSame('कोल्हापूर', $siblings[0]['address_line'] ?? null);
        $this->assertSame('sister', $siblings[1]['relation_type'] ?? null);
        $this->assertSame('unmarried', $siblings[1]['marital_status'] ?? null);
        $this->assertSame('आर्या नितीन पोवार', $siblings[1]['name'] ?? null);
        $this->assertSame('9765432109', $siblings[1]['contact_number'] ?? null);

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($text, true);
        $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString('Married married', $siblingsBlob);
        $this->assertStringContainsString('Mobile 9876543210', $siblingsBlob);
        $this->assertStringContainsString('Occupation व्यवसाय', $siblingsBlob);
        $this->assertStringContainsString('Address कोल्हापूर', $siblingsBlob);
        $this->assertStringContainsString('Married unmarried', $siblingsBlob);
        $this->assertStringNotContainsString('Sibling 1 Relation', $siblingsBlob);
    }

    public function test_siblings_preview_captures_unlabelled_continuation_names(): void
    {
        $text = <<<'TXT'
बहिण - कु.प्रतीक्षा उत्तम फडतरे
कु.शितल उत्तम फडतरे
भाऊ - कु.रोहन उत्तम फडतरे
कु.रोनक बाळकृष्ण फाळके
TXT;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);
        $siblings = $draft['normalized']['siblings'] ?? [];

        $this->assertCount(4, $siblings);
        $this->assertSame('sister', $siblings[0]['relation_type'] ?? null);
        $this->assertSame('प्रतीक्षा उत्तम फडतरे', $siblings[0]['name'] ?? null);
        $this->assertSame('sister', $siblings[1]['relation_type'] ?? null);
        $this->assertSame('शितल उत्तम फडतरे', $siblings[1]['name'] ?? null);
        $this->assertSame('brother', $siblings[2]['relation_type'] ?? null);
        $this->assertSame('रोहन उत्तम फडतरे', $siblings[2]['name'] ?? null);
        $this->assertSame('brother', $siblings[3]['relation_type'] ?? null);
        $this->assertSame('रोनक बाळकृष्ण फाळके', $siblings[3]['name'] ?? null);

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($text, true);
        $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString('Sister 1 - प्रतीक्षा उत्तम फडतरे', $siblingsBlob);
        $this->assertStringContainsString('Sister 2 - शितल उत्तम फडतरे', $siblingsBlob);
        $this->assertStringContainsString('Brother 1 - रोहन उत्तम फडतरे', $siblingsBlob);
        $this->assertStringContainsString('Brother 2 - रोनक बाळकृष्ण फाळके', $siblingsBlob);
    }

    public function test_siblings_preview_does_not_bleed_relatives_after_status_only_brother_line(): void
    {
        $text = <<<'TXT'
भाऊ : एक - अविवाहीत
कु. विवेक सर्जेराव पाटील (व्यवसाय)
मुलाचे मामा : श्री. सरदार ज्ञानोबा खांबे (कळे, ता. पन्हाळा, जि. कोल्हापूर)
भगवान ज्ञानोबा खांबे (कळे, ता. पन्हाळा, जि. कोल्हापूर)
इतर पाहूणे : पाटील, देवणे, मेंगाणे, शेळके, साळोखे, खांबे, कामिरे आणि परिवार
TXT;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);
        $siblings = $draft['normalized']['siblings'] ?? [];

        $this->assertCount(1, $siblings);
        $this->assertSame('brother', $siblings[0]['relation_type'] ?? null);
        $this->assertSame('unmarried', $siblings[0]['marital_status'] ?? null);
        $this->assertSame('विवेक सर्जेराव पाटील', $siblings[0]['name'] ?? null);
        $this->assertSame('व्यवसाय', $siblings[0]['occupation'] ?? null);

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($text, true);
        $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString('Brother - विवेक सर्जेराव पाटील', $siblingsBlob);
        $this->assertStringContainsString('Married unmarried', $siblingsBlob);
        $this->assertStringNotContainsString('मामा', $siblingsBlob);
        $this->assertStringNotContainsString('भगवान ज्ञानोबा खांबे', $siblingsBlob);
        $this->assertStringNotContainsString('इतर पाहूणे', $siblingsBlob);
    }

    public function test_siblings_preview_flattens_spouse_people_with_relation_titles(): void
    {
        $text = <<<'TXT'
मुलीचे नांव : कु. प्रीती राजेंद्र पाटील
भाऊ : विवाहित - श्री. समर्थ राजेंद्र पाटील (Bharat Forge) 9876543210
भावजय : सौ. कविता समर्थ पाटील (शिक्षिका) पत्ता. वाघोली, पुणे 9123456789
बहीण : सौ. पुजा नवनाथ कन्हेरे
दाजी :- श्री.नवनाथ रामचंद्र कन्हेरे पत्ता. देहू रोड, पुणे.
TXT;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);
        $siblings = $draft['normalized']['siblings'] ?? [];

        $this->assertSame('brother_wife', $siblings[1]['relation_type'] ?? null);
        $this->assertSame('married', $siblings[1]['marital_status'] ?? null);
        $this->assertSame('कविता समर्थ पाटील', $siblings[1]['name'] ?? null);
        $this->assertSame('शिक्षिका', $siblings[1]['occupation'] ?? null);
        $this->assertSame('वाघोली, पुणे', $siblings[1]['address_line'] ?? null);

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($text, true);
        $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString('Brother - समर्थ राजेंद्र पाटील', $siblingsBlob);
        $this->assertStringContainsString("Brother's wife - कविता समर्थ पाटील", $siblingsBlob);
        $this->assertStringContainsString("Married married", $siblingsBlob);
        $this->assertStringContainsString("Mobile 9123456789", $siblingsBlob);
        $this->assertStringContainsString("Occupation शिक्षिका", $siblingsBlob);
        $this->assertStringContainsString("Address वाघोली, पुणे", $siblingsBlob);
        $this->assertStringContainsString("Sister's husband नवनाथ रामचंद्र कन्हेरे", $siblingsBlob);
        $this->assertStringContainsString("Married married", $siblingsBlob);
        $this->assertStringContainsString("Address देहू रोड, पुणे", $siblingsBlob);
        $this->assertStringNotContainsString("Sister's husband 2", $siblingsBlob);
        $this->assertStringNotContainsString('Sibling 1 Name', $siblingsBlob);
        $this->assertStringNotContainsString('Spouse name', $siblingsBlob);
    }

    public function test_siblings_preview_uses_numbers_only_when_relation_repeats_and_stops_at_relatives(): void
    {
        $text = <<<'TXT'
भाऊ :- श्री. समर्थ राजेंद्र पाटील (9145206745)
पत्ता :- फ्लॅट नं सी-510 और्रा काउंटी उबाळे नगर,
वाघोली, पुणे.
नोकरी :- Bharat Forge Mundhawa,Pune.
बहीण :- सौ. पुजा नवनाथ कन्हेरे.
दाजी :- श्री.नवनाथ रामचंद्र कन्हेरे पत्ता. देहू रोड, पुणे.
चुलते :- श्री. अनिल भाऊराव पाटील ( वाघोली, पुणे)
TXT;

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($text, true);
        $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString('Brother - समर्थ राजेंद्र पाटील', $siblingsBlob);
        $this->assertStringContainsString('Sister - पुजा नवनाथ कन्हेरे.', $siblingsBlob);
        $this->assertStringContainsString("Sister's husband नवनाथ रामचंद्र कन्हेरे", $siblingsBlob);
        $this->assertStringContainsString("Sister's husband Address देहू रोड, पुणे.", $siblingsBlob);
        $this->assertStringNotContainsString('Brother 1 Name', $siblingsBlob);
        $this->assertStringNotContainsString('Sister 1 Name', $siblingsBlob);
        $this->assertStringNotContainsString('चुलते', $siblingsBlob);
        $this->assertStringNotContainsString('अनिल भाऊराव पाटील', $siblingsBlob);
    }

    public function test_siblings_preview_captures_multiple_jawai_rows_with_matching_numbers(): void
    {
        $text = <<<'TXT'
बहीण :- २ बहिणी (Married)
जावई :- १. दत्ताजी खंडेराव शिंदे (सरकार), बत्तीस शिराळा,
सांगली (व्यवसाय)
डॉ. अजय वसंतराव शिंदे (मुर्ती बारामती)
श्री क्लिनिक, डोंबिवली, ठाणे
प्रोपर्टी :- 1BHK Flat
TXT;

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($text, true);
        $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString("Sister's husband 1 - दत्ताजी खंडेराव शिंदे (सरकार)", $siblingsBlob);
        $this->assertStringContainsString("Occupation व्यवसाय", $siblingsBlob);
        $this->assertStringContainsString("Address बत्तीस शिराळा, सांगली", $siblingsBlob);
        $this->assertStringContainsString("डॉ. अजय वसंतराव शिंदे", $siblingsBlob);
        $this->assertStringContainsString("Occupation श्री क्लिनिक, डोंबिवली, ठाणे", $siblingsBlob);
        $this->assertStringContainsString("Address मुर्ती बारामती", $siblingsBlob);
        $this->assertStringNotContainsString("Sister's husband Occupation सरकार", $siblingsBlob);
        $this->assertStringNotContainsString('प्रोपर्टी', $siblingsBlob);
    }

    public function test_siblings_preview_keeps_braced_status_address_out_of_sister_name(): void
    {
        $text = <<<'TXT'
बहीण : सौ. सोनाली आकाश पावले. { विवाहित } पुणे.
TXT;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);
        $siblings = $draft['normalized']['siblings'] ?? [];

        $this->assertSame('सोनाली आकाश पावले.', $siblings[0]['name'] ?? null);
        $this->assertSame('married', $siblings[0]['marital_status'] ?? null);
        $this->assertSame('पुणे.', $siblings[0]['address_line'] ?? null);

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($text, true);
        $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString('Sister - सोनाली आकाश पावले.', $siblingsBlob);
        $this->assertStringContainsString('Married married', $siblingsBlob);
        $this->assertStringContainsString('Address पुणे.', $siblingsBlob);
        $this->assertStringNotContainsString('{ } पुणे', $siblingsBlob);
    }

    public function test_siblings_preview_keeps_status_only_single_sister_row(): void
    {
        $text = <<<'TXT'
भाऊ :- नाही
बहीण :- एक ( अविवाहित )
TXT;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);
        $siblings = $draft['normalized']['siblings'] ?? [];

        $this->assertCount(1, $siblings);
        $this->assertSame('sister', $siblings[0]['relation_type'] ?? null);
        $this->assertNull($siblings[0]['name'] ?? null);
        $this->assertSame('unmarried', $siblings[0]['marital_status'] ?? null);

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($text, true);
        $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString('Sister Married unmarried', $siblingsBlob);
        $this->assertStringNotContainsString('No data found', $siblingsBlob);
    }

    public function test_siblings_preview_does_not_mix_daji_and_brothers_wife_lines(): void
    {
        $text = <<<'TXT'
बहीण : सौ. राधा विजय पाटील
दाजी : श्री. विजय दत्तात्रय पाटील (B.A.) (शिक्षक) पत्ता. सांगली 9000000001
भावजय : सौ. रेखा रमेश पाटील (M.A. B.Ed.) (शिक्षिका) पत्ता. कोल्हापूर 9000000002
TXT;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);
        $siblings = $draft['normalized']['siblings'] ?? [];

        $this->assertSame('sister_husband', $siblings[1]['relation_type'] ?? null);
        $this->assertSame('विजय दत्तात्रय पाटील', $siblings[1]['name'] ?? null);
        $this->assertSame('शिक्षक', $siblings[1]['occupation'] ?? null);
        $this->assertSame('सांगली', $siblings[1]['address_line'] ?? null);
        $this->assertSame('B.A.', $siblings[1]['notes'] ?? null);
        $this->assertSame('brother_wife', $siblings[2]['relation_type'] ?? null);
        $this->assertSame('रेखा रमेश पाटील', $siblings[2]['name'] ?? null);
        $this->assertSame('शिक्षिका', $siblings[2]['occupation'] ?? null);
        $this->assertSame('कोल्हापूर', $siblings[2]['address_line'] ?? null);
        $this->assertSame('M.A. B.Ed.', $siblings[2]['notes'] ?? null);

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($text, true);
        $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString("Sister's husband विजय दत्तात्रय पाटील", $siblingsBlob);
        $this->assertStringContainsString("Sister's husband Address सांगली", $siblingsBlob);
        $this->assertStringContainsString("Additional info B.A.", $siblingsBlob);
        $this->assertStringContainsString("Brother's wife - रेखा रमेश पाटील", $siblingsBlob);
        $this->assertStringContainsString("Address कोल्हापूर", $siblingsBlob);
        $this->assertStringContainsString("Additional info M.A. B.Ed.", $siblingsBlob);
        $this->assertStringNotContainsString('भावजय : सौ. रेखा', $siblingsBlob);
    }

    public function test_siblings_preview_infers_marital_status_and_strips_numbered_prefixes(): void
    {
        $text = <<<'TXT'
भाऊ :- एक - अविवाहित कु. पवन बळवंत मोरे (B.A)
(व्यवसाय - श्री पांडुरंग ट्रेडर्स,प्लंबींग ॲण्ड हार्डवेअर्स, खुपीरे)
बहिण :- दोन - विवाहित १.सौ. शितल उत्तम पाटील (वाळोली, ता. पन्हाळा)
सौ. गिता सतिश निर्मळ (कंदलगाव, ता. करवीर)
TXT;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);
        $siblings = $draft['normalized']['siblings'] ?? [];

        $this->assertCount(3, $siblings);
        $this->assertSame('brother', $siblings[0]['relation_type'] ?? null);
        $this->assertSame('unmarried', $siblings[0]['marital_status'] ?? null);
        $this->assertSame('पवन बळवंत मोरे', $siblings[0]['name'] ?? null);
        $this->assertStringContainsString('B.A', (string) ($siblings[0]['notes'] ?? ''));
        $this->assertStringNotContainsString('B.A', (string) ($siblings[0]['occupation'] ?? ''));
        $this->assertStringContainsString('श्री पांडुरंग ट्रेडर्स', (string) ($siblings[0]['occupation'] ?? ''));
        $this->assertSame('sister', $siblings[1]['relation_type'] ?? null);
        $this->assertSame('married', $siblings[1]['marital_status'] ?? null);
        $this->assertSame('शितल उत्तम पाटील', $siblings[1]['name'] ?? null);
        $this->assertSame('वाळोली, ता. पन्हाळा', $siblings[1]['address_line'] ?? null);
        $this->assertArrayNotHasKey('occupation', $siblings[1]);
        $this->assertSame('sister', $siblings[2]['relation_type'] ?? null);
        $this->assertSame('married', $siblings[2]['marital_status'] ?? null);
        $this->assertSame('गिता सतिश निर्मळ', $siblings[2]['name'] ?? null);
        $this->assertSame('कंदलगाव, ता. करवीर', $siblings[2]['address_line'] ?? null);
        $this->assertArrayNotHasKey('occupation', $siblings[2]);
    }

    public function test_family_details_preview_splits_inline_father_and_mother_line(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
वडील :- बाळासाहेब बन्सी सुंबे, (नोकरी पाटबुंधारे सोसायटी) आई :- सौ. नंदा बाळासाहेब सुंबे (गृहिणी)
भाऊ :- श्री.सुरज बाळासाहेब सुंबे (B.com)
TXT, true);

        $family = $this->sectionBlob($out['sections']['family-details']);

        $this->assertStringContainsString('Father - बाळासाहेब बन्सी सुंबे', $family);
        $this->assertStringContainsString('Occupation नोकरी पाटबुंधारे सोसायटी', $family);
        $this->assertStringContainsString('Mother - सौ. नंदा बाळासाहेब सुंबे', $family);
        $this->assertStringContainsString('Occupation गृहिणी', $family);
        $this->assertStringNotContainsString('आई :-', $family);
    }

    public function test_family_details_preview_recovers_ocr_father_label_and_parent_address_phone(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
## कौटुंबिक माहिती
- वकिलांचे नाव - श्री. उत्तम राऊ फडतरे
- आईचे नाव - सौ.पार्वती उत्तम फडतरे
- पता - मु.पो.वाठार (किरोली) ता. कोरेगाव जि. सातारा.
- फोन नं - ८६००७८०८२४ /८३८०८३५७६४
- बहिण - कु.प्रतीक्षा उत्तम फडतरे
TXT, true);

        $family = $this->sectionBlob($out['sections']['family-details']);

        $this->assertStringContainsString('Father - श्री. उत्तम राऊ फडतरे', $family);
        $this->assertStringContainsString('Contact 1 8600780824', $family);
        $this->assertStringContainsString('Contact 2 8380835764', $family);
        $this->assertStringContainsString('Parents address 1', $family);
        $this->assertStringContainsString('मु.पो.वाठार (किरोली) ता. कोरेगाव जि. सातारा.', $family);
    }

    public function test_family_details_preview_captures_next_line_retired_father_occupation(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
## कौटुंबिक माहिती
- वडीलांचे नांव :- श्री. बळवंत पांडुरंग मोरे
- (सेवानिवृत्त केन यार्ड सुपरवायझर कुंभी-कासारी सह. साखर कारखाना,
- कुडित्रे)
- आईचे नांव :- सौ. मुक्ता बळवंत मोरे (गृहिणी)
TXT, true);

        $family = $this->sectionBlob($out['sections']['family-details']);

        $this->assertStringContainsString('Father - श्री. बळवंत पांडुरंग मोरे', $family);
        $this->assertStringContainsString('Occupation सेवानिवृत्त केन यार्ड सुपरवायझर कुंभी-कासारी सह. साखर कारखाना', $family);
        $this->assertStringContainsString('Mother - सौ. मुक्ता बळवंत मोरे', $family);
    }

    public function test_family_details_preview_keeps_parent_address_types_for_permanent_and_current(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
वडील :- बाळासाहेब बन्सी सुंबे, (नोकरी पाटबुंधारे सोसायटी) आई :- सौ. नंदा बाळासाहेब सुंबे (गृहिणी)
भाऊ :- श्री.सुरज बाळासाहेब सुंबे (B.com)
मूळगाव :- मु.पो. पाडळी तर्फ कान्हर, पो. गोरेगाव. ता. पारनेर,जि. अहमदनगर
पता :- घर नुं.३७,आशियाना कॉलोनी, अयोध्यानगर, शभस्तबाग, पाईपलाइन रोड, सावेडी, अहमिनगर - ४१४ ००३.
TXT);

        $parents = $draft['normalized']['parents_addresses'] ?? [];
        $siblings = $draft['normalized']['siblings'] ?? [];

        $this->assertSame('permanent', $parents[0]['address_type_key'] ?? null);
        $this->assertStringContainsString('पाडळी तर्फ कान्हर', (string) ($parents[0]['address_line'] ?? ''));
        $this->assertSame('current', $parents[1]['address_type_key'] ?? null);
        $this->assertStringContainsString('आशियाना कॉलोनी', (string) ($parents[1]['address_line'] ?? ''));
        $this->assertArrayNotHasKey('address_line', $siblings[0] ?? []);
    }

    public function test_family_details_preview_routes_gharcha_patta_as_parent_current_address(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
- ■ कौटुंबिक माहिती ■
- ■ वडिलांचे नांव : श्री. सर्जेराव शंकरराव पाटील (को.म.न.पा. नोकरदार)
- ■ आईचे नांव : सौ. शोभा सर्जेराव पाटील (गृहिणी)
- ■ भाऊ : एक - अविवाहीत
- ■ घरचा पत्ता : मु. पो. २०१८ ए वॉर्ड, शिवाजी पेठ, कोल्हापूर, ता. करवीर, जि. कोल्हापूर
- ■ मोबाईल नंबर : 8180939881 /8806768778
TXT);

        $parents = $draft['normalized']['parents_addresses'] ?? [];
        $siblings = $draft['normalized']['siblings'] ?? [];

        $this->assertSame('current', $parents[0]['address_type_key'] ?? null);
        $this->assertStringContainsString('२०१८ ए वॉर्ड', (string) ($parents[0]['address_line'] ?? ''));
        $this->assertArrayNotHasKey('address_line', $siblings[0] ?? []);
    }

    public function test_family_details_preview_splits_second_home_address_into_separate_row(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
कौटुंबिक माहिती *
वडिलांचे नांव : कै. श्री. नितीन विलासराव पोवार,
आईचे नांव : श्रीमती नीता नितीन पोवार (गृहिणी)
भाऊ : एक - विवाहीत - ओंकार नितीन पोवार
घरचा पत्ता : १) फ्लॅट नं. ४०१, केदारनाथ होमस् बाबा जरगनगर, कोल्हापूर
२७२९, बी वॉर्ड, मंगळवार पेठ, कोल्हापूर
फोन नंबर : 8369453302
TXT);

        $parents = $draft['normalized']['parents_addresses'] ?? [];

        $this->assertSame('current', $parents[0]['address_type_key'] ?? null);
        $this->assertStringContainsString('फ्लॅट नं. ४०१', (string) ($parents[0]['address_line'] ?? ''));
        $this->assertStringNotContainsString('२७२९, बी वॉर्ड', (string) ($parents[0]['address_line'] ?? ''));
        $this->assertSame('other', $parents[1]['address_type_key'] ?? null);
        $this->assertStringContainsString('२७२९, बी वॉर्ड', (string) ($parents[1]['address_line'] ?? ''));
        $this->assertStringNotContainsString('8369453302', (string) ($parents[0]['address_line'] ?? ''));
        $this->assertStringNotContainsString('8369453302', (string) ($parents[1]['address_line'] ?? ''));
    }

    public function test_intake_preview_463_marathi_sample_keeps_fields_in_correct_sections(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
मुलाचे नाव :- चि.अनिकेत जयवंत पाटील
जन्म दि :- १६/११/१९९६
जन्म स्थळ :- मु.पो.येडेमच्छिंद्र, ता.वाळवा,जि.सांगली.
उंची :- ५ फुट ५ इंच
वर्ण :- निम गोरा
रक्त गट :- B+ve
कुलदैवत :- श्री.जोतिर्लिंग कोल्हापूर
जात :- हिंदू मराठा
शिक्षण :- B.S.C CHEMISTRY
नोकरी :- SHILPA PHARMA LIFE SCINCE LTD UNIT 1 KARNATK RAICHUR
शेती :- 1 एकर

## कौटुंबिक माहिती

वडील :- श्री.जयवंत तुकाराम पाटील (पोस्टमास्टर) मोबाईल-८८०५५२६१९७
घराचा पत्ता :- मु.पो.रेठरे हरणाक्ष, ता.वाळवा, जि.सांगली.
चुलते :- १)श्री.दिलीप तुकाराम पाटील (शेती)
कै.धनाजी तुकाराम पाटील
बहीण :- श्री.प्रदीप गोरख पाटील(ग्रामपंचायत सदस्य)
एक(विवाहित) पत्ता-बिउर,ता.शिराळा,जिसांगली मोबाईल-९२०९९०५००५
मुलाचे मामा :- 1) श्री.हनुमंत दिनकर जगताप 2) चि.भोपाल दिनकर जगताप,
पत्ता - मु.पो.येडेमच्छिंद्र, ता. वाळवा जि. सांगली.
मुलाची आत्या :- श्री.बाबासो पांडुरंग पवार.
पत्ता - मु.पो.रेठरे हरणाक्ष, ता.वाळवा, जि.सांगली.
नाते संबंध :- येडेमच्छिंद्र,तुपारी,बहे,तासगाव,तांबवे (कासेगाव) कवलापूर
| रास | मकर | गण | देव |
| देवक | गण | नक्षत्र | श्रवण |
| चरण | १ले | | |
TXT);

        $normalized = $draft['normalized'] ?? [];
        $core = $normalized['core'] ?? [];
        $parents = $normalized['parents_addresses'] ?? [];
        $siblings = $normalized['siblings'] ?? [];
        $relatives = $normalized['relatives'] ?? [];
        $propertyAssets = $normalized['property_assets'] ?? [];
        $horoscope = $normalized['horoscope'] ?? [];

        $this->assertSame('१६/११/१९९६', $core['date_of_birth'] ?? null);
        $this->assertSame('निम गोरा', $core['complexion'] ?? null);
        $this->assertSame('B+', $core['blood_group'] ?? null);
        $this->assertSame('नोकरी', $core['occupation_title'] ?? null);
        $this->assertSame('SHILPA PHARMA LIFE SCINCE LTD UNIT 1 KARNATK RAICHUR', $core['company_name'] ?? null);
        $this->assertSame('श्री.जयवंत तुकाराम पाटील', $core['father_name'] ?? null);
        $this->assertSame('पोस्टमास्टर', $core['father_occupation'] ?? null);
        $this->assertSame('8805526197', $core['father_contact_1'] ?? null);
        $this->assertNull($core['father_contact_2'] ?? null);
        $this->assertStringContainsString('येडेमच्छिंद्र', (string) ($core['other_relatives_text'] ?? ''));
        $this->assertStringNotContainsString('| रास |', (string) ($core['other_relatives_text'] ?? ''));

        $this->assertCount(3, $parents);
        $this->assertStringNotContainsString('नाते संबंध', (string) ($parents[2]['address_line'] ?? ''));
        $this->assertStringNotContainsString('| रास |', (string) ($parents[2]['address_line'] ?? ''));
        $this->assertSame('प्रदीप गोरख पाटील', $siblings[0]['name'] ?? null);
        $this->assertArrayNotHasKey('address_line', $siblings[0] ?? []);

        $relativeNames = array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $relatives);
        $this->assertContains('श्री.दिलीप तुकाराम पाटील', $relativeNames);
        $this->assertContains('कै.धनाजी तुकाराम पाटील', $relativeNames);
        $this->assertContains('श्री.हनुमंत दिनकर जगताप', $relativeNames);
        $this->assertContains('चि.भोपाल दिनकर जगताप', $relativeNames);
        $this->assertContains('श्री.बाबासो पांडुरंग पवार', $relativeNames);
        $this->assertNotContains('बहीण :- श्री.प्रदीप गोरख पाटील', $relativeNames);

        $this->assertCount(1, $propertyAssets);
        $this->assertSame('land', $propertyAssets[0]['asset_type_key'] ?? null);
        $this->assertSame('1 एकर', $propertyAssets[0]['notes'] ?? null);
        $this->assertSame('मकर', $horoscope['rashi'] ?? null);
        $this->assertSame('देव', $horoscope['gan'] ?? null);
        $this->assertSame('श्रवण', $horoscope['nakshatra'] ?? null);
        $this->assertSame('१ले', $horoscope['charan'] ?? null);
        $this->assertSame([], $draft['review_flags'] ?? []);
    }

    /**
     * @param  list<array{label: string, value: string}>  $rows
     */
    private function sectionBlob(array $rows): string
    {
        return implode(' ', array_map(
            static function (array $row): string {
                $variant = (string) ($row['row_variant'] ?? '');
                if ($variant === 'group_heading') {
                    return (string) ($row['display_heading_text'] ?? trim((string) (($row['label'] ?? '').' '.($row['value'] ?? ''))));
                }
                if ($variant === 'group_detail_value_only') {
                    return (string) ($row['value'] ?? '');
                }

                $label = (string) ($row['display_label'] ?? ($row['label'] ?? ''));
                $value = (string) ($row['value'] ?? '');

                return trim($label.' '.$value);
            },
            $rows
        ));
    }

    /**
     * @param  array<string, list<array{value: string}>>  $sections
     */
    private function assertNoDuplicateEditableSectionValues(array $sections): void
    {
        $seen = [];
        foreach ($sections as $section => $rows) {
            if ($section === 'review_needed') {
                continue;
            }
            foreach ($rows as $row) {
                $value = trim((string) ($row['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $previousSection = $seen[$value] ?? null;
                $this->assertTrue(
                    $previousSection === null || $previousSection === $section,
                    "Value [{$value}] appears in both [{$previousSection}] and [{$section}]"
                );
                $seen[$value] = $section;
            }
        }
    }
}
