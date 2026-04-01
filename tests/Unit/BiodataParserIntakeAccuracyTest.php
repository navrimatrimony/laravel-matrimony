<?php

namespace Tests\Unit;

use App\Services\AIParsingService;
use App\Services\BiodataParserService;
use App\Services\ExternalAiParsingService;
use App\Services\Parsing\Parsers\AiFirstBiodataParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiodataParserIntakeAccuracyTest extends TestCase
{
    use RefreshDatabase;

    public function test_female_gender_from_explicit_ling_label_only(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = "मुलीचे नाव :- कु. प्रीती राजेंद्र पाटील\nलिंग :- स्त्री\nवडिलांचे नाव :- श्री. राजेंद्र पाटील";
        $parsed = $service->parse($raw);
        $this->assertSame('female', $parsed['core']['gender'] ?? null);
    }

    public function test_marital_status_inferred_from_avivahit_without_explicit_label(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = "मुलीचे नाव :- कु. प्रीती पाटील\nअविवाहित\nवडिलांचे नाव :- श्री. राजेंद्र पाटील";
        $parsed = $service->parse($raw);
        $this->assertSame('unmarried', $parsed['core']['marital_status'] ?? null);
    }

    public function test_father_occupation_cleanup_removes_nokari_prefix(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलीचे नाव :- कु. प्रीती पाटील
वडिलांचे नाव :- श्री. राजेंद्र पाटील
नोकरी :- सासवड माळी शुगर फॅक्टरी
आईचे नाव :- सौ. अनिता पाटील
Contact.No.- 9145206745
TXT;
        $parsed = $service->parse($raw);
        $fo = $parsed['core']['father_occupation'] ?? '';
        $this->assertStringNotContainsString('नोकरी :-', $fo);
        $this->assertStringNotContainsString('नोकरी >', $fo);
        $this->assertTrue(mb_strlen($fo) > 0);
    }

    public function test_career_amdocs_line_yields_company_and_location_split(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = <<<TXT
मुलाचे नाव :- चि. राहुल
वडिलांचे नाव :- श्री. एक पाटील
नोकरी :- Amdocs Company Magarpatta,Pune
भाऊ :- एक
Contact.No.- 9876543210
TXT;
        $parsed = $service->parse($raw);
        $career = $parsed['career_history'] ?? [];
        $this->assertNotEmpty($career);
        $first = $career[0];
        $this->assertSame('Amdocs Company', $first['company'] ?? null);
        $this->assertNotNull($first['location'] ?? null);
        $this->assertStringContainsString('Magarpatta', (string) ($first['location'] ?? ''));
        $this->assertStringContainsString(', ', (string) ($first['location'] ?? ''), 'Location should be normalized with comma space');
        $this->assertNull($first['job_title'] ?? null);
    }

    public function test_invalid_blood_group_becomes_null(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = "मुलीचे नाव :- कु. प्रीती पाटील\nरक्तगट :- 84४७\nवडिलांचे नाव :- श्री. राजेंद्र पाटील";
        $parsed = $service->parse($raw);
        $confidence = $parsed['confidence_map'] ?? [];
        $this->assertSame(0.0, $confidence['blood_group'] ?? 1.0, 'Invalid blood_group (84४७) must not be accepted; confidence should be MISSING');
    }

    public function test_mama_note_with_multiple_shri_splits_into_multiple_relative_rows(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        // Relative line with 3 "श्री." is kept by sanitizeDocument (isRelativeLine) and split into multiple rows.
        $raw = "मुलीचे नाव :- कु. प्रीती पाटील\nवडिलांचे नाव :- श्री. राजेंद्र पाटील\nमामा श्री. अभय पाटील श्री. विजय पाटील श्री. संजय पाटील\nContact.No.- 9145206745";
        $parsed = $service->parse($raw);
        $relatives = $parsed['relatives'] ?? [];
        $mamaRows = array_values(array_filter($relatives, fn ($r) => ($r['relation_type'] ?? '') === 'मामा'));
        $this->assertGreaterThanOrEqual(2, count($mamaRows), 'Expected multiple मामा rows when note contains 3+ श्री. (line kept by isRelativeLine, then split)');
    }

    public function test_relative_line_with_three_shri_kept_and_produces_multiple_rows(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = "मुलाचे नाव :- चि. राहुल\nवडिलांचे नाव :- श्री. एक पाटील\nचुलते श्री. X पाटील श्री. Y पाटील श्री. Z पाटील\nContact.No.- 9876543210";
        $parsed = $service->parse($raw);
        $relatives = $parsed['relatives'] ?? [];
        $chulteRows = array_values(array_filter($relatives, fn ($r) => ($r['relation_type'] ?? '') === 'चुलते'));
        $this->assertGreaterThanOrEqual(2, count($chulteRows), 'Relative line with 3 श्री. and relation keyword must survive sanitize and yield multiple rows');
    }

    public function test_non_relative_line_with_many_shri_still_filtered(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = "मुलीचे नाव :- कु. प्रीती पाटील\nवडिलांचे नाव :- श्री. राजेंद्र पाटील\nमामा श्री. अभय श्री. विजय श्री. संजय\nनको श्री. पहिला श्री. दुसरा श्री. तिसरा\nContact.No.- 9145206745";
        $parsed = $service->parse($raw);
        $relatives = $parsed['relatives'] ?? [];
        $mamaRows = array_values(array_filter($relatives, fn ($r) => ($r['relation_type'] ?? '') === 'मामा'));
        $otherRows = array_values(array_filter($relatives, fn ($r) => ($r['relation_type'] ?? '') === 'नको'));
        $this->assertGreaterThanOrEqual(2, count($mamaRows), 'मामा line with 3 श्री must be kept');
        $this->assertCount(0, $otherRows, 'Non-relative noisy line with >2 श्री must be dropped by sanitizeDocument');
    }

    public function test_ai_parsing_service_clean_occupation_label_strips_prefix(): void
    {
        $this->assertNull(AIParsingService::cleanOccupationLabel(null));
        $this->assertSame('Amdocs Company Pune', AIParsingService::cleanOccupationLabel('नोकरी :- Amdocs Company Pune'));
        $this->assertSame('Saswad Mali Sugar Factory', AIParsingService::cleanOccupationLabel('नोकरी > Saswad Mali Sugar Factory'));
    }

    public function test_marker_only_relative_rows_discarded(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = "मुलीचे नाव :- कु. प्रीती पाटील\nवडिलांचे नाव :- श्री. राजेंद्र पाटील\nदाजी *\nचुलते 2-\nमामा +\nमामा श्री. अभय पाटील\nContact.No.- 9145206745";
        $parsed = $service->parse($raw);
        $relatives = $parsed['relatives'] ?? [];
        $rawNotes = array_column($relatives, 'raw_note');
        $this->assertNotContains('दाजी *', $rawNotes, 'Marker-only row "दाजी *" must be discarded');
        $this->assertNotContains('चुलते 2-', $rawNotes, 'Marker-only row "चुलते 2-" must be discarded');
        $this->assertNotContains('मामा +', $rawNotes, 'Marker-only row "मामा +" must be discarded');
        $mamaWithContent = array_filter($relatives, fn ($r) => ($r['relation_type'] ?? '') === 'मामा' && (trim((string) ($r['raw_note'] ?? '')) !== '' || ($r['name'] ?? null) !== null));
        $this->assertNotEmpty($mamaWithContent, 'Valid मामा row with श्री. content must remain');
    }

    public function test_sister_line_with_only_relation_count_honorific_does_not_create_fake_row(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = "मुलीचे नाव :- कु. प्रीती पाटील\nवडिलांचे नाव :- श्री. राजेंद्र पाटील\nबहीण 2 सौ\nContact.No.- 9145206745";
        $parsed = $service->parse($raw);
        $siblings = $parsed['siblings'] ?? [];
        $sisters = array_values(array_filter($siblings, fn ($s) => ($s['relation_type'] ?? '') === 'sister'));
        foreach ($sisters as $s) {
            $name = $s['name'] ?? '';
            $this->assertStringNotContainsString('बहीण 2 सौ', $name, 'Sister name must not be relation+count+honorific only');
            $this->assertNotSame('सौ', trim($name));
        }
        $this->assertCount(0, $sisters, 'No sister row when only "बहीण 2 सौ" (no real name) present');
    }

    public function test_invalid_horoscope_junk_does_not_populate_devak_kul_gotra(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = "मुलीचे नाव :- कु. प्रीती पाटील\nवडिलांचे नाव :- श्री. राजेंद्र पाटील\nगोत्र :- 84४७\nकुल :- A+\nदेवक :- रक्तगट\nContact.No.- 9145206745";
        $parsed = $service->parse($raw);
        $horoscope = $parsed['horoscope'] ?? [];
        $row = is_array($horoscope) && isset($horoscope[0]) ? $horoscope[0] : [];
        $this->assertNull($row['gotra'] ?? null, 'Gotra must be null when value is numeric junk (84४७)');
        $this->assertNull($row['kuldaivat'] ?? null, 'Kuldaivat must be null when value is blood-group-like (A+)');
        $this->assertNull($row['devak'] ?? null, 'Devak must be null when value contains रक्तगट label');
    }

    public function test_horoscope_junk_filtered(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = "मुलीचे नाव :- कु. प्रीती पाटील\nवडिलांचे नाव :- श्री. राजेंद्र पाटील\nदेवक :- + वासनिचा वेल रक्त गट :- 8447\nकुल :- ी मराठा.\nगोत्र :- 8447\nContact.No.- 9145206745";
        $parsed = $service->parse($raw);
        $horoscope = $parsed['horoscope'] ?? [];
        $row = is_array($horoscope) && isset($horoscope[0]) ? $horoscope[0] : [];
        $this->assertNull($row['devak'] ?? null, 'Devak must be null for junk containing रक्त and leading +');
        $this->assertNull($row['kuldaivat'] ?? null, 'Kuldaivat must be null for junk like "ी मराठा."');
        $this->assertNull($row['gotra'] ?? null, 'Gotra must be null for numeric junk 8447');
    }

    public function test_relative_structured_with_name_and_location(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = "मुलीचे नाव :- कु. प्रीती पाटील\nवडिलांचे नाव :- श्री. राजेंद्र पाटील\nमामा श्री. दिलीप प्रभाकर जाधव (सवतगाव, माळीनगर)\nContact.No.- 9145206745";
        $parsed = $service->parse($raw);
        $relatives = $parsed['relatives'] ?? [];
        $mamaRows = array_values(array_filter($relatives, fn ($r) => ($r['relation_type'] ?? '') === 'मामा'));
        $this->assertNotEmpty($mamaRows, 'Expected at least one मामा relative');
        $first = $mamaRows[0];
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('location', $first);
        $this->assertArrayHasKey('raw_note', $first);
        $this->assertNotNull($first['name'] ?? null, 'Structured relative should have extracted name');
        $this->assertStringContainsString('दिलीप', (string) ($first['name'] ?? ''));
        $this->assertSame('सवतगाव, माळीनगर', $first['location'] ?? null, 'Location should be extracted from (city) pattern');
    }

    public function test_multiple_relatives_split_and_structured(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = "मुलाचे नाव :- चि. राहुल\nवडिलांचे नाव :- श्री. एक पाटील\nमामा श्री. अभय पाटील श्री. विजय पाटील\nContact.No.- 9876543210";
        $parsed = $service->parse($raw);
        $relatives = $parsed['relatives'] ?? [];
        $mamaRows = array_values(array_filter($relatives, fn ($r) => ($r['relation_type'] ?? '') === 'मामा'));
        $this->assertGreaterThanOrEqual(2, count($mamaRows), 'मामा श्री. A श्री. B should yield multiple rows');
        foreach ($mamaRows as $r) {
            $this->assertArrayHasKey('relation_type', $r);
            $this->assertArrayHasKey('raw_note', $r);
        }
    }

    public function test_itara_natevaik_goes_to_other_relatives_text_not_relatives(): void
    {
        $service = $this->app->make(BiodataParserService::class);
        $raw = "मुलाचे नाव :- चि. राहुल\nवडिलांचे नाव :- श्री. एक पाटील\nइतर नातेवाईक :- यादव (करमाळा), यादव (सोलापुर), भोसले (मोहोळ), पवार (पिंपळनेर)";
        $parsed = $service->parse($raw);
        $core = $parsed['core'] ?? [];
        $this->assertArrayHasKey('other_relatives_text', $core, 'इतर नातेवाईक block must populate core.other_relatives_text');
        $text = $core['other_relatives_text'] ?? '';
        $this->assertStringContainsString('यादव', $text);
        $this->assertStringContainsString('भोसले', $text);
        $this->assertStringContainsString('करमाळा', $text);
        $relatives = $parsed['relatives'] ?? [];
        $itarRows = array_values(array_filter($relatives, fn ($r) => ($r['relation_type'] ?? '') === 'इतर'));
        $this->assertCount(0, $itarRows, 'इतर नातेवाईक must not create relative rows; they go to Other Relatives engine only');
    }

    public function test_sanitize_horoscope_value_filters_junk(): void
    {
        $this->assertNull(BiodataParserService::sanitizeHoroscopeValue('+ वासनिचा वेल रक्त गट :- 8447'));
        $this->assertNull(BiodataParserService::sanitizeHoroscopeValue('ी मराठा.'));
        $this->assertNull(BiodataParserService::sanitizeHoroscopeValue('8447'));
        $this->assertNull(BiodataParserService::sanitizeHoroscopeValue('Blood group'));
        $this->assertNull(BiodataParserService::sanitizeHoroscopeValue('देवक :- + वासनिचा वेल रक्त गट :- 8447'));
    }

    public function test_sanitize_horoscope_value_rejects_blood_group_mixed_junk(): void
    {
        // ZWJ variant (रक्‍त) and space-split "रक्त गट" must be nulled
        $this->assertNull(BiodataParserService::sanitizeHoroscopeValue('वासनिचा वेल रक्‍त गट :- 8447'));
        $this->assertNull(BiodataParserService::sanitizeHoroscopeValue('वासनिचा वेल रक्त गट :- 8447'));
        $this->assertNull(BiodataParserService::sanitizeHoroscopeValue('blood group 8447'));
    }

    public function test_sanitize_blood_group_value_accepts_only_valid_normalizes_whitespace(): void
    {
        $this->assertNull(BiodataParserService::sanitizeBloodGroupValue('84४७'), 'OCR/numeric junk must become null');
        $this->assertSame('A+', BiodataParserService::sanitizeBloodGroupValue('A+'));
        $this->assertSame('AB+', BiodataParserService::sanitizeBloodGroupValue('ab +'), 'Normalize case and strip space');
        $this->assertSame('O-', BiodataParserService::sanitizeBloodGroupValue('o -'));
        $this->assertNull(BiodataParserService::sanitizeBloodGroupValue(null));
        $this->assertNull(BiodataParserService::sanitizeBloodGroupValue(''));
    }

    public function test_ai_first_final_output_sanitizes_horoscope_junk(): void
    {
        $junkHoroscope = [
            [
                'devak' => '+ वासनिचा वेल रक्त गट :- 8447',
                'kuldaivat' => 'ी मराठा.',
                'gotra' => '8447',
                'blood_group' => '84४७',
                'rashi_id' => null,
                'nakshatra_id' => null,
            ],
        ];
        $aiResult = [
            'core' => ['full_name' => 'Test', 'gender' => 'female'],
            'confidence_map' => [],
            'contacts' => [],
            'horoscope' => $junkHoroscope,
            'relatives' => [],
            'siblings' => [],
            'career_history' => [],
        ];
        $this->mock(ExternalAiParsingService::class, function ($mock) use ($aiResult) {
            $mock->shouldReceive('parseToSsot')->andReturn($aiResult);
        });
        $parser = $this->app->make(AiFirstBiodataParser::class);
        $result = $parser->parse('मुलीचे नाव :- कु. टेस्ट', []);
        $horoscope = $result['horoscope'] ?? [];
        $this->assertNotEmpty($horoscope, 'Horoscope row should exist');
        $row = $horoscope[0];
        $this->assertNull($row['devak'] ?? null, 'AI-first final output must sanitize devak junk');
        $this->assertNull($row['kuldaivat'] ?? null, 'AI-first final output must sanitize kuldaivat junk');
        $this->assertNull($row['gotra'] ?? null, 'AI-first final output must sanitize gotra junk');
        $this->assertArrayNotHasKey('blood_group', $row, 'blood_group must not appear on horoscope rows (core-only).');
    }
}
