<?php

namespace Tests\Unit\Intake;

use App\Services\Intake\IntakePreviewNormalizedDraftPresenter;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakePreviewNormalizedDraftPresenterTest extends TestCase
{
    private function yuvrajText(): string
    {
        return <<<'TXT'
*प्रतिमा: decorative logo*
:■:
वैयक्तिक माहिती
नाव : कु. युवराज नामदेव घाटेगस्ती.
जात : हिंदू मराठा {96 कुळी}
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

    private function apurvaText(): string
    {
        return <<<'TXT'
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
एका महिलेचे बाहेरील छायाचित्र. तिने पिवळ्या रंगाची साडी नेसली असून त्यावर गडद हिरव्या रंगाचा ब्लाउज आहे.
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
TXT;
    }

    public function test_sections_follow_wizard_order_and_old_sections_are_absent(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->yuvrajText(), true);

        $this->assertSame([
            'review_needed',
            'basic-info',
            'physical',
            'education-career',
            'family-details',
            'siblings',
            'relatives',
            'alliance',
            'property',
            'horoscope',
            'about-me',
            'about-preferences',
            'photo',
        ], array_keys($out['sections']));

        foreach (['personal', 'family', 'contacts', 'addresses'] as $oldSection) {
            $this->assertArrayNotHasKey($oldSection, $out['sections']);
        }
    }

    public function test_yuvraj_text_returns_available_basic_info_and_db_free_sections(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->yuvrajText(), true);

        $this->assertTrue($out['available']);
        $this->assertNull($out['skipped_reason']);
        $this->assertNull($out['build_error']);
        $this->assertNotEmpty($out['sections']['basic-info']);
        $this->assertNotEmpty($out['sections']['review_needed']);
        $this->assertSame([], $queries);

        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
        $this->assertStringContainsString('User contact 1 7350953384', $basicBlob);
        $this->assertStringContainsString('User contact 2 9673350078', $basicBlob);
        $this->assertArrayNotHasKey('contacts', $out['sections']);
    }

    public function test_swapnil_text_places_gender_contacts_and_relatives_in_wizard_sections_without_family_counts(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->swapnilText(), true);

        $this->assertTrue($out['available']);
        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
        $this->assertStringContainsString('Male', $basicBlob);
        $this->assertStringContainsString('User contact 1 9860956022', $basicBlob);
        $this->assertStringContainsString('User contact 2 8668270153', $basicBlob);
        $this->assertArrayNotHasKey('contacts', $out['sections']);

        $familyBlob = $this->sectionBlob($out['sections']['family-details']);
        $this->assertStringNotContainsString('Brother count', $familyBlob);
        $this->assertStringNotContainsString('Sister count', $familyBlob);

        $relativesBlob = $this->sectionBlob($out['sections']['relatives']);
        $this->assertStringContainsString('इस्लामपूर', $relativesBlob);
        $this->assertStringContainsString('Sister Married unmarried', $this->sectionBlob($out['sections']['siblings']));
    }

    public function test_mahesh_text_returns_available_basic_family_and_contact_rows(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->maheshText(), true);

        $this->assertTrue($out['available']);
        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
        $this->assertStringContainsString('महेशकुमार मोहन जगताप', $basicBlob);
        $this->assertStringContainsString('User contact 1 9870879727', $basicBlob);
        $this->assertStringContainsString('User contact 2 9137793371', $basicBlob);
        $this->assertArrayNotHasKey('contacts', $out['sections']);

        $familyBlob = $this->sectionBlob($out['sections']['family-details']);
        $this->assertStringContainsString('मोहनराव गणपतराव जगताप', $familyBlob);
    }

    public function test_mahesh_full_name_row_marked_needs_review_for_heading_fallback(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->maheshText(), true);

        $fullNameRow = $this->findRowByField($out['sections']['basic-info'], 'core.full_name');
        $this->assertNotNull($fullNameRow);
        $this->assertTrue($fullNameRow['needs_review']);
        $this->assertSame('candidate_name_from_heading_fallback', $fullNameRow['review_reason']);
        $this->assertSame(
            __('intake.normalized_draft_full_name_fallback_hint'),
            $fullNameRow['review_hint']
        );
        $this->assertArrayHasKey('core.full_name', $out['review_flags_by_field']);
    }

    public function test_apurva_text_maps_dash_variant_family_address_preference_and_work_lines(): void
    {
        $draft = app(\App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder::class)->build($this->apurvaText());
        $core = $draft['normalized']['core'] ?? [];
        $siblings = $draft['normalized']['siblings'] ?? [];
        $preferences = $draft['normalized']['preferences'] ?? [];

        $this->assertSame('female', $core['gender'] ?? null);
        $this->assertSame('सकाळी ७ वाजता', $core['birth_time'] ?? null);
        $this->assertSame('SAP consultant (Sr. Analyst)', $core['occupation_title'] ?? null);
        $this->assertSame('Capgemini', $core['company_name'] ?? null);
        $this->assertSame('निर्व्यसनी,उच्च शिक्षित,नोकरी,सुसंस्कृत', $preferences['expectations'] ?? null);
        $this->assertSame('native', $draft['normalized']['addresses'][0]['type'] ?? null);
        $this->assertSame('current', $draft['normalized']['addresses'][1]['type'] ?? null);
        $this->assertSame('brother', $siblings[0]['relation_type'] ?? null);
        $this->assertSame('brother_wife', $siblings[1]['relation_type'] ?? null);
        $this->assertSame('sister', $siblings[2]['relation_type'] ?? null);
        $this->assertSame('मयूर बाळू शेंडकर', $siblings[2]['spouse']['name'] ?? null);
        $this->assertSame('व्यवसाईक', $siblings[2]['spouse']['occupation_title'] ?? null);
        $this->assertSame(
            'पोखरकर,वर्पे,मुळे,ढोबळे,इंदोरे,तट्टू,ढमाले,घंघाले,डुंबरे,शेंडकर,तापकिर,दांगट,औटी',
            $core['other_relatives_text'] ?? null
        );
        $this->assertSame([], $draft['review_flags'] ?? []);

        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present($this->apurvaText(), true);
        $basic = $this->sectionBlob($out['sections']['basic-info']);
        $physical = $this->sectionBlob($out['sections']['physical']);
        $education = $this->sectionBlob($out['sections']['education-career']);
        $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);
        $allianceBlob = $this->sectionBlob($out['sections']['alliance']);
        $preferencesBlob = $this->sectionBlob($out['sections']['about-preferences']);

        $this->assertStringContainsString('Gender Female', $basic);
        $this->assertStringContainsString('Birth time सकाळी 7 वाजता', $basic);
        $this->assertStringContainsString('Native / Parents address मु.पोस्ट आर्वी नारायणगाव,पुणे', $basic);
        $this->assertStringContainsString('Residential / Current address पंतनगर,घाटकोपर(e),मुंबई', $basic);
        $this->assertStringContainsString('5\' 6" (168 cm)', $physical);
        $this->assertStringContainsString('Occupation title SAP consultant (Sr. Analyst)', $education);
        $this->assertStringContainsString('Company name Capgemini', $education);
        $this->assertStringContainsString('Brother प्रज्वल सुधीर डोंगरे', $siblingsBlob);
        $this->assertStringContainsString("Brother's wife मानसी प्रज्वल डोंगरे", $siblingsBlob);
        $this->assertStringContainsString('Sister स्नेहल मयूर शेंडकर', $siblingsBlob);
        $this->assertStringContainsString('Sister Occupation IT engineer', $siblingsBlob);
        $this->assertStringContainsString("Sister's husband मयूर बाळू शेंडकर", $siblingsBlob);
        $this->assertStringContainsString("Sister's husband Occupation व्यवसाईक", $siblingsBlob);
        $this->assertStringContainsString('पोखरकर,वर्पे,मुळे,ढोबळे,इंदोरे,तट्टू,ढमाले,घंघाले,डुंबरे,शेंडकर,तापकिर,दांगट,औटी', $allianceBlob);
        $this->assertStringContainsString('Expectations निर्व्यसनी,उच्च शिक्षित,नोकरी,सुसंस्कृत', $preferencesBlob);
    }

    public function test_display_rows_use_clean_unicode_separators(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नांव :- चि. रोहित शिंदे
भाऊ :- चि. संकेत शिंदे ( अविवाहित ) ( इंजिनियर )
आत्या :- श्री. भाऊसो कृष्णाजी मोरे रा. इस्लामपूर
मोबाइल नंबर :- 9860956022 / 8668270153
TXT, true);

        $blob = $this->sectionBlob($out['sections']['siblings'])
            .' '.$this->sectionBlob($out['sections']['relatives'])
            .' '.$this->sectionBlob($out['sections']['review_needed']);

        $this->assertStringNotContainsString('Â·', $blob);
        $this->assertStringNotContainsString('â€”', $blob);
        $this->assertStringContainsString('Brother Married unmarried', $blob);
        $this->assertStringContainsString('Paternal Aunt (atya) Address इस्लामपूर', $blob);
    }

    public function test_extended_family_splits_parenthesized_places_as_addresses(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव :- चि. रोहित पाटील
चुलते :- श्री. अनिल भाऊराव पाटील ( वाघोली, पुणे)
- श्री. सुनील भाऊराव पाटील ( माळीनगर)
TXT, true);

        $paternalBlob = $this->sectionBlob($out['sections']['relatives']);

        $this->assertStringContainsString('Paternal Uncle (chulte) 1 श्री. अनिल भाऊराव पाटील', $paternalBlob);
        $this->assertStringContainsString('Paternal Uncle (chulte) 1 Address वाघोली, पुणे', $paternalBlob);
        $this->assertStringContainsString('Paternal Uncle (chulte) 2 श्री. सुनील भाऊराव पाटील', $paternalBlob);
        $this->assertStringContainsString('Paternal Uncle (chulte) 2 Address माळीनगर', $paternalBlob);
        $this->assertStringNotContainsString('Paternal Uncle (chulte) 2 पुणे)', $paternalBlob);
    }

    public function test_relative_address_drops_orphan_number_suffix(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव :- चि. रोहित पाटील
आत्या :- सौ. रेखा जाधव रा. सोलापूर नं.
TXT, true);

        $paternalBlob = $this->sectionBlob($out['sections']['relatives']);

        $this->assertStringContainsString('Paternal Aunt (atya) Address सोलापूर', $paternalBlob);
        $this->assertStringNotContainsString('सोलापूर नं', $paternalBlob);
    }

    public function test_html_table_numbered_chulte_rows_all_land_in_extended_family(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'HTML'
<table>
<tr><td>मुलीचे नाव</td><td>कु. प्राजक्ता सुभाष पानसरे</td></tr>
<tr><td>चुलते</td><td>: १) रमेश किसन पानसरे रा.सोलापूर मो.नं. ९८६०१०८९७६<br/>२) सुरेश किसन पानसरे रा.पोमलवाडी ता.करमाळा मो.नं. ९७६३२३१७११</td></tr>
</table>
HTML, true);

        $paternalBlob = $this->sectionBlob($out['sections']['relatives']);

        $this->assertStringContainsString('Paternal Uncle (chulte) 1 रमेश किसन पानसरे', $paternalBlob);
        $this->assertStringContainsString('Paternal Uncle (chulte) 1 Mobile 9860108976', $paternalBlob);
        $this->assertStringContainsString('Paternal Uncle (chulte) 1 Address सोलापूर', $paternalBlob);
        $this->assertStringContainsString('Paternal Uncle (chulte) 2 सुरेश किसन पानसरे', $paternalBlob);
        $this->assertStringContainsString('Paternal Uncle (chulte) 2 Mobile 9763231711', $paternalBlob);
        $this->assertStringContainsString('Paternal Uncle (chulte) 2 Address पोमलवाडी ता.करमाळा', $paternalBlob);
    }

    public function test_other_relatives_block_does_not_become_paternal_aunts_and_cousin_places_are_addresses(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव :- चि. रोहित पाटील
चुलत भाऊ :- प्रमोद शरदराव जगताप (Devloper and Construction) ठाणे
:- कै. रतन दादासाहेब जाधव, पळशी (सांगली)
आत्या :- जाधव, देशमुख, घोरपडे, मोरे पाटील, भोसले, गायकवाड, कदम, मोहिते, पवार देसाई
उत्तर नातेवाईक :- वरकूटे-मलवडी
ता. माण
जि. सातारा
TXT, true);

        $paternalBlob = $this->sectionBlob($out['sections']['relatives']);
        $allianceBlob = $this->sectionBlob($out['sections']['alliance']);

        $this->assertStringContainsString('Cousin 1 प्रमोद शरदराव जगताप', $paternalBlob);
        $this->assertStringContainsString('Cousin 1 Address ठाणे', $paternalBlob);
        $this->assertStringContainsString('Cousin 1 Additional info Devloper and Construction', $paternalBlob);
        $this->assertStringContainsString('Cousin 2 कै. रतन दादासाहेब जाधव', $paternalBlob);
        $this->assertStringContainsString('Cousin 2 Address पळशी (सांगली)', $paternalBlob);
        $this->assertStringContainsString('Paternal Aunt (atya) 9 पवार देसाई', $paternalBlob);
        $this->assertStringNotContainsString('Paternal Aunt (atya) जाधव', $paternalBlob);
        $this->assertStringNotContainsString('Paternal Aunt (atya) देशमुख', $paternalBlob);
        $this->assertStringNotContainsString('Paternal Aunt (atya) 10', $paternalBlob);
        $this->assertStringNotContainsString('वरकूटे-मलवडी', $paternalBlob);
        $this->assertStringContainsString('वरकूटे-मलवडी; ता. माण; जि. सातारा', $allianceBlob);
    }

    public function test_property_preview_uses_asset_rows_instead_of_boolean_yes_no_rows(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'HTML'
<table>
<tr><td>इतर प्रॉपर्टी</td><td>:-</td><td>स्वतःचे घर, फ्लॅट, शेती 01 एकर</td></tr>
</table>
HTML, true);
        $propertyBlob = $this->sectionBlob($out['sections']['property']);

        $this->assertStringContainsString('Asset Type Flat', $propertyBlob);
        $this->assertStringContainsString('Asset Type Land', $propertyBlob);
        $this->assertStringContainsString('Ownership Type Sole', $propertyBlob);
        $this->assertStringContainsString('01 एकर', $propertyBlob);
        $this->assertStringContainsString('Notes Not mentioned', $propertyBlob);
        $this->assertStringNotContainsString('होय', $propertyBlob);
        $this->assertStringNotContainsString('yes', $propertyBlob);
    }

    public function test_values_are_not_duplicated_across_wizard_sections(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'HTML'
<table>
<tr><td>मुलाचे नाव</td><td>चि. रोहित शिंदे</td></tr>
<tr><td>उंची</td><td>१७२ सेमी</td></tr>
<tr><td>शिक्षण</td><td>B.Com</td></tr>
<tr><td>भाऊ</td><td>चि. संकेत शिंदे ( अविवाहित ) ( इंजिनियर )</td></tr>
<tr><td>मोबाईल नंबर</td><td>9876543210</td></tr>
</table>
HTML, true);

        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
        $physicalBlob = $this->sectionBlob($out['sections']['physical']);
        $educationBlob = $this->sectionBlob($out['sections']['education-career']);
        $familyBlob = $this->sectionBlob($out['sections']['family-details']);
        $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString('cm', $physicalBlob);
        $this->assertStringNotContainsString('cm', $basicBlob);
        $this->assertStringContainsString('B.Com', $educationBlob);
        $this->assertStringNotContainsString('B.Com', $basicBlob);
        $this->assertStringContainsString('User contact 1 9876543210', $basicBlob);
        $this->assertArrayNotHasKey('contacts', $out['sections']);
        $this->assertStringContainsString('संकेत', $siblingsBlob);
        $this->assertStringNotContainsString('संकेत', $familyBlob);
    }

    public function test_physical_section_displays_wizard_lifestyle_fields_when_present(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव :- चि. रोहित शिंदे
उंची :- 5 फूट 6 इंच
आहार :- शाकाहारी
धूम्रपान :- नाही
मद्यपान :- नाही
TXT, true);

        $physicalBlob = $this->sectionBlob($out['sections']['physical']);

        $this->assertStringContainsString('168 cm', $physicalBlob);
        $this->assertStringContainsString('Diet', $physicalBlob);
        $this->assertStringContainsString('शाकाहारी', $physicalBlob);
        $this->assertStringContainsString('Smoking', $physicalBlob);
        $this->assertStringContainsString('Drinking', $physicalBlob);
    }

    public function test_new_normalized_values_land_in_matching_wizard_sections_with_review_reasons(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलीचे नाव :- कु. अंजली पाटील
वार :- 3.45 A.M रात्री सोमवार
उंची :- 5 फुट 4 इंच
राशी :- मेष
नक्षत्र :- अश्विनी
नाडी :- आद्य
गण :- देव
देवक :- वड
- मामा - श्री. मोहन कदम पुणे
नोकरी माहिती उपलब्ध पण format वेगळा आहे
TXT, true);

        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
        $physicalBlob = $this->sectionBlob($out['sections']['physical']);
        $horoscopeBlob = $this->sectionBlob($out['sections']['horoscope']);
        $relativesBlob = $this->sectionBlob($out['sections']['alliance']);
        $reviewBlob = $this->reviewAlertBlob($out['sections']['review_needed']);

        $this->assertStringContainsString('3.45 A.M', $basicBlob);
        $this->assertStringContainsString('cm', $physicalBlob);
        $this->assertStringNotContainsString('cm', $basicBlob);
        $this->assertStringContainsString('मेष', $horoscopeBlob);
        $this->assertStringContainsString('अश्विनी', $horoscopeBlob);
        $this->assertStringContainsString('मोहन कदम', $relativesBlob);
        $this->assertStringContainsString('Education / career information was detected but not mapped cleanly', $reviewBlob);
        $this->assertStringContainsString('Education & career', $reviewBlob);
    }

    public function test_horoscope_preview_uses_wizard_field_order_for_mapped_values(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
नावरस :- सीताराम
देवक :- वासनलिवेल
कुलस्वामी :- जोतिबा
गोत्र :- कश्यप
नक्षत्र :- अश्विनी
चरण :- २ रे
राशी :- कुंभ
गण :- देव
नाडी :- आद्य
योनी :- व्याघ्र
TXT, true);

        $rows = $out['sections']['horoscope'];
        $labels = array_values(array_map(
            static fn (array $row): string => (string) ($row['label'] ?? ''),
            $rows
        ));

        $this->assertSame([
            __('components.horoscope.navras_name'),
            __('components.horoscope.devak'),
            __('components.horoscope.kul'),
            __('components.horoscope.gotra'),
            __('components.horoscope.nakshatra'),
            __('components.horoscope.charan'),
            __('components.horoscope.rashi'),
            __('components.horoscope.gan'),
            __('components.horoscope.nadi'),
            __('components.horoscope.yoni'),
        ], $labels);
    }

    public function test_horoscope_preview_keeps_unmatched_raw_lines_without_repeating_mapped_lines(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
राशी :- कुंभ
नक्षत्र :- अश्विनी
वश्य :- मानव
TXT, true);

        $horoscopeBlob = $this->sectionBlob($out['sections']['horoscope']);

        $this->assertStringContainsString('कुंभ', $horoscopeBlob);
        $this->assertStringContainsString('अश्विनी', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.vashya'), $horoscopeBlob);
        $this->assertStringContainsString('मानव', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 1 राशी :- कुंभ', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 2 नक्षत्र :- अश्विनी', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 3 वश्य :- मानव', $horoscopeBlob);
    }

    public function test_horoscope_unknown_values_move_to_detected_but_not_included_block(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
देवक :- वडाचे पान
कुल दैवत :- जेजुरीचा खंडोबा
नक्षत्र :- उत्तरा भाद्र पदा
नाडी :- मध्य
योग :- बष्ट
रक्तगट : A+
TXT, true);

        $horoscopeBlob = $this->sectionBlob($out['sections']['horoscope']);
        $detectedBlob = $this->detectedBlob($out['detected_but_not_included'] ?? []);

        $this->assertStringContainsString(__('components.horoscope.devak'), $horoscopeBlob);
        $this->assertStringContainsString('वडाचे पान', $horoscopeBlob);
        $this->assertStringNotContainsString('Yog', $horoscopeBlob);
        $this->assertStringNotContainsString('योग', $horoscopeBlob);
        $this->assertStringContainsString('Yog बष्ट', $detectedBlob);
        $this->assertStringContainsString('Line 5', $detectedBlob);
        $this->assertStringContainsString('Detected in biodata text but not mapped to a wizard field.', $detectedBlob);
    }

    public function test_supported_lines_do_not_leak_into_detected_but_not_included_block(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
नाव :- कु. रोहिणी पाटील
जात :- हिंदू मराठा
अपेक्षा :- उच्चशिक्षित
TXT, true);

        $this->assertSame([], $out['detected_but_not_included'] ?? []);
    }

    public function test_horoscope_preview_maps_swami_and_vairavarga_into_wizard_labels(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
स्वामी :- शनि
वैरवर्ग :- मानव
TXT, true);

        $horoscopeBlob = $this->sectionBlob($out['sections']['horoscope']);

        $this->assertStringContainsString(__('components.horoscope.rashi_lord'), $horoscopeBlob);
        $this->assertStringContainsString('शनि', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.vashya'), $horoscopeBlob);
        $this->assertStringContainsString('मानव', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 1 स्वामी :- शनि', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 2 वैरवर्ग :- मानव', $horoscopeBlob);
    }

    public function test_horoscope_preview_for_intake_457_keeps_single_mapped_values_without_duplicate_raw_lines(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
देवक :- साळुंकी, कलदैवत :-पालीचा खुंडोबा,
कुंची :- ५' ३" . वर्ण :- निमगोरा,
रास :- कन्या, योनी :- व्याघ्र,
रास नाव :- पेमदेवी, गण :- राक्षस
नक्षत्र :- चचत्रा, वर्ण :- वैश्य,
TXT, true);

        $horoscopeBlob = $this->sectionBlob($out['sections']['horoscope']);

        $this->assertStringContainsString(__('components.horoscope.devak'), $horoscopeBlob);
        $this->assertStringContainsString('साळुंकी', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.kul'), $horoscopeBlob);
        $this->assertStringContainsString('पालीचा खुंडोबा', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.rashi'), $horoscopeBlob);
        $this->assertStringContainsString('कन्या', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.yoni'), $horoscopeBlob);
        $this->assertStringContainsString('वाघ', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.navras_name'), $horoscopeBlob);
        $this->assertStringContainsString('पेमदेवी', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.nakshatra'), $horoscopeBlob);
        $this->assertStringContainsString('चित्रा', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.varna'), $horoscopeBlob);
        $this->assertStringContainsString('वैश्य', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 1', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 2', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 3', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 4', $horoscopeBlob);
    }

    public function test_marathi_locale_uses_marathi_sibling_marriage_label(): void
    {
        $originalLocale = app()->getLocale();
        app()->setLocale('mr');

        try {
            $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव :- चि. रोहित शिंदे
भाऊ :- चि. संकेत शिंदे ( विवाहित )
TXT, true);

            $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);

            $this->assertStringContainsString('भावाची विवाह माहिती विवाहित', $siblingsBlob);
            $this->assertStringNotContainsString('Brother Married', $siblingsBlob);
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_marathi_locale_translates_review_rows_for_missing_gender(): void
    {
        $originalLocale = app()->getLocale();
        app()->setLocale('mr');

        try {
            $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
परिचय पत्र
जात :- हिंदू मराठा
TXT, true);

            $reviewBlob = $this->sectionBlob($out['sections']['review_needed']);

            $this->assertStringContainsString('लिंग', $reviewBlob);
            $this->assertStringContainsString('आवश्यक माहिती सापडली नाही', $reviewBlob);
            $this->assertStringNotContainsString('missing_critical', $reviewBlob);
            $this->assertStringNotContainsString('core.gender', $reviewBlob);
            $this->assertStringNotContainsString('Raw:', $reviewBlob);
            $this->assertStringNotContainsString('Suggested section:', $reviewBlob);
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_marathi_locale_translates_gender_property_and_parent_address_labels(): void
    {
        $originalLocale = app()->getLocale();
        app()->setLocale('mr');

        try {
            $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव :- चि. रोहित शिंदे
लिंग :- male
पालकांचा पत्ता :- राजारामपुरी, कोल्हापूर
स्थावर व शेती :- स्वतःचे घर, १ फ्लॅट, शेती १ एकर
TXT, true);

            $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
            $familyBlob = $this->sectionBlob($out['sections']['family-details']);
            $propertyBlob = $this->sectionBlob($out['sections']['property']);

            $this->assertStringContainsString('लिंग पुरुष', $basicBlob);
            $this->assertStringNotContainsString('Gender male', $basicBlob);
            $this->assertStringContainsString('मालमत्ता साधन 1', $propertyBlob);
            $this->assertStringContainsString('साधन प्रकार घर', $propertyBlob);
            $this->assertStringContainsString('मालमत्ता साधन 2', $propertyBlob);
            $this->assertStringContainsString('साधन प्रकार फ्लॅट', $propertyBlob);
            $this->assertStringContainsString('अतिरिक्त माहिती फ्लॅट', $propertyBlob);
            $this->assertStringContainsString('साधन प्रकार जमीन', $propertyBlob);
            $this->assertStringContainsString('अतिरिक्त माहिती शेती जमीन, 1 एकर', $propertyBlob);
            $this->assertStringContainsString('नोंदी उल्लेख नाही', $propertyBlob);
            $this->assertStringNotContainsString('Property Asset', $propertyBlob);
            $this->assertStringNotContainsString('Asset Type', $propertyBlob);
            $this->assertStringNotContainsString('Additional Information', $propertyBlob);
            $this->assertStringNotContainsString('Not mentioned', $propertyBlob);
            $this->assertStringNotContainsString('(permanent)', $familyBlob);
            $this->assertStringNotContainsString('(current)', $familyBlob);
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_horoscope_preview_drops_blood_group_and_complexion_leftovers_from_horoscope_raw_lines(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
देवक :- वासनिचा वेल रक्त गट :- B+ve
रास :- मकर
वर्ण :- गोरा
TXT, true);

        $horoscopeBlob = $this->sectionBlob($out['sections']['horoscope']);
        $physicalBlob = $this->sectionBlob($out['sections']['physical']);

        $this->assertStringContainsString(__('components.horoscope.devak'), $horoscopeBlob);
        $this->assertStringContainsString('वासनिचा वेल', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.rashi'), $horoscopeBlob);
        $this->assertStringContainsString('मकर', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 1', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 2', $horoscopeBlob);
        $this->assertStringNotContainsString('B+ve', $horoscopeBlob);
        $this->assertStringNotContainsString('गोरा', $horoscopeBlob);
        $this->assertStringContainsString('B+', $physicalBlob);
        $this->assertStringContainsString('गोरा', $physicalBlob);
    }

    public function test_horoscope_preview_maps_navras_alias_without_showing_raw_horoscope_line(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
राशी :- मकर
नावास नाव :- खुशालदेवी
TXT, true);

        $horoscopeBlob = $this->sectionBlob($out['sections']['horoscope']);

        $this->assertStringContainsString(__('components.horoscope.navras_name'), $horoscopeBlob);
        $this->assertStringContainsString('खुशालदेवी', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 1', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 2', $horoscopeBlob);
        $this->assertStringNotContainsString('नावास नाव :- खुशालदेवी', $horoscopeBlob);
    }

    public function test_horoscope_preview_maps_kuldevat_alias_and_omits_false_review_rows_for_no_brother_line(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
रास :- मीन देवक :- मरेडीचा वेल
कुलदेवत :- जोतिबा नक्षत्र :- उत्तर भाद्रपदा
भाऊ :- नाही
बहीण :- एक ( अविवाहित )
TXT, true);

        $horoscopeBlob = $this->sectionBlob($out['sections']['horoscope']);
        $reviewBlob = $this->sectionBlob($out['sections']['review_needed']);

        $this->assertStringContainsString(__('components.horoscope.rashi'), $horoscopeBlob);
        $this->assertStringContainsString('मीन', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.devak'), $horoscopeBlob);
        $this->assertStringContainsString('मरेडीचा वेल', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.kul'), $horoscopeBlob);
        $this->assertStringContainsString('जोतिबा', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.nakshatra'), $horoscopeBlob);
        $this->assertStringContainsString('उत्तर भाद्रपदा', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 1', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 2', $horoscopeBlob);
        $this->assertStringNotContainsString('कुलदेवत :- जोतिबा नक्षत्र :- उत्तर भाद्रपदा', $reviewBlob);
        $this->assertStringNotContainsString('भाऊ :- नाही', $reviewBlob);
    }

    public function test_horoscope_preview_maps_janma_aliases_nad_combo_and_navaras_name_without_raw_lines(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
जन्मरास :- वृषभ
जन्मनक्षत्र :- रोहिणी ४ नावरस नाव : वू
नाड :- आध्य गण :- राक्षस. चरण :- ४
## कौटुंबिक माहिती
TXT, true);

        $horoscopeBlob = $this->sectionBlob($out['sections']['horoscope']);
        $reviewBlob = $this->sectionBlob($out['sections']['review_needed']);

        $this->assertStringContainsString(__('components.horoscope.rashi'), $horoscopeBlob);
        $this->assertStringContainsString('वृषभ', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.nakshatra'), $horoscopeBlob);
        $this->assertStringContainsString('रोहिणी', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.charan'), $horoscopeBlob);
        $this->assertStringContainsString('4', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.navras_name'), $horoscopeBlob);
        $this->assertStringContainsString('वू', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.nadi'), $horoscopeBlob);
        $this->assertStringContainsString('आध्य', $horoscopeBlob);
        $this->assertStringContainsString(__('components.horoscope.gan'), $horoscopeBlob);
        $this->assertStringContainsString('राक्षस', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 1', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 2', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 3', $horoscopeBlob);
        $this->assertStringNotContainsString('जन्मरास :- वृषभ', $reviewBlob);
        $this->assertStringNotContainsString('जन्मनक्षत्र :- रोहिणी ४ नावरस नाव : वू', $reviewBlob);
        $this->assertStringNotContainsString('नाड :- आध्य गण :- राक्षस. चरण :- ४', $reviewBlob);
        $this->assertStringNotContainsString('## कौटुंबिक माहिती', $reviewBlob);
    }

    public function test_review_needed_does_not_repeat_confidently_mapped_values(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
||श्री गणेशायनम: ||
कौटुंबिक माहिती
मुलाचे नाव :- विशाल पांडुरंग डाकवे
सध्याचा पत्ता :- Wonder Residency, Pune
मामा :- जितेंद्र शामराव पवार
राशी :- कुंभ
देवक :- वासनलिवेल
कुलस्वामी :- जोतिबा
TXT, true);

        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
        $familyBlob = $this->sectionBlob($out['sections']['family-details']);
        $relativesBlob = $this->sectionBlob($out['sections']['alliance']);
        $horoscopeBlob = $this->sectionBlob($out['sections']['horoscope']);
        $reviewBlob = $this->sectionBlob($out['sections']['review_needed']);

        $this->assertStringContainsString('Wonder Residency', $familyBlob);
        $this->assertStringNotContainsString('Wonder Residency', $basicBlob);
        $this->assertStringContainsString('जितेंद्र शामराव पवार', $relativesBlob);
        $this->assertStringContainsString('कुंभ', $horoscopeBlob);
        $this->assertStringContainsString('वासनलिवेल', $horoscopeBlob);
        $this->assertStringContainsString('जोतिबा', $horoscopeBlob);
        $this->assertStringNotContainsString('Wonder Residency', $reviewBlob);
        $this->assertStringNotContainsString('जितेंद्र शामराव पवार', $reviewBlob);
        $this->assertStringNotContainsString('कुंभ', $reviewBlob);
        $this->assertStringNotContainsString('श्री गणेश', $reviewBlob);
        $this->assertStringNotContainsString('कौटुंबिक माहिती', $reviewBlob);
    }

    public function test_vishal_sample_routes_fields_to_wizard_sections_without_parent_contacts_as_candidate_contacts(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
## बायोडेटा
* मुलाचे नाव : विशाल पांडुरंग डाकवे
* जन्म तारीख : ०२/११/१९९५
* जन्मवार आणि वेळ : गुरुवारी सकाळी ११ वा. २७ मी.
* नावरस : सीताराम
* रास : कुंभ
* देवक : वासनलिवेल
* उंची : ५ फूट 4 इंच
* कुलस्वामी : जोतिबा
* शिक्षण : BE (MECH)
* जात : हिंदू-मराठा
* नोकरी : Production Engineer
* वडिलांचे नाव : पांडुरंग लक्ष्मण डाकवे (नोकरी-9322202146)
* आईचे नाव : सुवर्णा पांडुरंग डाकवे (नोकरी-9527610122)
* पत्ता : मु. पो. डाकेवाडी काळगाव ता. पाटण जि.सातारा
* निवासी पत्ता : A/303, Wonder Residency ,fatherwadi Vasai.
* चुलते : कै. शामराव लक्ष्मण डाकवे, कृष्णा लक्ष्मण डाकवे,
* हरि लक्ष्मण डाकवे.
* आजोळ : मु. पो. कुठरे मोळावडेवाडी ता. पाटण जि.सातारा
* मामा : जितेंद्र शामराव पवार
TXT, true);

        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
        $physicalBlob = $this->sectionBlob($out['sections']['physical']);
        $educationBlob = $this->sectionBlob($out['sections']['education-career']);
        $familyBlob = $this->sectionBlob($out['sections']['family-details']);
        $paternalBlob = $this->sectionBlob($out['sections']['relatives']);
        $maternalBlob = $this->sectionBlob($out['sections']['alliance']);
        $horoscopeBlob = $this->sectionBlob($out['sections']['horoscope']);
        $aboutBlob = $this->sectionBlob($out['sections']['about-me']);

        $this->assertStringContainsString('विशाल पांडुरंग डाकवे', $basicBlob);
        $this->assertStringContainsString('02/11/1995', $basicBlob);
        $this->assertStringNotContainsString('9322202146', $basicBlob);
        $this->assertStringContainsString('163 cm', $physicalBlob);
        $this->assertStringContainsString('BE (MECH)', $educationBlob);
        $this->assertStringContainsString('Production Engineer', $educationBlob);
        $this->assertStringContainsString('पांडुरंग लक्ष्मण डाकवे', $familyBlob);
        $this->assertStringContainsString('नोकरी', $familyBlob);
        $this->assertStringContainsString('9322202146', $familyBlob);
        $this->assertStringContainsString('सुवर्णा पांडुरंग डाकवे', $familyBlob);
        $this->assertStringContainsString('9527610122', $familyBlob);
        $this->assertArrayNotHasKey('contacts', $out['sections']);
        $this->assertGreaterThanOrEqual(3, substr_count($paternalBlob, 'Paternal Uncle (chulte)'));
        $this->assertStringContainsString('Paternal Uncle (chulte) 1 कै. शामराव लक्ष्मण डाकवे', $paternalBlob);
        $this->assertStringContainsString('Paternal Uncle (chulte) 2 कृष्णा लक्ष्मण डाकवे', $paternalBlob);
        $this->assertStringContainsString('Paternal Uncle (chulte) 3 हरि लक्ष्मण डाकवे', $paternalBlob);
        $this->assertStringNotContainsString('Relative 1 Relation', $paternalBlob);
        $this->assertStringContainsString('कै. शामराव लक्ष्मण डाकवे', $paternalBlob);
        $this->assertStringContainsString('कृष्णा लक्ष्मण डाकवे', $paternalBlob);
        $this->assertStringContainsString('हरि लक्ष्मण डाकवे', $paternalBlob);
        $this->assertStringContainsString('Maternal address (Ajol) Address', $maternalBlob);
        $this->assertStringContainsString('मु. पो. कुठरे मोळावडेवाडी ता. पाटण जि.सातारा', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama)', $maternalBlob);
        $this->assertStringContainsString('जितेंद्र शामराव पवार', $maternalBlob);
        $this->assertStringNotContainsString('Relative 1', $maternalBlob);
        $this->assertStringNotContainsString('चुलते', $maternalBlob);
        $this->assertStringNotContainsString('मामा', $paternalBlob);
        $this->assertStringContainsString('सीताराम', $horoscopeBlob);
        $this->assertStringContainsString('कुंभ', $horoscopeBlob);
        $this->assertStringContainsString('वासनलिवेल', $horoscopeBlob);
        $this->assertStringContainsString('जोतिबा', $horoscopeBlob);
        $this->assertSame('', $aboutBlob);
    }

    public function test_horoscope_birth_weekday_line_does_not_repeat_when_birth_time_is_already_mapped_elsewhere(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
## बायोडेटा

- मुलाचे नाव : विशाल पांडुरंग डाकवे
- जन्म तारीख : ०२/११/१९९५
- जन्मवार आणि वेळ : गुरुवारी सकाळी ११ वा. २७ मी.
- नावरस : सीताराम
- रास : कुंभ
- देवक : वासनलिवेल
- कुलस्वामी : जोतिबा
TXT, true);

        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
        $horoscopeBlob = $this->sectionBlob($out['sections']['horoscope']);

        $this->assertStringContainsString('Birth time', $basicBlob);
        $this->assertStringContainsString('गुरुवारी सकाळी 11 वा. 27 मी.', $basicBlob);
        $this->assertStringContainsString(__('components.horoscope.birth_weekday'), $horoscopeBlob);
        $this->assertStringContainsString('गुरुवार', $horoscopeBlob);
        $this->assertStringNotContainsString('Horoscope line 1', $horoscopeBlob);
        $this->assertStringNotContainsString('जन्मवार आणि वेळ : गुरुवारी सकाळी ११ वा. २७ मी.', $horoscopeBlob);
    }

    public function test_maternal_relatives_use_relation_labels_and_preserve_all_fields(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलीचे नाव :- कु. अंजली पाटील
आजोळ :- मु. पो. कराड, जि. सातारा
मामा :- श्री. मोहन कदम (मोठे मामा) (Teacher) रा. पुणे मो. 9123456789
मामा :- श्री. सोमनाथ कदम (Business) रा. सातारा मो. 9876543210
मामी :- सौ. रेखा मोहन कदम (गृहिणी) रा. पुणे
मावशीचे यजमान :- श्री. संजय जाधव (Doctor) रा. कोल्हापूर मो. 9765432109
मावस भाऊ :- चि. रोहित जाधव (B.Com) ठाणे
TXT, true);

        $maternalBlob = $this->sectionBlob($out['sections']['alliance']);

        $this->assertStringContainsString('Maternal address (Ajol) Address मु. पो. कराड, जि. सातारा', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 1 श्री. मोहन कदम', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 1 Mobile 9123456789', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 1 Occupation Teacher', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 1 Address पुणे', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 1 Additional info मोठे मामा', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 2 श्री. सोमनाथ कदम', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 2 Mobile 9876543210', $maternalBlob);
        $this->assertStringContainsString('Wife of Maternal Uncle सौ. रेखा मोहन कदम', $maternalBlob);
        $this->assertStringContainsString('Wife of Maternal Uncle Occupation गृहिणी', $maternalBlob);
        $this->assertStringContainsString('Husband of Maternal Aunt श्री. संजय जाधव', $maternalBlob);
        $this->assertStringContainsString('Husband of Maternal Aunt Mobile 9765432109', $maternalBlob);
        $this->assertStringContainsString('Maternal Cousin चि. रोहित जाधव', $maternalBlob);
        $this->assertStringContainsString('Maternal Cousin Additional info B.Com', $maternalBlob);
        $this->assertStringContainsString('Maternal Cousin Address ठाणे', $maternalBlob);
        $this->assertStringNotContainsString('Relative 1', $maternalBlob);
        $this->assertStringNotContainsString('Relative 4', $maternalBlob);
    }

    public function test_maternal_relatives_drop_photo_caption_noise_and_clean_other_relative_contacts(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलीचे नाव :- कु. अंजली पाटील
मामा :- श्री. मोहन कदम
एका महिलाचे क्लोज-अप छायाचित्र
जिने गुलाबी रंगाची रेशमी साडी नेसली आहे.
पार्श्वभूमी अस्पष्ट आहे
नातेवाईक :- पाटील, कदम मो. नं. 9876543210
TXT, true);

        $maternalBlob = $this->sectionBlob($out['sections']['alliance']);

        $this->assertStringContainsString('Maternal Uncle (mama) श्री. मोहन कदम', $maternalBlob);
        $this->assertStringContainsString('Other relatives पाटील, कदम', $maternalBlob);
        $this->assertStringNotContainsString('छायाचित्र', $maternalBlob);
        $this->assertStringNotContainsString('साडी', $maternalBlob);
        $this->assertStringNotContainsString('9876543210', $maternalBlob);
    }

    public function test_maternal_address_and_phone_continuations_merge_into_previous_uncle(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलीचे नाव :- कु. प्राजक्ता पानसरे
मामा :- श्री. तुकाराम भगवान इंगोले- नं.
मामा :- श्री. बाबासाहेब भगवान इंगोले
(प्राथमिक शिक्षक)
रा.एखतपूर ता.सांगोला जि.सोलापूर मो. नं. ९६०४९६९५९३
मु.पो.मोहीतेवाडी ता.कोरेगाव जि.सातारा
TXT, true);

        $maternalBlob = $this->sectionBlob($out['sections']['alliance']);

        $this->assertStringContainsString('Maternal Uncle (mama) 1 श्री. तुकाराम भगवान इंगोले', $maternalBlob);
        $this->assertStringNotContainsString('इंगोले- नं', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 2 श्री. बाबासाहेब भगवान इंगोले', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 2 Occupation प्राथमिक शिक्षक', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 2 Mobile 9604969593', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 2 Address एखतपूर ता.सांगोला जि.सोलापूर; मोहीतेवाडी ता.कोरेगाव जि.सातारा', $maternalBlob);
        $this->assertStringNotContainsString('Maternal Uncle (mama) 3', $maternalBlob);
        $this->assertStringNotContainsString('Additional info रा.एखतपूर', $maternalBlob);
    }

    public function test_embedded_chulte_and_other_relatives_do_not_become_maternal_uncles(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव :- चि. रोहित सुंबे
मामा :- कै.दशरथ बबन गगे (आंबी खालसा, ता. सुंगमनेर) चुलते:- श्री. भीमराव बन्सी सुंबे ( पाडळी तर्फ कान्हर ता. पारनेर)
नातेवाईक :- सर्वश्री सिनारे, दावभट, जईड, निमसे, वाकळे, राखुंडे
TXT, true);

        $maternalBlob = $this->sectionBlob($out['sections']['alliance']);
        $paternalBlob = $this->sectionBlob($out['sections']['relatives']);

        $this->assertStringContainsString('Maternal Uncle (mama) कै.दशरथ बबन गगे', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) Address आंबी खालसा, ता. सुंगमनेर', $maternalBlob);
        $this->assertStringContainsString('Paternal Uncle (chulte) श्री. भीमराव बन्सी सुंबे', $paternalBlob);
        $this->assertStringContainsString('Paternal Uncle (chulte) Address पाडळी तर्फ कान्हर ता. पारनेर', $paternalBlob);
        $this->assertStringContainsString('Other relatives सर्वश्री सिनारे, दावभट, जईड, निमसे, वाकळे, राखुंडे', $maternalBlob);
        $this->assertStringNotContainsString('Maternal Uncle (mama) 2 कान्हर', $maternalBlob);
        $this->assertStringNotContainsString('Maternal Uncle (mama) 3 नातेवाईक', $maternalBlob);
    }

    public function test_multiline_embedded_chulte_address_does_not_split_into_second_relative_row(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव :- चि. रोहित सुंबे
मामा :- कै.दशरथ बबन गगे (आंबी खालसा, ता. सुंगमनेर) चुलते:- श्री. भीमराव बन्सी सुंबे ( पाडळी तर्फ
कान्हर ता. पारनेर)
TXT, true);

        $paternalBlob = $this->sectionBlob($out['sections']['relatives']);

        $this->assertStringContainsString('Paternal Uncle (chulte) श्री. भीमराव बन्सी सुंबे', $paternalBlob);
        $this->assertStringContainsString('Paternal Uncle (chulte) Address पाडळी तर्फ कान्हर ता. पारनेर', $paternalBlob);
        $this->assertStringNotContainsString('Paternal Uncle (chulte) 2 कान्हर', $paternalBlob);
        $this->assertStringNotContainsString('श्री. भीमराव बन्सी सुंबे ( पाडळी तर्फ', $paternalBlob);
    }

    public function test_orphan_biodata_numbers_render_in_father_then_user_then_mother_slots(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
नाव :- श्वेताली बाळासाहेब सुंबे
वडील :- बाळासाहेब बन्सी सुंबे
आई :- सौ. नंदा बाळासाहेब सुंबे
मोबाईल नंबर:
9860771090
7972565670
9423651090
9123456789
9234567890
9345678901
TXT, true);

        $familyBlob = $this->sectionBlob($out['sections']['family-details']);
        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);

        $this->assertStringNotContainsString('Father contact 1', $familyBlob);
        $this->assertStringNotContainsString('Mother contact 1', $familyBlob);
        $this->assertStringContainsString('User contact 1 9860771090', $basicBlob);
        $this->assertStringContainsString('User contact 2 7972565670', $basicBlob);
        $this->assertStringContainsString('User contact 3 9423651090', $basicBlob);
        $this->assertStringContainsString('User contact 4 9123456789', $basicBlob);
        $this->assertStringContainsString('User contact 5 9234567890', $basicBlob);
        $this->assertStringContainsString('User contact 6 9345678901', $basicBlob);
    }

    public function test_preview_family_and_relative_sections_expose_grouped_heading_metadata(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव :- चि. रोहित शिंदे
वडिलांचे नाव :- मोहन शिंदे
वडिलांचे व्यवसाय :- व्यवसाय
आईचे नाव :- अनिता शिंदे
आईचा व्यवसाय :- गृहिणी
बहीण :- कविता शिंदे ( विवाहित )
दाजी :- दत्ताजी शिंदे
मामा :- डॉ. मोरे
TXT, true);

        $familyHeading = $out['sections']['family-details'][0] ?? null;
        $siblingsHeading = $out['sections']['siblings'][0] ?? null;
        $allianceHeading = $out['sections']['alliance'][0] ?? null;

        $this->assertSame('group_heading', $familyHeading['row_variant'] ?? null);
        $this->assertSame('Father - मोहन शिंदे', $familyHeading['display_heading_text'] ?? null);
        $this->assertSame('group_heading', $siblingsHeading['row_variant'] ?? null);
        $this->assertSame('Sister - कविता शिंदे', $siblingsHeading['display_heading_text'] ?? null);
        $this->assertSame('group_heading', $allianceHeading['row_variant'] ?? null);
        $this->assertSame('Maternal Uncle (mama) - डॉ. मोरे', $allianceHeading['display_heading_text'] ?? null);
    }

    public function test_mama_list_and_pahune_other_relatives_are_preserved_without_javai_relatives(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव :- चि. रोहित पाटील
बहीण :- सौ. सुरेखा शिंदे
बहीण :- सौ. रेखा शिंदे
मामा :- १. डॉ. वसंतराव शिवाजीराव मोरे पाटील
- (प्राध्यापक, शिवाजी विद्यापीठ, कोल्हापूर)
- केशवराव शिवाजीराव मोरे पाटील, सोलापूर
जावई :- १. दत्ताजी खंडेराव शिंदे (सरकार), बत्तीस शिराळा,
- सांगली (व्यवसाय)
- डॉ. अजय वसंतराव शिंदे (मुर्ती बारामती)
- श्री क्लिनिक, डोंबिवली, ठाणे
पाहुणे :- तातुगडे - देशमुख ( आमणापूर ), जाधव ( नरसेवाडी )
इतर पाहूणे : पाटील, देवणे, मेंगाणे, शेळके
TXT, true);

        $maternalBlob = $this->sectionBlob($out['sections']['alliance']);
        $siblingsBlob = $this->sectionBlob($out['sections']['siblings']);

        $this->assertStringContainsString('Maternal Uncle (mama) 1 डॉ. वसंतराव शिवाजीराव मोरे पाटील', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 1 Occupation प्राध्यापक', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 1 Address शिवाजी विद्यापीठ, कोल्हापूर', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 2 केशवराव शिवाजीराव मोरे पाटील', $maternalBlob);
        $this->assertStringContainsString('Maternal Uncle (mama) 2 Address सोलापूर', $maternalBlob);
        $this->assertStringContainsString('Other relatives तातुगडे - देशमुख ( आमणापूर ), जाधव ( नरसेवाडी ); पाटील, देवणे, मेंगाणे, शेळके', $maternalBlob);
        $this->assertStringNotContainsString('जावई', $maternalBlob);
        $this->assertStringNotContainsString('Maternal Uncle (mama) 3', $maternalBlob);
        $this->assertStringContainsString("Sister's husband दत्ताजी खंडेराव शिंदे", $siblingsBlob);
        $this->assertStringContainsString("Sister's husband Occupation व्यवसाय", $siblingsBlob);
        $this->assertStringContainsString("Sister's husband Address बत्तीस शिराळा, सांगली", $siblingsBlob);
        $this->assertStringContainsString("Sister's husband 2 डॉ. अजय वसंतराव शिंदे", $siblingsBlob);
        $this->assertStringContainsString("Sister's husband 2 Occupation श्री क्लिनिक, डोंबिवली, ठाणे", $siblingsBlob);
        $this->assertStringContainsString("Sister's husband 2 Address मुर्ती बारामती", $siblingsBlob);
    }

    public function test_ashish_caste_line_maps_sub_caste_without_detected_coverage_row(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव :- कु. आशिष बापूराव गायकवाड
जात :- 96 कुळी हिंदू-मराठा
TXT, true);

        $basicBlob = $this->sectionBlob($out['sections']['basic-info']);
        $detected = $out['detected_but_not_included'] ?? [];
        $reviewBlob = $this->reviewAlertBlob($out['sections']['review_needed']);

        $this->assertStringContainsString('Religion हिंदू', $basicBlob);
        $this->assertStringContainsString('Caste मराठा', $basicBlob);
        $this->assertStringContainsString('Sub caste 96 कुळी', $basicBlob);
        $this->assertSame([], $detected);

        $casteRow = $this->findRowByField($out['sections']['basic-info'], 'core.caste');
        $this->assertNotNull($casteRow);
        $this->assertFalse((bool) ($casteRow['needs_review'] ?? false));
        $this->assertStringNotContainsString('Coverage missing fact', $reviewBlob);
    }

    public function test_ashish_caste_line_marathi_locale_shows_sub_caste_in_basic_info(): void
    {
        $originalLocale = app()->getLocale();
        app()->setLocale('mr');

        try {
            $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव :- कु. आशिष बापूराव गायकवाड
जात :- 96 कुळी हिंदू-मराठा
TXT, true);

            $basicBlob = $this->sectionBlob($out['sections']['basic-info']);

            $this->assertStringContainsString('उपजात 96 कुळी', $basicBlob);
            $this->assertSame([], $out['detected_but_not_included'] ?? []);
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_unavailable_when_not_biodata_text(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present('AI unavailable message', false);

        $this->assertFalse($out['available']);
        $this->assertSame('not_biodata_text', $out['skipped_reason']);
        $this->assertSame([], $out['sections']['basic-info']);
        $this->assertArrayNotHasKey('personal', $out['sections']);
    }

    public function test_unavailable_when_text_empty(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present('   ', true);

        $this->assertFalse($out['available']);
        $this->assertSame('empty_text', $out['skipped_reason']);
    }

    public function test_property_preview_cleans_flat_location_quantity_and_land_notes(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
प्रोपर्टी :- 1BHK Flat (1)
प्रोपर्टी :- 2 BHK Flat (2) मीरा रोड ठाणे मध्ये
शेती :- १६ एकर
TXT, true);

        $property = $this->sectionBlob($out['sections']['property']);

        $this->assertStringContainsString('Property Asset 1', $property);
        $this->assertStringContainsString('Asset Type Flat', $property);
        $this->assertStringContainsString('Location Not mentioned', $property);
        $this->assertStringContainsString('Ownership Type Not mentioned', $property);
        $this->assertStringContainsString('Additional Information 1 BHK Flat', $property);
        $this->assertStringContainsString('Property Asset 2', $property);
        $this->assertStringContainsString('Location मीरा रोड, ठाणे', $property);
        $this->assertStringContainsString('Additional Information 2 Flats, 2 BHK', $property);
        $this->assertStringContainsString('Property Asset 3', $property);
        $this->assertStringContainsString('Additional Information 16 एकर', $property);
        $this->assertStringContainsString('Notes Not mentioned', $property);
        $this->assertStringNotContainsString('(1)', $property);
        $this->assertStringNotContainsString('(2)', $property);
    }

    public function test_property_preview_expands_house_plot_and_bagayat_land_from_single_line(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
स्थावर :- स्वतः चे घर , ५ गुंठे प्लॉट व जमीन - १ एकर ( बागायत )
TXT, true);

        $property = $this->sectionBlob($out['sections']['property']);

        $this->assertStringContainsString('Property Asset 1', $property);
        $this->assertStringContainsString('Asset Type House', $property);
        $this->assertStringContainsString('Ownership Type Sole', $property);
        $this->assertStringContainsString('Property Asset 2', $property);
        $this->assertStringContainsString('Asset Type Plot', $property);
        $this->assertStringContainsString('Additional Information 5 गुंठे', $property);
        $this->assertStringContainsString('Property Asset 3', $property);
        $this->assertStringContainsString('Asset Type Land', $property);
        $this->assertStringContainsString('Additional Information Farm land, Bagayat, 1 एकर', $property);
    }

    public function test_property_preview_does_not_infer_property_from_house_number_in_address_only_text(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
नाव :- श्वेताली बाळासाहेब सुंबे
पता :- घर नुं.३७, आशियाना कॉलोनी, सावेडी, अहमदनगर - ४१४ ००३.
TXT, true);

        $property = $this->sectionBlob($out['sections']['property']);

        $this->assertStringNotContainsString('Asset Type House', $property);
        $this->assertSame('', $property);
    }

    public function test_property_preview_keeps_shared_kolhapur_house_plot_and_land_location(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
स्थावर व शेती : कोल्हापूर येथे स्वतःचे घर व २ प्लॉट, चार एक्कर शेती बांबवडे/ कळंबा
स्थावर व शेती : सद्या- श्रीराम फोंड्री (झंवर ग्रुप ) सुपर वायझर (Quality Development)
TXT, true);

        $property = $this->sectionBlob($out['sections']['property']);

        $this->assertStringContainsString('Property Asset 1', $property);
        $this->assertStringContainsString('Asset Type House', $property);
        $this->assertStringContainsString('Location कोल्हापूर', $property);
        $this->assertStringContainsString('Ownership Type Sole', $property);
        $this->assertStringContainsString('Property Asset 2', $property);
        $this->assertStringContainsString('Asset Type Plot', $property);
        $this->assertStringContainsString('Location कोल्हापूर', $property);
        $this->assertStringContainsString('Additional Information 2 Plots', $property);
        $this->assertStringContainsString('Property Asset 3', $property);
        $this->assertStringContainsString('Asset Type Land', $property);
        $this->assertStringContainsString('Location Not mentioned', $property);
        $this->assertStringContainsString('Additional Information Farm land, 4 एकर', $property);
        $this->assertStringContainsString('Additional Information कळंबा', $property);
        $this->assertStringNotContainsString('श्रीराम फोंड्री', $property);
    }

    public function test_property_preview_reads_land_location_from_braced_belgaav(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
शेती : 01 एकर शेती {बेळगाव.}
TXT, true);

        $property = $this->sectionBlob($out['sections']['property']);

        $this->assertStringContainsString('Asset Type Land', $property);
        $this->assertStringContainsString('Location बेळगाव', $property);
        $this->assertStringContainsString('Additional Information Farm land, 01 एकर', $property);
    }

    public function test_property_preview_keeps_common_location_quantity_in_notes(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
प्रोपर्टी :- पुणे मध्ये 6 2BHK flats
प्रोपर्टी :- कोल्हापूर मध्ये commercial office
TXT, true);

        $property = $this->sectionBlob($out['sections']['property']);

        $this->assertStringContainsString('Property Asset 1', $property);
        $this->assertStringContainsString('Asset Type Flat', $property);
        $this->assertStringContainsString('Location पुणे', $property);
        $this->assertStringContainsString('Ownership Type Not mentioned', $property);
        $this->assertStringContainsString('Additional Information 6 Flats, 2 BHK', $property);
        $this->assertStringContainsString('Property Asset 2', $property);
        $this->assertStringContainsString('Asset Type Commercial', $property);
        $this->assertStringContainsString('Location कोल्हापूर', $property);
        $this->assertStringContainsString('Additional Information Office', $property);
        $this->assertStringContainsString('Notes Not mentioned', $property);
    }

    public function test_intake_447_preview_shows_real_candidate_name_and_family_relatives_without_false_review_rows(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
## * बायोडटा *

## *मुलाचे नाव : चि. विजय विलास काळुगडे

- जन्म तारीख :-24/09/1995
- जन्म वेळ :-सकाळी ७.वा
- जन्म स्थळ :-आष्टा - मिरजवाडी
- वर्ण :-सावळा
- कुलदैवत :-श्री. जोतिबा
- मूळगाव :-ऐतवडे बुः
- ता. वाळवा जि. सांगली
- उंची :-५ फूट ६इंच
- शिक्षण :-B.A (Government Iti Fitter)
- नोकरी :-Quality power Electric Equipment Limited.
- kupwad (sangli)
- शेती -:१ एकर बागायत

## *कौटुंबिक माहिती*

- वडिलांचे नाव :-श्री. विलास आकाराम काळुगडे (शेती )
- आईचे नाव :-सौ. सुजाता विलास काळुगडे
- भाऊ :-नाही
- बहीण :-नाही
- मामाचे नाव :-श्री. शिवाजी आनंदा साळुंखे (रा. मिरजवाडी ता.
- वाळवा जि. सांगली)
- मुलाची आत्या :-सौ. छाया शामराव जाधव (रा.रेठरे बुः)
- इतर नातेवाईक :-साळुंखे,पाटील,चव्हाण,जाधव
- संपर्क :-9579254525/9637700398/9527905986
TXT, true);

        $basicRow = $this->findRowByField($out['sections']['basic-info'], 'core.full_name');
        $allianceBlob = $this->sectionBlob($out['sections']['alliance']);
        $relativesBlob = $this->sectionBlob($out['sections']['relatives']);
        $reviewBlob = $this->sectionBlob($out['sections']['review_needed']);

        $this->assertNotNull($basicRow);
        $this->assertSame('चि. विजय विलास काळुगडे', $basicRow['value'] ?? null);
        $this->assertFalse((bool) ($basicRow['needs_review'] ?? false));
        $this->assertStringContainsString('शिवाजी आनंदा साळुंखे', $allianceBlob);
        $this->assertStringContainsString('मिरजवाडी ता. वाळवा जि. सांगली', $allianceBlob);
        $this->assertStringContainsString('छाया शामराव जाधव', $relativesBlob);
        $this->assertStringContainsString('रेठरे बुः', $relativesBlob);
        $this->assertStringNotContainsString('नाव heading वरून अंदाजाने', $reviewBlob);
        $this->assertStringNotContainsString('## *कौटुंबिक माहिती*', $reviewBlob);
        $this->assertStringNotContainsString('मामाचे नाव', $reviewBlob);
        $this->assertStringNotContainsString('मुलाची आत्या', $reviewBlob);
    }

    public function test_decorative_eight_divider_preview_does_not_render_split_parent_address_rows(): void
    {
        $out = app(IntakePreviewNormalizedDraftPresenter::class)->present(<<<'TXT'
मुलाचे नाव ८ चि. आविनाश आवासी पाटील
जन्म तारीख ८ २१.०६.१९९२. जन्म ठिकाण ८ कराड.
शिक्षण ८ B. Com धर्म ८ हिंदू ९६ कुळी मराठा
वडिलांचे नाव ८ श्री.आवासो भगवान पाटील . व्यवसाय ८ शेती
पत्ता ८ मु.पो.येडेमच्छिंद्र ता. वाळवा. जि. सांगली.मो.न.९६६५९१९२१५.
TXT, true);

        $basic = $this->sectionBlob($out['sections']['basic-info']);
        $family = $this->sectionBlob($out['sections']['family-details']);
        $review = $this->detectedBlob($out['sections']['review_needed']);

        $this->assertStringContainsString('चि. आविनाश आवासी पाटील', $basic);
        $this->assertStringContainsString('User contact 1 9665919215', $basic);
        $this->assertStringContainsString('श्री.आवासो भगवान पाटील', $family);
        $this->assertStringContainsString('शेती', $family);
        $this->assertStringContainsString('मु.पो.येडेमच्छिंद्र ता. वाळवा. जि. सांगली', $family);
        $this->assertStringNotContainsString('Parents address 2', $family);
        $this->assertStringNotContainsString('Parents address 3', $family);
        $this->assertStringNotContainsString('full_name_looks_like_address', $review);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function findRowByField(array $rows, string $field): ?array
    {
        foreach ($rows as $row) {
            if (($row['field'] ?? null) === $field) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  list<array{label: string, value: string}>  $rows
     */
    private function sectionBlob(array $rows): string
    {
        return implode(' ', array_map(
            static fn (array $row): string => ($row['label'] ?? '').' '.($row['value'] ?? ''),
            $rows
        ));
    }

    /**
     * @param  list<array{label: string, value: string, reason?: ?string, suggested_section?: ?string, draft_shows?: ?string, source_line_no?: ?int, missing_field?: ?string, missing_value?: ?string, correction_target?: ?string}>  $rows
     */
    private function detectedBlob(array $rows): string
    {
        return implode(' ', array_map(
            static fn (array $row): string => trim(
                ($row['label'] ?? '').' '
                .($row['value'] ?? '').' '
                .($row['draft_shows'] ?? '').' '
                .(! empty($row['source_line_no']) ? 'Line '.$row['source_line_no'].' ' : '')
                .($row['missing_field'] ?? '').' '
                .($row['missing_value'] ?? '').' '
                .($row['correction_target'] ?? '').' '
                .($row['reason'] ?? '').' '
                .($row['suggested_section'] ?? '')
            ),
            $rows
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function reviewAlertBlob(array $rows): string
    {
        return implode(' ', array_map(
            static fn (array $row): string => trim(
                ($row['label'] ?? '').' '
                .($row['value'] ?? '').' '
                .($row['source_text'] ?? '').' '
                .(! empty($row['source_line_no']) ? 'Line '.$row['source_line_no'].' ' : '')
                .($row['missing_field'] ?? '').' '
                .($row['missing_value'] ?? '').' '
                .($row['correction_target'] ?? '').' '
                .($row['suggested_section'] ?? '')
            ),
            $rows
        ));
    }
}
