<?php

namespace Tests\Unit\Parsing;

use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\IntakeHtmlTableHintApplier;
use App\Services\Parsing\IntakeNormalizedBiodataDraftBuilder;
use App\Services\Parsing\IntakeNormalizedDraftToParsedJsonMapper;
use Tests\TestCase;

class IntakeHtmlTableHintApplierTest extends TestCase
{
    public function test_no_hints_leaves_draft_unchanged(): void
    {
        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build(<<<'TXT'
मुलीचे नाव :- कु. अंजली पाटील
मोबाईल नं :- 9876543210
TXT);

        $beforeCore = $draft['normalized']['core'] ?? [];
        $beforeContacts = $draft['normalized']['contacts'] ?? [];

        $applied = app(IntakeHtmlTableHintApplier::class)->apply($draft);

        $this->assertSame($beforeCore, $applied['normalized']['core'] ?? []);
        $this->assertSame($beforeContacts, $applied['normalized']['contacts'] ?? []);
    }

    public function test_basic_html_table_applies_core_fields_and_contacts(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>मुलीचे नाव</td><td>कु. अंजली पाटील</td></tr>
<tr><td>जन्म तारीख</td><td>01/01/1998</td></tr>
<tr><td>जन्म वेळ</td><td>सकाळी 09:30</td></tr>
<tr><td>जन्म स्थळ</td><td>पुणे</td></tr>
<tr><td>जात</td><td>हिंदू मराठा 96 कुळी</td></tr>
<tr><td>शिक्षण</td><td>B.Com</td></tr>
<tr><td>मोबाईल नं</td><td>9876543210 / 9123456789</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $core = $draft['normalized']['core'] ?? [];

        $this->assertStringContainsString('अंजली', (string) ($core['full_name'] ?? ''));
        $this->assertNotSame('', trim((string) ($core['date_of_birth'] ?? '')));
        $this->assertTrue(
            str_contains((string) ($core['birth_time'] ?? ''), '09')
            || str_contains((string) ($core['birth_time'] ?? ''), 'सकाळी')
        );
        $this->assertStringContainsString('पुणे', (string) ($core['birth_place_text'] ?? ''));
        $this->assertSame('हिंदू', $core['religion'] ?? null);
        $this->assertSame('मराठा', $core['caste'] ?? null);
        $this->assertSame('96 कुळी', (string) ($core['sub_caste'] ?? ''));
        $this->assertSame('B.Com', $core['highest_education'] ?? null);

        $phones = array_map(
            static fn (array $c): string => (string) ($c['phone_number'] ?? $c['number'] ?? ''),
            $draft['normalized']['contacts'] ?? []
        );
        $this->assertContains('9876543210', $phones);
        $this->assertContains('9123456789', $phones);
        $this->assertSame(1, count(array_filter(
            $draft['normalized']['contacts'] ?? [],
            static fn (array $c): bool => ! empty($c['is_primary'])
        )));
        $this->assertSame('9876543210', (string) ($core['primary_contact_number'] ?? ''));
    }

    public function test_two_cell_html_table_leading_separators_do_not_leak_into_normalized_core(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>मुलीचे नाव</td><td>: कु. प्राजक्ता सुभाष पानसरे</td></tr>
<tr><td>जन्म वेळ</td><td>: दु.१. वा.३८.मि</td></tr>
<tr><td>जन्म स्थळ</td><td>: एखतपूर ता.सांगोला जि.सोलापुर</td></tr>
<tr><td>शिक्षण</td><td>: B.D.S</td></tr>
<tr><td>रक्तगट</td><td>: A-ve</td></tr>
<tr><td>वर्ण</td><td>: गोरा</td></tr>
<tr><td>इतर नातेवाईक</td><td>: शेंडे, निकम, पवार</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $core = $draft['normalized']['core'] ?? [];

        foreach ([
            'full_name',
            'birth_time',
            'birth_place_text',
            'highest_education',
            'blood_group',
            'complexion',
            'other_relatives_text',
        ] as $field) {
            $this->assertDoesNotMatchRegularExpression('/^\s*:/u', (string) ($core[$field] ?? ''), "{$field} should not start with a colon");
        }

        $this->assertSame('कु. प्राजक्ता सुभाष पानसरे', $core['full_name'] ?? null);
        $this->assertSame('B.D.S', $core['highest_education'] ?? null);
        $this->assertSame('गोरा', $core['complexion'] ?? null);
        $this->assertSame('शेंडे, निकम, पवार', $core['other_relatives_text'] ?? null);
    }

    public function test_html_table_mobile_excludes_footer_phone_from_contacts(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>मोबाईल नं</td><td>9876543210</td></tr>
</table>
Print Shop Contact: 9604289289
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $phones = array_map(
            static fn (array $c): string => (string) ($c['phone_number'] ?? $c['number'] ?? ''),
            $draft['normalized']['contacts'] ?? []
        );

        $this->assertContains('9876543210', $phones);
        $this->assertNotContains('9604289289', $phones);
    }

    public function test_mapper_does_not_leak_draft_meta_or_table_hints(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>मुलीचे नाव</td><td>कु. अंजली पाटील</td></tr>
<tr><td>मोबाईल नंबर</td><td>9876543210</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);

        foreach (['meta', 'table_hints', 'cleaned_text', 'sections', 'post_table_body'] as $key) {
            $this->assertArrayNotHasKey($key, $parsed, "parsed_json must not contain draft key: {$key}");
        }

        $this->assertArrayHasKey('core', $parsed);
        $this->assertArrayHasKey('contacts', $parsed);
        $this->assertStringContainsString('अंजली', (string) (($parsed['core'] ?? [])['full_name'] ?? ''));
        $this->assertNotEmpty($parsed['contacts'] ?? []);
    }

    public function test_html_table_current_and_native_address_hints(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>सध्याचा पत्ता</td><td>:-</td><td>मीरा रोड, ठाणे</td></tr>
<tr><td>मूळ पत्ता</td><td>:-</td><td>उरुण इस्लामपूर, ता. वाळवा, जि. सांगली</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $core = $draft['normalized']['core'] ?? [];
        $addresses = $draft['normalized']['addresses'] ?? [];

        $this->assertStringContainsString('मीरा रोड', (string) ($core['address_line'] ?? ''));

        $currentRows = array_values(array_filter(
            $addresses,
            static fn (array $a): bool => ($a['type'] ?? '') === 'current'
        ));
        $nativeRows = array_values(array_filter(
            $addresses,
            static fn (array $a): bool => ($a['type'] ?? '') === 'native'
        ));

        $this->assertNotEmpty($currentRows);
        $this->assertNotEmpty($nativeRows);
        $this->assertStringContainsString('मीरा रोड', (string) ($currentRows[0]['address_line'] ?? ''));
        $this->assertStringContainsString('उरुण इस्लामपूर', (string) ($nativeRows[0]['address_line'] ?? ''));

        foreach ($addresses as $address) {
            $line = (string) ($address['address_line'] ?? '');
            $this->assertDoesNotMatchRegularExpression('/(?:मोबाईल|संपर्क|[6-9]\d{9})/u', $line);
        }
    }

    public function test_html_table_current_address_wins_over_native_for_core_address_line(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>मूळ पत्ता</td><td>:-</td><td>मु. पो. वाळवा, जि. पुणे</td></tr>
<tr><td>सध्याचा पत्ता</td><td>:-</td><td>फ्लॅट ३, बानेर, पुणे</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $core = $draft['normalized']['core'] ?? [];

        $this->assertStringContainsString('बानेर', (string) ($core['address_line'] ?? ''));
        $this->assertStringNotContainsString('वाळवा', (string) ($core['address_line'] ?? ''));
    }

    public function test_html_table_other_relatives_text_from_table_row(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>इतर पाहुणे</td><td>:-</td><td>पाटील, कदम, जाधव</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $core = $draft['normalized']['core'] ?? [];

        $this->assertStringContainsString('पाटील', (string) ($core['other_relatives_text'] ?? ''));
        $this->assertStringContainsString('कदम', (string) ($core['other_relatives_text'] ?? ''));
        $this->assertStringContainsString('जाधव', (string) ($core['other_relatives_text'] ?? ''));
        $this->assertSame([], $draft['normalized']['relatives'] ?? []);
    }

    public function test_html_table_polluted_other_relatives_text_rejected(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>इतर पाहुणे</td><td>:-</td><td>पाटील, कदम. अपेक्षा: शिक्षित मुलगी हवी. मोबाईल: 9999999999</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $ort = (string) (($draft['normalized']['core'] ?? [])['other_relatives_text'] ?? '');

        $this->assertTrue($ort === '' || (
            ! str_contains($ort, 'अपेक्षा') && ! str_contains($ort, 'मोबाईल')
        ));
    }

    public function test_html_table_property_summary_from_table_row(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>इतर प्रॉपर्टी</td><td>:-</td><td>स्वतःचे घर, फ्लॅट, शेती 01 एकर</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $property = $draft['normalized']['property_summary'] ?? null;

        $this->assertIsArray($property);
        $this->assertStringContainsString('स्वतःचे घर', (string) ($property['summary_notes'] ?? $property['summary_text'] ?? ''));
        $this->assertTrue($property['owns_house'] ?? false);
        $this->assertTrue($property['owns_flat'] ?? false);
        $this->assertTrue($property['owns_agriculture'] ?? false);
        $this->assertSame(1.0, (float) ($property['total_land_acres'] ?? 0));

        $parsed = app(IntakeNormalizedDraftToParsedJsonMapper::class)->map($draft);
        $mappedProperty = $parsed['property_summary'] ?? null;
        $this->assertIsArray($mappedProperty);
        $this->assertStringContainsString('01 एकर', (string) ($mappedProperty['summary_notes'] ?? ''));
        $this->assertTrue($mappedProperty['owns_house'] ?? false);
    }

    public function test_html_table_horoscope_grid_aliases_map_to_wizard_fields(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>स्वामी</td><td>शनि</td></tr>
<tr><td>वैरवर्ग</td><td>मानव</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);

        $horoscope = $draft['normalized']['horoscope'] ?? [];
        $this->assertSame('मानव', $horoscope['vashya'] ?? null);
        $this->assertSame('शनि', $horoscope['rashi_lord'] ?? null);
    }

    public function test_html_table_gharcha_patta_does_not_create_property_summary(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>घरचा पत्ता</td><td>:-</td><td>मु. पो. समडोळी, ता. मिरज</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $parents = $draft['normalized']['parents_addresses'] ?? [];
        $property = $draft['normalized']['property_summary'] ?? null;

        $this->assertNotEmpty($parents);
        $this->assertStringContainsString('समडोळी', (string) ($parents[0]['address_line'] ?? ''));
        $this->assertTrue($property === null || $property === [] || trim((string) (($property['summary_text'] ?? '') ?: ($property['summary_notes'] ?? ''))) === '');
        $this->assertNull((($draft['normalized']['core'] ?? [])['address_line'] ?? null));
    }

    public function test_html_table_horoscope_height_and_sibling_hints_are_normalized(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>मुलाचे नाव</td><td>चि. रोहित शिंदे</td></tr>
<tr><td>उंची</td><td>5 फूट 4 इंच</td></tr>
<tr><td>रास</td><td>मेष</td></tr>
<tr><td>नक्षत्र</td><td>अश्विनी</td></tr>
<tr><td>चरण</td><td>२</td></tr>
<tr><td>नाडी</td><td>आद्य</td></tr>
<tr><td>गण</td><td>देव</td></tr>
<tr><td>देवक</td><td>वड</td></tr>
<tr><td>कुलदैवत</td><td>जोतिबा</td></tr>
<tr><td>भाऊ</td><td>2</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $core = $draft['normalized']['core'] ?? [];
        $horoscope = $draft['normalized']['horoscope'] ?? [];

        $this->assertEqualsWithDelta(162.56, (float) ($core['height_cm'] ?? 0), 0.01);
        $this->assertSame(2, $core['brother_count'] ?? null);
        $this->assertSame('मेष', $horoscope['rashi'] ?? null);
        $this->assertSame('अश्विनी', $horoscope['nakshatra'] ?? null);
        $this->assertSame('२', $horoscope['charan'] ?? null);
        $this->assertSame('आद्य', $horoscope['nadi'] ?? null);
        $this->assertSame('देव', $horoscope['gan'] ?? null);
        $this->assertSame('वड', $horoscope['devak'] ?? null);
        $this->assertSame('जोतिबा', $horoscope['kuldaivat'] ?? null);
    }

    public function test_html_table_multiple_pairs_keep_physical_values_separate_from_horoscope(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>● रक्तगट</td><td>-</td><td>B+ve</td><td>नक्षत्र -</td><td>मृग</td></tr>
<tr><td>● योनी</td><td>-</td><td>सर्प</td><td>रंग -</td><td>गोरा</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $core = $draft['normalized']['core'] ?? [];
        $horoscope = $draft['normalized']['horoscope'] ?? [];

        $this->assertSame('B+', $core['blood_group'] ?? null);
        $this->assertSame('गोरा', $core['complexion'] ?? null);
        $this->assertSame('मृग', $horoscope['nakshatra'] ?? null);
        $this->assertSame('सर्प', $horoscope['yoni'] ?? null);
        $this->assertStringNotContainsString('नक्षत्र', (string) ($core['blood_group'] ?? ''));
    }

    public function test_prajakta_table_keeps_family_relatives_and_horoscope_clean(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>|| श्री गणेशायनम: ||</td><td></td></tr>
<tr><td>मुलीचे नाव</td><td>कु. प्राजक्ता सुभाष पानसरे</td></tr>
<tr><td>वडिलांचे नाव</td><td>सुभाष पानसरे (प्राथमिक शिक्षक.) घरचा पत्ता: बार्शी</td></tr>
<tr><td>आईचे नाव</td><td>सौ. लता पानसरे (गृहिणी मो. नं. 9876543210)</td></tr>
<tr><td>उंची</td><td>5 फूट 4 इंच</td></tr>
<tr><td>रक्तगट</td><td>A+</td></tr>
<tr><td>जात</td><td>हिंदू मराठा ९६ कुळी</td></tr>
<tr><td>भाऊ</td><td>श्री. सागर पानसरे (B.Com)</td></tr>
<tr><td>भाऊ</td><td>श्री. सागर पानसरे (B.Com)</td></tr>
<tr><td>मामा</td><td>श्री. मोहन कदम 9123456789</td></tr>
<tr><td>चुलते</td><td>श्री. राजू पानसरे, श्री. संजय पानसरे</td></tr>
<tr><td>इतर नातेवाईक</td><td>कदम, जाधव</td></tr>
<tr><td>रास</td><td>कन्या</td></tr>
<tr><td>रास नाव</td><td>नवरस नाव चुकीचे</td></tr>
<tr><td>नक्षत्र</td><td>चित्रा</td></tr>
<tr><td>गण</td><td>राक्षस</td></tr>
<tr><td>नाडी</td><td>मध्य</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $core = $draft['normalized']['core'] ?? [];
        $siblings = $draft['normalized']['siblings'] ?? [];
        $horoscope = $draft['normalized']['horoscope'] ?? [];

        $this->assertSame('सुभाष पानसरे', $core['father_name'] ?? null);
        $this->assertSame('प्राथमिक शिक्षक.', $core['father_occupation'] ?? null);
        $this->assertSame('सौ. लता पानसरे', $core['mother_name'] ?? null);
        $this->assertSame('गृहिणी', $core['mother_occupation'] ?? null);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) ($core['sub_caste'] ?? '')));
        $this->assertCount(1, $siblings);

        $relativeBlob = implode(' ', array_map(
            static fn ($row) => implode(' ', array_map('strval', is_array($row) ? $row : [])),
            $draft['normalized']['relatives'] ?? []
        ));
        $this->assertStringContainsString('मोहन कदम', $relativeBlob);
        $this->assertStringContainsString('9123456789', $relativeBlob);
        $this->assertStringContainsString('राजू पानसरे', $relativeBlob);
        $this->assertStringContainsString('संजय पानसरे', $relativeBlob);
        $this->assertSame('कदम, जाधव', $core['other_relatives_text'] ?? null);
        $this->assertSame('कन्या', $horoscope['rashi'] ?? null);
        $this->assertNotSame('नवरस नाव चुकीचे', $horoscope['rashi'] ?? null);
        $this->assertStringNotContainsString('श्री गणेश', implode(' ', array_map('json_encode', $draft['review_flags'] ?? [])));
    }

    public function test_html_table_parent_hint_splits_name_address_and_phone(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>मुलीचे नाव</td><td>: कु. प्राजक्ता सुभाष पानसरे</td></tr>
<tr><td>वडिलांचे नाव</td><td>: श्री सुभाष किसन पानसरे (प्राथमिक शिक्षक )<br/>मू.पो.केत्दूर नं २ ता.करमाळा,जिल्हा-सोलापूर<br/>-(मो.नं. ९६०४५६३२९२)</td></tr>
<tr><td>आई</td><td>: सौ. वनिता सुभाष पानसरे (प्राथमिक शिक्षिका)(मो.नं.९४२०३५९७४०)</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $core = $draft['normalized']['core'] ?? [];
        $parents = $draft['normalized']['parents_addresses'] ?? [];

        $this->assertSame('श्री सुभाष किसन पानसरे', $core['father_name'] ?? null);
        $this->assertSame('प्राथमिक शिक्षक', $core['father_occupation'] ?? null);
        $this->assertSame('9604563292', $core['father_contact_1'] ?? null);
        $this->assertSame('सौ. वनिता सुभाष पानसरे', $core['mother_name'] ?? null);
        $this->assertSame('प्राथमिक शिक्षिका', $core['mother_occupation'] ?? null);
        $this->assertSame('9420359740', $core['mother_contact_1'] ?? null);
        $this->assertStringContainsString('मू.पो.', (string) ($parents[0]['address_line'] ?? ''));
        $this->assertStringContainsString('केत्दूर', (string) ($parents[0]['address_line'] ?? ''));
        $this->assertStringContainsString('करमाळा', (string) ($parents[0]['address_line'] ?? ''));
    }

    public function test_html_table_preview_474_family_address_and_caste_fields_stay_structured(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>मुलीचे नांव</td><td>कु. शितल तानाजी कदम</td></tr>
<tr><td>जन्म तारीख</td><td>03/09/1990</td></tr>
<tr><td>जन्म वेल</td><td>सकाळी ९.१५ मि. सोमवार</td></tr>
<tr><td>कुळ</td><td>९६ कुळी मराठा</td></tr>
<tr><td>वडिलांचे नांव</td><td>श्री. तानाजी सोपान कदम Mob. 9922820735</td></tr>
<tr><td>मुळगांव</td><td>सौंदरे, ता. बार्शी, जि. सोलापूर</td></tr>
<tr><td>आईचे नांव</td><td>सौ. कुंदा तानाजी कदम (अविवाहित) महाराष्ट्र पोलिस</td></tr>
<tr><td>भाऊ</td><td>चि. शुभम तानाजी कदम (अविवाहित) महाराष्ट्र पोलिस</td></tr>
<tr><td>नातेसंबंध</td><td>शिंदे, घावटे, गोरे(काका), चव्हाण, जाधव</td></tr>
<tr><td>संपर्क पत्ता</td><td>श्री. तानाजी सोपान कदम<br/>उपःकाल हौसिंग सोसायटी, गोसावी हॉस्पिटलजवळ,<br/>रुपीनगर,(तळवडे), ता. हवेली, जि. पुणे. Mob. 9922820735</td></tr>
</table>
HTML;

        $draft = app(IntakeNormalizedBiodataDraftBuilder::class)->build($html);
        $core = $draft['normalized']['core'] ?? [];
        $siblings = $draft['normalized']['siblings'] ?? [];
        $addresses = $draft['normalized']['addresses'] ?? [];

        $this->assertSame('सकाळी ९.१५ मि. सोमवार', $core['birth_time'] ?? null);
        $this->assertSame('मराठा', $core['caste'] ?? null);
        $this->assertSame('96 कुळी', OcrNormalize::normalizeDigits((string) ($core['sub_caste'] ?? '')));
        $this->assertSame('श्री. तानाजी सोपान कदम', $core['father_name'] ?? null);
        $this->assertSame('9922820735', $core['father_contact_1'] ?? null);
        $this->assertSame('सौ. कुंदा तानाजी कदम', $core['mother_name'] ?? null);
        $this->assertSame('महाराष्ट्र पोलिस', $core['mother_occupation'] ?? null);
        $this->assertSame('शिंदे, घावटे, गोरे(काका), चव्हाण, जाधव', $core['other_relatives_text'] ?? null);
        $this->assertStringContainsString('उपःकाल हौसिंग सोसायटी', (string) ($core['address_line'] ?? ''));
        $this->assertStringNotContainsString('श्री. तानाजी सोपान कदम', (string) ($core['address_line'] ?? ''));
        $this->assertStringNotContainsString('9922820735', (string) ($core['address_line'] ?? ''));
        $this->assertStringNotContainsString('Mob.', (string) ($core['address_line'] ?? ''));

        $nativeRows = array_values(array_filter(
            $addresses,
            static fn (array $row): bool => ($row['type'] ?? null) === 'native'
        ));
        $currentRows = array_values(array_filter(
            $addresses,
            static fn (array $row): bool => ($row['type'] ?? null) === 'current'
        ));

        $this->assertCount(1, $nativeRows);
        $this->assertSame('सौंदरे, ता. बार्शी, जि. सोलापूर', $nativeRows[0]['address_line'] ?? null);
        $this->assertCount(1, $currentRows);
        $this->assertStringContainsString('रुपीनगर', (string) ($currentRows[0]['address_line'] ?? ''));

        $brothers = array_values(array_filter(
            $siblings,
            static fn (array $row): bool => ($row['relation_type'] ?? null) === 'brother'
        ));
        $this->assertCount(1, $brothers);
        $this->assertSame('शुभम तानाजी कदम', $brothers[0]['name'] ?? null);
        $this->assertSame('unmarried', $brothers[0]['marital_status'] ?? null);
        $this->assertSame('महाराष्ट्र पोलिस', $brothers[0]['occupation'] ?? null);
    }
}
