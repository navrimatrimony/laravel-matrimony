<?php

namespace Tests\Feature\Intake;

use App\Services\BiodataParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeHeightParserRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_marathi_feet_inches_height_is_extracted(): void
    {
        $this->assertParsedHeight(
            "नाव :- अनिल नमुना पाटील\nउंची: 5 फूट 5 इंच\nशिक्षण :- B Com",
            5,
            5
        );
    }

    public function test_marathi_devanagari_digits_height_is_extracted(): void
    {
        $this->assertParsedHeight(
            "नाव :- कविता नमुना पाटील\nउंची: ५ फूट ३ इंच\nशिक्षण :- B A",
            5,
            3
        );
    }

    public function test_english_ft_in_height_is_extracted(): void
    {
        $this->assertParsedHeight(
            "Name: Synthetic Height Candidate\nHeight: 5 ft 7 in\nEducation: B Com",
            5,
            7
        );
    }

    public function test_decimal_height_is_treated_as_feet_and_inches(): void
    {
        $this->assertParsedHeight(
            "Name: Synthetic Decimal Candidate\nheight: 5.6\nEducation: B Com",
            5,
            6
        );
    }

    public function test_date_is_not_extracted_as_height(): void
    {
        $core = $this->parseCore("नाव :- दिनांक नमुना पाटील\nजन्म तारीख: 05/06/1998\nशिक्षण :- B Com");

        $this->assertEmpty($core['height'] ?? null);
        $this->assertNull($core['height_cm'] ?? null);
    }

    public function test_birth_time_decimal_is_not_extracted_as_height(): void
    {
        $core = $this->parseCore("नाव :- वेळ नमुना पाटील\nजन्म वेळ: सकाळी 5.30\nशिक्षण :- B Com");

        $this->assertEmpty($core['height'] ?? null);
        $this->assertNull($core['height_cm'] ?? null);
    }

    public function test_existing_full_name_and_date_of_birth_parsing_still_works(): void
    {
        $core = $this->parseCore(<<<'TXT'
नाव :- अनिल नमुना पाटील
जन्म तारीख :- 13/06/1998
उंची :- 5 फूट 5 इंच
जात :- मराठा
शिक्षण :- B Com
TXT);

        $this->assertSame('अनिल नमुना पाटील', (string) ($core['full_name'] ?? ''));
        $this->assertSame('1998-06-13', (string) ($core['date_of_birth'] ?? ''));
        $this->assertSame('5 ft 5 in', (string) ($core['height'] ?? ''));
        $this->assertEqualsWithDelta(165.10, (float) ($core['height_cm'] ?? 0), 0.01);
    }

    public function test_parser_output_keeps_height_paths_used_by_ocr_regression_mapping(): void
    {
        $parsed = app(BiodataParserService::class)->parse(
            "Name: Synthetic Structure Candidate\nHeight: 5 feet 11 inches\nEducation: B Com"
        );

        $this->assertArrayHasKey('core', $parsed);
        $this->assertArrayHasKey('height', $parsed['core']);
        $this->assertArrayHasKey('height_cm', $parsed['core']);
        $this->assertSame('5 ft 11 in', (string) $parsed['core']['height']);
        $this->assertEqualsWithDelta(180.34, (float) $parsed['core']['height_cm'], 0.01);
    }

    private function assertParsedHeight(string $text, int $feet, int $inches): void
    {
        $core = $this->parseCore($text);
        $expectedCm = round((($feet * 12) + $inches) * 2.54, 2);

        $this->assertSame($feet.' ft '.$inches.' in', (string) ($core['height'] ?? ''));
        $this->assertEqualsWithDelta($expectedCm, (float) ($core['height_cm'] ?? 0), 0.01);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCore(string $text): array
    {
        $parsed = app(BiodataParserService::class)->parse($text);

        return $parsed['core'] ?? [];
    }
}
