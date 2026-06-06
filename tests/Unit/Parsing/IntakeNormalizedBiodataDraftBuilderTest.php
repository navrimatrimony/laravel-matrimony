<?php

namespace Tests\Unit\Parsing;

use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntakeNormalizedBiodataDraftBuilderTest extends TestCase
{
    public function test_yuvraj_sample_normalizes_contacts_caste_mother_and_keeps_gender_review_safe(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
*प्रतिमा: decorative logo*
:■:
वैयक्तिक माहिती
नाव : कु. युवराज नामदेव घाटेगस्ती.
जात : हिंदू मराठा {96 कुळी}
आईचे नाव : सौ. सुनंदा नामदेव घाटेगस्ती. { गृहिणी }
मोबाईल नं : 73509 53384/ 96733 50078
TXT);

        $core = $draft['normalized']['core'];
        $phones = $this->phones($draft);
        $this->assertContains('7350953384', $phones);
        $this->assertContains('9673350078', $phones);
        $this->assertSame('मराठा', $core['caste']);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) $core['sub_caste']));
        $this->assertSame('हिंदू', $core['religion']);
        $this->assertSame('गृहिणी', $core['mother_occupation']);
        $this->assertNull($core['gender']);
        $this->assertTrue($this->hasReviewFlag($draft, 'core.gender', 'ambiguous_gender'));
        $this->assertSame([], $queries);
    }

    public function test_swapnil_sample_counts_siblings_and_stops_relatives_before_boundaries(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
बायोडाटा
मुलाचे नांव :- चि. स्वप्नील सतिश शिंदे
भाऊ :- नाही
बहीण :- एक ( अविवाहित )
आत्या :- श्री. भाऊसो कृष्णाजी मोरे रा. इस्लामपूर
घरचा पत्ता :- मु. पो. समडोळी , ता. मिरज , जि. सांगली.
पाहुणे :- तातुगडे - देशमुख
मोबाइल नंबर :- 9860956022 / 8668270153
TXT);

        $core = $draft['normalized']['core'];
        $this->assertSame('male', $core['gender']);
        $this->assertSame(0, $core['brother_count']);
        $this->assertSame(1, $core['sister_count']);
        foreach ($draft['normalized']['siblings'] as $sibling) {
            $this->assertNotContains($sibling['name'] ?? '', ['नाही', 'एक']);
        }
        $phones = $this->phones($draft);
        $this->assertContains('9860956022', $phones);
        $this->assertContains('8668270153', $phones);

        $relativeBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $draft['normalized']['relatives']
        ));
        $this->assertStringNotContainsString('घरचा पत्ता', $relativeBlob);
        $this->assertStringNotContainsString('पाहुणे', $relativeBlob);
        $this->assertStringNotContainsString('मोबाइल नंबर', $relativeBlob);
    }

    public function test_mahesh_sample_prefers_heading_name_and_keeps_contacts_clean(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
कास्ट :- ९६ कुळी मराठा
पित्याचे नाव :-मोहनराव गणपतराव जगताप
प्रोपर्टी :- 1BHK Flat (1) 2 BHK Flat (2)
गावचा पत्ता :- चंद्रेश बिल्डिंग, ठाणे

## महेशकुमार मोहन जगताप

मोबाईल नंबर :- महेश मोहन जगताप (९८७०८७९७२७)
:- मोहन जगताप (९१३७७९३३७१)
TXT);

        $core = $draft['normalized']['core'];
        $this->assertSame('महेशकुमार मोहन जगताप', $core['full_name']);
        $this->assertNotSame('मोहनराव गणपतराव जगताप', $core['full_name']);
        $this->assertSame('मोहनराव गणपतराव जगताप', $core['father_name']);
        $this->assertSame('मराठा', $core['caste']);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) $core['sub_caste']));
        $this->assertTrue($this->hasReviewFlag($draft, 'core.full_name', 'candidate_name_from_heading_fallback'));

        $phones = $this->phones($draft);
        $this->assertContains('9870879727', $phones);
        $this->assertContains('9137793371', $phones);

        $addressBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $draft['normalized']['addresses']
        ));
        $this->assertStringNotContainsString('मोबाईल', $addressBlob);
        $this->assertStringNotContainsString('संपर्क', $addressBlob);
        $this->assertStringNotContainsString('कौटुंबिक', $addressBlob);

        $relativeBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $draft['normalized']['relatives']
        ));
        $this->assertStringNotContainsString('प्रोपर्टी', $relativeBlob);
        $this->assertStringNotContainsString('मोबाईल', $relativeBlob);
        $this->assertStringNotContainsString('गावचा पत्ता', $relativeBlob);
    }

    public function test_candidate_heading_rejects_parent_and_relative_names(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
पित्याचे नाव :- मोहनराव गणपतराव जगताप
आईचे नाव :- अनिता मोहनराव जगताप
मामा :- डॉ. वसंतराव मोरे पाटील

## महेशकुमार मोहन जगताप
TXT);

        $core = $draft['normalized']['core'];
        $this->assertSame('महेशकुमार मोहन जगताप', $core['full_name']);
        $this->assertNotSame($core['father_name'], $core['full_name']);
        $this->assertNotSame($core['mother_name'], $core['full_name']);
        $this->assertStringNotContainsString('वसंतराव', (string) $core['full_name']);
    }

    public function test_candidate_heading_skips_personal_details_section_and_uses_actual_name_heading(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
*प्रतिमा लाल रंगातील एक सजावटीचे कोपरा चिन्ह दर्शवते. यात एक शैलीकृत, वक्र डिझाइन आहे जे एका कोपऱ्यात ठेवले जाते, जे बहुतेक वेळा कागदपत्रे किंवा पुस्तकांमध्ये वापरले जाते.*

- जन्म तारीख :- २२ ऑक्टोबर १९९३
- कास्ट :- ९६ कुळी मराठा

## वैयक्तिक तपशील

- पित्याचे नाव :-मोहनराव गणपतराव जगताप
- आईचे नाव :-अनिता मोहनराव जगताप

## कौटुंबिक तपशील

## ॥ श्री गजानन प्रसन्न ॥ ॥ श्री खंडोबा प्रसन्न ॥
बयोडाटा

*प्रतिमामध्ये एका माणसाचे वर्तुळाकार चित्र आहे. तो गडद दाढी आणि मिशा असलेला एक तरुण आहे. त्याने पांढऱ्या शर्टवर काळा नेहरू जॅकेट घातला आहे. पार्श्वभूमी गडद लाल रंगाची आहे.*

## महेशकुमार मोहन जगताप
TXT);

        $core = $draft['normalized']['core'];
        $this->assertSame('महेशकुमार मोहन जगताप', $core['full_name']);
        $this->assertNotSame('वैयक्तिक तपशील', $core['full_name']);
        $this->assertNotSame('कौटुंबिक तपशील', $core['full_name']);
        $this->assertTrue($this->hasReviewFlag($draft, 'core.full_name', 'candidate_name_from_heading_fallback'));
        $this->assertFalse($this->hasReviewFlag($draft, 'core.full_name', 'suspicious_heading_as_name'));
    }

    public function test_gharcha_patta_is_address_not_property(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
घरचा पत्ता :- मु. पो. समडोळी, ता. मिरज, जि. सांगली
TXT);

        $addressBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $draft['normalized']['addresses']
        ));
        $this->assertStringContainsString('समडोळी', $addressBlob);

        $propertySummary = (string) ($draft['normalized']['property_summary']['summary_text'] ?? '');
        $this->assertStringNotContainsString('समडोळी', $propertySummary);
        $this->assertStringNotContainsString('घरचा पत्ता', $propertySummary);
    }

    public function test_svatache_ghar_is_property_with_address(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
सध्याचा पत्ता : स्वराज्य कॉलनी, संगमनगर.
(स्व:ताच्या मालकीचे घर.)
TXT);

        $addressBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $draft['normalized']['addresses']
        ));
        $this->assertStringContainsString('स्वराज्य कॉलनी', $addressBlob);

        $propertySummary = (string) ($draft['normalized']['property_summary']['summary_text'] ?? '');
        $this->assertMatchesRegularExpression('/मालकीच(?:े|्या)\s*घर/u', $propertySummary);
    }

    public function test_relative_block_stops_before_address_pahune_and_mobile(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
मावशी :- कै. संजय बापुसो शिंदे ( समडोळी )
श्री. ऋषिकेश मोहन सावंत ( कुंडल )
श्री. नंदकुमार बाळासो जाधव ( शिरोळ )
घरचा पत्ता :- मु. पो. समडोळी , ता. मिरज , जि. सांगली.
पाहुणे :- तातुगडे - देशमुख
मोबाइल नंबर :- 9860956022 / 8668270153
TXT);

        $this->assertGreaterThanOrEqual(1, count($draft['normalized']['relatives']));

        foreach ($draft['normalized']['relatives'] as $relative) {
            $blob = implode(' ', array_map('strval', is_array($relative) ? $relative : []));
            $this->assertStringNotContainsString('घरचा पत्ता', $blob);
            $this->assertStringNotContainsString('पाहुणे', $blob);
            $this->assertStringNotContainsString('मोबाइल नंबर', $blob);
            $this->assertStringNotContainsString('मोबाइल', $blob);
        }

        $phones = $this->phones($draft);
        $this->assertContains('9860956022', $phones);
        $this->assertContains('8668270153', $phones);
    }

    public function test_multiline_embedded_relative_address_stays_in_single_chulte_row(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
मुलाचे नाव :- चि. रोहित सुंबे
मामा :- कै.दशरथ बबन गगे (आंबी खालसा, ता. सुंगमनेर) चुलते:- श्री. भीमराव बन्सी सुंबे ( पाडळी तर्फ
कान्हर ता. पारनेर)
नातेवाईक :- सर्वश्री सिनारे, दावभट, जईड, निमसे
TXT);

        $relatives = $draft['normalized']['relatives'];
        $this->assertCount(2, $relatives);

        $mamaRows = array_values(array_filter($relatives, static fn ($row) => ($row['relation_type'] ?? null) === 'maternal_uncle'));
        $chulteRows = array_values(array_filter($relatives, static fn ($row) => ($row['relation_type'] ?? null) === 'paternal_uncle'));

        $this->assertCount(1, $mamaRows);
        $this->assertCount(1, $chulteRows);
        $this->assertSame('कै.दशरथ बबन गगे', $mamaRows[0]['name'] ?? null);
        $this->assertSame('आंबी खालसा, ता. सुंगमनेर', $mamaRows[0]['address_line'] ?? null);
        $this->assertSame('श्री. भीमराव बन्सी सुंबे', $chulteRows[0]['name'] ?? null);
        $this->assertSame('पाडळी तर्फ कान्हर ता. पारनेर', $chulteRows[0]['address_line'] ?? null);
        $this->assertSame('सर्वश्री सिनारे, दावभट, जईड, निमसे', $draft['normalized']['core']['other_relatives_text'] ?? null);
    }

    public function test_orphan_biodata_numbers_do_not_backfill_parent_preview_slots(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
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
TXT);

        $core = $draft['normalized']['core'];

        $this->assertNull($core['father_contact_1'] ?? null);
        $this->assertNull($core['father_contact_2'] ?? null);
        $this->assertNull($core['father_contact_3'] ?? null);
        $this->assertSame('9860771090', $core['primary_contact_number_2'] ?? null);
        $this->assertSame('7972565670', $core['primary_contact_number_3'] ?? null);
        $this->assertNull($core['mother_contact_1'] ?? null);
        $this->assertNull($core['mother_contact_2'] ?? null);
        $this->assertNull($core['mother_contact_3'] ?? null);
    }

    public function test_parent_slots_do_not_absorb_relative_phone_numbers_from_table_rows(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
<table>
<tr><td>वडिलांचे नाव</td><td>: श्री सुभाष किसन पानसरे (प्राथमिक शिक्षक )<br/>मू.पो.केत्दूर नं २ ता.करमाळा,जिल्हा-सोलापूर<br/>-(मो.नं. ९६०४५६३२९२)</td></tr>
<tr><td>आई</td><td>: सौ. वनिता सुभाष पानसरे (प्राथमिक शिक्षिका)(मो.नं.९४२०३५९७४०)</td></tr>
<tr><td>मामा</td><td>: १) श्री. तुकाराम भगवान इंगोले- मो. नं. ९९३००५७३१२<br/>२) श्री. बाबासाहेब भगवान इंगोले (प्राथमिक शिक्षक )<br/>रा.एखतपूर ता.सांगोला जि.सोलापूर मो. नं. ९६०४९६९५९३</td></tr>
</table>
TXT);

        $core = $draft['normalized']['core'];

        $this->assertSame('9604563292', $core['father_contact_1'] ?? null);
        $this->assertNull($core['father_contact_2'] ?? null);
        $this->assertNull($core['father_contact_3'] ?? null);
        $this->assertSame('9420359740', $core['mother_contact_1'] ?? null);
        $this->assertNull($core['mother_contact_2'] ?? null);
        $this->assertNull($core['mother_contact_3'] ?? null);
        $this->assertSame('9930057312', $core['primary_contact_number'] ?? null);
        $this->assertNull($core['primary_contact_number_2'] ?? null);
        $this->assertNull($core['primary_contact_number_3'] ?? null);
    }

    public function test_caste_without_religion_remains_review_safe(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
कास्ट :- ९६ कुळी मराठा
TXT);

        $core = $draft['normalized']['core'];
        $this->assertSame('मराठा', $core['caste']);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) $core['sub_caste']));
        $this->assertNull($core['religion']);
        $this->assertTrue($this->hasReviewFlag($draft, 'core.religion', 'missing_critical'));
    }

    public function test_clean_text_preserves_useful_symbols_in_real_biodata_lines(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
नोकरी :- सांगली जि. म. सह. बँक लि. (क्लर्क परमनंट)
शिक्षण :- M.com (G.D.C & A)
मोबाईल नं : 73509 53384/ 96733 50078
TXT);

        $this->assertStringContainsString('G.D.C & A', $draft['cleaned_text']);
        $this->assertStringContainsString('क्लर्क परमनंट', $draft['cleaned_text']);

        $phones = $this->phones($draft);
        $this->assertContains('7350953384', $phones);
        $this->assertContains('9673350078', $phones);
    }

    public function test_candidate_name_does_not_fall_back_to_section_heading(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
बायोडाटा
कौटुंबिक माहिती
वडिलांचे नाव :- श्री. नामदेव पाटील
आईचे नाव :- सौ. सुनंदा पाटील
TXT);

        $this->assertNull($draft['normalized']['core']['full_name'] ?? null);
    }

    public function test_mixed_birth_time_religion_caste_height_horoscope_and_relatives_are_normalized(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
मुलीचे नाव :- कु. अंजली शंकर पाटील
जन्म तारीख :- 01/01/1998
वार :- 3.45 A.M रात्री सोमवार
धर्म :- हिंदू
जात :- मराठा
उंची :- 5 फुट 4 इंच
राशी :- मेष
नक्षत्र :- अश्विनी
चरण :- २
नाडी :- आद्य
गण :- देव
देवक :- वड
कुलस्वामी :- जोतिबा
- मामा - श्री. मोहन कदम पुणे
- माऊशी - सौ. कविता जाधव
- इतर नातेवाईक - पाटील, कदम, जाधव
भाऊ :- 2
बहिणी :- 1
नोकरी :- Software Engineer - TCS, Pune
प्रॉपर्टी :- स्वतःचे घर, शेती 2 एकर
TXT);

        $core = $draft['normalized']['core'];
        $this->assertSame('कु. अंजली शंकर पाटील', $core['full_name']);
        $this->assertStringContainsString('3.45 A.M', (string) $core['birth_time']);
        $this->assertSame('हिंदू', $core['religion']);
        $this->assertSame('मराठा', $core['caste']);
        $this->assertEqualsWithDelta(162.56, (float) $core['height_cm'], 0.01);
        $this->assertSame(2, $core['brother_count']);
        $this->assertSame(1, $core['sister_count']);
        $this->assertSame('Software Engineer', $core['occupation_title']);
        $this->assertSame('TCS', $core['company_name']);
        $this->assertSame('Pune', $core['work_location_text']);
        $this->assertStringContainsString('पाटील', (string) ($core['other_relatives_text'] ?? ''));

        $horoscope = $draft['normalized']['horoscope'];
        $this->assertSame('मेष', $horoscope['rashi']);
        $this->assertSame('अश्विनी', $horoscope['nakshatra']);
        $this->assertSame('२', $horoscope['charan']);
        $this->assertSame('आद्य', $horoscope['nadi']);
        $this->assertSame('देव', $horoscope['gan']);
        $this->assertSame('वड', $horoscope['devak']);
        $this->assertSame('जोतिबा', $horoscope['kuldaivat']);

        $relativeBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $draft['normalized']['relatives']
        ));
        $this->assertStringContainsString('मोहन कदम', $relativeBlob);
        $this->assertStringContainsString('कविता जाधव', $relativeBlob);
        $this->assertStringContainsString('स्वतःचे घर', (string) ($draft['normalized']['property_summary']['summary_text'] ?? ''));
    }

    public function test_inline_birth_time_is_split_from_date_of_birth(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
मुलीचे नाव :- कु. प्रीती राजेंद्र पाटील
जन्म तारीख :- 24/10/1998 जन्म वेळ :- रात्री 09 वा.45 मि.
जन्म स्थळ :- माळीनगर. ता.- माळशिरस, जि.सोलापूर.
जात :- हिंदू मराठा 96 कुळी
TXT);

        $core = $draft['normalized']['core'];

        $this->assertSame('24/10/1998', $core['date_of_birth']);
        $this->assertSame('रात्री 09 वा.45 मि.', $core['birth_time']);
        $this->assertFalse($this->hasReviewFlag($draft, 'review.missing', 'mixed_field_value'));
    }

    public function test_property_descriptor_is_inherited_for_numbered_locations(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
स्थावर मिळकत : स्वतःचे घर - १) बाबा जरगनगर, कोल्हापूर
२) मंगळवार पेठ, कोल्हापूर
TXT);

        $assets = $draft['normalized']['property_assets'] ?? [];

        $this->assertCount(2, $assets);
        $this->assertSame('house', $assets[0]['asset_type_key'] ?? null);
        $this->assertSame('sole', $assets[0]['ownership_type_key'] ?? null);
        $this->assertSame('बाबा जरगनगर, कोल्हापूर', $assets[0]['location'] ?? null);
        $this->assertSame('house', $assets[1]['asset_type_key'] ?? null);
        $this->assertSame('sole', $assets[1]['ownership_type_key'] ?? null);
        $this->assertSame('मंगळवार पेठ, कोल्हापूर', $assets[1]['location'] ?? null);
    }

    public function test_physical_ocr_typos_are_normalized_without_horoscope_pollution(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
मुलीचे नाव :- श्वेताली बाळासाहेब सुंबे
कुंची :- ५' ३" . वर्ण :- निमगोरा,
ब्लड ग्रप :- A+
नक्षत्र :- चित्रा, वर्ण :- वैश्य,
TXT);

        $core = $draft['normalized']['core'];
        $horoscope = $draft['normalized']['horoscope'] ?? [];

        $this->assertEqualsWithDelta(160.02, (float) ($core['height_cm'] ?? 0), 0.01);
        $this->assertSame('निमगोरा', $core['complexion']);
        $this->assertSame('A+', $core['blood_group']);
        $this->assertStringContainsString('वैश्य', (string) ($horoscope['varna'] ?? ''));
    }

    public function test_horoscope_alias_lines_fill_wizard_parity_fields(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
स्वामी :- शनि
वैरवर्ग :- मानव
TXT);

        $horoscope = $draft['normalized']['horoscope'] ?? [];

        $this->assertSame('शनि', $horoscope['rashi_lord'] ?? null);
        $this->assertSame('मानव', $horoscope['vashya'] ?? null);
    }

    public function test_horoscope_builder_keeps_devak_from_blood_group_combo_and_rejects_complexion_as_varna(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
देवक :- वासनिचा वेल रक्त गट :- B+ve
वर्ण :- गोरा
TXT);

        $core = $draft['normalized']['core'];
        $horoscope = $draft['normalized']['horoscope'] ?? [];

        $this->assertSame('B+', $core['blood_group'] ?? null);
        $this->assertSame('गोरा', $core['complexion'] ?? null);
        $this->assertSame('वासनिचा वेल', $horoscope['devak'] ?? null);
        $this->assertNull($horoscope['varna'] ?? null);
    }

    public function test_horoscope_builder_maps_kuldevat_alias_and_no_brother_line_without_review_flag(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
रास :- मीन देवक :- मरेडीचा वेल
कुलदेवत :- जोतिबा नक्षत्र :- उत्तर भाद्रपदा
भाऊ :- नाही
बहीण :- एक ( अविवाहित )
TXT);

        $core = $draft['normalized']['core'];
        $horoscope = $draft['normalized']['horoscope'] ?? [];
        $reviewBlob = $this->reviewBlob($draft);

        $this->assertSame('मीन', $horoscope['rashi'] ?? null);
        $this->assertSame('मरेडीचा वेल', $horoscope['devak'] ?? null);
        $this->assertSame('जोतिबा', $horoscope['kuldaivat'] ?? null);
        $this->assertSame('उत्तर भाद्रपदा', $horoscope['nakshatra'] ?? null);
        $this->assertSame(0, $core['brother_count']);
        $this->assertSame(1, $core['sister_count']);
        $this->assertStringNotContainsString('कुलदेवत :- जोतिबा नक्षत्र :- उत्तर भाद्रपदा', $reviewBlob);
        $this->assertStringNotContainsString('भाऊ :- नाही', $reviewBlob);
    }

    public function test_intake_457_same_line_horoscope_labels_split_cleanly(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
देवक :- साळुंकी, कलदैवत :-पालीचा खुंडोबा,
कुंची :- ५' ३" . वर्ण :- निमगोरा,
रास :- कन्या, योनी :- व्याघ्र,
रास नाव :- पेमदेवी, गण :- राक्षस
नक्षत्र :- चचत्रा, वर्ण :- वैश्य,
TXT);

        $core = $draft['normalized']['core'];
        $horoscope = $draft['normalized']['horoscope'] ?? [];

        $this->assertEqualsWithDelta(160.02, (float) ($core['height_cm'] ?? 0), 0.01);
        $this->assertSame('निमगोरा', $core['complexion']);
        $this->assertSame('साळुंकी', $horoscope['devak'] ?? null);
        $this->assertSame('पालीचा खुंडोबा', $horoscope['kuldaivat'] ?? null);
        $this->assertSame('कन्या', $horoscope['rashi'] ?? null);
        $this->assertSame('वाघ', $horoscope['yoni'] ?? null);
        $this->assertSame('पेमदेवी', $horoscope['navras_name'] ?? null);
        $this->assertSame('राक्षस', $horoscope['gan'] ?? null);
        $this->assertSame('चित्रा', $horoscope['nakshatra'] ?? null);
        $this->assertSame('वैश्य', $horoscope['varna'] ?? null);
        $this->assertStringNotContainsString('कलदैवत', (string) ($horoscope['devak'] ?? ''));
        $this->assertStringNotContainsString('नाव :-', (string) ($horoscope['rashi'] ?? ''));
        $this->assertStringNotContainsString('वर्ण :-', (string) ($horoscope['nakshatra'] ?? ''));
    }

    public function test_horoscope_builder_maps_janma_aliases_navadras_alias_and_nad_combo_without_review_noise(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
जन्मरास :- वृषभ
जन्मनक्षत्र :- रोहिणी ४ नावरस नाव : वू
नाड :- आध्य गण :- राक्षस. चरण :- ४
## कौटुंबिक माहिती
TXT);

        $horoscope = $draft['normalized']['horoscope'] ?? [];
        $reviewBlob = $this->reviewBlob($draft);

        $this->assertSame('वृषभ', $horoscope['rashi'] ?? null);
        $this->assertSame('रोहिणी', $horoscope['nakshatra'] ?? null);
        $this->assertTrue(in_array($horoscope['charan'] ?? null, ['४', '4'], true));
        $this->assertSame('वू', $horoscope['navras_name'] ?? null);
        $this->assertSame('आध्य', $horoscope['nadi'] ?? null);
        $this->assertSame('राक्षस', $horoscope['gan'] ?? null);
        $this->assertStringNotContainsString('जन्मरास :- वृषभ', $reviewBlob);
        $this->assertStringNotContainsString('जन्मनक्षत्र :- रोहिणी ४ नावरस नाव : वू', $reviewBlob);
        $this->assertStringNotContainsString('नाड :- आध्य गण :- राक्षस. चरण :- ४', $reviewBlob);
        $this->assertStringNotContainsString('## कौटुंबिक माहिती', $reviewBlob);
    }

    public function test_positional_physical_values_are_mapped_from_stacked_biodata_rows(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
## मुलाची माहिती
चि. विवेक वसंत पवार
०६/०३/१९९६ जन्म वेळ :- बुधवार रात्री १.४५ मी
5. 7 इंच रास :- सिंह
पूर्वा चरण :- ४ थे
B+ देवक :-पाच पालवी
हिंदू-मराठा { ९६ कुळी }
TXT);

        $core = $draft['normalized']['core'];

        $this->assertEqualsWithDelta(170.18, (float) ($core['height_cm'] ?? 0), 0.01);
        $this->assertSame('B+', $core['blood_group']);
    }

    public function test_unmapped_useful_lines_are_flagged_without_full_raw_dump(): void
    {
        $text = <<<TXT
बायोडाटा
अपेक्षा :- शिक्षित मुलगी हवी
संदिग्ध माहिती :- हा लांब मजकूर review मध्ये पूर्ण biodata dump म्हणून जाऊ नये.
नोकरी माहिती उपलब्ध पण format वेगळा आहे
TXT;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($text);

        $this->assertTrue($this->hasReviewFlag($draft, 'review.education-career', 'unmapped_career'));
        $reviewBlob = implode("\n", array_map(
            static fn ($flag) => (string) ($flag['raw'] ?? ''),
            $draft['review_flags']
        ));
        $this->assertStringContainsString('नोकरी माहिती', $reviewBlob);
        $this->assertStringNotContainsString($text, $reviewBlob);
    }

    public function test_shwetali_fixture_maps_confident_values_and_ignores_prayer_headers(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
||श्री गणेशानं प्रसन्न||
परिचय पत्र
मुलीचे नाव :- श्वेताली बाळासाहेब सुंबे
जन्म तारीख :- सोमवार दि. १६/०८/१९९९
जन्म वेळ :- पहाटे ०३.४५ वाजता
जन्म स्थळ :- सुंगमनेर, जि. अहमदनगर
धर्म :- हिंदू
जात :- मराठा
उंची :- ५' ३"
वर्ण :- निमगोरा
ब्लड ग्रुप :- A+
शिक्षण :- MSC (computer science)
नोकरी :- Software Engineer - Simplify healthcare, Magarpatta Package 3.55 LPA
वडिलांचे नाव :- बाळासाहेब बन्सी सुंबे (पाटबुंधारे सोसायटी)
आईचे नाव :- सौ. नंदा बाळासाहेब सुंबे (गृहिणी)
भाऊ :- श्री.सुरज बाळासाहेब सुंबे (B.com)
सध्याचा पत्ता :- घर नं 12 सावेडी, अहमदनगर
मूळगाव :- मु.पो. पाडळी तर्फ कान्हर
मामा :- श्री. मोहन पाटील
चुलते :- श्री. रमेश सुंबे
इतर नातेवाईक :- काळे, पवार
देवक :- साळुंकी
कुलदैवत :- पालीचा खुंडोबा
राशी :- कन्या
योनी :- व्याघ्र
गण :- राक्षस
नक्षत्र :- चित्रा
वर्ण :- वैश्य
TXT);

        $core = $draft['normalized']['core'];
        $horoscope = $draft['normalized']['horoscope'] ?? [];

        $this->assertSame('श्वेताली बाळासाहेब सुंबे', $core['full_name']);
        $this->assertSame('निमगोरा', $core['complexion']);
        $this->assertSame('बाळासाहेब बन्सी सुंबे', $core['father_name']);
        $this->assertSame('पाटबुंधारे सोसायटी', $core['father_occupation']);
        $this->assertSame('सौ. नंदा बाळासाहेब सुंबे', $core['mother_name']);
        $this->assertSame('गृहिणी', $core['mother_occupation']);
        $this->assertNull($core['annual_income']);
        $this->assertStringContainsString('Package 3.55 LPA', (string) ($core['salary_package_text'] ?? ''));

        $this->assertSame('साळुंकी', $horoscope['devak'] ?? null);
        $this->assertSame('पालीचा खुंडोबा', $horoscope['kuldaivat'] ?? null);
        $this->assertSame('कन्या', $horoscope['rashi'] ?? null);
        $this->assertSame('वाघ', $horoscope['yoni'] ?? null);
        $this->assertSame('राक्षस', $horoscope['gan'] ?? null);
        $this->assertSame('चित्रा', $horoscope['nakshatra'] ?? null);
        $this->assertSame('वैश्य', $horoscope['varna'] ?? null);

        $relativeBlob = $this->normalizedBlob($draft['normalized']['relatives'] ?? []);
        $addressBlob = $this->normalizedBlob($draft['normalized']['addresses'] ?? []);
        $siblingBlob = $this->normalizedBlob($draft['normalized']['siblings'] ?? []);
        $reviewBlob = $this->reviewBlob($draft);

        $this->assertStringContainsString('मोहन पाटील', $relativeBlob);
        $this->assertStringContainsString('रमेश सुंबे', $relativeBlob);
        $this->assertStringContainsString('काळे, पवार', (string) ($core['other_relatives_text'] ?? ''));
        $this->assertStringContainsString('सावेडी', $addressBlob);
        $this->assertStringContainsString('पाडळी', $addressBlob);
        $this->assertStringContainsString('सुरज', $siblingBlob);
        $this->assertStringNotContainsString('श्री गणेश', $reviewBlob);
        $this->assertStringNotContainsString('परिचय पत्र', $reviewBlob);
        $this->assertStringNotContainsString('कन्या', $reviewBlob);
        $this->assertStringNotContainsString('सावेडी', $reviewBlob);
    }

    public function test_vishal_fixture_maps_addresses_relatives_and_parent_contacts_without_occupation_pollution(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
बायोडेटा
मुलाचे नाव :- विशाल पांडुरंग डाकवे.
जन्म तारीख :- गुरुवार दि. १२/०२/१९९८
जन्म वेळ :- सकाळी ०७.३०
धर्म :- हिंदू
जात :- मराठा
उंची :- 5 ft 8 in
शिक्षण :- B.E.
व्यवसाय :- Engineer
वडील :- पांडुरंग डाकवे (नोकरी-9322202146)
आई :- सौ. सुनीता डाकवे (गृहिणी मो. नं. 9822202146)
सध्याचा पत्ता :- Wonder Residency, Pune
निवासी पत्ता :- Karve Nagar, Pune
चुलते :- 1) कै. शामराव लक्ष्मण डाकवे, 2) कृष्णा लक्ष्मण डाकवे, 3) हरि लक्ष्मण डाकवे
मामा :- जितेंद्र शामराव पवार
आजोळ :- मु.पो. वाठार, जि. सातारा
राशी :- कुंभ
देवक :- वासनलिवेल
कुलस्वामी :- जोतिबा
TXT);

        $core = $draft['normalized']['core'];
        $this->assertSame('विशाल पांडुरंग डाकवे', $core['full_name']);
        $this->assertSame('पांडुरंग डाकवे', $core['father_name']);
        $this->assertSame('नोकरी', $core['father_occupation']);
        $this->assertSame('सौ. सुनीता डाकवे', $core['mother_name']);
        $this->assertSame('गृहिणी', $core['mother_occupation']);
        $this->assertSame('9322202146', $core['father_contact_number']);
        $this->assertSame('9822202146', $core['mother_contact_number']);
        $this->assertNotContains('9322202146', $this->phones($draft));
        $this->assertNotContains('9822202146', $this->phones($draft));

        $addressBlob = $this->normalizedBlob($draft['normalized']['addresses'] ?? []);
        $relativeBlob = $this->normalizedBlob($draft['normalized']['relatives'] ?? []);
        $reviewBlob = $this->reviewBlob($draft);

        $this->assertStringContainsString('Wonder Residency', $addressBlob);
        $this->assertStringContainsString('Karve Nagar', $addressBlob);
        $this->assertStringContainsString('शामराव लक्ष्मण डाकवे', $relativeBlob);
        $this->assertStringContainsString('कृष्णा लक्ष्मण डाकवे', $relativeBlob);
        $this->assertStringContainsString('हरि लक्ष्मण डाकवे', $relativeBlob);
        $this->assertStringContainsString('जितेंद्र शामराव पवार', $relativeBlob);
        $this->assertSame('कुंभ', $draft['normalized']['horoscope']['rashi'] ?? null);
        $this->assertSame('वासनलिवेल', $draft['normalized']['horoscope']['devak'] ?? null);
        $this->assertSame('जोतिबा', $draft['normalized']['horoscope']['kuldaivat'] ?? null);
        $this->assertStringNotContainsString('Wonder Residency', $reviewBlob);
        $this->assertStringNotContainsString('जितेंद्र शामराव पवार', $reviewBlob);
        $this->assertStringNotContainsString('कुंभ', $reviewBlob);
    }

    public function test_intake_447_markdown_heading_name_and_family_relatives_are_mapped_without_false_review_noise(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
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
TXT);

        $core = $draft['normalized']['core'];
        $relatives = $draft['normalized']['relatives'] ?? [];
        $reviewBlob = $this->reviewBlob($draft);

        $this->assertSame('चि. विजय विलास काळुगडे', $core['full_name'] ?? null);
        $this->assertSame('male', $core['gender'] ?? null);
        $this->assertSame('श्री. विलास आकाराम काळुगडे', $core['father_name'] ?? null);
        $this->assertSame('शेती', $core['father_occupation'] ?? null);
        $this->assertSame('सौ. सुजाता विलास काळुगडे', $core['mother_name'] ?? null);
        $this->assertSame(0, $core['brother_count'] ?? null);
        $this->assertSame(0, $core['sister_count'] ?? null);

        $maternalUncles = array_values(array_filter($relatives, static fn ($row) => ($row['relation_type'] ?? null) === 'maternal_uncle'));
        $paternalAunts = array_values(array_filter($relatives, static fn ($row) => ($row['relation_type'] ?? null) === 'paternal_aunt'));

        $this->assertCount(1, $maternalUncles);
        $this->assertCount(1, $paternalAunts);
        $this->assertSame('श्री. शिवाजी आनंदा साळुंखे', $maternalUncles[0]['name'] ?? null);
        $this->assertSame('रा. मिरजवाडी ता. वाळवा जि. सांगली', $maternalUncles[0]['address_line'] ?? null);
        $this->assertSame('सौ. छाया शामराव जाधव', $paternalAunts[0]['name'] ?? null);
        $this->assertSame('रा.रेठरे बुः', $paternalAunts[0]['address_line'] ?? null);
        $this->assertSame('साळुंखे,पाटील,चव्हाण,जाधव', $core['other_relatives_text'] ?? null);

        $this->assertStringNotContainsString('candidate_name_from_heading_fallback', $reviewBlob);
        $this->assertStringNotContainsString('## *कौटुंबिक माहिती*', $reviewBlob);
        $this->assertStringNotContainsString('मामाचे नाव', $reviewBlob);
        $this->assertStringNotContainsString('मुलाची आत्या', $reviewBlob);
    }

    public function test_other_relatives_text_preserves_tail_entries_while_removing_contact_no_noise(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
इतर नातेवाईक :- यादव (करमाळा), यादव (सोलापुर), कन्हेरे (माढा), भोसले (मोहोळ), पवार (पिंपळनेर),
बोरुडे (माळीनगर), चौधरी (करमाळा), चव्हाण (सोलापूर, इंदापूर, माढा, वेळापूर), सुरवसे (बार्शी), फाटे
(पाटोदा), Contact.No. कदम (इंदापूर, पुणे, पंढरपूर), भोसले (इंदापूर), शिंदे (इंदापूर, कुर्डुवाडी), भुजबळ (शेळगाव),
मिसाळ (वाघोली), माने (कोंडबावी, अकलुज, कुर्डुवाडी), चव्हाण (लवंग), गायकवाड (बावडा), मिटकल
(बाभुळ गाव), पराडे (संगम)
TXT);

        $text = (string) (($draft['normalized']['core'] ?? [])['other_relatives_text'] ?? '');

        $this->assertStringContainsString('यादव (करमाळा)', $text);
        $this->assertStringContainsString('कदम (इंदापूर, पुणे, पंढरपूर)', $text);
        $this->assertStringContainsString('मिसाळ (वाघोली)', $text);
        $this->assertStringContainsString('पराडे (संगम)', $text);
        $this->assertStringNotContainsString('Contact.No.', $text);
        $this->assertStringNotContainsString('Contact No.', $text);
        $this->assertStringNotContainsString('No.', $text);
    }

    public function test_mahesh_more_sample_keeps_birth_time_sub_caste_income_and_other_relatives_without_false_mama_rows(): void
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

        $core = $draft['normalized']['core'];
        $relatives = $draft['normalized']['relatives'] ?? [];

        $this->assertSame('शुक्रवार रात्री १२ वा. १५ मि.', $core['birth_time'] ?? null);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) ($core['sub_caste'] ?? '')));
        $this->assertSame(504000, $core['annual_income'] ?? null);
        $this->assertStringContainsString('पोवार', (string) ($core['other_relatives_text'] ?? ''));
        $this->assertStringContainsString('भोसले', (string) ($core['other_relatives_text'] ?? ''));

        $maternalUncles = array_values(array_filter(
            $relatives,
            static fn ($row) => ($row['relation_type'] ?? null) === 'maternal_uncle'
        ));

        $this->assertCount(3, $maternalUncles);
        $this->assertSame('श्री. कृष्णात बापू हुजरे- पाटील', $maternalUncles[0]['name'] ?? null);
        $this->assertSame('खुपिरे, ता. करवीर, जि. कोल्हापूर.', $maternalUncles[0]['address_line'] ?? null);
        $this->assertSame('श्री. सर्जेराव बापू हुजरे-पाटील', $maternalUncles[1]['name'] ?? null);
        $this->assertSame('खुपिरे, ता. करवीर, जि. कोल्हापूर.', $maternalUncles[1]['address_line'] ?? null);
        $this->assertSame('श्री. यशवंत बापू हुजरे - पाटील', $maternalUncles[2]['name'] ?? null);
        $this->assertSame('खुपिरे, ता. करवीर, जि. कोल्हापूर.', $maternalUncles[2]['address_line'] ?? null);

        $relativeBlob = $this->normalizedBlob($relatives);
        $this->assertStringNotContainsString('\"name\":\"सर्व\"', json_encode($relatives, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('\"name\":\"इतर\"', json_encode($relatives, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('maternal_uncle सर्व', $relativeBlob);
    }

    public function test_relative_address_continuation_and_nate_sambandh_do_not_pollute_aunt_rows(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
चुलते :- १)श्री.दिलीप तुकाराम पाटील (शेती)
कै.धनाजी तुकाराम पाटील
मुलाचे मामा :- 1) श्री.हनुमंत दिनकर जगताप 2) चि.भोपाल दिनकर जगताप,
पत्ता - मु.पो.येडेमच्छिंद्र, ता. वाळवा जि. सांगली.
मुलाची आत्या :- श्री.बाबासो पांडुरंग पवार.
पत्ता - मु.पो.रेठरे हरणाक्ष, ता.वाळवा, जि.सांगली.
नाते संबंध :- येडेमच्छिंद्र,तुपारी,बहे,तासगाव,तांबवे (कासेगाव) कवलापूर
TXT);

        $relatives = $draft['normalized']['relatives'] ?? [];
        $core = $draft['normalized']['core'] ?? [];
        $flags = $draft['review_flags'] ?? [];

        $paternalAunts = array_values(array_filter($relatives, static fn ($row) => ($row['relation_type'] ?? null) === 'paternal_aunt'));
        $this->assertCount(1, $paternalAunts);
        $this->assertSame('श्री.बाबासो पांडुरंग पवार', $paternalAunts[0]['name'] ?? null);
        $this->assertSame('मु.पो.रेठरे हरणाक्ष, ता.वाळवा, जि.सांगली.', $paternalAunts[0]['address_line'] ?? null);
        $this->assertSame('येडेमच्छिंद्र,तुपारी,बहे,तासगाव,तांबवे (कासेगाव) कवलापूर', $core['other_relatives_text'] ?? null);
        $this->assertStringNotContainsString('"name":"नाते"', json_encode($relatives, JSON_UNESCAPED_UNICODE));
        $this->assertFalse(collect($flags)->contains(static fn ($flag) => ($flag['field'] ?? null) === 'relatives.paternal_aunt.address_line'));
    }

    public function test_akshada_sample_extracts_salary_kuldaivat_parent_extra_property_sibling_addresses_and_horoscope_cleanly(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($this->akshadaText());

        $core = $draft['normalized']['core'];
        $this->assertSame('Bajaj Electricals', $core['company_name'] ?? null);
        $this->assertSame('नवी मुंबई', $core['work_location_text'] ?? null);
        $this->assertSame(1675000, $core['annual_income'] ?? null);
        $this->assertSame('16,75,000 P/A', $core['salary_package_text'] ?? null);
        $this->assertSame('शेती/व्यावसायिक', $core['father_occupation'] ?? null);
        $this->assertSame('B.Com', $core['father_extra_info'] ?? null);

        $horoscope = $draft['normalized']['horoscope'] ?? [];
        $this->assertSame('जेजुरीचा खंडोबा', $horoscope['kuldaivat'] ?? null);
        $this->assertSame('वडाचे पान', $horoscope['devak'] ?? null);
        $this->assertSame('उत्तरा भाद्र पदा', $horoscope['nakshatra'] ?? null);
        $this->assertSame('मध्य', $horoscope['nadi'] ?? null);
        $this->assertSame('तिसरे', $horoscope['charan'] ?? null);
        $this->assertSame('मनुष्य', $horoscope['gan'] ?? null);
        $this->assertSame('मिन', $horoscope['rashi'] ?? null);
        $this->assertSame('बज्ठ', $horoscope['yog'] ?? null);
        $this->assertSame('A+', $core['blood_group'] ?? null);

        $parentsAddresses = $draft['normalized']['parents_addresses'] ?? [];
        $this->assertCount(2, $parentsAddresses);
        $this->assertSame('ईशा बेला विस्टा, डी-वींग,फ्लॅट नं.१०३', $parentsAddresses[0]['address_line'] ?? null);
        $this->assertSame('यमुना निवास,अल्कोन आकाशिया जवळ; कोंढवा बु.।। ता.हवेली जि.पुणे-४११०४८', $parentsAddresses[1]['address_line'] ?? null);

        $propertySummary = (string) ($draft['normalized']['property_summary']['summary_text'] ?? '');
        $this->assertStringContainsString('स्वता:ची मालमत्ता', $propertySummary);
        $this->assertStringNotContainsString('( शेती/व्यावसायिक )', $propertySummary);

        $propertyBlob = $this->normalizedBlob($draft['normalized']['property_assets'] ?? []);
        $this->assertStringContainsString('land', $propertyBlob);
        $this->assertStringContainsString('house', $propertyBlob);
        $this->assertStringContainsString('शॉप(भाडे)', $propertyBlob);
        $this->assertStringContainsString('रुम भाडे', $propertyBlob);

        $siblings = $draft['normalized']['siblings'] ?? [];
        $this->assertCount(1, $siblings);
        $this->assertSame('अनिकेत अनिल कामठे', $siblings[0]['name'] ?? null);
        $this->assertSame('unmarried', $siblings[0]['marital_status'] ?? null);
        $this->assertSame('San Francisco (USA)', $siblings[0]['address_line'] ?? null);
        $this->assertSame('शिक्षण IIT चेन्नई M.tech', $siblings[0]['notes'] ?? null);
    }

    public function test_apurva_sample_emits_source_lines_extracted_facts_and_clean_coverage_audit(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
नाव - अपूर्वा सुधीर डोंगरे
जन्मतारीख - ४-०३-२०००
जन्मवेळ - सकाळी ७ वाजता
देवक - आरखड
नाडी - मध्य
वडील – सुधीर रामचंद्र डोंगरे (सेवानिवृत)
आई – उज्वला सुधीर डोंगरे (प्राध्यापिका)
भाऊ – प्रज्वल सुधीर डोंगरे (विवाहित) (IT engineer)
वाहिनी – मानसी प्रज्वल डोंगरे (Civil engineer )
बहीण – स्नेहल मयूर शेंडकर (विवाहित) (IT engineer)
जावई -मयूर बाळू शेंडकर (व्यवसाईक)
मूळ गाव – मु.पोस्ट आर्वी नारायणगाव,पुणे
निवास – पंतनगर,घाटकोपर(e),मुंबई
मामा – राजेश गणपत पोखरकर
नातेसंबंध – पोखरकर,वर्पे,मुळे
अपेक्षा – निर्व्यसनी,उच्च शिक्षित
भ्रमणध्वनी – ९५९४२३७११७, ९६९९७३८८२२, ८६५५२११७२८
TXT);

        $sourceLines = $draft['source_lines'] ?? [];
        $facts = $draft['extracted_facts'] ?? [];
        $coverage = $draft['coverage_audit'] ?? [];

        $this->assertNotEmpty($sourceLines);
        $this->assertSame(1, $sourceLines[0]['line_no'] ?? null);
        $this->assertSame('नाव - अपूर्वा सुधीर डोंगरे', $sourceLines[0]['raw'] ?? null);
        $this->assertArrayHasKey('normalized', $sourceLines[0] ?? []);
        $this->assertArrayHasKey('ignored', $sourceLines[0] ?? []);

        $factsJson = json_encode($facts, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('9594237117', $factsJson);
        $this->assertStringContainsString('9699738822', $factsJson);
        $this->assertStringContainsString('8655211728', $factsJson);
        $this->assertStringContainsString('राजेश गणपत पोखरकर', $factsJson);
        $this->assertStringContainsString('आरखड', $factsJson);
        $this->assertStringContainsString('मध्य', $factsJson);
        $this->assertStringContainsString('निर्व्यसनी,उच्च शिक्षित', $factsJson);

        $this->assertSame([], $coverage['missing_facts'] ?? []);
        $this->assertSame([], $coverage['duplicate_facts'] ?? []);
        $this->assertSame([], $coverage['suspicious_mapped_facts'] ?? []);
        $this->assertGreaterThanOrEqual(10, (int) ($coverage['source_fact_count'] ?? 0));
        $this->assertGreaterThanOrEqual(10, (int) ($coverage['visible_fact_count'] ?? 0));
    }

    public function test_address_looking_candidate_name_is_rejected_from_basic_info(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
नाव :- मु. पो. समडोळी, ता. मिरज, जि. सांगली
TXT);

        $this->assertNull($draft['normalized']['core']['full_name'] ?? null);
        $this->assertTrue($this->hasReviewFlag($draft, 'core.primary_contact_number', 'missing_critical'));
    }

    public function test_parent_name_heading_like_value_is_not_accepted(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
वडिलांचे नाव :- कौटुंबिक माहिती
आईचे नाव :- वैयक्तिक माहिती
TXT);

        $this->assertNull($draft['normalized']['core']['father_name'] ?? null);
        $this->assertNull($draft['normalized']['core']['mother_name'] ?? null);
    }

    public function test_decorative_eight_divider_does_not_pollute_parent_address_or_parent_name_fields(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
मुलाचे नाव ८ चि. आविनाश आवासी पाटील
जन्म तारीख ८ २१.०६.१९९२. जन्म ठिकाण ८ कराड.
जन्म वेळ ८ सायं.६ वा.३८ मि. नक्षत्र ८ श्रवण १ ला चरण
शिक्षण ८ B. Com धर्म ८ हिंदू ९६ कुळी मराठा
वडिलांचे नाव ८ श्री.आवासो भगवान पाटील . व्यवसाय ८ शेती
आईचे नाव ८ सौ.शोभा आवासी पाटील.
पत्ता ८ मु.पो.येडेमच्छिंद्र ता. वाळवा. जि. सांगली.मो.न.९६६५९१९२१५.
TXT);

        $core = $draft['normalized']['core'] ?? [];
        $parentsAddresses = $draft['normalized']['parents_addresses'] ?? [];
        $phones = $this->phones($draft);

        $this->assertSame('चि. आविनाश आवासी पाटील', $core['full_name'] ?? null);
        $this->assertSame('21.06.1992', OcrNormalize::normalizeDigits((string) ($core['date_of_birth'] ?? '')));
        $this->assertSame('कराड', rtrim((string) ($core['birth_place_text'] ?? ''), '.'));
        $this->assertSame('B. Com', $core['highest_education'] ?? null);
        $this->assertSame('हिंदू', $core['religion'] ?? null);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) ($core['sub_caste'] ?? '')));
        $this->assertSame('श्री.आवासो भगवान पाटील', $core['father_name'] ?? null);
        $this->assertSame('शेती', $core['father_occupation'] ?? null);
        $this->assertCount(1, $parentsAddresses);
        $this->assertSame('मु.पो.येडेमच्छिंद्र ता. वाळवा. जि. सांगली', rtrim((string) ($parentsAddresses[0]['address_line'] ?? ''), '.'));
        $this->assertContains('9665919215', $phones);
    }

    public function test_decorative_eight_sibling_context_stops_before_chulte_and_atya_blocks(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
भाऊ ८ एक (विवाहित) श्री.हेमंत आवासी पाटील(MCA) Whats App मो.नं.९८२३३२९९०३
नोकरी- बी.जी.शिकें.कंन्स्ट्रक्शन कंपनी मुंबई.
मुलाचे चुलते ८ श्री.मोहन भगवान पाटील
श्री.सर्जेराव भगवान पाटील (माजी युनियन अध्यक्ष व.मो.कृष्णा सहकारी साग्नर कारखाना).
मुलाची आत्त्या ८ श्री.शिवाजी ज्ञानदेव पाटील (मु.पो. रेथरे खुर्द)
TXT);

        $siblings = $draft['normalized']['siblings'] ?? [];
        $relatives = $draft['normalized']['relatives'] ?? [];

        $this->assertCount(1, $siblings);
        $this->assertSame('हेमंत आवासी पाटील', $siblings[0]['name'] ?? null);
        $this->assertSame('बी.जी.शिकें.कंन्स्ट्रक्शन कंपनी मुंबई.; MCA', $siblings[0]['occupation'] ?? null);
        $this->assertCount(1, $relatives);
        $this->assertSame('paternal_aunt', $relatives[0]['relation_type'] ?? null);
        $this->assertSame('श्री.शिवाजी ज्ञानदेव पाटील', $relatives[0]['name'] ?? null);
    }

    public function test_decorative_eight_mulichi_bahin_routes_to_siblings_not_relatives(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
मुलाचे मामा ८ श्री.सुरेश राजाराम माने
श्री.अनिल राजाराम माने
श्री.विनायक राजाराम माने (मु.पो.सदाशिवगड ता.कराड.जि.सातारा)
मुलाची बहिण ८ श्री.अशोक भगवान पाटील (मु.पो.तासगाव चिंचणी जि.सांगली)
TXT);

        $siblings = $draft['normalized']['siblings'] ?? [];
        $relatives = $draft['normalized']['relatives'] ?? [];
        $flags = $draft['review_flags'] ?? [];

        $this->assertCount(1, $siblings);
        $this->assertSame('sister', $siblings[0]['relation_type'] ?? null);
        $this->assertSame('अशोक भगवान पाटील', $siblings[0]['name'] ?? null);
        $this->assertSame('मु.पो.तासगाव चिंचणी जि.सांगली', $siblings[0]['address_line'] ?? null);
        $this->assertStringNotContainsString('"name":"मुलाची"', json_encode($relatives, JSON_UNESCAPED_UNICODE));
        $this->assertFalse(collect($flags)->contains(static fn ($flag) => ($flag['field'] ?? null) === 'siblings.sister.address_line'));
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<string>
     */
    private function phones(array $draft): array
    {
        return array_values(array_map(
            static fn ($row) => (string) ($row['phone_number'] ?? ''),
            $draft['normalized']['contacts']
        ));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function hasReviewFlag(array $draft, string $field, string $reason): bool
    {
        foreach ($draft['review_flags'] as $flag) {
            if (($flag['field'] ?? null) === $field && ($flag['reason'] ?? null) === $reason) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function normalizedBlob(array $rows): string
    {
        return implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $rows
        ));
    }

    private function reviewBlob(array $draft): string
    {
        return implode("\n", array_map(
            static fn ($flag) => implode(' ', array_map('strval', is_array($flag) ? $flag : [])),
            $draft['review_flags'] ?? []
        ));
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
