<?php

namespace Tests\Unit\Parsing;

use App\Services\BiodataParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: Sarvam-style biodata with bureau noise, मुलीचे नांव, जन्मवेळ, family table quirks.
 */
class BiodataParserSampleFamilyTableTest extends TestCase
{
    use RefreshDatabase;

    private function sampleText(): string
    {
        return <<<'TXT'
वधूवर सूचक केंद्र संपर्क 9876543210
मुलीचे नांव : कु. दिव्या हेमंत जाधव
जन्मवेळ : सोमवार पहाटे 02 वा. 01 मि.
रक्तगट : A+
गण : राक्षस
नाडी : आध्य
वडिलांचे नांव : श्री. हेमंत आनंदराव जाधव, मो. 9123456789
भाऊ/बहिण : अविवाहित
मुलीचे चुलते
श्री. प्रविण राम पाटील (रा. मु.पो. कोळे) / श्री. सचिन दिनकर नलवडे B
मुलीची मावशी-काका श्री. अजित दिनकर नलवडे B
आजोळ : रा. मु.पो. सांगोला, ता. माळशिरस, जि. सोलापूर
मामा : श्री. दिनकर रामचंद्र कदम, मु
TXT;
    }

    public function test_rules_parser_extracts_critical_fields_and_family_structure(): void
    {
        $svc = app(BiodataParserService::class);
        $out = $svc->parse($this->sampleText());
        $core = $out['core'] ?? [];

        $this->assertNotNull($core['full_name'] ?? null);
        $this->assertStringContainsString('दिव्या', (string) $core['full_name']);
        $this->assertSame('A+', $core['blood_group'] ?? null);
        $this->assertNotNull($core['birth_time'] ?? null);
        $this->assertSame('9123456789', $core['father_contact_1'] ?? null);
        $this->assertNull($core['primary_contact_number'] ?? null);

        $horoscope = $out['horoscope'][0] ?? [];
        $this->assertSame('राक्षस', $horoscope['gan'] ?? null);

        $siblings = $out['siblings'] ?? [];
        foreach ($siblings as $s) {
            $n = (string) ($s['name'] ?? '');
            $this->assertStringNotContainsString('भाऊ/बहिण', $n);
            $this->assertStringNotContainsString('अविवाहित', $n);
        }

        $relTypes = array_map(fn ($r) => (string) ($r['relation_type'] ?? ''), $out['relatives'] ?? []);
        $this->assertContains('आजोळ', $relTypes);

        $hasChulte = false;
        foreach ($out['relatives'] ?? [] as $r) {
            if (($r['relation_type'] ?? '') === 'चुलते') {
                $hasChulte = true;
                if (($r['name'] ?? '') === null || $r['name'] === '') {
                    $this->fail('Heading-only चुलते row should not appear');
                }
            }
        }
        $this->assertTrue($hasChulte);

        $mavshiKakaMale = false;
        foreach ($out['relatives'] ?? [] as $r) {
            if (($r['relation_type'] ?? '') === 'other_maternal' && str_contains((string) ($r['raw_note'] ?? ''), 'मावशी')) {
                $mavshiKakaMale = true;
            }
        }
        $this->assertTrue($mavshiKakaMale);
    }
}
