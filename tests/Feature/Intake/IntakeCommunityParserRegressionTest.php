<?php

namespace Tests\Feature\Intake;

use App\Services\BiodataParserService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class IntakeCommunityParserRegressionTest extends TestCase
{
    public function test_marathi_religion_label_extracts_normalized_religion(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nधर्म: हिंदू\nजात: मराठा");

        $this->assertSame('हिंदू', $this->core($parsed, 'religion'));
    }

    public function test_english_religion_label_extracts_normalized_religion(): void
    {
        $parsed = $this->parse("Name: Synthetic Candidate\nReligion: Hindu\nCaste: Maratha");

        $this->assertSame('हिंदू', $this->core($parsed, 'religion'));
    }

    public function test_hindu_maratha_caste_line_splits_religion_and_caste(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nजात: हिंदू मराठा");

        $this->assertSame('हिंदू', $this->core($parsed, 'religion'));
        $this->assertSame('मराठा', $this->core($parsed, 'caste'));
    }

    public function test_hyphenated_hindu_maratha_caste_line_splits_religion_and_caste(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nजात: हिंदू-मराठा");

        $this->assertSame('हिंदू', $this->core($parsed, 'religion'));
        $this->assertSame('मराठा', $this->core($parsed, 'caste'));
    }

    public function test_marathi_upajat_label_extracts_normalized_sub_caste(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nजात: मराठा\nउपजात: 96 कुळी");

        $this->assertSame('96 कुळी', $this->core($parsed, 'sub_caste'));
    }

    public function test_marathi_potjat_label_extracts_normalized_devanagari_sub_caste(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nजात: मराठा\nपोटजात: ९६ कूळी");

        $this->assertSame('96 कुळी', $this->core($parsed, 'sub_caste'));
    }

    public function test_caste_line_with_kuli_maratha_splits_caste_and_sub_caste(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nजात: 96 कुळी मराठा");

        $this->assertSame('मराठा', $this->core($parsed, 'caste'));
        $this->assertSame('96 कुळी', $this->core($parsed, 'sub_caste'));
    }

    public function test_standalone_kuli_maratha_line_splits_caste_and_sub_caste(): void
    {
        $marathi = $this->parse("नाव: नमुना उमेदवार\n96 कुळी मराठा");
        $english = $this->parse("Name: Synthetic Candidate\n96 Kuli Maratha");

        $this->assertSame('मराठा', $this->core($marathi, 'caste'));
        $this->assertSame('96 कुळी', $this->core($marathi, 'sub_caste'));
        $this->assertSame('मराठा', $this->core($english, 'caste'));
        $this->assertSame('96 कुळी', $this->core($english, 'sub_caste'));
    }

    public function test_sub_caste_does_not_include_caste_word(): void
    {
        $parsed = $this->parse("Name: Synthetic Candidate\nSub caste: 96 Kuli Maratha");

        $this->assertSame('मराठा', $this->core($parsed, 'caste'));
        $this->assertSame('96 कुळी', $this->core($parsed, 'sub_caste'));
        $this->assertStringNotContainsString('मराठा', (string) $this->core($parsed, 'sub_caste'));
        $this->assertStringNotContainsString('Maratha', (string) $this->core($parsed, 'sub_caste'));
    }

    public function test_maratha_caste_alone_does_not_force_religion(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nजात: मराठा");

        $this->assertSame('मराठा', $this->core($parsed, 'caste'));
        $this->assertNull($this->core($parsed, 'religion'));
    }

    public function test_phone_date_pincode_and_income_are_not_sub_caste(): void
    {
        foreach (['9876543210', '02/10/1998', '416416', '16,75,000 P/A'] as $value) {
            $parsed = $this->parse("नाव: नमुना उमेदवार\nजात: मराठा\nउपजात: {$value}");

            $this->assertNull($this->core($parsed, 'sub_caste'), "Unexpected sub_caste for {$value}");
        }
    }

    public function test_family_and_expectation_community_lines_do_not_fill_candidate_fields(): void
    {
        $family = $this->parse("नाव: नमुना उमेदवार\nवडिलांची जात: हिंदू मराठा\nउपजात: 96 कुळी");
        $expectation = $this->parse("नाव: नमुना उमेदवार\nअपेक्षा: जात हिंदू मराठा, 96 कुळी");

        $this->assertNull($this->core($family, 'religion'));
        $this->assertNull($this->core($expectation, 'religion'));
        $this->assertNull($this->core($expectation, 'sub_caste'));
    }

    public function test_regression_command_compares_religion_and_sub_caste_normalized_forms(): void
    {
        $path = $this->writeDataset([
            'case_id' => 'community_normalized_case',
            'layout_type' => 'single_column',
            'language' => 'mr-en',
            'ocr_text' => "Name: Synthetic Community Candidate\nReligion: Hindu\nजात: ९६ कूळी मराठा",
            'parser_expected_fields' => [
                'religion' => 'HINDU',
                'sub_caste' => '96 Kuli Maratha',
            ],
        ]);

        try {
            $exitCode = Artisan::call('intake:ocr-regression', [
                '--dataset' => $path,
                '--json' => true,
            ]);
            $payload = json_decode(trim(Artisan::output()), true);

            $this->assertSame(0, $exitCode);
            $this->assertSame(2, $payload['summary']['total_expected_fields'] ?? null);
            $this->assertSame(2, $payload['summary']['exact_match_count'] ?? null);
            $this->assertEquals(100.0, $payload['summary']['overall_accuracy_percent'] ?? null);
        } finally {
            File::delete(base_path($path));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $text): array
    {
        return app(BiodataParserService::class)->parse($text);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function core(array $parsed, string $key): ?string
    {
        $value = $parsed['core'][$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function writeDataset(array $case): string
    {
        $relative = 'storage/app/testing/intake-community-regression-'.uniqid().'.jsonl';
        $path = base_path($relative);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $relative;
    }
}
