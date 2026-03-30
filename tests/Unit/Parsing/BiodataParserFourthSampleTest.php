<?php

namespace Tests\Unit\Parsing;

use App\Services\BiodataParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fourth biodata: table-style OCR — DOB (Marathi month), जन्म वार व वेळ, शिक्षण vs गोत्र,
 * व्यवसाय, parents (श्रीमती.), पत्ता vs कुलस्वामी/पेठ, horoscope rows, numbered siblings, आजोळ/मावशी.
 */
class BiodataParserFourthSampleTest extends TestCase
{
    use RefreshDatabase;

    private function sampleText(): string
    {
        return <<<'TXT'
नाव :- कु. प्राजक्ता शहाजी भोसले
जन्म तारीख :- ०८ ऑगस्ट १९९७
जन्म वार व वेळ :- शुक्रवार दुपारी १:४० मि.
जन्म ठिकाण :- मुंबई.
वर्ण :- गोरा
शिक्षण :- B.A. LL.B देवक :- पंचपल्लव
व्यवसाय :- वकील गोत्र :- कश्यप पुरशी (कौशिक)
उंची :- 5 फूट 3 इंच
रक्तगट :- O+ve
रास :- कन्या वर्ण :- गोरा
नक्षत्र :- हरत
नाडी :- आद्य
गण :- देव
योनी :- महिषा
कुलस्वामी :- श्री. माणकेश्वर (पेठ)
कुलस्वामीनी :- श्री तुळजाभवानी (तुळजापूर)
पत्ता :- मु. पो. उरूण-इस्लामपूर, ता. वाळवा, जि. सांगली. मो. ९८६०४४६१०९.
वडिलांचे नांव :- कै. डॉ. शहाजी विष्णू भोसले
आईचे नांव :- श्रीमती. सुप्रिया शहाजी भोसले (गृहिणी)
भाऊ :- दोन- अविवाहीत - १) चि. संस्कार शहाजी भोसले. २) चि. सार्थक शहाजी भोसले
बहिण :- एक अविवाहीत - कु. डॉ. संचिता शहाजी भोसले (B.PTH.)
आजोळ (मामा) :- 1) श्री. अनिल सुभाष जाधव 2) श्री. विजय सुभाष जाधव मु.पो. सोनवडे, ता. शिराळा, जि. सांगली.
मावशी :- 1) सौ. सुनीता कुलकर्णी (मो. 9284040413) 2) सौ. राधा शिंदे
TXT;
    }

    public function test_fourth_sample_table_ocr_core_family_horoscope_contacts(): void
    {
        $out = app(BiodataParserService::class)->parse($this->sampleText());
        $core = $out['core'] ?? [];

        $this->assertSame('कु. प्राजक्ता शहाजी भोसले', (string) ($core['full_name'] ?? ''));
        $this->assertSame('1997-08-08', (string) ($core['date_of_birth'] ?? ''));
        $this->assertStringContainsString('मुंबई', (string) ($core['birth_place'] ?? ''));
        $this->assertSame('13:40', (string) ($core['birth_time'] ?? ''));
        $this->assertSame('B.A. LL.B', (string) ($core['highest_education'] ?? ''));
        $this->assertSame('वकील', (string) ($core['occupation_title'] ?? ''));

        $this->assertNotNull($core['father_name'] ?? null);
        $this->assertSame('श्रीमती. सुप्रिया शहाजी भोसले', (string) ($core['mother_name'] ?? ''));
        $this->assertSame('गृहिणी', (string) ($core['mother_occupation'] ?? ''));

        $addr = (string) ($core['address_line'] ?? '');
        $this->assertStringContainsString('उरूण-इस्लामपूर', $addr);
        $this->assertStringNotContainsString('माणकेश्वर', $addr);

        $this->assertNull($core['primary_contact_number'] ?? null);

        $nums = [];
        foreach ($out['contacts'] ?? [] as $c) {
            $nums[] = (string) ($c['number'] ?? $c['phone_number'] ?? '');
        }
        $this->assertContains('9860446109', $nums);

        $this->assertSame(2, $core['brother_count'] ?? null);
        $this->assertSame(1, $core['sister_count'] ?? null);
        $this->assertCount(3, $out['siblings'] ?? []);
        $brotherNames = [];
        foreach ($out['siblings'] ?? [] as $s) {
            if (($s['relation_type'] ?? '') === 'brother') {
                $brotherNames[] = (string) ($s['name'] ?? '');
            }
        }
        $this->assertCount(2, $brotherNames);
        $this->assertTrue(
            count(array_filter($brotherNames, static fn ($n) => str_contains($n, 'संस्कार') && str_contains($n, 'भोसले'))) >= 1
        );
        $this->assertTrue(
            count(array_filter($brotherNames, static fn ($n) => str_contains($n, 'सार्थक') && str_contains($n, 'भोसले'))) >= 1
        );

        $this->assertTrue(
            ($core['mama'] ?? null) === null || $core['mama'] === '',
            'Legacy core.mama must be empty when value would be आजोळ-(मामा) heading bleed; structured rows are SSOT.'
        );

        $h = $out['horoscope'][0] ?? [];
        $this->assertSame('कन्या', $h['rashi'] ?? null);
        $this->assertSame('शुक्रवार', $h['birth_weekday'] ?? null);
        $this->assertSame('हरत', $h['nakshatra'] ?? null);
        $this->assertArrayNotHasKey('blood_group', $h, 'Blood group is core-only; horoscope row must not carry blood_group.');
        $this->assertSame('O+', $core['blood_group'] ?? null);
        $this->assertSame('श्री. माणकेश्वर (पेठ)', (string) ($h['kuldaivat'] ?? ''));
        $this->assertStringNotContainsString('नक्षत्र', (string) ($h['kuldaivat'] ?? ''));
        $this->assertSame('कश्यप पुरशी (कौशिक)', (string) ($h['gotra'] ?? ''));
        $this->assertSame('कश्यप पुरशी (कौशिक)', (string) ($core['gotra'] ?? ''));

        $edu = $out['education_history'][0] ?? [];
        $this->assertSame('B.A. LL.B', $edu['degree'] ?? null);

        $ch = $out['career_history'][0] ?? [];
        $this->assertSame('वकील', (string) ($ch['occupation_title'] ?? $ch['role'] ?? ''));

        $sec = $out['relatives_sectioned'] ?? [];
        $mama = $sec['maternal']['mama'] ?? [];
        $mamaNames = array_filter(array_map(static fn ($row) => trim((string) ($row['name'] ?? '')), $mama));
        $this->assertGreaterThanOrEqual(2, count($mamaNames), 'Expected both मामा names from आजोळ (मामा) line');
        $joinedMama = implode(' ', $mamaNames);
        $this->assertStringContainsString('अनिल', $joinedMama);
        $this->assertStringContainsString('विजय', $joinedMama);
        foreach ($mama as $row) {
            $n = (string) ($row['name'] ?? $row['notes'] ?? '');
            $this->assertDoesNotMatchRegularExpression('/^\s*\d+\s*[\).]\s*$/u', $n);
            $rn = trim((string) ($row['raw_note'] ?? ''));
            $nn = trim((string) ($row['name'] ?? ''));
            $this->assertFalse($nn === '' && $rn === '', 'No empty maternal.mama rows');
        }

        $mavshi = $sec['maternal']['mavshi'] ?? [];
        $sunita = null;
        foreach ($mavshi as $row) {
            if (str_contains((string) ($row['name'] ?? ''), 'सुनीता')) {
                $sunita = $row;
                break;
            }
        }
        $this->assertNotNull($sunita);
        $this->assertSame('9284040413', (string) ($sunita['contact_number'] ?? ''));
        foreach ($mavshi as $row) {
            $raw = (string) ($row['raw_note'] ?? '');
            if ($raw !== '') {
                $this->assertDoesNotMatchRegularExpression('/^मावशी\s*[:\-–—]?\s*$/u', $raw);
                $this->assertNotSame('1', trim($raw));
            }
        }

        foreach ($mama as $row) {
            $nm = (string) ($row['name'] ?? '');
            $addr = (string) ($row['address_line'] ?? '');
            if ($nm !== '' && $addr !== '' && str_contains($addr, 'सोनवडे')) {
                $this->assertStringNotContainsString('मु.पो.', $nm);
                $this->assertStringNotContainsString('मु. पो.', $nm);
            }
        }

        foreach ($out['siblings'] ?? [] as $sib) {
            $occ = (string) ($sib['occupation'] ?? '');
            if ($occ !== '') {
                $this->assertStringNotContainsString('अपेक्षा', $occ);
            }
        }
    }

    public function test_fourth_sample_sibling_occupation_rejects_appeksha_bleed(): void
    {
        $base = $this->sampleText();
        $text = str_replace(
            'भाऊ :- दोन- अविवाहीत - १) चि. संस्कार शहाजी भोसले. २) चि. सार्थक शहाजी भोसले',
            "भाऊ :- दोन- अविवाहीत - १) चि. संस्कार शहाजी भोसले (अपेक्षा :- खानदानी, नोकरी, उच्चशिक्षीत.)\n२) चि. सार्थक शहाजी भोसले",
            $base
        );
        $out = app(BiodataParserService::class)->parse($text);
        foreach ($out['siblings'] ?? [] as $sib) {
            if (($sib['relation_type'] ?? '') === 'brother' && str_contains((string) ($sib['name'] ?? ''), 'संस्कार')) {
                $this->assertNull($sib['occupation'] ?? null);
            }
        }
    }

    public function test_fourth_sample_blood_group_when_label_is_rakt_gat_with_space(): void
    {
        $text = str_replace('रक्तगट :-', 'रक्त गट :-', $this->sampleText());
        $out = app(BiodataParserService::class)->parse($text);
        $this->assertSame('O+', (string) (($out['core'] ?? [])['blood_group'] ?? ''));
        $this->assertArrayNotHasKey('blood_group', $out['horoscope'][0] ?? []);
    }

    public function test_fourth_sample_dob_when_ocr_reads_august_as_ojast(): void
    {
        $base = $this->sampleText();
        $text = str_replace('०८ ऑगस्ट १९९७', '०८ ऑजस्ट १९९७', $base);
        $out = app(BiodataParserService::class)->parse($text);
        $this->assertSame('1997-08-08', (string) (($out['core'] ?? [])['date_of_birth'] ?? ''));
    }

    public function test_fourth_sample_mavshi_splits_inline_mo_and_ra_from_name(): void
    {
        $text = <<<'TXT'
नाव :- कु. टेस्ट
मावशी :- सौ. सुनिल रामचंद्र पाटील मो. 9284040413. रा. सागांव, ता. शिराळा.
TXT;
        $out = app(BiodataParserService::class)->parse($text);
        $sec = $out['relatives_sectioned'] ?? [];
        $mavshi = $sec['maternal']['mavshi'] ?? [];
        $this->assertNotEmpty($mavshi);
        $row = $mavshi[0];
        $this->assertSame('सौ. सुनिल रामचंद्र पाटील', (string) ($row['name'] ?? ''));
        $this->assertSame('9284040413', (string) ($row['contact_number'] ?? ''));
        $this->assertStringContainsString('रा. सागांव', (string) ($row['address_line'] ?? ''));
        $this->assertStringContainsString('शिराळा', (string) ($row['address_line'] ?? ''));
    }

    /** Real intake HTML table: 4th column bleeds into रक्तगट line; भाऊ cell uses br; अपेक्षा row lists "नोकरी," inline. */
    public function test_fourth_sample_html_table_ocr_pipeline_regression(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>रक्तगट</td><td>:-</td><td>O+ve</td><td>नाडी :- आद्य</td></tr>
<tr><td>व्यवसाय</td><td>:-</td><td>वकील</td><td>गोत्र :- कश्यप पुरशी (कौशिक)</td></tr>
<tr><td>भाऊ</td><td>:-</td><td colspan="2">दोन- अविवाहीत - १) चि. संस्कार शहाजी भोसले.<br/>२) चि. सार्थक शहाजी भोसले</td></tr>
<tr><td>अपेक्षा</td><td>:-</td><td colspan="2">खानदानी, नोकरी, उच्चशिक्षीत.</td></tr>
</table>
HTML;
        $out = app(BiodataParserService::class)->parse($html);
        $core = $out['core'] ?? [];
        $this->assertSame('O+', (string) ($core['blood_group'] ?? ''));
        $this->assertSame('वकील', (string) ($core['occupation_title'] ?? ''));
        $this->assertSame(2, (int) ($core['brother_count'] ?? 0));
        $brothers = array_values(array_filter($out['siblings'] ?? [], static fn ($s) => ($s['relation_type'] ?? '') === 'brother'));
        $this->assertCount(2, $brothers);
        $this->assertStringContainsString('संस्कार', (string) ($brothers[0]['name'] ?? ''));
        $this->assertStringContainsString('सार्थक', (string) ($brothers[1]['name'] ?? ''));
        $this->assertNull($brothers[0]['occupation'] ?? null);
        $this->assertNull($brothers[1]['occupation'] ?? null);
    }
}
