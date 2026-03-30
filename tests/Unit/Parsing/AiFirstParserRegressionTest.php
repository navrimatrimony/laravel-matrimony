<?php

namespace Tests\Unit\Parsing;

use App\Services\ExternalAiParsingService;
use App\Services\Parsing\Parsers\AiFirstBiodataParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * AI-first path with mocked external parse: rules merge + repairs for Sarvam-style sample (no live API).
 */
class AiFirstParserRegressionTest extends TestCase
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_ai_first_v1_merges_rules_when_ai_core_and_structure_are_partially_wrong(): void
    {
        $badAi = [
            'core' => [
                'full_name' => null,
                'primary_contact_number' => null,
                'father_contact_1' => null,
                'blood_group' => null,
                'birth_time' => null,
                'father_name' => 'हेमंत आनंदराव जाधव, मो',
            ],
            'confidence_map' => [],
            'horoscope' => [
                ['gan' => 'ेशाचे आहे', 'blood_group' => '99'],
            ],
            'siblings' => [
                ['relation_type' => 'sister', 'name' => 'भाऊ/बहिण : अविवाहित'],
            ],
            'relatives' => [
                ['relation_type' => 'चुलते', 'name' => null, 'raw_note' => 'मुलीचे चुलते'],
            ],
        ];

        $this->mock(ExternalAiParsingService::class, function ($m) use ($badAi) {
            $m->shouldReceive('parseToSsot')->once()->andReturn($badAi);
        });

        $parser = app(AiFirstBiodataParser::class);
        $out = $parser->parse($this->sampleText(), ['parser_mode' => 'ai_first_v1']);

        $core = $out['core'] ?? [];
        $this->assertStringContainsString('दिव्या', (string) ($core['full_name'] ?? ''));
        $this->assertSame('A+', $core['blood_group'] ?? null);
        $this->assertNotNull($core['birth_time'] ?? null);
        $this->assertStringNotContainsString(', मो', (string) ($core['father_name'] ?? ''));
        $this->assertSame('9123456789', $core['father_contact_1'] ?? null);

        $this->assertSame('राक्षस', ($out['horoscope'][0]['gan'] ?? null));
        $this->assertArrayNotHasKey('blood_group', $out['horoscope'][0] ?? [], 'Blood group SSOT is core.blood_group; horoscope row must not carry blood_group.');

        foreach ($out['siblings'] ?? [] as $s) {
            $n = (string) ($s['name'] ?? '');
            $this->assertStringNotContainsString('अविवाहित', $n);
        }

        $mavshiKakaMale = false;
        foreach ($out['relatives'] ?? [] as $r) {
            if (($r['relation_type'] ?? '') === 'other_maternal' && str_contains((string) ($r['raw_note'] ?? ''), 'मावशी')) {
                $mavshiKakaMale = true;
            }
        }
        $this->assertTrue($mavshiKakaMale);

        $relTypes = array_map(fn ($r) => (string) ($r['relation_type'] ?? ''), $out['relatives'] ?? []);
        $this->assertContains('आजोळ', $relTypes);
    }

    /**
     * Runtime preview uses AiFirst; External AI can return plausible-looking JSON that misreads table rows.
     * Rules parser on the same raw text must win for core fields not in the old merge list.
     */
    public function test_ai_first_v1_merges_fourth_sample_rules_when_ai_ssot_corrupt(): void
    {
        $text = <<<'TXT'
नाव :- कु. प्राजक्ता शहाजी भोसले
जन्म तारीख :- ०८ ऑगस्ट १९९७
जन्म वार व वेळ :- शुक्रवार दुपारी १:४० मि.
जन्म ठिकाण :- मुंबई.
वर्ण :- गोरा
शिक्षण :- B.A. LL.B देवक :- पंचपल्लव
व्यवसाय :- वकील गोत्र :- कश्यप पुरशी (कौशिक)
उंची :- 5 फूट 3 इंच
रक्त गट :- O+ve
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
मावशी :- 1) सौ. सुनीता कुलकर्णी 2) सौ. राधा शिंदे
TXT;

        $badAi = [
            'core' => [
                'full_name' => 'कु. प्राजक्ता शहाजी भोसले',
                'date_of_birth' => null,
                'birth_time' => null,
                'birth_place' => null,
                'birth_place_text' => null,
                'highest_education' => 'गोत्र :- कश्यप पुरशी (कौशिक)',
                'address_line' => 'श्री. माणकेश्वर (पेठ)',
                'rashi' => 'कुलस्वामी',
                'occupation_title' => null,
                'primary_contact_number' => null,
                'father_name' => 'कै. डॉ. शहाजी विष्णू भोसले',
                'blood_group' => 'O+',
                'brother_count' => 0,
                'sister_count' => 1,
            ],
            'confidence_map' => [],
            'horoscope' => [
                [
                    'rashi' => 'स्वामी',
                    'birth_weekday' => null,
                    'blood_group' => 'O+',
                    'nakshatra' => 'हरत',
                ],
            ],
            'education_history' => [
                ['degree' => 'गोत्र :- कश्यप पुरशी (कौशिक)', 'institution' => null],
            ],
            'addresses' => [
                ['address_line' => 'श्री. माणकेश्वर (पेठ)', 'type' => 'current'],
            ],
            'siblings' => [
                ['relation_type' => 'sister', 'name' => 'बहिण एक अविवाहीत - कु'],
            ],
            'career_history' => [],
            'contacts' => [],
            'relatives' => [
                ['relation_type' => 'आजोळ (मामा)', 'name' => 'आजोळ (मामा)', 'notes' => ''],
            ],
        ];

        $this->mock(ExternalAiParsingService::class, function ($m) use ($badAi) {
            $m->shouldReceive('parseToSsot')->once()->andReturn($badAi);
        });

        $parser = app(AiFirstBiodataParser::class);
        $out = $parser->parse($text, ['parser_mode' => 'ai_first_v1']);

        $core = $out['core'] ?? [];
        $this->assertSame('1997-08-08', (string) ($core['date_of_birth'] ?? ''));
        $this->assertStringContainsString('मुंबई', (string) ($core['birth_place'] ?? ''));
        $this->assertSame('13:40', (string) ($core['birth_time'] ?? ''));
        $this->assertSame('B.A. LL.B', (string) ($core['highest_education'] ?? ''));
        $this->assertSame('वकील', (string) ($core['occupation_title'] ?? ''));
        $this->assertStringContainsString('उरूण-इस्लामपूर', (string) ($core['address_line'] ?? ''));
        $this->assertStringNotContainsString('माणकेश्वर', (string) ($core['address_line'] ?? ''));
        $this->assertSame('कन्या', (string) ($core['rashi'] ?? ''));

        $h = $out['horoscope'][0] ?? [];
        $this->assertSame('कन्या', $h['rashi'] ?? null);
        $this->assertSame('शुक्रवार', $h['birth_weekday'] ?? null);

        $this->assertSame('B.A. LL.B', (string) (($out['education_history'][0] ?? [])['degree'] ?? ''));
        $this->assertStringContainsString('उरूण', (string) (($out['addresses'][0] ?? [])['address_line'] ?? ''));
        $this->assertCount(3, $out['siblings'] ?? []);
        $this->assertSame(2, $core['brother_count'] ?? null);
        $this->assertSame(1, $core['sister_count'] ?? null);

        $mat = $out['relatives_sectioned']['maternal'] ?? [];
        $ajol = $mat['ajol'] ?? [];
        $mama = $mat['mama'] ?? [];
        $this->assertTrue(count($ajol) + count($mama) >= 1, 'Expected आजोळ/मामा rows from rules merge');
    }

    public function test_ai_first_v2_uses_same_rules_merge_as_v1(): void
    {
        $text = <<<'TXT'
नाव :- कु. प्राजक्ता शहाजी भोसले
जन्म तारीख :- ०८ ऑगस्ट १९९७
जन्म ठिकाण :- मुंबई.
शिक्षण :- B.A. LL.B
व्यवसाय :- वकील
पत्ता :- मु. पो. उरूण-इस्लामपूर, ता. वाळवा, जि. सांगली.
TXT;

        $badAi = [
            'core' => [
                'full_name' => 'कु. प्राजक्ता शहाजी भोसले',
                'date_of_birth' => null,
                'birth_place' => null,
                'highest_education' => 'गोत्र :- कश्यप पुरशी',
                'occupation_title' => null,
                'primary_contact_number' => null,
            ],
            'horoscope' => [],
            'education_history' => [['degree' => 'गोत्र :- कश्यप पुरशी', 'institution' => null]],
            'addresses' => [],
            'siblings' => [],
            'career_history' => [],
            'contacts' => [],
            'relatives' => [],
            'confidence_map' => [],
        ];

        $this->mock(ExternalAiParsingService::class, function ($m) use ($badAi) {
            $m->shouldReceive('parseToSsotV2')->once()->andReturn($badAi);
        });

        $out = app(AiFirstBiodataParser::class)->parse($text, ['parser_mode' => 'ai_first_v2']);
        $this->assertSame('1997-08-08', (string) (($out['core'] ?? [])['date_of_birth'] ?? ''));
        $this->assertStringContainsString('मुंबई', (string) (($out['core'] ?? [])['birth_place'] ?? ''));
        $this->assertSame('वकील', (string) (($out['core'] ?? [])['occupation_title'] ?? ''));
        $this->assertStringContainsString('B.A.', (string) (($out['education_history'][0] ?? [])['degree'] ?? ''));
    }

    public function test_ai_first_v1_merges_when_confidence_map_omitted(): void
    {
        $text = "नाव :- कु. प्राजक्ता शहाजी भोसले\nजन्म तारीख :- ०८ ऑगस्ट १९९७\n";
        $badAi = [
            'core' => [
                'full_name' => 'कु. प्राजक्ता शहाजी भोसले',
                'date_of_birth' => null,
                'primary_contact_number' => null,
            ],
        ];

        $this->mock(ExternalAiParsingService::class, function ($m) use ($badAi) {
            $m->shouldReceive('parseToSsot')->once()->andReturn($badAi);
        });

        $out = app(AiFirstBiodataParser::class)->parse($text, ['parser_mode' => 'ai_first_v1']);
        $this->assertSame('1997-08-08', (string) (($out['core'] ?? [])['date_of_birth'] ?? ''));
    }

    public function test_ai_first_v1_parses_dob_from_html_table_cells(): void
    {
        $html = <<<'HTML'
<table>
<tr><td>जन्म तारीख</td><td>:-</td><td>०८ ऑगस्ट १९९७</td></tr>
<tr><td>शिक्षण</td><td>:-</td><td>B.A. LL.B</td></tr>
</table>
HTML;

        $badAi = [
            'core' => [
                'full_name' => null,
                'date_of_birth' => null,
                'highest_education' => null,
                'primary_contact_number' => null,
            ],
            'confidence_map' => [],
        ];

        $this->mock(ExternalAiParsingService::class, function ($m) use ($badAi) {
            $m->shouldReceive('parseToSsot')->once()->andReturn($badAi);
        });

        $out = app(AiFirstBiodataParser::class)->parse($html, ['parser_mode' => 'ai_first_v1']);
        $this->assertSame('1997-08-08', (string) (($out['core'] ?? [])['date_of_birth'] ?? ''));
        $this->assertStringContainsString('B.A.', (string) (($out['core'] ?? [])['highest_education'] ?? ''));
    }
}
