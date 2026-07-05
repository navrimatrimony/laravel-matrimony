<?php

namespace Tests\Feature\Intake;

use App\Services\BiodataParserService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class IntakeAddressParserRegressionTest extends TestCase
{
    public function test_english_address_label_extracts_address(): void
    {
        $parsed = $this->parse("Name: Synthetic Address Candidate\nAddress: Flat 10, Pune\nEducation: B.Com");

        $this->assertSame('Flat 10, Pune', $this->candidateAddress($parsed));
    }

    public function test_marathi_address_label_extracts_address(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nपत्ता: फ्लॅट 10, पुणे\nशिक्षण: B.Com");

        $this->assertSame('फ्लॅट 10, पुणे', $this->candidateAddress($parsed));
    }

    public function test_multiline_address_continuation_is_kept(): void
    {
        $parsed = $this->parse("Name: Synthetic Multiline Candidate\nAddress: Flat 3, Example Heights\nNear Market Road, Pune 411001\nEducation: MBA Finance");

        $address = $this->candidateAddress($parsed);

        $this->assertNotNull($address);
        $this->assertStringContainsString('Example Heights', $address);
        $this->assertStringContainsString('Near Market Road', $address);
    }

    public function test_address_capture_stops_before_strong_label(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nपत्ता: घर नं. 12, सावेडी, अहमदनगर\nशिक्षण: B.Com\nनोकरी: Software Engineer");

        $address = $this->candidateAddress($parsed);

        $this->assertNotNull($address);
        $this->assertStringContainsString('सावेडी', $address);
        $this->assertStringNotContainsString('शिक्षण', $address);
        $this->assertStringNotContainsString('Software Engineer', $address);
    }

    public function test_family_address_is_not_candidate_address(): void
    {
        $parsed = $this->parse("नाव: नमुना उमेदवार\nमामा पत्ता: नातेवाईक गल्ली, पुणे\nशिक्षण: B.Com");

        $this->assertNull($this->candidateAddress($parsed));
    }

    public function test_contact_address_does_not_override_explicit_candidate_address(): void
    {
        $parsed = $this->parse("Name: Synthetic Contact Candidate\nCurrent Address: Candidate Lane, Pune 411001\nContact Address: Reference Office Road, Mumbai 400001\nEducation: B.Com");

        $address = $this->candidateAddress($parsed);

        $this->assertNotNull($address);
        $this->assertStringContainsString('Candidate Lane', $address);
        $this->assertStringNotContainsString('Reference Office', $address);
    }

    public function test_work_location_alone_does_not_become_address(): void
    {
        $parsed = $this->parse("Name: Synthetic Work Candidate\nWork Location: Sample Nagar, Pune\nOccupation: Software Engineer");

        $this->assertNull($this->candidateAddress($parsed));
    }

    public function test_regression_command_compares_normalized_address_text(): void
    {
        $path = $this->writeDataset([
            'case_id' => 'address_normalized_case',
            'layout_type' => 'single_column',
            'language' => 'en',
            'ocr_text' => "Name: Synthetic Address Candidate\nAddress: Flat 10, Pune\nEducation: B.Com",
            'parser_expected_fields' => [
                'address' => 'Flat 10 Pune',
            ],
        ]);

        try {
            $exitCode = Artisan::call('intake:ocr-regression', [
                '--dataset' => $path,
                '--field' => 'address',
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
    private function candidateAddress(array $parsed): ?string
    {
        $core = $parsed['core'] ?? [];
        if (isset($core['address_line']) && trim((string) $core['address_line']) !== '') {
            return trim((string) $core['address_line']);
        }

        foreach (($parsed['addresses'] ?? []) as $address) {
            $line = trim((string) ($address['address_line'] ?? $address['raw'] ?? ''));
            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function writeDataset(array $case): string
    {
        $relative = 'storage/app/testing/intake-address-regression-'.uniqid().'.jsonl';
        $path = base_path($relative);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $relative;
    }
}
