<?php

namespace Tests\Unit\Parsing;

use App\Services\BiodataParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fifth biodata: mixed markdown list OCR — जन्मवार आणि वेळ, पत्ता vs निवासी पत्ता, चुलते comma split, plain मामा name.
 */
class BiodataParserFifthSampleTest extends TestCase
{
    use RefreshDatabase;

    private function sampleText(): string
    {
        return <<<'TXT'
नाव :- विशाल पांडुरंग डाकवे
लिंग :- पुरुष
जन्म तारीख :- ०२ नोव्हेंबर १९९५
जात :- मराठा
धर्म :- हिंदू
उंची :- 5 फूट 4 इंच
शिक्षण :- BE (MECH)
व्यवसाय :- Production Engineer
रास :- कुंभ
कुलदैवत :- जोतिबा
जन्मवार :- गुरुवार
जन्मवार आणि वेळ : गुरुवारी सकाळी ११ वा. २७ मी.
- पत्ता : मु. पो. डाकेवाडी काळगाव ता. पाटण जि.सातारा
- निवासी पत्ता : A/303, Wonder Residency ,fatherwadi Vasai.
मामा : जितेंद्र शामराव पवार
चुलते : कै. शामराव लक्ष्मण डाकवे, कृष्णा लक्ष्मण डाकवे, हरि लक्ष्मण डाकवे.
TXT;
    }

    public function test_fifth_sample_birth_time_address_chulte_mama(): void
    {
        $out = app(BiodataParserService::class)->parse($this->sampleText());
        $core = $out['core'] ?? [];

        $this->assertSame('विशाल पांडुरंग डाकवे', (string) ($core['full_name'] ?? ''));
        $this->assertSame('1995-11-02', (string) ($core['date_of_birth'] ?? ''));
        $this->assertSame('male', (string) ($core['gender'] ?? ''));
        $this->assertSame('हिंदू', (string) ($core['religion'] ?? ''));
        $this->assertSame('मराठा', (string) ($core['caste'] ?? ''));
        $this->assertSame('5 ft 4 in', (string) ($core['height'] ?? ''));
        $this->assertSame(162.56, (float) ($core['height_cm'] ?? 0));
        $this->assertSame('BE (MECH)', (string) ($core['highest_education'] ?? ''));
        $this->assertSame('Production Engineer', (string) ($core['occupation_title'] ?? ''));
        $this->assertSame('कुंभ', (string) ($core['rashi'] ?? ''));
        $this->assertSame('जोतिबा', (string) ($core['kuldaivat'] ?? ''));

        $this->assertSame('11:27', (string) ($core['birth_time'] ?? ''));

        $addr = (string) ($core['address_line'] ?? '');
        $this->assertStringContainsString('डाकेवाडी', $addr);
        $this->assertStringContainsString('सातारा', $addr);
        $this->assertStringNotContainsString('Wonder Residency', $addr);
        $this->assertStringNotContainsString('निवासी पत्ता', $addr);

        $addresses = $out['addresses'] ?? [];
        $res = array_values(array_filter($addresses, fn ($a) => ($a['type'] ?? '') === 'residential'));
        $this->assertNotEmpty($res);
        $this->assertStringContainsString('Wonder Residency', (string) ($res[0]['address_line'] ?? ''));

        $horo = $out['horoscope'] ?? [];
        $this->assertNotEmpty($horo);
        $this->assertSame('गुरुवार', (string) (($horo[0] ?? [])['birth_weekday'] ?? ''));

        $this->assertSame('जितेंद्र शामराव पवार', (string) ($core['mama'] ?? ''));

        $sectioned = $out['relatives_sectioned'] ?? [];
        $mamaRows = $sectioned['maternal']['mama'] ?? [];
        $this->assertNotEmpty($mamaRows);
        $this->assertSame('जितेंद्र शामराव पवार', (string) (($mamaRows[0] ?? [])['name'] ?? ''));

        $chulte = $sectioned['paternal']['chulte'] ?? [];
        $this->assertCount(3, $chulte);
        $names = array_map(fn ($r) => (string) ($r['name'] ?? ''), $chulte);
        $this->assertSame('कै. शामराव लक्ष्मण डाकवे', $names[0]);
        $this->assertSame('कृष्णा लक्ष्मण डाकवे', $names[1]);
        $this->assertSame('हरि लक्ष्मण डाकवे', $names[2]);
    }
}
