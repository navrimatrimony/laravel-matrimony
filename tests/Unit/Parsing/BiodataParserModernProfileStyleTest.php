<?php

namespace Tests\Unit\Parsing;

use App\Services\BiodataParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiodataParserModernProfileStyleTest extends TestCase
{
    use RefreshDatabase;

    private function sampleText(): string
    {
        return <<<'TXT'
Basic Details

## Sourabh Dharmadhikari

Marital Status : Divorced

Religious Background
Community : Brahmin - Deshastha
Sub-Community : Rugvedi

Location, Education & Career
Highest Qualification : B.E / B.Tech & Career
Living in : Kolhapur, Maharashtra
Native Place : Kolhapur

Father's Status : Passed Away
Mother's Status : Retired

Contact No. : +91 98765 43210
TXT;
    }

    public function test_modern_profile_style_section_parse(): void
    {
        $out = app(BiodataParserService::class)->parse($this->sampleText());
        $core = $out['core'] ?? [];

        $this->assertSame('Sourabh Dharmadhikari', (string) ($core['full_name'] ?? ''));
        $this->assertSame('divorced', (string) ($core['marital_status'] ?? ''));
        $this->assertSame('Brahmin', (string) ($core['caste'] ?? ''));
        $this->assertSame('Deshastha', (string) ($core['sub_caste'] ?? ''));
        $this->assertSame('B.E / B.Tech', (string) ($core['highest_education'] ?? ''));
        $this->assertSame('Kolhapur, Maharashtra', (string) ($core['address_line'] ?? ''));

        // No hallucinated blood group.
        $this->assertNull($core['blood_group'] ?? null);

        $contacts = $out['contacts'] ?? [];
        $this->assertNotEmpty($contacts);
        $this->assertSame('9876543210', (string) (($contacts[0] ?? [])['number'] ?? ''));
        $this->assertSame('alternate', (string) (($contacts[0] ?? [])['type'] ?? ''));
    }
}

