<?php

namespace Tests\Feature\Intake;

use App\Services\BiodataParserService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class IntakeEducationParserRegressionTest extends TestCase
{
    public function test_marathi_bcom_label_extracts_candidate_education(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nशिक्षण: B.Com\nनोकरी: Sample Business");

        $this->assertSame('B.Com', $this->candidateEducation($parsed));
    }

    public function test_marathi_ba_llb_label_extracts_candidate_education(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nशिक्षण: B.A., L.L.B.\nव्यवसाय: वकीली");

        $this->assertSame('B.A., L.L.B', $this->candidateEducation($parsed));
    }

    public function test_marathi_engineering_mtech_label_extracts_candidate_education(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nशिक्षण: B.E Mechanical / M.Tech Design");

        $this->assertSame('B.E Mechanical / M.Tech Design', $this->candidateEducation($parsed));
    }

    public function test_english_education_label_extracts_candidate_education(): void
    {
        $parsed = $this->parse("Name: Synthetic Education Candidate\nEducation: MBA Finance");

        $this->assertSame('MBA Finance', $this->candidateEducation($parsed));
    }

    public function test_english_qualification_label_extracts_candidate_education(): void
    {
        $parsed = $this->parse("Name: Synthetic Qualification Candidate\nQualification: MCA");

        $this->assertSame('MCA', $this->candidateEducation($parsed));
    }

    public function test_father_education_is_not_candidate_education(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nवडिलांचे शिक्षण: B.Sc Agriculture");

        $this->assertNull($this->candidateEducation($parsed));
    }

    public function test_brother_education_is_not_candidate_education(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nभाऊचे शिक्षण: B.E. E&TC");

        $this->assertNull($this->candidateEducation($parsed));
    }

    public function test_expectation_text_is_not_candidate_education(): void
    {
        $parsed = $this->parse("Name: Synthetic Expectation Candidate\nअपेक्षा: Engineer, MBA");

        $this->assertNull($this->candidateEducation($parsed));
    }

    public function test_job_line_is_not_candidate_education(): void
    {
        $parsed = $this->parse("Name: Synthetic Job Candidate\nOccupation: Software Engineer");

        $this->assertNull($this->candidateEducation($parsed));
    }

    public function test_regression_command_compares_normalized_education_text(): void
    {
        $path = $this->writeDataset([
            'case_id' => 'education_normalized_case',
            'layout_type' => 'single_column',
            'language' => 'en',
            'ocr_text' => "Name: Synthetic Normalized Candidate\nEducation: B.E. Mechanical / M.Tech Design",
            'parser_expected_fields' => [
                'education' => 'BE Mechanical MTech Design',
            ],
        ]);

        try {
            $exitCode = Artisan::call('intake:ocr-regression', [
                '--dataset' => $path,
                '--field' => 'education',
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
    private function candidateEducation(array $parsed): ?string
    {
        $core = $parsed['core'] ?? [];
        $education = $parsed['education_history'][0] ?? [];
        foreach ([
            $core['highest_education'] ?? null,
            $core['education'] ?? null,
            $education['degree'] ?? null,
            $education['qualification'] ?? null,
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
        $relative = 'storage/app/testing/intake-education-regression-'.uniqid().'.jsonl';
        $path = base_path($relative);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $relative;
    }
}
