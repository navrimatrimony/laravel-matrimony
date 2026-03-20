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

        $contacts = $parsed['contacts'];
        $this->assertNotEmpty($contacts);
        $primary = $contacts[0];
        $this->assertSame('9145206745', $primary['number'] ?? $primary['phone_number'] ?? null);
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
}

