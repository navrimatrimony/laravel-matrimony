<?php

namespace Tests\Unit\Parsing;

use App\Services\BiodataParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deterministic parser regressions: combined horoscope lines, education boundaries,
 * residence vs दाजी, siblings, दाजी name/address split.
 */
class BiodataParserSecondSampleTest extends TestCase
{
    use RefreshDatabase;

    private function sampleText(): string
    {
        return <<<'TXT'
मुलीचे नांव :- कु. प्रीती पाटील
शिक्षण :- BE – Computer Engineering. -
उंची :- 5 फूट 4 इंच
वर्ण :- गोरा
देवक :- वासनिचा वेल रक्त गट :- B+ve
रास :- वृश्चिक नक्षत्र :- मृग
नाड :- आध्य गण :- राक्षस. चरण :- ४
जन्म ठिकाण :- माळीनगर. ता.- माळशिरस, जि.सोलापूर.
नोकरी :- Amdocs Company Magarpatta,Pune.
वडिलांचे नाव :- श्री. राजेंद्र पाटील
आईचे नाव :- सौ. सुनीता पाटील
पत्ता :- माळीनगर (गणेश कॉलनी)
ता. माळशिरस जि. सोलापूर
भाऊ :- श्री. समर्थ राजेंद्र पाटील (9145206745)
पत्ता :- फ्लॅट नं सी-510 वाघोली, पुणे.
नोकरी :- Bharat Forge Mundhawa,Pune.
बहीण :- सौ. पुजा नवनाथ कन्हेरे.
दाजी :- श्री.नवनाथ रामचंद्र कन्हेरे पत्ता. देहू रोड, पुणे.
चुलते
मामा
TXT;
    }

    public function test_second_sample_composite_horoscope_education_address_siblings(): void
    {
        $out = app(BiodataParserService::class)->parse($this->sampleText());
        $core = $out['core'] ?? [];

        $this->assertNull($core['primary_contact_number'] ?? null);
        $this->assertNotNull($core['blood_group'] ?? null);
        $this->assertSame('B+', $core['blood_group'] ?? null);

        $this->assertSame('BE – Computer Engineering', $core['highest_education'] ?? null);
        $this->assertStringNotContainsString(' -', (string) ($core['highest_education'] ?? ''));
        $this->assertStringContainsString('माळीनगर', (string) ($core['birth_place'] ?? ''));
        $this->assertStringContainsString('माळीनगर', (string) ($core['birth_place_text'] ?? ''));
        $this->assertStringContainsString('माळीनगर', (string) ($core['address_line'] ?? ''));
        $this->assertStringContainsString('सोलापूर', (string) ($core['address_line'] ?? ''));
        $this->assertStringNotContainsString('देहू', (string) ($core['address_line'] ?? ''));
        $this->assertStringNotContainsString('आईचे नाव', (string) ($core['address_line'] ?? ''));
        $this->assertStringNotContainsString('वडिलांचे', (string) ($core['address_line'] ?? ''));
        $this->assertStringNotContainsString('फ्लॅट', (string) ($core['address_line'] ?? ''));
        $this->assertStringNotContainsString('वाघोली', (string) ($core['address_line'] ?? ''));
        $this->assertStringNotContainsString('समर्थ', (string) ($core['address_line'] ?? ''));

        $this->assertSame('Amdocs Company', $core['company_name'] ?? null);
        $this->assertSame(1, $core['brother_count'] ?? null);
        $this->assertSame(1, $core['sister_count'] ?? null);
        $this->assertTrue((bool) ($core['has_siblings'] ?? false));

        $edu = $out['education_history'][0] ?? [];
        $this->assertSame('BE – Computer Engineering', $edu['degree'] ?? null);

        $h = $out['horoscope'][0] ?? [];
        $this->assertStringContainsString('वासनिचा वेल', (string) ($h['devak'] ?? ''));
        $this->assertArrayNotHasKey('blood_group', $h, 'Blood group is core-only (core.blood_group).');
        $this->assertSame('वृश्चिक', $h['rashi'] ?? null);
        $this->assertSame('मृग', $h['nakshatra'] ?? null);
        $this->assertSame('आध्य', $h['nadi'] ?? null);
        $this->assertSame('राक्षस', $h['gan'] ?? null);
        $this->assertTrue(in_array($h['charan'] ?? null, ['४', '4'], true), 'charan digit');

        $daji = array_values(array_filter(
            $out['relatives'] ?? [],
            fn ($r) => ($r['relation_type'] ?? '') === 'दाजी'
        ));
        $this->assertNotEmpty($daji);
        $this->assertStringContainsString('नवनाथ रामचंद्र कन्हेरे', (string) ($daji[0]['name'] ?? ''));
        $this->assertStringContainsString('देहू रोड', (string) ($daji[0]['address_line'] ?? ''));

        $relTypes = array_map(fn ($r) => (string) ($r['relation_type'] ?? ''), $out['relatives'] ?? []);
        $this->assertNotContains('चुलते', $relTypes);
        $this->assertNotContains('मामा', $relTypes);

        $sibs = $out['siblings'] ?? [];
        $this->assertCount(2, $sibs);
        $bro = array_values(array_filter($sibs, fn ($r) => ($r['relation_type'] ?? '') === 'brother'));
        $sis = array_values(array_filter($sibs, fn ($r) => ($r['relation_type'] ?? '') === 'sister'));
        $this->assertNotEmpty($bro);
        $this->assertNotEmpty($sis);
        $this->assertStringContainsString('समर्थ राजेंद्र पाटील', (string) ($bro[0]['name'] ?? ''));
        $this->assertSame('9145206745', (string) ($bro[0]['contact_number'] ?? ''));
        $this->assertStringContainsString('पुजा नवनाथ कन्हेरे', (string) ($sis[0]['name'] ?? ''));
        foreach ($sibs as $r) {
            $this->assertStringNotContainsString('बहीण :-', (string) ($r['name'] ?? ''));
        }

        $addr0 = (string) (($out['addresses'][0] ?? [])['address_line'] ?? '');
        $this->assertStringContainsString('माळीनगर', $addr0);
        $this->assertStringNotContainsString('आईचे नाव', $addr0);
    }

    /** Minimal fixture: composite देवक + रक्त गट must populate core.blood_group (regression guard). */
    public function test_devak_and_rakt_gat_on_one_line_populates_blood_group(): void
    {
        $out = app(BiodataParserService::class)->parse("देवक :- वासनिचा वेल रक्त गट :- B+ve\n");
        $core = $out['core'] ?? [];
        $this->assertNull($core['primary_contact_number'] ?? null);
        $this->assertNotNull($core['blood_group'] ?? null);
        $this->assertSame('B+', $core['blood_group'] ?? null);
        $h = $out['horoscope'][0] ?? [];
        $this->assertArrayNotHasKey('blood_group', $h);
        $this->assertStringContainsString('वासनिचा वेल', (string) ($h['devak'] ?? ''));
    }
}
