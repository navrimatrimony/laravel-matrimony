<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('converter converts fake synthetic CSV to JSONL', function () {
    $csvPath = goldenCsvToJsonlPath('golden-input.csv');
    $jsonlPath = goldenCsvToJsonlPath('golden-output.jsonl');

    try {
        goldenCsvToJsonlWriteCsv($csvPath, [
            goldenCsvToJsonlRow([
                'case_id' => 'synthetic_csv_case_001',
                'layout_type' => 'single_column',
                'language' => 'en',
                'ocr_text' => "Name: Synthetic CSV Alpha\nDate of Birth: 1996-04-12\nMobile: 5550101001",
                'parser_full_name' => 'Synthetic CSV Alpha',
                'parser_date_of_birth' => '1996-04-12',
                'parser_document_contact_number' => '5550101001',
                'profile_full_name' => 'Synthetic CSV Alpha',
                'profile_date_of_birth' => '1996-04-12',
                'profile_primary_contact_number' => '5550199001',
                'profile_document_contacts' => '5550101001',
                'current_address_raw' => 'Sample CSV Lane, Test City',
                'current_village_or_city' => 'Test City',
                'current_district' => 'Test District',
                'father_name' => 'Synthetic Father',
                'relatives_notes' => 'Synthetic relatives note',
                'property_notes' => 'Synthetic property note',
                'expectations_notes' => 'Synthetic expectations note',
                'source_primary_contact_rule' => 'communication_or_consent_number_is_primary',
                'source_consent_note' => 'Synthetic consent note',
            ]),
            goldenCsvToJsonlRow(),
        ]);

        [$exitCode, $payload] = goldenCsvToJsonlJson([
            '--csv' => $csvPath,
            '--output' => $jsonlPath,
        ]);
        $rows = goldenCsvToJsonlRows($jsonlPath);
        $case = $rows[0];

        expect($exitCode)->toBe(0)
            ->and($payload['success'])->toBeTrue()
            ->and($payload['summary']['csv_rows'])->toBe(2)
            ->and($payload['summary']['cases_written'])->toBe(1)
            ->and($payload['summary']['skipped_blank_rows'])->toBe(1)
            ->and($payload['summary']['fields_present_counts']['full_name'])->toBe(1)
            ->and($payload['summary']['fields_present_counts']['document_contact_number'])->toBe(1)
            ->and($payload['summary']['profile_snapshot_count'])->toBe(1)
            ->and($payload['summary']['source_context_count'])->toBe(1)
            ->and($rows)->toHaveCount(1)
            ->and($case['parser_expected_fields']['document_contact_number'])->toBe('5550101001')
            ->and($case['expected_profile_snapshot']['core']['primary_contact_number'])->toBe('5550199001')
            ->and($case['expected_profile_snapshot']['contacts'][0]['type'])->toBe('primary_communication_contact')
            ->and($case['expected_profile_snapshot']['contacts'][0]['number'])->toBe('5550199001')
            ->and($case['expected_profile_snapshot']['contacts'][1]['type'])->toBe('document_contact')
            ->and($case['expected_profile_snapshot']['contacts'][1]['raw'])->toBe('5550101001')
            ->and($case['source_context']['primary_contact_rule'])->toBe('communication_or_consent_number_is_primary');
    } finally {
        goldenCsvToJsonlDelete($csvPath, $jsonlPath);
    }
});

test('converted JSONL works with intake ocr regression', function () {
    $csvPath = goldenCsvToJsonlPath('golden-regression-input.csv');
    $jsonlPath = goldenCsvToJsonlPath('golden-regression-output.jsonl');

    try {
        goldenCsvToJsonlWriteCsv($csvPath, [
            goldenCsvToJsonlRow([
                'case_id' => 'synthetic_csv_regression_case',
                'layout_type' => 'single_column',
                'language' => 'en',
                'ocr_text' => "Name: Synthetic Regression CSV\nDate of Birth: 1996-04-12\nMobile: 9876543210",
                'parser_full_name' => 'Synthetic Regression CSV',
                'parser_date_of_birth' => '1996-04-12',
                'parser_document_contact_number' => '9876543210',
                'profile_full_name' => 'Synthetic Regression CSV',
                'profile_primary_contact_number' => '9123456780',
                'profile_document_contacts' => '9876543210',
                'source_primary_contact_rule' => 'communication_or_consent_number_is_primary',
            ]),
        ]);

        goldenCsvToJsonlJson([
            '--csv' => $csvPath,
            '--output' => $jsonlPath,
        ]);

        $exitCode = Artisan::call('intake:ocr-regression', [
            '--dataset' => $jsonlPath,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE)
            ->and($exitCode)->toBe(0)
            ->and($payload['success'])->toBeTrue()
            ->and($payload['summary']['regression_status'])->toBe('pass')
            ->and($payload['summary']['overall_accuracy_percent'])->toBe(100)
            ->and($payload['rows'][0]['profile_snapshot_present'])->toBeTrue()
            ->and($payload['rows'][0]['source_context_present'])->toBeTrue();
    } finally {
        goldenCsvToJsonlDelete($csvPath, $jsonlPath);
    }
});

test('converter rejects invalid rows safely', function () {
    $csvPath = goldenCsvToJsonlPath('golden-invalid-input.csv');
    $jsonlPath = goldenCsvToJsonlPath('golden-invalid-output.jsonl');

    try {
        goldenCsvToJsonlWriteCsv($csvPath, [
            goldenCsvToJsonlRow([
                'layout_type' => 'single_column',
                'language' => 'en',
                'profile_full_name' => 'Sensitive Invalid Candidate',
                'profile_primary_contact_number' => '7777777777',
                'current_address_raw' => 'Sensitive Invalid Address',
            ]),
        ]);

        [$exitCode, $payload] = goldenCsvToJsonlJson([
            '--csv' => $csvPath,
            '--output' => $jsonlPath,
        ], expectSuccess: false);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(1)
            ->and($payload['success'])->toBeFalse()
            ->and($payload['error']['code'])->toBe('csv_validation_failed')
            ->and($payload['validation_errors'])->toHaveCount(1)
            ->and($payload['validation_errors'][0]['row_number'])->toBe(2)
            ->and($payload['validation_errors'][0]['error_codes'])->toContain('case_id_missing')
            ->and($payload['validation_errors'][0]['error_codes'])->toContain('ocr_text_missing')
            ->and($payload['validation_errors'][0]['error_codes'])->toContain('parser_expected_fields_missing')
            ->and(File::exists(base_path($jsonlPath)))->toBeFalse()
            ->and($output)->not->toContain('Sensitive Invalid Candidate')
            ->and($output)->not->toContain('7777777777')
            ->and($output)->not->toContain('Sensitive Invalid Address');
    } finally {
        goldenCsvToJsonlDelete($csvPath, $jsonlPath);
    }
});

test('converter rejects input path outside private directory', function () {
    $jsonlPath = goldenCsvToJsonlPath('golden-output-path-safe.jsonl');

    try {
        [$exitCode, $payload] = goldenCsvToJsonlJson([
            '--csv' => '.env',
            '--output' => $jsonlPath,
        ], expectSuccess: false);

        expect($exitCode)->toBe(1)
            ->and($payload['success'])->toBeFalse()
            ->and($payload['error']['code'])->toBe('csv_path_not_allowed');
    } finally {
        goldenCsvToJsonlDelete($jsonlPath);
    }
});

test('converter rejects output path outside private directory', function () {
    $csvPath = goldenCsvToJsonlPath('golden-input-path-safe.csv');

    try {
        goldenCsvToJsonlWriteCsv($csvPath, [
            goldenCsvToJsonlValidRow(),
        ]);

        [$exitCode, $payload] = goldenCsvToJsonlJson([
            '--csv' => $csvPath,
            '--output' => 'tests/Fixtures/Intake/golden-output.jsonl',
        ], expectSuccess: false);

        expect($exitCode)->toBe(1)
            ->and($payload['success'])->toBeFalse()
            ->and($payload['error']['code'])->toBe('output_path_not_allowed');
    } finally {
        goldenCsvToJsonlDelete($csvPath);
    }
});

test('converter does not print raw phone name address ocr or profile values', function () {
    $csvPath = goldenCsvToJsonlPath('golden-redaction-input.csv');
    $humanJsonlPath = goldenCsvToJsonlPath('golden-redaction-human.jsonl');
    $jsonJsonlPath = goldenCsvToJsonlPath('golden-redaction-json.jsonl');

    try {
        goldenCsvToJsonlWriteCsv($csvPath, [
            goldenCsvToJsonlRow([
                'case_id' => 'synthetic_redaction_case',
                'layout_type' => 'single_column',
                'language' => 'en',
                'ocr_text' => "Name: Sensitive Output Candidate\nMobile: 7777777777\nAddress: Sensitive Output Address",
                'parser_full_name' => 'Sensitive Output Candidate',
                'profile_full_name' => 'Sensitive Profile Candidate',
                'profile_primary_contact_number' => '8888888888',
                'current_address_raw' => 'Sensitive Profile Address',
                'source_consent_note' => 'Sensitive source consent note',
            ]),
        ]);

        $humanExitCode = Artisan::call('intake:golden-dataset-csv-to-jsonl', [
            '--csv' => $csvPath,
            '--output' => $humanJsonlPath,
        ]);
        $humanOutput = Artisan::output();

        [$jsonExitCode] = goldenCsvToJsonlJson([
            '--csv' => $csvPath,
            '--output' => $jsonJsonlPath,
        ]);
        $jsonOutput = trim(Artisan::output());

        expect($humanExitCode)->toBe(0)
            ->and($jsonExitCode)->toBe(0)
            ->and($humanOutput)->not->toContain('Sensitive Output Candidate')
            ->and($humanOutput)->not->toContain('Sensitive Profile Candidate')
            ->and($humanOutput)->not->toContain('7777777777')
            ->and($humanOutput)->not->toContain('8888888888')
            ->and($humanOutput)->not->toContain('Sensitive Output Address')
            ->and($humanOutput)->not->toContain('Sensitive Profile Address')
            ->and($humanOutput)->not->toContain('Sensitive source consent note')
            ->and($jsonOutput)->not->toContain('Sensitive Output Candidate')
            ->and($jsonOutput)->not->toContain('Sensitive Profile Candidate')
            ->and($jsonOutput)->not->toContain('7777777777')
            ->and($jsonOutput)->not->toContain('8888888888')
            ->and($jsonOutput)->not->toContain('Sensitive Output Address')
            ->and($jsonOutput)->not->toContain('Sensitive Profile Address')
            ->and($jsonOutput)->not->toContain('Sensitive source consent note');
    } finally {
        goldenCsvToJsonlDelete($csvPath, $humanJsonlPath, $jsonJsonlPath);
    }
});

test('converter does not mutate intakes snapshots parsed raw OCR attempts profile routing quality or field confidence', function () {
    $csvPath = goldenCsvToJsonlPath('golden-no-mutation-input.csv');
    $jsonlPath = goldenCsvToJsonlPath('golden-no-mutation-output.jsonl');
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before CSV Conversion',
    ]);
    $parsed = goldenCsvToJsonlParsed('Parsed Candidate', '9876543210');
    $snapshot = goldenCsvToJsonlParsed('Reviewed Candidate', '9876543210');
    $quality = ['score' => 0.91, 'is_low' => false];
    $fieldConfidence = [
        'full_name' => ['score' => 0.91, 'present' => true, 'source_path' => 'core.full_name'],
    ];
    $routing = [
        'recommended_action' => 'manual_review',
        'reason_codes' => ['field_confidence_low'],
        'signals' => ['quality_score' => 0.91],
    ];
    $intake = BiodataIntake::create([
        'uploaded_by' => $member->id,
        'matrimony_profile_id' => $profile->id,
        'raw_ocr_text' => 'Original OCR text 9876543210',
        'parsed_json' => $parsed,
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'approval_snapshot_json' => $snapshot,
        'approval_status' => 'approved',
        'approved_by_user' => true,
        'approved_at' => now(),
        'reviewed_at' => now(),
        'intake_locked' => false,
        'quality_summary_json' => $quality,
        'failure_codes_json' => [BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED],
        'field_confidence_json' => $fieldConfidence,
        'routing_recommendation_json' => $routing,
    ]);
    $attempt = BiodataIntakeOcrAttempt::create([
        'intake_id' => $intake->id,
        'engine' => BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
        'source' => 'mobile_app',
        'status' => BiodataIntakeOcrAttempt::STATUS_SUCCESS,
        'raw_text' => 'Attempt raw OCR evidence',
        'text_hash' => 'abcdef1234567890abcdef1234567890',
        'created_by_user_id' => $member->id,
        'created_by_actor_type' => BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        'source_surface' => BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
    ]);
    $attemptCountBefore = BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count();

    try {
        goldenCsvToJsonlWriteCsv($csvPath, [
            goldenCsvToJsonlValidRow(),
        ]);

        $exitCode = Artisan::call('intake:golden-dataset-csv-to-jsonl', [
            '--csv' => $csvPath,
            '--output' => $jsonlPath,
        ]);

        $intake->refresh();
        $attempt->refresh();
        $profile->refresh();

        expect($exitCode)->toBe(0)
            ->and($intake->raw_ocr_text)->toBe('Original OCR text 9876543210')
            ->and($intake->parsed_json)->toEqual($parsed)
            ->and($intake->parse_status)->toBe('parsed')
            ->and($intake->approval_snapshot_json)->toEqual($snapshot)
            ->and($intake->quality_summary_json)->toEqual($quality)
            ->and($intake->field_confidence_json)->toEqual($fieldConfidence)
            ->and($intake->routing_recommendation_json)->toEqual($routing)
            ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore)
            ->and($attempt->raw_text)->toBe('Attempt raw OCR evidence')
            ->and($profile->full_name)->toBe('Profile Before CSV Conversion');
    } finally {
        goldenCsvToJsonlDelete($csvPath, $jsonlPath);
    }
});

test('converter JSON output is valid', function () {
    $csvPath = goldenCsvToJsonlPath('golden-json-input.csv');
    $jsonlPath = goldenCsvToJsonlPath('golden-json-output.jsonl');

    try {
        goldenCsvToJsonlWriteCsv($csvPath, [
            goldenCsvToJsonlValidRow(),
        ]);

        [$exitCode, $payload] = goldenCsvToJsonlJson([
            '--csv' => $csvPath,
            '--output' => $jsonlPath,
        ]);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKeys([
                'success',
                'output_path',
                'summary',
                'validation_errors',
                'regression_command',
            ])
            ->and($payload['summary'])->toHaveKeys([
                'csv_rows',
                'cases_written',
                'skipped_blank_rows',
                'fields_present_counts',
                'profile_snapshot_count',
                'source_context_count',
            ]);
    } finally {
        goldenCsvToJsonlDelete($csvPath, $jsonlPath);
    }
});

function goldenCsvToJsonlJson(array $parameters, bool $expectSuccess = true): array
{
    $exitCode = Artisan::call('intake:golden-dataset-csv-to-jsonl', array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim(Artisan::output()), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE);
    if ($expectSuccess) {
        expect($exitCode)->toBe(0);
    }

    return [$exitCode, $payload];
}

function goldenCsvToJsonlPath(string $filename): string
{
    return 'storage/app/intake-golden-datasets/'.uniqid().'-'.$filename;
}

function goldenCsvToJsonlDelete(string ...$paths): void
{
    foreach ($paths as $path) {
        File::delete(base_path($path));
    }
}

/**
 * @param  list<array<string, string>>  $rows
 */
function goldenCsvToJsonlWriteCsv(string $path, array $rows): void
{
    File::ensureDirectoryExists(dirname(base_path($path)));
    $handle = fopen('php://temp', 'r+');

    expect($handle)->not->toBeFalse();
    fputcsv($handle, goldenCsvToJsonlColumns());
    foreach ($rows as $row) {
        fputcsv($handle, array_map(
            fn (string $column): string => $row[$column] ?? '',
            goldenCsvToJsonlColumns()
        ));
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    File::put(base_path($path), (string) $csv);
}

/**
 * @return list<array<string, mixed>>
 */
function goldenCsvToJsonlRows(string $path): array
{
    return collect(explode("\n", trim(File::get(base_path($path)))))
        ->filter(fn (string $line): bool => trim($line) !== '')
        ->map(function (string $line): array {
            $decoded = json_decode($line, true);

            expect(json_last_error())->toBe(JSON_ERROR_NONE)
                ->and($decoded)->toBeArray();

            return $decoded;
        })
        ->values()
        ->all();
}

/**
 * @return array<string, string>
 */
function goldenCsvToJsonlRow(array $overrides = []): array
{
    return array_merge(array_fill_keys(goldenCsvToJsonlColumns(), ''), $overrides);
}

/**
 * @return array<string, string>
 */
function goldenCsvToJsonlValidRow(): array
{
    return goldenCsvToJsonlRow([
        'case_id' => 'synthetic_valid_csv_case',
        'layout_type' => 'single_column',
        'language' => 'en',
        'ocr_text' => "Name: Synthetic Valid CSV\nDate of Birth: 1996-04-12",
        'parser_full_name' => 'Synthetic Valid CSV',
        'parser_date_of_birth' => '1996-04-12',
        'profile_full_name' => 'Synthetic Valid CSV',
        'source_primary_contact_rule' => 'communication_or_consent_number_is_primary',
    ]);
}

/**
 * @return list<string>
 */
function goldenCsvToJsonlColumns(): array
{
    return [
        'case_id',
        'layout_type',
        'language',
        'ocr_text',
        'parser_full_name',
        'parser_date_of_birth',
        'parser_height',
        'parser_education',
        'parser_occupation',
        'parser_document_contact_number',
        'parser_document_address',
        'parser_religion',
        'parser_caste',
        'parser_sub_caste',
        'profile_full_name',
        'profile_date_of_birth',
        'profile_birth_time',
        'profile_birth_place',
        'profile_height_text',
        'profile_education',
        'profile_occupation',
        'profile_religion',
        'profile_caste',
        'profile_sub_caste',
        'profile_primary_contact_number',
        'profile_document_contacts',
        'current_address_raw',
        'current_village_or_city',
        'current_taluka',
        'current_district',
        'current_state',
        'native_address_raw',
        'native_village_or_city',
        'native_taluka',
        'native_district',
        'native_state',
        'birth_place_raw',
        'father_name',
        'mother_name',
        'brother_notes',
        'sister_notes',
        'family_notes',
        'relatives_notes',
        'property_notes',
        'expectations_notes',
        'source_primary_contact_rule',
        'source_consent_note',
        'curator_notes',
    ];
}

function goldenCsvToJsonlParsed(string $name, string $phone): array
{
    return [
        'core' => [
            'full_name' => $name,
            'date_of_birth' => '1996-04-12',
            'height_cm' => 170,
            'highest_education' => 'MCA',
            'occupation_title' => 'Engineer',
            'primary_contact_number' => $phone,
            'religion' => 'Hindu',
            'caste' => 'Maratha',
            'address_line' => 'Pune',
        ],
        'contacts' => [
            [
                'phone_number' => $phone,
                'is_primary' => true,
            ],
        ],
        'addresses' => [
            [
                'address_line' => 'Pune',
            ],
        ],
    ];
}
