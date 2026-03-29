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
        $this->assertSame('सुप्रिया शहाजी भोसले', (string) ($core['mother_name'] ?? ''));
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

        $h = $out['horoscope'][0] ?? [];
        $this->assertSame('कन्या', $h['rashi'] ?? null);
        $this->assertSame('शुक्रवार', $h['birth_weekday'] ?? null);
        $this->assertSame('हरत', $h['nakshatra'] ?? null);
        $this->assertSame('O+', $h['blood_group'] ?? null);
        $this->assertSame('O+', $core['blood_group'] ?? null);

        $edu = $out['education_history'][0] ?? [];
        $this->assertSame('B.A. LL.B', $edu['degree'] ?? null);

        $sec = $out['relatives_sectioned'] ?? [];
        $mama = $sec['maternal']['mama'] ?? [];
        foreach ($mama as $row) {
            $n = (string) ($row['name'] ?? $row['notes'] ?? '');
            $this->assertDoesNotMatchRegularExpression('/^\s*\d+\s*[\).]\s*$/u', $n);
        }
    }
}
