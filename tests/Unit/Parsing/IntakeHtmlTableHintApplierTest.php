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
}
