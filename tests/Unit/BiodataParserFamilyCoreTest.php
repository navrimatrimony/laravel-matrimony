<?php

namespace Tests\Unit;

use App\Services\BiodataParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiodataParserFamilyCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_extracts_marathi_family_core_and_primary_contact()
    {
        /** @var BiodataParserService $service */
        $service = $this->app->make(BiodataParserService::class);

        // Simplified Marathi biodata snippet similar to intake 191.
        $rawText = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
वडिलांचे नाव :- श्री. राजेंद्र भाऊराव पाटील
नोकरी :- सासवड माळी शुगर फॅक्टरी
आईचे नाव :- सौ. अनिता राजेंद्र पाटील (गृहिणी)
भाऊ :- समर्थ राजेंद्र पाटील
बहीण :- पूजा नवनाथ कन्हेरे
Contact.No.- 9145206745
TXT;

        $parsed = $service->parse($rawText);

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('core', $parsed);
        $this->assertArrayHasKey('contacts', $parsed);

        $core = $parsed['core'];

        $this->assertArrayHasKey('father_name', $core);
        $this->assertStringContainsString('राजेंद्र भाऊराव पाटील', $core['father_name']);

        $this->assertNull($core['primary_contact_number'] ?? null, 'primary phone is registration-controlled, not biodata OCR');

        $contacts = $parsed['contacts'];
        $this->assertNotEmpty($contacts);
        $nums = array_map(fn ($c) => (string) ($c['number'] ?? $c['phone_number'] ?? ''), $contacts);
        $this->assertContains('9145206745', $nums);
        foreach ($contacts as $c) {
            $this->assertFalse((bool) ($c['is_primary'] ?? false));
            $this->assertSame('alternate', $c['type'] ?? null);
        }
    }

    public function test_bhahi_nahi_line_does_not_create_sister_sibling_row(): void
    {
        /** @var BiodataParserService $service */
        $service = $this->app->make(BiodataParserService::class);

        $rawText = <<<TXT
मुलीचे नाव :- कु. प्राजक्ता सुभाष पानसरे
4 भाऊ :-नाही
4 बहीण १-नाही
मामाचे नाव :- श्री. शिवाजी साळुंखे
TXT;

        $parsed = $service->parse($rawText);
        $siblings = $parsed['siblings'] ?? [];

        $sisters = array_filter($siblings, fn ($r) => ($r['relation_type'] ?? '') === 'sister');
        $this->assertCount(0, $sisters, 'बहीण नाही should not become a named sister row');
    }

    public function test_dob_on_same_line_as_janma_vel_extracts_iso_only(): void
    {
        /** @var BiodataParserService $service */
        $service = $this->app->make(BiodataParserService::class);

        $rawText = <<<'TXT'
मुलीचे नाव :- कु. उदाहरण
जन्म तारीख :- 24/10/1998 जन्म वेळ :- रात्री 09 वा.45 मि.
TXT;

        $parsed = $service->parse($rawText);

        $this->assertSame('1998-10-24', $parsed['core']['date_of_birth'] ?? null);
    }
}

