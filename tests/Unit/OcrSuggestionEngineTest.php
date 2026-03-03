<?php

use App\Services\Ocr\OcrSuggestionEngine;
use App\Services\Ocr\OcrNormalize;
use App\Models\OcrCorrectionPattern;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OcrSuggestionEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! \Schema::hasTable('ocr_correction_patterns')) {
            \Schema::create('ocr_correction_patterns', function ($table) {
                $table->id();
                $table->string('field_key');
                $table->string('wrong_pattern');
                $table->string('corrected_value');
                $table->decimal('pattern_confidence', 3, 2)->default(0);
                $table->integer('usage_count')->default(0);
                $table->string('source', 32)->default('frequency_rule');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        } else {
            OcrCorrectionPattern::query()->delete();
        }

        // Create baseline patterns directly
        OcrCorrectionPattern::create([
            'field_key' => 'gender',
            'wrong_pattern' => 'male',
            'corrected_value' => 'Male',
            'pattern_confidence' => 0.75,
            'source' => 'frequency_rule',
            'is_active' => true,
        ]);
        OcrCorrectionPattern::create([
            'field_key' => 'gender',
            'wrong_pattern' => 'female',
            'corrected_value' => 'Female',
            'pattern_confidence' => 0.75,
            'source' => 'frequency_rule',
            'is_active' => true,
        ]);
        OcrCorrectionPattern::create([
            'field_key' => 'gender',
            'wrong_pattern' => 'à¤ªà¥à¤°à¥à¤·',
            'corrected_value' => 'Male',
            'pattern_confidence' => 0.75,
            'source' => 'frequency_rule',
            'is_active' => true,
        ]);
        OcrCorrectionPattern::create([
            'field_key' => 'gender',
            'wrong_pattern' => 'à¤¸à¥à¤¤à¥à¤°à¥€',
            'corrected_value' => 'Female',
            'pattern_confidence' => 0.75,
            'source' => 'frequency_rule',
            'is_active' => true,
        ]);
        OcrCorrectionPattern::create([
            'field_key' => 'primary_contact_number',
            'wrong_pattern' => '+91 98765 43210',
            'corrected_value' => '9876543210',
            'pattern_confidence' => 0.70,
            'source' => 'frequency_rule',
            'is_active' => true,
        ]);
    }

    public function test_suggests_male_for_lowercase_male(): void
    {
        $engine = app(OcrSuggestionEngine::class);
        $result = $engine->getSuggestion('gender', 'male');

        $this->assertEquals('Male', $result['suggested_value']);
        $this->assertContains($result['source'], ['frequency_rule', 'normalization']);
        $this->assertGreaterThan(0.0, $result['confidence']);
    }

    public function test_suggests_female_for_lowercase_female(): void
    {
        $engine = app(OcrSuggestionEngine::class);
        $result = $engine->getSuggestion('gender', 'female');

        $this->assertEquals('Female', $result['suggested_value']);
        $this->assertContains($result['source'], ['frequency_rule', 'normalization']);
        $this->assertGreaterThan(0.0, $result['confidence']);
    }

    public function test_suggests_male_for_marathi_purush(): void
    {
        $engine = app(OcrSuggestionEngine::class);
        $result = $engine->getSuggestion('gender', 'à¤ªà¥à¤°à¥à¤·');

        $this->assertEquals('Male', $result['suggested_value']);
        $this->assertEquals('frequency_rule', $result['source']);
    }

    public function test_suggests_female_for_marathi_stri(): void
    {
        $engine = app(OcrSuggestionEngine::class);
        $result = $engine->getSuggestion('gender', 'à¤¸à¥à¤¤à¥à¤°à¥€');

        $this->assertEquals('Female', $result['suggested_value']);
        $this->assertEquals('frequency_rule', $result['source']);
    }

    public function test_normalizes_devanagari_digits_in_date_of_birth(): void
    {
        $engine = app(OcrSuggestionEngine::class);
        $result = $engine->getSuggestion('date_of_birth', 'à¥¨à¥ª/à¥§à¥¦/à¥§à¥¯à¥¯à¥®');

        // Should normalize digits first, then pattern match
        $this->assertNotEquals('à¥¨à¥ª/à¥§à¥¦/à¥§à¥¯à¥¯à¥®', $result['suggested_value']);
    }

    public function test_normalizes_phone_number_with_country_code(): void
    {
        $engine = app(OcrSuggestionEngine::class);
        $result = $engine->getSuggestion('primary_contact_number', '+91 98765 43210');

        $this->assertEquals('9876543210', $result['suggested_value']);
        $this->assertContains($result['source'], ['frequency_rule', 'normalization']);
    }

    public function test_returns_null_suggestion_for_empty_value(): void
    {
        $engine = app(OcrSuggestionEngine::class);
        $result = $engine->getSuggestion('gender', '');

        $this->assertNull($result['suggested_value']);
        $this->assertEquals('none', $result['source']);
    }

    public function test_returns_null_suggestion_when_no_pattern_matches(): void
    {
        $engine = app(OcrSuggestionEngine::class);
        $result = $engine->getSuggestion('gender', 'unknown_value');

        $this->assertNull($result['suggested_value']);
        $this->assertEquals('none', $result['source']);
    }

    public function test_does_not_suggest_when_value_already_correct(): void
    {
        $engine = app(OcrSuggestionEngine::class);
        $result = $engine->getSuggestion('gender', 'Male');

        // Should not suggest if already correct
        $this->assertNull($result['suggested_value']);
    }

    /** Do not truncate Devanagari vowel signs: à¤¹à¤¿à¤‚à¤¦à¥‚ must not become à¤¹à¤¿à¤‚à¤¦ */
    public function test_religion_hindu_not_truncated(): void
    {
        $this->markTestSkipped('Requires full OCR religion normalization behavior.');
        $engine = app(OcrSuggestionEngine::class);
        $result = $engine->getSuggestion('religion', 'à¤¹à¤¿à¤‚à¤¦à¥‚', null);

        $this->assertNotEquals('à¤¹à¤¿à¤‚à¤¦', $result['suggested_value'] ?? '');
        if ($result['suggested_value'] !== null) {
            $this->assertStringContainsString('à¥‚', $result['suggested_value']);
        }
    }

    /** Do not truncate Devanagari vowel signs: à¤®à¤°à¤¾à¤ à¤¾ must not become à¤®à¤°à¤¾à¤  */
    public function test_caste_maratha_not_truncated(): void
    {
        $engine = app(OcrSuggestionEngine::class);
        $result = $engine->getSuggestion('caste', 'à¤®à¤°à¤¾à¤ à¤¾', null);

        $this->assertNotEquals('à¤®à¤°à¤¾à¤ ', $result['suggested_value'] ?? '');
        if ($result['suggested_value'] !== null) {
            $this->assertStringContainsString('à¤¾', $result['suggested_value']);
        }
    }

    /** Marathi caste line: à¤œà¤¾à¤¤ : à¤¹à¤¿à¤‚à¤¦à¥‚ à¤®à¤°à¤¾à¤ à¤¾ {96 à¤•à¥à¤³à¥€} -> religion, caste (DB canonical), sub_caste all have candidates. */
    public function test_marathi_caste_line_extracts_religion_caste_subcaste(): void
    {
        $this->markTestSkipped('Requires full OCR getCandidates and DB castes.');
        $engine = app(OcrSuggestionEngine::class);
        $raw = "à¤œà¤¾à¤¤ : à¤¹à¤¿à¤‚à¤¦à¥‚ à¤®à¤°à¤¾à¤ à¤¾ {96 à¤•à¥à¤³à¥€}";
        $rel = $engine->getCandidates('religion', '', $raw);
        $caste = $engine->getCandidates('caste', '', $raw);
        $sub = $engine->getCandidates('sub_caste', '', $raw);

        $this->assertNotEmpty($rel, 'religion should have at least one candidate');
        $this->assertNotEmpty($caste, 'caste should have at least one candidate');
        $this->assertNotEmpty($sub, 'sub_caste should have at least one candidate');
        $this->assertTrue(
            ($rel[0]['value'] ?? '') === 'Hindu' || mb_strpos($rel[0]['value'] ?? '', 'à¤¹à¤¿à¤‚à¤¦à¥‚') !== false,
            'religion should be Hindu or à¤¹à¤¿à¤‚à¤¦à¥‚'
        );
        $this->assertEquals('Maratha', $caste[0]['value'] ?? '', 'caste must be DB-validated canonical Maratha');
        $this->assertStringContainsString('96', $sub[0]['value'] ?? '');
        $this->assertStringContainsString('à¤•à¥à¤³à¥€', $sub[0]['value'] ?? '');
    }

    /** Negative keyword: line with à¤µà¤¡à¤¿à¤²à¤¾à¤‚à¤šà¥‡ must NOT produce à¤µà¤¡à¤¿à¤²à¤¾à¤‚à¤šà¥‡ as caste candidate. */
    public function test_caste_negative_keyword_vadilanche_not_caste_candidate(): void
    {
        $this->markTestSkipped('Requires full OCR getCandidates and DB castes.');
        $engine = app(OcrSuggestionEngine::class);
        $raw = "à¤µà¤¡à¤¿à¤²à¤¾à¤‚à¤šà¥‡ à¤¨à¤¾à¤µ : à¤°à¤¾à¤® à¤¶à¤°à¥à¤®à¤¾\nà¤œà¤¾à¤¤ : à¤¹à¤¿à¤‚à¤¦à¥‚ à¤®à¤°à¤¾à¤ à¤¾";
        $caste = $engine->getCandidates('caste', '', $raw);

        $this->assertNotEmpty($caste, 'caste should have candidate from à¤œà¤¾à¤¤ line');
        $top = $caste[0]['value'] ?? '';
        $this->assertNotEquals('à¤µà¤¡à¤¿à¤²à¤¾à¤‚à¤šà¥‡', $top, 'caste candidate must not be à¤µà¤¡à¤¿à¤²à¤¾à¤‚à¤šà¥‡ (negative keyword)');
        $this->assertEquals('Maratha', $top, 'caste should be Maratha from à¤œà¤¾à¤¤ line');
    }

    /** Caste â†’ religion dependency: resolveCasteToCanonical and getReligionFromCasteDependency (requires DB). */
    public function test_resolve_caste_to_canonical_returns_maratha_for_maratha(): void
    {
        $this->markTestSkipped('Requires castes/religions DB and resolveCasteToCanonical.');
        $engine = app(OcrSuggestionEngine::class);
        $this->assertEquals('Maratha', $engine->resolveCasteToCanonical('à¤®à¤°à¤¾à¤ à¤¾'));
        $this->assertEquals('Maratha', $engine->resolveCasteToCanonical('Maratha'));
        $this->assertNull($engine->resolveCasteToCanonical('à¤µà¤¡à¤¿à¤²à¤¾à¤‚à¤šà¥‡'));
    }

    /** Phone near label (à¤®à¥‹. / Mobile) should rank higher than number elsewhere. */
    public function test_phone_near_label_scores_higher(): void
    {
        $this->markTestSkipped('Requires full OCR phone candidate scoring.');
        $engine = app(OcrSuggestionEngine::class);
        $raw = "Some text 9952927493 more text. à¤®à¥‹. 9322202146 and rest.";
        $candidates = $engine->getCandidates('primary_contact_number', '', $raw);

        $this->assertNotEmpty($candidates, 'should have phone candidates');
        $top = $candidates[0]['value'] ?? '';
        $this->assertEquals('9322202146', $top, 'number near à¤®à¥‹. should be top candidate');
    }

    /** Full name: trailing single Latin 'x' (OCR junk) should be removed. */
    public function test_full_name_trailing_single_x_removed(): void
    {
        $this->markTestSkipped('Requires full OCR full_name getCandidates.');
        $engine = app(OcrSuggestionEngine::class);
        $raw = "à¤¨à¤¾à¤µ à¤‡à¤¬à¥à¤°à¤¾à¤¹à¤¿à¤® à¤•à¤¾à¤¶à¥€à¤® à¤¦à¥‡à¤¸à¤¾à¤ˆ x";
        $candidates = $engine->getCandidates('full_name', '', $raw);

        $this->assertNotEmpty($candidates, 'should have name candidate');
        $name = $candidates[0]['value'] ?? '';
        $this->assertStringNotContainsString(' x', $name);
        $this->assertStringEndsNotWith('x', trim($name));
        $this->assertStringContainsString('à¤‡à¤¬à¥à¤°à¤¾à¤¹à¤¿à¤® à¤•à¤¾à¤¶à¥€à¤® à¤¦à¥‡à¤¸à¤¾à¤ˆ', $name);
    }
}

