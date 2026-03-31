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

    public function test_marathi_separated_label_value_format(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलाचे नाव
जन्म तारीख
उंची
रक्त गट
शिक्षण
नोकरी
मुलाचे वडील
मुलाची आई
मुलाचे भाऊ
मुलाचे मामा
मुलाची आत्या
इतर पाहुणे
संपर्क नंबर

चि. विवेक वसंत पवार
06/03/1996
5.7 इंच
B+
B.Com
software engineer
श्री वसंत केशव पवार
श्रीमती सुनीता वसंत पवार
एक भाऊ — अविवाहित, चि. राहुल वसंत पवार
श्री. अनिल रामचंद्र कुलकर्णी — मामा
कै. वर्षा विठोबा पाटील — आत्या, मु. पो. पुणे
कै. सुधीर पाटील — पाहुणे
9876543210
TXT;
        $parsed = $service->parse($raw);
        $core = $parsed['core'] ?? [];

        $this->assertStringContainsString('विवेक', (string) ($core['full_name'] ?? ''));
        $this->assertStringContainsString('वसंत पवार', (string) ($core['full_name'] ?? ''));

        $this->assertSame('1996-03-06', (string) ($core['date_of_birth'] ?? ''));

        $this->assertNotNull($core['height_cm'] ?? null);
        $this->assertGreaterThan(160.0, (float) ($core['height_cm'] ?? 0));

        $this->assertSame('B+', $core['blood_group'] ?? null);

        $this->assertStringContainsString('B.Com', (string) ($core['highest_education'] ?? ''));

        $this->assertStringContainsString('वसंत', (string) ($core['father_name'] ?? ''));
        $this->assertStringContainsString('केशव पवार', (string) ($core['father_name'] ?? ''));
        $this->assertStringNotContainsString('मुलाची आई', (string) ($core['father_name'] ?? ''));

        $this->assertStringContainsString('सुनीता', (string) ($core['mother_name'] ?? ''));
        $this->assertStringNotContainsString('मुलाचे वडील', (string) ($core['mother_name'] ?? ''));

        $ort = (string) ($core['other_relatives_text'] ?? '');
        $this->assertNotSame('', $ort);
        $this->assertStringNotContainsString('मुलाचे नाव', $ort);
        $this->assertStringNotContainsString('जन्म तारीख', $ort);
        $this->assertStringContainsString('सुधीर', $ort);

        $nums = [];
        foreach ($parsed['contacts'] ?? [] as $c) {
            $nums[] = (string) ($c['number'] ?? $c['phone_number'] ?? '');
        }
        $this->assertContains('9876543210', $nums);
    }

    public function test_html_table_marathi_biodata_maps_to_core_without_other_relatives_dump(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<HTML
<table>
<tr><td>मुलीचे नांव</td><td>:-</td><td>कु. अंजली रामचंद्र कदम</td></tr>
<tr><td>जन्म तारीख</td><td>:-</td><td>१५/०५/१९९५</td></tr>
<tr><td>जन्म स्थळ</td><td>:-</td><td>पुणे</td></tr>
<tr><td>जन्मवेळ</td><td>:-</td><td>दुपारी १:२० मि.</td></tr>
<tr><td>उंची</td><td>:-</td><td>5.4 इंच</td></tr>
<tr><td>वर्ण</td><td>:-</td><td>गोरा</td></tr>
<tr><td>रक्तगट</td><td>:-</td><td>A+ve</td></tr>
<tr><td>शिक्षण</td><td>:-</td><td>M.Sc Computer Science</td></tr>
<tr><td>जात</td><td>:-</td><td>मराठा</td></tr>
<tr><td>कुलस्वामी</td><td>:-</td><td>श्री. महादेव मंदिर</td></tr>
<tr><td>देवक</td><td>:-</td><td>पंचपल्लव</td></tr>
<tr><td>गोत्र</td><td>:-</td><td>कश्यप</td></tr>
<tr><td>वडिलांचे नांव</td><td>:-</td><td>श्री. रामचंद्र बाळकृष्ण कदम</td></tr>
<tr><td>आईचे नांव</td><td>:-</td><td>सौ. सुनिता रामचंद्र कदम (गृहिणी)</td></tr>
<tr><td>मुळ पत्ता</td><td>:-</td><td>मु. पो. वाळवा, ता. शिरूर, जि. पुणे</td></tr>
<tr><td>सध्याचा पत्ता</td><td>:-</td><td>फ्लॅट ३, बानेर, पुणे</td></tr>
<tr><td>मोबाईल नंबर</td><td>:-</td><td>9820012345</td></tr>
<tr><td>इतर पाहुणे</td><td>:-</td><td>कै. सुनीताबाई पाटील — वेळच्या गावी</td></tr>
<tr><td>इतर प्रॉपर्टी</td><td>:-</td><td>बागायत दोन एकर शेती</td></tr>
</table>
HTML;
        $parsed = $service->parse($raw);
        $core = $parsed['core'] ?? [];

        $this->assertStringContainsString('कु. अंजली', (string) ($core['full_name'] ?? ''));
        $this->assertSame('female', (string) ($core['gender'] ?? ''));

        $this->assertSame('1995-05-15', (string) ($core['date_of_birth'] ?? ''));
        $this->assertStringContainsString('पुणे', (string) ($core['birth_place'] ?? ''));
        $this->assertSame('13:20', (string) ($core['birth_time'] ?? ''));

        $this->assertNotNull($core['height_cm'] ?? null);
        $this->assertStringContainsString('गोरा', (string) ($core['complexion'] ?? ''));
        $this->assertSame('A+', (string) ($core['blood_group'] ?? ''));

        $this->assertStringContainsString('M.Sc', (string) ($core['highest_education'] ?? ''));
        $this->assertSame('मराठा', (string) ($core['caste'] ?? ''));

        $this->assertStringContainsString('श्री.', (string) ($core['father_name'] ?? ''));
        $this->assertStringContainsString('रामचंद्र', (string) ($core['father_name'] ?? ''));
        $this->assertStringContainsString('सौ.', (string) ($core['mother_name'] ?? ''));
        $this->assertStringContainsString('सुनिता', (string) ($core['mother_name'] ?? ''));
        $this->assertSame('गृहिणी', (string) ($core['mother_occupation'] ?? ''));

        $this->assertStringContainsString('बानेर', (string) ($core['address_line'] ?? ''));

        $ort = (string) ($core['other_relatives_text'] ?? '');
        $this->assertStringContainsString('सुनीताबाई', $ort);
        $this->assertStringNotContainsString('रास', $ort);
        $this->assertStringNotContainsString('नक्षत्र', $ort);
        $this->assertLessThan(400, mb_strlen($ort));

        $prop = (string) ($parsed['property_summary'] ?? '');
        $this->assertStringContainsString('एकर', $prop);

        $nums = [];
        foreach ($parsed['contacts'] ?? [] as $c) {
            $nums[] = (string) ($c['number'] ?? $c['phone_number'] ?? '');
        }
        $this->assertContains('9820012345', $nums);

        $ho = $parsed['horoscope'][0] ?? [];
        $this->assertArrayNotHasKey('blood_group', $ho);
    }

    public function test_html_table_parent_names_split_occupation_from_parentheses(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<HTML
<table>
<tr><td>मुलीचे नांव</td><td>:-</td><td>कु. काजल रामचंद्र कदम</td></tr>
<tr><td>वडिलांचे नांव</td><td>:-</td><td>श्री.रामचंद्र बाळकृष्ण कदम (सेवानिवृत्त-मुंबई पोलिस A.S.I.)</td></tr>
<tr><td>आईचे नांव</td><td>:-</td><td>सौ.सुनिता रामचंद्र कदम (गृहिणी)</td></tr>
</table>
HTML;
        $parsed = $service->parse($raw);
        $core = $parsed['core'] ?? [];
        $this->assertStringContainsString('श्री.', (string) ($core['father_name'] ?? ''));
        $this->assertStringContainsString('रामचंद्र बाळकृष्ण कदम', (string) ($core['father_name'] ?? ''));
        $this->assertStringContainsString('सेवानिवृत्त', (string) ($core['father_occupation'] ?? ''));
        $this->assertStringContainsString('सौ.', (string) ($core['mother_name'] ?? ''));
        $this->assertStringContainsString('सुनिता', (string) ($core['mother_name'] ?? ''));
        $this->assertSame('गृहिणी', (string) ($core['mother_occupation'] ?? ''));
    }

    public function test_html_table_ku_prefix_forces_female_gender(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<HTML
<table>
<tr><td>मुलीचे नांव</td><td>:-</td><td>कु. काजल रामचंद्र कदम</td></tr>
</table>
HTML;
        $parsed = $service->parse($raw);
        $this->assertSame('female', (string) (($parsed['core'] ?? [])['gender'] ?? ''));
    }

    public function test_html_table_current_address_wins_for_core_address_line(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<HTML
<table>
<tr><td>मुळ पत्ता</td><td>:-</td><td>मु. पो. वाळवा, जि. पुणे</td></tr>
<tr><td>सध्याचा पत्ता</td><td>:-</td><td>फ्लॅट ३, बानेर, पुणे</td></tr>
</table>
HTML;
        $parsed = $service->parse($raw);
        $core = $parsed['core'] ?? [];
        $this->assertStringContainsString('बानेर', (string) ($core['address_line'] ?? ''));
        $addrs = $parsed['addresses'] ?? [];
        $this->assertNotEmpty($addrs);
        $res = null;
        foreach ($addrs as $a) {
            if (($a['type'] ?? '') === 'residential' || ($a['label'] ?? '') === 'native') {
                $res = $a['address_line'] ?? $a['line'] ?? null;
            }
        }
        $flat = json_encode($addrs, JSON_UNESCAPED_UNICODE);
        $this->assertTrue(
            str_contains((string) $flat, 'वाळवा') || str_contains((string) ($core['address_line'] ?? ''), 'बानेर'),
            'Native address should remain discoverable while current wins for core.address_line'
        );
    }

    public function test_html_table_horoscope_grid_maps_by_row_not_legend_bleed(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<HTML
<table>
<tr><td>नक्षत्र</td><td>:-</td><td>पूर्वा फाल्गुनी</td></tr>
<tr><td>चरण</td><td>:-</td><td>२रे</td></tr>
<tr><td>नाडी</td><td>:-</td><td>मध्य</td></tr>
<tr><td>योनी</td><td>:-</td><td>उंदीर</td></tr>
<tr><td>गण</td><td>:-</td><td>मनुष्य</td></tr>
<tr><td>रास</td><td>:-</td><td>सिंह</td></tr>
<tr><td>स्वामी</td><td>:-</td><td>सुर्य</td></tr>
<tr><td>वर्ण</td><td>:-</td><td>क्षत्रिय</td></tr>
<tr><td>वैरवर्ग</td><td>:-</td><td>कुत्रा</td></tr>
</table>
HTML;
        $parsed = $service->parse($raw);
        $ho = $parsed['horoscope'][0] ?? [];
        $this->assertStringContainsString('पूर्वा', (string) ($ho['nakshatra'] ?? ''));
        $this->assertSame('मध्य', (string) ($ho['nadi'] ?? ''));
        $this->assertSame('मनुष्य', (string) ($ho['gan'] ?? ''));
        $this->assertStringContainsString('सिंह', (string) ($ho['rashi'] ?? ''));
        $this->assertStringNotContainsString('स्वामी', (string) ($ho['rashi'] ?? ''));
        $this->assertStringNotContainsString('नक्षत्र', (string) ($ho['nadi'] ?? ''));
        $this->assertStringContainsString('सुर्य', (string) ($ho['navras_name'] ?? ''));
        $this->assertSame('क्षत्रिय', (string) (($parsed['core'] ?? [])['varna'] ?? ''));
        $this->assertStringContainsString('कुत्रा', (string) ($ho['vairavarga'] ?? ''));
    }

    public function test_footer_print_shop_phone_not_added_when_biodata_mobile_present(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
मोबाईल नंबर :- ९८६५२३२१३३
Print Shop Contact ९६०४२८९२८९
TXT;
        $parsed = $service->parse($raw);
        $nums = [];
        foreach ($parsed['contacts'] ?? [] as $c) {
            $nums[] = (string) ($c['number'] ?? $c['phone_number'] ?? '');
        }
        $this->assertContains('9865232133', $nums);
        $this->assertNotContains('9604289289', $nums);
    }

    public function test_sibling_sister_rich_line_splits_name_degree_address_marital(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<'TXT'
मुलीचे नाव :- कु. टेस्ट
बहिण :- सौ.स्नेहल उमेश पाटील, (B.Com) रा.शिराळा, जि.सांगली.
TXT;
        $parsed = $service->parse($raw);
        $sis = array_values(array_filter($parsed['siblings'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'sister'));
        $this->assertCount(1, $sis);
        $this->assertStringContainsString('सौ.', (string) $sis[0]['name']);
        $this->assertStringContainsString('स्नेहल उमेश पाटील', (string) $sis[0]['name']);
        $this->assertSame('married', (string) ($sis[0]['marital_status'] ?? ''));
        $this->assertStringContainsString('B.Com', (string) ($sis[0]['occupation'] ?? ''));
        $this->assertStringContainsString('शिराळा', (string) ($sis[0]['address_line'] ?? ''));
        $this->assertStringContainsString('सांगली', (string) ($sis[0]['address_line'] ?? ''));
    }

    public function test_sibling_brother_two_paren_splits_marital_and_occupation(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<'TXT'
मुलीचे नाव :- कु. टेस्ट
भाऊ :- चि. शुभम रामचंद्र कदम (अविवाहीत) (B.Com II Year Appear)
TXT;
        $parsed = $service->parse($raw);
        $bro = array_values(array_filter($parsed['siblings'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'brother'));
        $this->assertCount(1, $bro);
        $this->assertStringContainsString('चि.', (string) $bro[0]['name']);
        $this->assertStringContainsString('शुभम रामचंद्र कदम', (string) $bro[0]['name']);
        $this->assertStringNotContainsString('अविवाहीत', (string) $bro[0]['name']);
        $this->assertSame('unmarried', (string) ($bro[0]['marital_status'] ?? ''));
        $this->assertStringContainsString('B.Com II Year Appear', (string) ($bro[0]['occupation'] ?? ''));
    }

    public function test_html_table_other_relatives_clean_and_mira_road_current_address(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<'HTML'
<table>
<tr><td>मुळ पत्ता</td><td>:-</td><td>मु. पो. वाळवा, जि. पुणे</td></tr>
<tr><td>सध्याचा पत्ता</td><td>:-</td><td>३/१०४, साई सरस्वती धाम, शांतीवन, मिरा रोड (पुर्व), जि.ठाणे (मुंबई)</td></tr>
<tr><td>इतर नातेवाईक</td><td>:-</td><td>मोहिते (पाटील)-मांजर्डे, जाधव (पाटील)-आंधळी पलूस, घोरपडे-झरे, निकम-सावळज, पाटील-नागांव कवठे</td></tr>
</table>
HTML;
        $parsed = $service->parse($raw);
        $core = $parsed['core'] ?? [];
        $this->assertStringContainsString('मिरा रोड', (string) ($core['address_line'] ?? ''));
        $this->assertStringContainsString('ठाणे', (string) ($core['address_line'] ?? ''));
        $ort = (string) ($core['other_relatives_text'] ?? '');
        $this->assertSame(
            'मोहिते (पाटील)-मांजर्डे, जाधव (पाटील)-आंधळी पलूस, घोरपडे-झरे, निकम-सावळज, पाटील-नागांव कवठे',
            $ort
        );
        $this->assertStringNotContainsString('कुंडली', $ort);
        $this->assertStringNotContainsString('बायोडेटा', $ort);
        $addrs = $parsed['addresses'] ?? [];
        $this->assertNotEmpty($addrs);
        $this->assertSame('current', (string) ($addrs[0]['type'] ?? ''));
        $this->assertStringContainsString('मिरा रोड', (string) ($addrs[0]['address_line'] ?? ''));
        $this->assertGreaterThanOrEqual(2, count($addrs));
        $this->assertSame('residential', (string) ($addrs[1]['type'] ?? ''));
        $this->assertStringContainsString('वाळवा', (string) ($addrs[1]['address_line'] ?? ''));
    }

    public function test_html_table_other_relatives_authoritative_despite_body_pollution_after_table(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<'HTML'
<table>
<tr><td>इतर नातेवाईक</td><td>:-</td><td>मोहिते (पाटील)-मांजर्डे, जाधव (पाटील)-आंधळी पलूस</td></tr>
</table>

बायोडेटा
जन्म लग्न कुंडली
नक्षत्र स्वामी रास
Print Shop 9999999999
ಒಂದು ಕನ್ನಡ
123456789012345
HTML;
        $parsed = $service->parse($raw);
        $ort = (string) (($parsed['core'] ?? [])['other_relatives_text'] ?? '');
        $this->assertSame(
            'मोहिते (पाटील)-मांजर्डे, जाधव (पाटील)-आंधळी पलूस',
            $ort
        );
        $this->assertStringNotContainsString('बायोडेटा', $ort);
        $this->assertStringNotContainsString('कुंडली', $ort);
        $this->assertStringNotContainsString('Print', $ort);
        $this->assertStringNotContainsString('ಕನ್ನಡ', $ort);
    }

    public function test_other_relatives_text_does_not_absorb_apeksha_line(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<'TXT'
मुलीचे नाव :- कु. टेस्ट
इतर पाहुणे :- मोहिते (पाटील)-मांजर्डे, जाधव (पाटील)-आंधळी पलूस
अपेक्षा :- खानदानी, नोकरी, उच्चशिक्षीत.
TXT;
        $parsed = $service->parse($raw);
        $ort = (string) (($parsed['core'] ?? [])['other_relatives_text'] ?? '');
        $this->assertStringContainsString('मोहिते', $ort);
        $this->assertStringNotContainsString('अपेक्षा', $ort);
    }

    public function test_label_bounded_fields_parse_cleanly_for_vita_coimbatore_sample(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलाचे नाव :- चि. नमुना व्यक्ती
जन्मस्थळ: मु.पो.विटा, ता.खानापूर, जि.सांगली
संपर्क नंबर: 9940168213
शिक्षण: B.Com (Chennai) रक्तगट :- B+
व्यवसाय: Baapu Die Works, Coimbatore -641 001.
स्थावर मिळकत: मुलाचे स्वता:चे घर / Flat No. B5-4D, Gujan Arudra Apartment / TelunguPalyam, Pirivu, Coimbatore – 641 026
मूळचा पत्ता: मु.पो.देविखिंडी, ता.खानापूर, जि.सांगली
इतर नातेवाईक: चितळी, मोराळे, वलवण, वेजेगांव, घानवड
नक्षत्र: चित्रा
रास: कन्या
चरण: १ ले
योनी: व्याघ्र
नाडी: मध्य
नावरसनांव: पे
गण: राक्षस
वैरवर्ग: उदर
वर्ण: वैश्य
वश्य: मानव
प्रिंट शॉप संपर्क: 9604289289
मुलाचे भाऊ :- १) सौ.पूजा/श्री.गोपीनाथ सिध्देश्वर पाटील, कोईमतूर
२) सौ.दिपाली/श्री.सोमनाथ सिध्देश्वर पाटील, चेन्नई
मुलाची बहिण :- नाही
मामा : श्री. संपूर्ण मामा लाईन, मु.पो. विटा, ता.खानापूर, जि.सांगली
TXT;

        $parsed = $service->parse($raw);
        $core = $parsed['core'] ?? [];

        $this->assertSame('मु.पो.विटा, ता.खानापूर, जि.सांगली', (string) ($core['birth_place_text'] ?? ''));
        $this->assertSame('9940168213', (string) ($core['primary_contact_number'] ?? ''));
        $this->assertNotEmpty($parsed['contacts'] ?? []);
        $allPhones = array_map(fn ($c) => (string) ($c['phone_number'] ?? $c['number'] ?? ''), $parsed['contacts'] ?? []);
        $this->assertContains('9940168213', $allPhones);
        $this->assertNotContains('9604289289', $allPhones);

        $this->assertSame('B.Com (Chennai)', (string) ($core['highest_education'] ?? ''));
        $this->assertSame('B+', (string) ($core['blood_group'] ?? ''));

        $this->assertSame('Baapu Die Works', (string) ($core['company_name'] ?? ''));
        $this->assertStringContainsString('Coimbatore -641 001', (string) ($core['work_location_text'] ?? ''));
        $this->assertTrue(
            ($core['occupation_title'] ?? null) === null || (string) ($core['occupation_title'] ?? '') === '',
            'employer-only line must not duplicate company into occupation_title'
        );

        $this->assertSame(
            'मु.पो.देविखिंडी, ता.खानापूर, जि.सांगली',
            (string) (($parsed['native_place'] ?? [])['address_line'] ?? '')
        );
        $addrTypes = array_column($parsed['addresses'] ?? [], 'type');
        $this->assertContains('native', $addrTypes);

        $prop = (string) ($parsed['property_summary'] ?? '');
        $this->assertStringContainsString('Flat No. B5-4D', $prop);
        $this->assertStringContainsString('Coimbatore', $prop);

        $ort = (string) ($core['other_relatives_text'] ?? '');
        $this->assertSame('चितळी, मोराळे, वलवण, वेजेगांव, घानवड', $ort);

        $this->assertSame('वैश्य', (string) ($core['varna'] ?? ''));
        $hor = $parsed['horoscope'][0] ?? [];
        $this->assertSame('वैश्य', (string) ($hor['varna'] ?? ''));
        $this->assertSame('१ ले', (string) ($hor['charan'] ?? ''));
        $this->assertSame('पे', (string) ($hor['navras_name'] ?? ''));

        $this->assertStringContainsString('संपूर्ण मामा लाईन', (string) ($core['mama'] ?? ''));

        $this->assertSame(2, (int) ($core['brother_count'] ?? 0));
        $this->assertSame(0, (int) ($core['sister_count'] ?? -1));
        $brothers = array_values(array_filter($parsed['siblings'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'brother'));
        $this->assertCount(2, $brothers);
        $this->assertStringContainsString('गोपीनाथ', (string) ($brothers[0]['name'] ?? ''));
        $this->assertStringContainsString('सोमनाथ', (string) ($brothers[1]['name'] ?? ''));
        $this->assertSame('सौ.पूजा', (string) (($brothers[0]['spouse'] ?? [])['name'] ?? ''));
        $this->assertSame('सौ.दिपाली', (string) (($brothers[1]['spouse'] ?? [])['name'] ?? ''));
        $this->assertSame('कोईमतूर', (string) ($brothers[0]['address_line'] ?? ''));
        $this->assertSame('चेन्नई', (string) ($brothers[1]['address_line'] ?? ''));
        foreach ($parsed['siblings'] ?? [] as $s) {
            $this->assertNotSame('मुलाचे भाऊ', trim((string) ($s['name'] ?? '')));
        }

        $ssot = $this->app->make(IntakeParsedSnapshotSkeleton::class)->ensure($parsed);
        $this->assertIsString($ssot['property_summary'] ?? null);
        $this->assertStringContainsString('Flat No.', (string) ($ssot['property_summary'] ?? ''));
        foreach ($parsed['relatives'] ?? [] as $rel) {
            $notes = trim((string) ($rel['notes'] ?? ''));
            $this->assertFalse(
                (bool) preg_match('/^मुलाचे\s+(?:चुलते|मामा|मुलाची\s+आत्या)\s*$/u', $notes),
                'pseudo section headers must not appear as relative rows: '.$notes
            );
        }
    }

    public function test_address_context_phone_promoted_into_contacts_when_no_mobile_row(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<'TXT'
मुलीचे नाव :- कु. टेस्ट
पत्ता :- इस्लामपूर, जि. सांगली मो. ९८६०४४६१०९
मामा :- सौ. सुनिल रामचंद्र पाटील मो. 9284040413. रा. सागांव, ता. शिराळा.
TXT;
        $parsed = $service->parse($raw);
        $nums = array_map(fn ($c) => (string) ($c['number'] ?? $c['phone_number'] ?? ''), $parsed['contacts'] ?? []);
        $this->assertContains('9860446109', $nums);
        // Relative phones may be excluded from contacts; the key is that the address-context phone is not dropped.
    }

    public function test_chulte_paren_continuation_stays_single_row(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<'TXT'
मुलीचे नाव :- कु. टेस्ट
चुलते : श्री. संपतराव उर्फ अजित विष्णू भोसले (निवृत्त कर निरीक्षक
इस्लामपूर नगरपरिषद) मो. 9850522929 रा. इस्लामपूर
TXT;
        $parsed = $service->parse($raw);
        $chulte = array_values(array_filter($parsed['relatives'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'चुलते'));
        $this->assertCount(1, $chulte);
        $this->assertStringContainsString('संपतराव', (string) ($chulte[0]['name'] ?? ''));
        $this->assertSame('9850522929', (string) ($chulte[0]['contact_number'] ?? ''));
        $this->assertStringContainsString('निवृत्त', (string) ($chulte[0]['occupation'] ?? ''));
        $this->assertStringContainsString('नगरपरिषद', (string) ($chulte[0]['occupation'] ?? ''));
    }

    public function test_sibling_section_unmarried_applies_marital_status_to_rows(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<'TXT'
मुलीचे नाव :- कु. टेस्ट
भाऊ :- दोन- अविवाहीत - १) चि. संस्कार शहाजी भोसले. २) चि. सार्थक शहाजी भोसले
TXT;
        $parsed = $service->parse($raw);
        $brothers = array_values(array_filter($parsed['siblings'] ?? [], fn ($r) => ($r['relation_type'] ?? '') === 'brother'));
        $this->assertCount(2, $brothers);
        foreach ($brothers as $b) {
            $this->assertSame('unmarried', (string) ($b['marital_status'] ?? ''));
        }
    }

    public function test_core_relatives_null_when_structured_relatives_present(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<'TXT'
मुलीचे नाव :- कु. टेस्ट
मामा :- श्री. सुनील रामचंद्र पाटील, मु. पो. शिरोली
TXT;
        $parsed = $service->parse($raw);
        $this->assertNotEmpty($parsed['relatives'] ?? []);
        $this->assertTrue(! isset(($parsed['core'] ?? [])['relatives']) || ($parsed['core']['relatives'] ?? null) === null);
    }

    public function test_html_table_mobile_row_two_numbers_excludes_footer(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<'HTML'
<table>
<tr><td>मोबाईल नंबर</td><td>:-</td><td>9082922044 / 8765432109</td></tr>
</table>
Print Shop Contact 9604289289
HTML;
        $parsed = $service->parse($raw);
        $nums = [];
        foreach ($parsed['contacts'] ?? [] as $c) {
            $nums[] = (string) ($c['number'] ?? $c['phone_number'] ?? '');
        }
        $this->assertContains('9082922044', $nums);
        $this->assertContains('8765432109', $nums);
        $this->assertNotContains('9604289289', $nums);
    }
}
