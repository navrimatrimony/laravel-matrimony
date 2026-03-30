<?php

namespace Tests\Unit;

use App\Services\BiodataParserService;
use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiodataParserMarathiIntakeFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sibling_brother_line_with_avivahit_chi_preserves_name_and_degree(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
भाऊ : १) अविवाहित- चि. रविकिरण शंकर पाटील (BE. Mech)
TXT;
        $parsed = $service->parse($raw);
        $brothers = array_values(array_filter(
            $parsed['siblings'] ?? [],
            fn ($r) => ($r['relation_type'] ?? '') === 'brother'
        ));
        $this->assertNotEmpty($brothers);
        $bn = (string) ($brothers[0]['name'] ?? '');
        $this->assertStringStartsWith('चि.', $bn);
        $this->assertStringContainsString('रविकिरण शंकर पाटील', $bn);
        $this->assertStringContainsString('BE. Mech', (string) ($brothers[0]['occupation'] ?? ''));
    }

    public function test_marathi_honorific_prefixes_preserved_on_structured_names(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्रीती पाटील
मामा : श्री. रविकिरण पाटील
आत्या : कै. वसंत विठोबा पाटील, मु. पो. येळावी
TXT;
        $parsed = $service->parse($raw);
        $this->assertSame('कु. प्रीती पाटील', (string) ($parsed['core']['full_name'] ?? ''));
        $mama = array_values(array_filter($parsed['relatives'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'मामा'));
        $this->assertNotEmpty($mama);
        $this->assertStringStartsWith('श्री.', (string) ($mama[0]['name'] ?? ''));
        $atya = array_values(array_filter($parsed['relatives'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'आत्या'));
        $this->assertNotEmpty($atya);
        $this->assertStringStartsWith('कै.', (string) ($atya[0]['name'] ?? ''));
    }

    public function test_no_strip_invariant_for_all_marathi_honorifics_across_output_fields(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. रविना शंकर पाटील
वडिलांचे नाव :- श्री. शंकर रामचंद्र पाटील
आईचे नाव :- श्रीमती. सुप्रिया शहाजी भोसले (गृहिणी)
भाऊ : १) अविवाहित- चि. रविकिरण शंकर पाटील (BE. Mech)
बहिण :- सौ. सुनीता कुलकर्णी
मामा : कै. वसंत विठोबा पाटील
TXT;
        $parsed = $service->parse($raw);
        $this->assertSame('कु. रविना शंकर पाटील', (string) ($parsed['core']['full_name'] ?? ''));
        $this->assertSame('श्री. शंकर रामचंद्र पाटील', (string) ($parsed['core']['father_name'] ?? ''));
        $this->assertStringStartsWith('श्रीमती.', (string) ($parsed['core']['mother_name'] ?? ''));

        $bro = array_values(array_filter($parsed['siblings'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'brother'));
        $this->assertNotEmpty($bro);
        $this->assertStringStartsWith('चि.', (string) ($bro[0]['name'] ?? ''));

        $sis = array_values(array_filter($parsed['siblings'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'sister'));
        $this->assertNotEmpty($sis);
        $this->assertStringStartsWith('सौ.', (string) ($sis[0]['name'] ?? ''));

        $mama = array_values(array_filter($parsed['relatives'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'मामा'));
        $this->assertNotEmpty($mama);
        $this->assertStringStartsWith('कै.', (string) ($mama[0]['name'] ?? ''));
    }

    public function test_compound_bullet_kulswami_and_nakshatra_on_one_row(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
कुलस्वामी : श्री सिद्धनाथ प्रसन्न, खरसुंडी   • नक्षत्र : आश्लेषा
TXT;
        $parsed = $service->parse($raw);
        $kul = (string) ($parsed['core']['kuldaivat'] ?? '');
        $ho = $parsed['horoscope'][0] ?? [];
        $nak = (string) ($ho['nakshatra'] ?? '');
        $this->assertStringContainsString('सिद्धनाथ', $kul);
        $this->assertStringContainsString('खरसुंडी', $kul);
        $this->assertStringContainsString('आश्लेषा', $nak);
    }

    public function test_compound_bullet_marathi_blood_group_and_nadi(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
रक्तगट : 'बी' पॉझिटिव्ह   • नाडी : अंत्य
TXT;
        $parsed = $service->parse($raw);
        $this->assertSame('B+', $parsed['core']['blood_group'] ?? null);
        $ho = $parsed['horoscope'][0] ?? [];
        $this->assertArrayNotHasKey('blood_group', $ho);
        $this->assertStringContainsString('अंत्य', (string) ($ho['nadi'] ?? $parsed['core']['nadi'] ?? ''));
    }

    public function test_father_occupation_from_retired_employer_continuation_line(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
वडिलांचे नाव : श्री. शंकर रामचंद्र पाटील
रिटायर्ड बजाज अॅटो लिमिटेड, (औरंगाबाद)
TXT;
        $parsed = $service->parse($raw);
        $fn = (string) ($parsed['core']['father_name'] ?? '');
        $fo = (string) ($parsed['core']['father_occupation'] ?? '');
        $this->assertStringContainsString('शंकर रामचंद्र पाटील', $fn);
        $this->assertStringContainsString('बजाज', $fo);
        $this->assertStringContainsString('रिटायर्ड', $fo);
    }

    public function test_ajol_mama_header_is_not_relative_person_name(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
आजोळ - (मामा) :
श्री. सुनील रामचंद्र पाटील, श्री. अनिल रामचंद्र पाटील
TXT;
        $parsed = $service->parse($raw);
        $rels = $parsed['relatives'] ?? [];
        foreach ($rels as $r) {
            $name = (string) ($r['name'] ?? '');
            $this->assertDoesNotMatchRegularExpression('/आजोळ\s*[\-–—]?\s*\(\s*मामा\s*\)/u', $name);
        }
        $mamas = array_values(array_filter($rels, fn ($r) => str_contains((string) ($r['relation_type'] ?? ''), 'मामा')));
        $this->assertNotEmpty($mamas);
        $this->assertStringContainsString('सुनील', (string) ($mamas[0]['name'] ?? ''));
        $sec = $parsed['relatives_sectioned']['maternal']['mama'] ?? [];
        foreach ($sec as $row) {
            $this->assertDoesNotMatchRegularExpression('/आजोळ\s*[\-–—]?\s*\(\s*मामा\s*\)/u', (string) ($row['name'] ?? ''));
        }
        foreach ($mamas as $row) {
            $rn = (string) ($row['raw_note'] ?? '');
            if ($rn === '') {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/[,\\-–—]\\s*$/u', $rn);
        }
    }

    public function test_atyaa_multi_line_block_captures_shri_and_kai_rows(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
आत्या :
श्री. भीमराव दत्तात्रय पाटील, मु. पो. शिरोली
कै. वसंत विठोबा पाटील, मु. पो. येळावी
श्री. कुमार भीमराव पाटील. मु. पो. बोरगाव
TXT;
        $parsed = $service->parse($raw);
        $atya = array_values(array_filter($parsed['relatives'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'आत्या'));
        $this->assertCount(3, $atya, 'Expected each आत्या continuation line as its own structured row');
        $secAtya = $parsed['relatives_sectioned']['paternal']['atya'] ?? [];
        $this->assertCount(3, $secAtya);
        $joined = implode(' ', array_map(fn ($r) => (string) ($r['name'] ?? ''), $atya));
        $this->assertStringContainsString('भीमराव', $joined);
        $this->assertStringContainsString('वसंत', $joined);
        $this->assertStringContainsString('कुमार', $joined);
        foreach (array_merge($atya, $secAtya) as $row) {
            $rn = (string) ($row['raw_note'] ?? '');
            if ($rn === '') {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/[,\\-–—]\\s*$/u', $rn, 'raw_note should not end with stray comma or dash');
            $this->assertStringNotContainsString('- आत्या :', $rn);
        }
    }

    public function test_marathi_blood_group_normalization_in_ocr_normalize(): void
    {
        $this->assertSame('B+', OcrNormalize::normalizeBloodGroup("'बी' पॉझिटिव्ह"));
        $this->assertSame('B+', OcrNormalize::normalizeBloodGroup('बी पॉझिटिव्ह'));
        $this->assertSame('B+', OcrNormalize::normalizeBloodGroup('B Positive'));
    }

    public function test_contact_address_strips_comma_hyphen_merge_artifact(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        // Single-line OCR join artifact ", - " between locality segments (extractor may not span newline after संपर्क पत्ता).
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
संपर्क पत्ता :- न्यायनगर, दुर्गामाता कॉलनी, प्लॉट नं. ७०, गल्ली नं. ८, - गारखेडा परिसर, औरंगाबाद ४३१ ००१
TXT;
        $parsed = $service->parse($raw);
        $addr = (string) (($parsed['addresses'][0] ?? [])['address_line'] ?? '');
        $this->assertStringNotContainsString(', -', $addr);
        $this->assertStringContainsString('गारखेडा', $addr);
        $this->assertStringContainsString('औरंगाबाद', $addr);
    }

    public function test_atyaa_pahune_line_is_hard_boundary_no_guest_text_in_rows(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
आत्या : श्री. भीमराव दत्तात्रय पाटील, मु. पो. शिरोली
कै. वसंत विठोबा पाटील, मु. पो. येळावी
श्री. कुमार भीमराव पाटील. मु. पो. बोरगाव
पाहुणे - शिरोली-पुलाची, कामेरी, कापूसखेड, येडेनिपाणी, जुनेखेड
TXT;
        $parsed = $service->parse($raw);
        $atya = array_values(array_filter($parsed['relatives'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'आत्या'));
        $this->assertCount(3, $atya);
        $third = $atya[2];
        $this->assertStringContainsString('कुमार', (string) ($third['name'] ?? ''));
        $this->assertSame('मु. पो. बोरगाव', (string) ($third['address_line'] ?? ''));
        $this->assertSame('मु. पो. बोरगाव', (string) ($third['location'] ?? ''));
        $this->assertSame('श्री. कुमार भीमराव पाटील. मु. पो. बोरगाव', (string) ($third['raw_note'] ?? ''));
        foreach ($atya as $row) {
            foreach (['address_line', 'location'] as $k) {
                $v = (string) ($row[$k] ?? '');
                $this->assertStringNotContainsString('पाहुणे', $v, $k);
            }
        }
        $ort = (string) (($parsed['core'] ?? [])['other_relatives_text'] ?? '');
        $this->assertSame(
            'पाहुणे - शिरोली-पुलाची, कामेरी, कापूसखेड, येडेनिपाणी, जुनेखेड',
            $ort
        );
    }

    public function test_father_mupo_address_maps_to_father_extra_info_not_birth_place(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
वडिलांचे नाव : श्री. शंकर रामचंद्र पाटील
रिटायर्ड बजाज अॅटो लिमिटेड, (औरंगाबाद)
मु. पो. : उरुण इस्लामपूर, ता. वाळवा, जि. सांगली
TXT;
        $parsed = $service->parse($raw);
        $core = $parsed['core'] ?? [];
        $this->assertSame(
            'उरुण इस्लामपूर, ता. वाळवा, जि. सांगली',
            (string) ($core['father_extra_info'] ?? '')
        );
        $bp = (string) ($core['birth_place'] ?? '');
        $this->assertStringNotContainsString('उरुण', $bp);
        $this->assertStringNotContainsString('वाळवा', $bp);
    }

    public function test_father_moba_line_populates_father_contact_slots(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
वडिलांचे नाव : श्री. शंकर रामचंद्र पाटील
मोबा. : ९४२३८३१३४६, ९७६४८९४००६
TXT;
        $parsed = $service->parse($raw);
        $core = $parsed['core'] ?? [];
        $this->assertSame('9423831346', (string) ($core['father_contact_1'] ?? ''));
        $this->assertSame('9764894006', (string) ($core['father_contact_2'] ?? ''));
    }

    public function test_parse_then_skeleton_ensure_preserves_core_fields_like_parse_intake_job(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
वडिलांचे नाव : श्री. शंकर रामचंद्र पाटील
रिटायर्ड बजाज अॅटो लिमिटेड, (औरंगाबाद)
मु. पो. : उरुण इस्लामपूर, ता. वाळवा, जि. सांगली
मोबा. : ९४२३८३१३४६, ९७६४८९४००६
आत्या : श्री. भीमराव दत्तात्रय पाटील, मु. पो. शिरोली
कै. वसंत विठोबा पाटील, मु. पो. येळावी
श्री. कुमार भीमराव पाटील. मु. पो. बोरगाव
पाहुणे - शिरोली-पुलाची, कामेरी, कापूसखेड, येडेनिपाणी, जुनेखेड
TXT;
        $parsed = $service->parse($raw);
        $ssot = $this->app->make(IntakeParsedSnapshotSkeleton::class)->ensure($parsed);
        $c = $ssot['core'] ?? [];
        $this->assertSame(
            'पाहुणे - शिरोली-पुलाची, कामेरी, कापूसखेड, येडेनिपाणी, जुनेखेड',
            (string) ($c['other_relatives_text'] ?? '')
        );
        $this->assertSame('उरुण इस्लामपूर, ता. वाळवा, जि. सांगली', (string) ($c['father_extra_info'] ?? ''));
        $this->assertSame('9423831346', (string) ($c['father_contact_1'] ?? ''));
        $this->assertSame('9764894006', (string) ($c['father_contact_2'] ?? ''));
        $this->assertArrayHasKey('father_extra_info', $c);
    }

    public function test_multiple_standalone_pahune_lines_preserved_in_other_relatives_text(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
आत्या : श्री. भीमराव दत्तात्रय पाटील, मु. पो. शिरोली
पाहुणे - शिरोली-पुलाची, कामेरी
पाहुणे - कापूसखेड, येडेनिपाणी, जुनेखेड
TXT;
        $parsed = $service->parse($raw);
        $ort = (string) (($parsed['core'] ?? [])['other_relatives_text'] ?? '');
        $this->assertStringContainsString('शिरोली-पुलाची', $ort);
        $this->assertStringContainsString('कामेरी', $ort);
        $this->assertStringContainsString('कापूसखेड', $ort);
        $this->assertStringContainsString('जुनेखेड', $ort);
        $this->assertSame(2, substr_count($ort, 'पाहुणे'));
    }

    public function test_relative_address_trims_trailing_dash_only(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
आत्या :
श्री. भीमराव दत्तात्रय पाटील, मु. पो. शिरोली -
कै. वसंत विठोबा पाटील, मु. पो. येळावी -
TXT;
        $parsed = $service->parse($raw);
        $atya = array_values(array_filter($parsed['relatives'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'आत्या'));
        $this->assertCount(2, $atya);
        $this->assertSame('मु. पो. शिरोली', (string) ($atya[0]['address_line'] ?? $atya[0]['location'] ?? ''));
        $this->assertSame('मु. पो. येळावी', (string) ($atya[1]['address_line'] ?? $atya[1]['location'] ?? ''));
    }

    public function test_relative_raw_note_normalizes_name_dash_before_muppo(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
मामा : श्री. अनिल रामचंद्र पाटील - मु. पो. बावची
TXT;
        $parsed = $service->parse($raw);
        $mama = array_values(array_filter($parsed['relatives'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'मामा'));
        $this->assertNotEmpty($mama);
        $rn = (string) ($mama[0]['raw_note'] ?? '');
        $this->assertStringNotContainsString(' - मु. पो.', $rn);
        $this->assertStringContainsString('श्री. अनिल रामचंद्र पाटील', $rn);
        $this->assertStringContainsString('मु. पो. बावची', $rn);
        $this->assertStringContainsString(', मु. पो.', $rn);
    }
}
