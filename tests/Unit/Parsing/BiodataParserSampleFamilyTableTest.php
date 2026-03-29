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
श्री. प्रविण काकासो जाधव (रा. मु.पो. कोळे) / श्री. सचिन दिनकर नलवडे B
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

        $this->assertNull($core['brother_count'] ?? null, 'भाऊ/बहिण : अविवाहित must not infer numeric sibling counts');
        $this->assertNull($core['sister_count'] ?? null);
        $this->assertNull($core['has_siblings'] ?? null);
        $this->assertSame([], $out['siblings'] ?? null);

        $this->assertNotNull($core['full_name'] ?? null);
        $this->assertStringContainsString('दिव्या', (string) $core['full_name']);
        $this->assertSame('A+', $core['blood_group'] ?? null);
        $this->assertNotNull($core['birth_time'] ?? null);
        $this->assertSame('9123456789', $core['father_contact_1'] ?? null);
        $this->assertNull($core['primary_contact_number'] ?? null);
        $this->assertSame([], $out['contacts'] ?? [], 'Suchak/bureau header numbers must not populate contacts[]');

        $sec = $out['relatives_sectioned'] ?? [];
        $this->assertNotEmpty($sec['paternal']['chulte'] ?? [], 'relatives_sectioned.paternal.chulte must mirror चुलते rows');
        $this->assertNotEmpty($sec['maternal']['mama'] ?? [], 'relatives_sectioned.maternal.mama must mirror मामा rows');
        $ajolSec = $sec['maternal']['ajol'][0] ?? [];
        $this->assertNotEmpty($ajolSec);
        $this->assertArrayNotHasKey('occupation', $ajolSec);
        $this->assertNull($ajolSec['name'] ?? null);
        $this->assertNotNull($ajolSec['address_line'] ?? null);
        $this->assertStringContainsString('सोलापूर', (string) $ajolSec['address_line']);

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

        $mamaWithName = array_values(array_filter(
            $out['relatives'] ?? [],
            fn ($r) => ($r['relation_type'] ?? '') === 'मामा' && ($r['name'] ?? null) !== null && trim((string) $r['name']) !== ''
        ));
        $this->assertNotEmpty($mamaWithName);
        $this->assertStringContainsString('दिनकर रामचंद्र कदम', (string) ($mamaWithName[0]['name'] ?? ''));
        $this->assertStringNotContainsString('मु. पो.', (string) ($mamaWithName[0]['name'] ?? ''), 'Name must not include address after comma');

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

        $chulteNames = array_map(
            fn ($r) => (string) ($r['name'] ?? ''),
            array_values(array_filter($out['relatives'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'चुलते'))
        );
        $this->assertNotEmpty($chulteNames);
        $this->assertStringContainsString('प्रविण काकासो जाधव', $chulteNames[0], 'Slash-separated first चुलते name must stay full (काका must not truncate काकासो)');
        $this->assertStringEndsNotWith('/', trim($chulteNames[0]));

        $mavshiKakaMale = false;
        foreach ($out['relatives'] ?? [] as $r) {
            if (($r['relation_type'] ?? '') === 'other_maternal' && str_contains((string) ($r['raw_note'] ?? ''), 'मावशी')) {
                $mavshiKakaMale = true;
            }
        }
        $this->assertTrue($mavshiKakaMale);
    }

    public function test_bhau_bahin_split_across_two_lines_still_locks_sibling_counts(): void
    {
        $raw = <<<'TXT'
भाऊ/
बहिण : अविवाहित
वडिलांचे नांव : श्री. हेमंत आनंदराव जाधव
TXT;
        $out = app(BiodataParserService::class)->parse($raw);
        $this->assertNull($out['core']['brother_count'] ?? null);
        $this->assertNull($out['core']['sister_count'] ?? null);
        $this->assertSame([], $out['siblings'] ?? null);
    }

    public function test_ajol_kai_two_lines_yields_name_and_address(): void
    {
        $raw = <<<'TXT'
भाऊ/बहिण : अविवाहित
आजोळ : कै. नानासो रामचंद्र पाटील (आजोबा)
मु. पो. महारुगडेवाडी, ता. कराड, जि. सातारा.
TXT;
        $out = app(BiodataParserService::class)->parse($raw);
        $this->assertNull($out['core']['brother_count'] ?? null);
        $this->assertNull($out['core']['sister_count'] ?? null);
        $this->assertSame([], $out['siblings'] ?? []);

        $ajol = array_values(array_filter(
            $out['relatives'] ?? [],
            fn ($r) => ($r['relation_type'] ?? '') === 'आजोळ'
        ));
        $this->assertNotEmpty($ajol);
        $this->assertStringContainsString('कै.', (string) ($ajol[0]['name'] ?? ''));
        $this->assertStringContainsString('नानासो', (string) ($ajol[0]['name'] ?? ''));
        $this->assertStringContainsString('मु. पो.', (string) ($ajol[0]['address_line'] ?? ''));
        $this->assertStringContainsString('सातारा', (string) ($ajol[0]['address_line'] ?? ''));
        $this->assertNull($ajol[0]['occupation'] ?? null, '(आजोबा) must not be stored as occupation');
        $ajolBucket = ($out['relatives_sectioned']['maternal']['ajol'] ?? [])[0] ?? [];
        $this->assertNotEmpty($ajolBucket);
        $this->assertArrayNotHasKey('occupation', $ajolBucket);
        $this->assertNull($ajolBucket['name'] ?? null);
    }

    public function test_ajol_heading_then_kai_line_on_next_row_merges_notes(): void
    {
        $raw = <<<'TXT'
आजोळ :
कै. नानासो रामचंद्र पाटील (आजोबा)
मु. पो. महारुगडेवाडी, ता. कराड, जि. सातारा.
TXT;
        $out = app(BiodataParserService::class)->parse($raw);
        $ajol = array_values(array_filter(
            $out['relatives'] ?? [],
            fn ($r) => ($r['relation_type'] ?? '') === 'आजोळ'
        ));
        $this->assertNotEmpty($ajol);
        $this->assertNotNull($ajol[0]['name'] ?? null);
        $this->assertStringContainsString('कै.', (string) $ajol[0]['name']);
        $this->assertStringContainsString('मु. पो.', (string) ($ajol[0]['address_line'] ?? ''));
    }

    public function test_suchak_header_name_lines_do_not_become_contacts_when_bureau_marker_present(): void
    {
        $raw = <<<'TXT'
वधूवर सूचक केंद्र
प्रकाश सावंत 9730440103
पूजा सावंत 968986869
मुलीचे नांव : कु. दिव्या हेमंत जाधव
वडिलांचे नांव : श्री. हेमंत आनंदराव जाधव, मो. 9123456789
TXT;
        $out = app(BiodataParserService::class)->parse($raw);
        $this->assertNull($out['core']['primary_contact_number'] ?? null);
        $nums = [];
        foreach ($out['contacts'] ?? [] as $c) {
            if (is_array($c)) {
                $nums[] = (string) ($c['number'] ?? $c['phone_number'] ?? '');
            }
        }
        $this->assertNotContains('9730440103', $nums);
        $this->assertNotContains('968986869', $nums);
        $this->assertSame('9123456789', $out['core']['father_contact_1'] ?? null);
    }
}
