<?php

namespace Tests\Unit\Parsing;

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
}
