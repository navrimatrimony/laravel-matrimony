<?php

namespace Tests\Feature\Intake;

use App\Services\BiodataParserService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class IntakeOccupationParserRegressionTest extends TestCase
{
    public function test_marathi_business_label_extracts_candidate_occupation(): void
    {
        $parsed = $this->parse("नाव :- अनिल नमुना पाटील\nव्यवसाय: साईनाथ गोल्ड अँड सिल्वर टेस्टिंग व बुलियन\nशिक्षण :- B Com");

        $this->assertSame('साईनाथ गोल्ड अँड सिल्वर टेस्टिंग व बुलियन', $this->candidateOccupation($parsed));
    }

    public function test_marathi_job_label_extracts_candidate_occupation(): void
    {
        $parsed = $this->parse("नाव :- कविता नमुना पाटील\nनोकरी: महाबळ कंपनी लि.\nशिक्षण :- B A");

        $this->assertSame('महाबळ कंपनी लि', $this->candidateOccupation($parsed));
    }

    public function test_english_occupation_label_extracts_candidate_occupation(): void
    {
        $parsed = $this->parse("Name: Synthetic Occupation Candidate\nOccupation: Associate Software Developer\nEducation: B Com");

        $this->assertSame('Associate Software Developer', $this->candidateOccupation($parsed));
    }

    public function test_english_working_as_label_extracts_candidate_occupation(): void
    {
        $parsed = $this->parse("Name: Synthetic Consultant Candidate\nWorking as: SAP Consultant\nEducation: B Sc");

        $this->assertSame('SAP Consultant', $this->candidateOccupation($parsed));
    }

    public function test_bare_marathi_farming_line_extracts_candidate_occupation(): void
    {
        $parsed = $this->parse("नाव :- रोहन नमुना पाटील\nशेती\nशिक्षण :- B A");

        $this->assertSame('शेती', $this->candidateOccupation($parsed));
    }

    public function test_father_occupation_is_not_candidate_occupation(): void
    {
        $parsed = $this->parse("नाव :- पत्ता नमुना पाटील\nवडिलांचा व्यवसाय: शेती\nशिक्षण :- B Com");

        $this->assertNull($this->candidateOccupation($parsed));
    }

    public function test_brother_job_is_not_candidate_occupation(): void
    {
        $parsed = $this->parse("नाव :- दिनांक नमुना पाटील\nभाऊची नोकरी: Software Developer\nशिक्षण :- B Com");

        $this->assertNull($this->candidateOccupation($parsed));
    }

    public function test_expectation_text_is_not_candidate_occupation(): void
    {
        $parsed = $this->parse("Name: Synthetic Expectation Candidate\nअपेक्षा: नोकरी/व्यवसाय\nEducation: B Com");

        $this->assertNull($this->candidateOccupation($parsed));
    }

    public function test_work_location_alone_is_not_candidate_occupation(): void
    {
        $parsed = $this->parse("Name: Synthetic Work Location Candidate\nकामाचे ठिकाण: पुणे\nEducation: B Com");

        $this->assertNull($this->candidateOccupation($parsed));
    }

    public function test_regression_command_compares_normalized_occupation_text(): void
    {
        $path = $this->writeDataset([
            'case_id' => 'occupation_normalized_case',
            'layout_type' => 'single_column',
            'language' => 'en',
            'ocr_text' => "Name: Synthetic Punctuation Candidate\nOccupation: Associate Software Developer.\nEducation: B Com",
            'parser_expected_fields' => [
                'occupation' => 'associate software developer',
            ],
        ]);

        try {
            $exitCode = Artisan::call('intake:ocr-regression', [
                '--dataset' => $path,
                '--field' => 'occupation',
                '--json' => true,
            ]);
            $payload = json_decode(trim(Artisan::output()), true);

            $this->assertSame(0, $exitCode);
            $this->assertSame(1, $payload['summary']['total_expected_fields'] ?? null);
            $this->assertSame(1, $payload['summary']['exact_match_count'] ?? null);
            $this->assertEquals(100.0, $payload['field_accuracy'][0]['accuracy_percent'] ?? null);
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
    private function candidateOccupation(array $parsed): ?string
    {
        $core = $parsed['core'] ?? [];
        $career = $parsed['career_history'][0] ?? [];
        foreach ([
            $core['occupation_title'] ?? null,
            $core['occupation'] ?? null,
            $core['profession'] ?? null,
            $career['occupation_title'] ?? null,
            $career['designation'] ?? null,
            $career['job_title'] ?? null,
            $career['role'] ?? null,
            $core['company_name'] ?? null,
            $career['company_name'] ?? null,
            $career['employer'] ?? null,
        ] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function writeDataset(array $case): string
    {
        $relative = 'storage/app/testing/intake-occupation-regression-'.uniqid().'.jsonl';
        $path = base_path($relative);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $relative;
    }
}
