<?php

namespace Tests\Unit\Parsing;

use App\Services\BiodataParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Third biodata sample: नाव :- , वडील/आई short labels, Hindi पता, भाऊ (B.com), मामा+चुलते same line, रास/योनी composite, कलदैवत.
 */
class BiodataParserThirdSampleTest extends TestCase
{
    use RefreshDatabase;

    private function sampleText(): string
    {
        return <<<'TXT'
नाव :- श्वेताली बाळासाहेब सुंबे
वर्ण :- गोरा,
रक्त गट :- B+ve
रास :- कन्या, योनी :- व्याघ्र
कलदैवत :- पालीचा खुंडोबा
पता :- घर नं.३७, माळीनगर, ता. माळशिरस, जि. सोलापूर
वडील :- बाळासाहेब बन्सी सुंबे, (नोकरी पाटबुंधारे सोसायटी) आई :- सौ. नंदा बाळासाहेब सुंबे (गहिणी)
भाऊ :- श्री. सुरज बाळासाहेब सुंबे (B.com)
नोकरी :- Bharat Forge Mundhawa,Pune.
मामा :- कै.दशरथ बबन गगे (आंबी खालसा, ता. सुंगमने) ) चुलते:- श्री. चुलते व्यक्ती
TXT;
    }

    public function test_third_sample_name_parents_address_siblings_horoscope_relatives(): void
    {
        $out = app(BiodataParserService::class)->parse($this->sampleText());
        $core = $out['core'] ?? [];

        $this->assertNull($core['primary_contact_number'] ?? null);
        $this->assertNotNull($core['blood_group'] ?? null);
        $this->assertSame('B+', $core['blood_group'] ?? null);

        $this->assertSame('श्वेताली बाळासाहेब सुंबे', (string) ($core['full_name'] ?? ''));
        $this->assertSame('बाळासाहेब बन्सी सुंबे', (string) ($core['father_name'] ?? ''));
        $this->assertSame('पाटबुंधारे सोसायटी', (string) ($core['father_occupation'] ?? ''));
        $this->assertSame('नंदा बाळासाहेब सुंबे', (string) ($core['mother_name'] ?? ''));
        $this->assertSame('गहिणी', (string) ($core['mother_occupation'] ?? ''));

        $this->assertNotNull($core['address_line'] ?? null);
        $addrLine = (string) ($core['address_line'] ?? '');
        $this->assertStringContainsString('घर नं.', $addrLine);
        $this->assertTrue(
            str_contains($addrLine, '३७') || str_contains($addrLine, '37'),
            'house number preserved (Devanagari or ASCII digits after OCR normalize)'
        );
        $this->assertStringContainsString('सोलापूर', $addrLine);
        $this->assertStringNotContainsString('वडील', (string) ($core['address_line'] ?? ''));

        $this->assertSame('गोरा', (string) ($core['complexion'] ?? ''));

        $this->assertNotNull($core['kuldaivat'] ?? null);
        $this->assertStringContainsString('पालीचा खुंडोबा', (string) ($core['kuldaivat'] ?? ''));

        $h = $out['horoscope'][0] ?? [];
        $this->assertSame('कन्या', $h['rashi'] ?? null);
        $this->assertSame('व्याघ्र', $h['yoni'] ?? null);
        $this->assertStringContainsString('पालीचा खुंडोबा', (string) ($h['kuldaivat'] ?? ''));

        $sibs = $out['siblings'] ?? [];
        $this->assertNotEmpty($sibs);
        $bro = $sibs[0];
        $this->assertSame('brother', $bro['relation_type'] ?? null);
        $this->assertSame('सुरज बाळासाहेब सुंबे', (string) ($bro['name'] ?? ''));
        $this->assertSame('B.com', (string) ($bro['occupation'] ?? ''));

        $sec = $out['relatives_sectioned'] ?? [];
        $mama = $sec['maternal']['mama'] ?? [];
        $chulte = $sec['paternal']['chulte'] ?? [];
        $this->assertNotEmpty($mama, 'maternal.mama should contain मामा row');
        $this->assertNotEmpty($chulte, 'paternal.chulte should contain चुलते row');
        $this->assertNotNull($mama[0]['name'] ?? null);
        $this->assertSame('दशरथ बबन गगे', (string) ($mama[0]['name'] ?? ''));
        $this->assertStringContainsString('आंबी खालसा', (string) ($mama[0]['location'] ?? ''));
        $this->assertStringContainsString('चुलते व्यक्ती', (string) (($chulte[0]['name'] ?? '') ?: ($chulte[0]['raw_note'] ?? '')));

        $addr0 = (string) (($out['addresses'][0] ?? [])['address_line'] ?? '');
        $this->assertStringContainsString('घर नं.', $addr0);
    }
}
