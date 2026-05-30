<?php

namespace Tests\Unit\Parsing;

use App\Services\BiodataParserService;
use Tests\TestCase;

class BiodataParserParentsAddressTest extends TestCase
{
    public function test_splits_father_mu_po_into_parents_addresses_not_extra_info(): void
    {
        $text = <<<'TXT'
वडिलांचे नांव :- श्री. राम पाटील
मु. पो. :- 2018 ए वॉर्ड, शिवाजी पेठ, कोल्हापूर, ता. करवीर, जि. कोल्हापूर
मोबा. :- 8180939881
आईचे नांव :- सीता पाटील
TXT;

        $out = app(BiodataParserService::class)->parse($text);
        $core = $out['core'] ?? [];

        $this->assertNull($core['father_extra_info'] ?? null);
        $this->assertSame('8180939881', $core['father_contact_1'] ?? null);

        $parents = $out['parents_addresses'] ?? [];
        $this->assertNotEmpty($parents);
        $row = $parents[0];
        $this->assertStringContainsString('2018', (string) ($row['address_line'] ?? ''));
        $this->assertStringNotContainsString('मोबाईल', (string) ($row['address_line'] ?? ''));
        $this->assertStringContainsString('ता. करवीर', (string) ($row['location_text'] ?? ''));
    }
}
