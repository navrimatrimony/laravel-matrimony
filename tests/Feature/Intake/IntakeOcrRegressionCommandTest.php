<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('missing dataset path fails with clear message', function () {
    $exitCode = Artisan::call('intake:ocr-regression', [
        '--dataset' => 'storage/app/intake-golden-datasets/missing.jsonl',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Dataset file was not found.')
        ->and($output)->toContain('storage/app/intake-golden-datasets/golden.jsonl')
        ->and($output)->toContain('php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl');
});

test('invalid JSONL row is reported as invalid dataset', function () {
    $path = writeOcrRegressionDataset('{invalid json');

    try {
        [$exitCode, $payload] = ocrRegressionJson([
            '--dataset' => $path,
        ], expectSuccess: false);

        expect($exitCode)->toBe(1)
            ->and($payload['success'])->toBeFalse()
            ->and($payload['summary']['regression_status'])->toBe('invalid_dataset')
            ->and($payload['summary']['invalid_cases'])->toBe(1)
            ->and($payload['schema_errors'][0]['error_codes'])->toContain('invalid_json');
    } finally {
        File::delete(base_path($path));
    }
});

test('valid minimal dataset runs and returns success', function () {
    [$exitCode, $payload] = ocrRegressionJson([
        '--dataset' => ocrRegressionFixtureRelativePath(),
    ]);

    expect($exitCode)->toBe(0)
        ->and($payload['success'])->toBeTrue()
        ->and($payload['summary']['regression_status'])->toBe('pass')
        ->and($payload['summary']['total_cases'])->toBe(1)
        ->and($payload['summary']['valid_cases'])->toBe(1)
        ->and($payload['rows'][0]['case_id'])->toBe('synthetic_case_001');
});

test('lowercase repo-relative fixture path works safely', function () {
    [$exitCode, $payload] = ocrRegressionJson([
        '--dataset' => 'tests/fixtures/Intake/golden_dataset_minimal.jsonl',
    ]);

    expect($exitCode)->toBe(0)
        ->and($payload['success'])->toBeTrue()
        ->and($payload['summary']['valid_cases'])->toBe(1)
        ->and($payload['rows'][0]['case_id'])->toBe('synthetic_case_001');
});

test('arbitrary relative dataset path is rejected', function (string $dataset) {
    [$exitCode, $payload] = ocrRegressionJson([
        '--dataset' => $dataset,
    ], expectSuccess: false);

    expect($exitCode)->toBe(1)
        ->and($payload['success'])->toBeFalse()
        ->and($payload['schema_errors'][0]['error_codes'])->toContain('dataset_path_not_allowed')
        ->and($payload['schema_errors'][0]['message'])->toContain('Dataset path is not allowed');
})->with([
    '.env',
    'app/Models/User.php',
    'vendor/autoload.php',
]);

test('storage app dataset path still works', function () {
    $path = writeOcrRegressionDataset(ocrRegressionCase([
        'case_id' => 'storage_dataset_case',
        'ocr_text' => "Name: Storage Dataset Candidate\nMobile: 9876543210",
        'expected_fields' => [
            'primary_contact_number' => '9876543210',
        ],
    ]));

    try {
        [$exitCode, $payload] = ocrRegressionJson([
            '--dataset' => $path,
            '--field' => 'primary_contact_number',
        ]);

        expect($exitCode)->toBe(0)
            ->and($payload['summary']['valid_cases'])->toBe(1)
            ->and($payload['rows'][0]['case_id'])->toBe('storage_dataset_case');
    } finally {
        File::delete(base_path($path));
    }
});

test('legacy expected fields still work when parser expected fields are absent', function () {
    $path = writeOcrRegressionDataset(ocrRegressionCase([
        'case_id' => 'legacy_expected_fields_case',
        'ocr_text' => "Name: Legacy Expected Candidate\nMobile: 9876543210",
        'expected_fields' => [
            'full_name' => 'Legacy Expected Candidate',
        ],
    ]));

    try {
        [$exitCode, $payload] = ocrRegressionJson([
            '--dataset' => $path,
            '--field' => 'full_name',
        ]);

        expect($exitCode)->toBe(0)
            ->and($payload['summary']['total_expected_fields'])->toBe(1)
            ->and($payload['summary']['exact_match_count'])->toBe(1)
            ->and($payload['rows'][0]['profile_snapshot_present'])->toBeFalse()
            ->and($payload['rows'][0]['source_context_present'])->toBeFalse();
    } finally {
        File::delete(base_path($path));
    }
});

test('parser expected fields are used for scoring when present', function () {
    $path = writeOcrRegressionDataset(ocrRegressionCase([
        'case_id' => 'parser_expected_fields_case',
        'ocr_text' => "Name: Parser Expected Candidate\nMobile: 9876543210",
        'expected_fields' => [
            'full_name' => 'Wrong Legacy Candidate',
        ],
        'parser_expected_fields' => [
            'full_name' => 'Parser Expected Candidate',
        ],
    ]));

    try {
        [$exitCode, $payload] = ocrRegressionJson([
            '--dataset' => $path,
            '--field' => 'full_name',
        ]);

        expect($exitCode)->toBe(0)
            ->and($payload['summary']['total_expected_fields'])->toBe(1)
            ->and($payload['summary']['exact_match_count'])->toBe(1)
            ->and($payload['summary']['mismatch_count'])->toBe(0)
            ->and($payload['rows'][0]['status'])->toBe('pass');
    } finally {
        File::delete(base_path($path));
    }
});

test('profile snapshot and source context expose only safe metadata and are not scored', function () {
    $path = writeOcrRegressionDataset(ocrRegressionCase([
        'case_id' => 'profile_snapshot_metadata_case',
        'ocr_text' => "Name: Metadata Candidate\nMobile: 9876543210",
        'parser_expected_fields' => [
            'full_name' => 'Metadata Candidate',
        ],
        'expected_profile_snapshot' => [
            'core' => [
                'full_name' => 'Private Snapshot Candidate',
                'primary_contact_number' => '7777777777',
            ],
            'contacts' => [
                ['type' => 'primary', 'number' => '7777777777'],
                ['type' => 'document_contact', 'number' => '8888888888'],
            ],
            'addresses' => [
                ['address_line' => 'Private Full Address, Hidden City'],
            ],
            'family' => [
                'father_name' => 'Private Father Name',
            ],
            'property' => [
                'summary' => 'Private property note',
            ],
        ],
        'source_context' => [
            'consent' => [
                'source_name' => 'Private Source Person',
                'source_phone' => '9999999999',
            ],
            'primary_contact' => [
                'source' => 'communication_or_consent',
                'number' => '7777777777',
            ],
            '9999999999' => [
                'source' => 'unsafe phone-like metadata key',
            ],
            'Private Source Person' => [
                'source' => 'unsafe free-text metadata key',
            ],
        ],
    ]));

    try {
        [$exitCode, $payload] = ocrRegressionJson([
            '--dataset' => $path,
        ]);
        $jsonOutput = trim(Artisan::output());
        $row = $payload['rows'][0];

        expect($exitCode)->toBe(0)
            ->and($payload['summary']['total_expected_fields'])->toBe(1)
            ->and($row['profile_snapshot_present'])->toBeTrue()
            ->and($row['profile_snapshot_sections'])->toBe(['core', 'contacts', 'addresses', 'family', 'property'])
            ->and($row['address_count'])->toBe(1)
            ->and($row['contact_count'])->toBe(2)
            ->and($row['family_section_present'])->toBeTrue()
            ->and($row['source_context_present'])->toBeTrue()
            ->and($row['source_context_keys'])->toBe(['consent', 'primary_contact', 'redacted_key'])
            ->and($jsonOutput)->not->toContain('Private Snapshot Candidate')
            ->and($jsonOutput)->not->toContain('7777777777')
            ->and($jsonOutput)->not->toContain('8888888888')
            ->and($jsonOutput)->not->toContain('9999999999')
            ->and($jsonOutput)->not->toContain('Private Full Address')
            ->and($jsonOutput)->not->toContain('Private Father Name')
            ->and($jsonOutput)->not->toContain('Private Source Person')
            ->and($jsonOutput)->not->toContain('Private property note');

        $humanExitCode = Artisan::call('intake:ocr-regression', [
            '--dataset' => $path,
        ]);
        $humanOutput = Artisan::output();

        expect($humanExitCode)->toBe(0)
            ->and($humanOutput)->toContain('core, contacts, addresses, family, property')
            ->and($humanOutput)->toContain('consent, primary_contact')
            ->and($humanOutput)->not->toContain('Private Snapshot Candidate')
            ->and($humanOutput)->not->toContain('7777777777')
            ->and($humanOutput)->not->toContain('8888888888')
            ->and($humanOutput)->not->toContain('9999999999')
            ->and($humanOutput)->not->toContain('Private Full Address')
            ->and($humanOutput)->not->toContain('Private Father Name')
            ->and($humanOutput)->not->toContain('Private Source Person')
            ->and($humanOutput)->not->toContain('Private property note');
    } finally {
        File::delete(base_path($path));
    }
});

test('absolute dataset path still works', function () {
    [$exitCode, $payload] = ocrRegressionJson([
        '--dataset' => ocrRegressionFixturePath(),
    ]);

    expect($exitCode)->toBe(0)
        ->and($payload['summary']['valid_cases'])->toBe(1)
        ->and($payload['rows'][0]['case_id'])->toBe('synthetic_case_001');
});

test('field filter works', function () {
    [$exitCode, $payload] = ocrRegressionJson([
        '--dataset' => ocrRegressionFixtureRelativePath(),
        '--field' => 'primary_contact_number',
    ]);

    expect($exitCode)->toBe(0)
        ->and($payload['filters']['field'])->toBe('primary_contact_number')
        ->and($payload['field_accuracy'])->toHaveCount(1)
        ->and($payload['field_accuracy'][0]['field'])->toBe('primary_contact_number')
        ->and($payload['summary']['total_expected_fields'])->toBe(1);
});

test('fail-under threshold exits non-zero when accuracy is below threshold', function () {
    $path = writeOcrRegressionDataset(ocrRegressionCase([
        'case_id' => 'threshold_case',
        'ocr_text' => "Name: Synthetic Threshold\nMobile: 9876543210",
        'expected_fields' => [
            'full_name' => 'Different Expected Name',
        ],
    ]));

    try {
        [$exitCode, $payload] = ocrRegressionJson([
            '--dataset' => $path,
            '--field' => 'full_name',
            '--fail-under' => '100',
        ], expectSuccess: false);

        expect($exitCode)->toBe(1)
            ->and($payload['summary']['regression_status'])->toBe('fail_under_threshold')
            ->and($payload['summary']['overall_accuracy_percent'])->toBeLessThan(100);
    } finally {
        File::delete(base_path($path));
    }
});

test('command output redacts raw OCR text phone full address and candidate names', function () {
    $path = writeOcrRegressionDataset(ocrRegressionCase([
        'case_id' => 'redaction_case',
        'ocr_text' => "Name: Sensitive Synthetic Name\nMobile: 9876543210\nAddress: 123 Secret Full Address, Pune\nProvider payload sk-proj-secret",
        'expected_fields' => [
            'full_name' => 'Sensitive Synthetic Name',
            'primary_contact_number' => '9876543210',
            'address' => '123 Secret Full Address, Pune',
        ],
    ]));

    try {
        $exitCode = Artisan::call('intake:ocr-regression', [
            '--dataset' => $path,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->not->toContain('Sensitive Synthetic Name')
            ->and($output)->not->toContain('9876543210')
            ->and($output)->not->toContain('123 Secret Full Address')
            ->and($output)->not->toContain('sk-proj-secret');
    } finally {
        File::delete(base_path($path));
    }
});

test('command does not mutate intakes snapshots parsed raw OCR attempts profile routing quality or field confidence', function () {
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before OCR Regression',
    ]);
    $parsed = ocrRegressionParsed('Parsed Candidate', '9876543210');
    $snapshot = ocrRegressionParsed('Reviewed Candidate', '9876543210');
    $quality = ['score' => 0.91, 'is_low' => false];
    $failureCodes = [BiodataIntakeOcrAttempt::FAILURE_TEXT_FOUND_MAPPING_FAILED];
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
        'failure_codes_json' => $failureCodes,
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

    [$exitCode, $payload] = ocrRegressionJson([
        '--dataset' => ocrRegressionFixtureRelativePath(),
    ]);

    $intake->refresh();
    $attempt->refresh();
    $profile->refresh();

    expect($exitCode)->toBe(0)
        ->and($payload['summary']['valid_cases'])->toBe(1)
        ->and($intake->raw_ocr_text)->toBe('Original OCR text 9876543210')
        ->and($intake->parsed_json)->toEqual($parsed)
        ->and($intake->parse_status)->toBe('parsed')
        ->and($intake->approval_snapshot_json)->toEqual($snapshot)
        ->and($intake->quality_summary_json)->toEqual($quality)
        ->and($intake->failure_codes_json)->toEqual($failureCodes)
        ->and($intake->field_confidence_json)->toEqual($fieldConfidence)
        ->and($intake->routing_recommendation_json)->toEqual($routing)
        ->and(BiodataIntakeOcrAttempt::where('intake_id', $intake->id)->count())->toBe($attemptCountBefore)
        ->and($attempt->raw_text)->toBe('Attempt raw OCR evidence')
        ->and($profile->full_name)->toBe('Profile Before OCR Regression');
});

test('json output is valid and includes expected keys', function () {
    [$exitCode, $payload] = ocrRegressionJson([
        '--dataset' => ocrRegressionFixtureRelativePath(),
    ]);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys([
            'success',
            'filters',
            'summary',
            'field_accuracy',
            'layout_accuracy',
            'rows',
            'schema_errors',
        ])
        ->and($payload['summary'])->toHaveKeys([
            'total_cases',
            'valid_cases',
            'invalid_cases',
            'total_expected_fields',
            'exact_match_count',
            'mismatch_count',
            'missing_count',
            'overall_accuracy_percent',
            'regression_status',
        ]);
});

function ocrRegressionJson(array $parameters, bool $expectSuccess = true): array
{
    $exitCode = Artisan::call('intake:ocr-regression', array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim(Artisan::output()), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE);
    if ($expectSuccess) {
        expect($exitCode)->toBe(0);
    }

    return [$exitCode, $payload];
}

function ocrRegressionFixturePath(): string
{
    return base_path('tests/Fixtures/Intake/golden_dataset_minimal.jsonl');
}

function ocrRegressionFixtureRelativePath(): string
{
    return 'tests/Fixtures/Intake/golden_dataset_minimal.jsonl';
}

function writeOcrRegressionDataset(string $contents): string
{
    $relative = 'storage/app/testing/intake-ocr-regression-'.uniqid().'.jsonl';
    $path = base_path($relative);
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);

    return $relative;
}

function ocrRegressionCase(array $overrides = []): string
{
    $case = array_merge([
        'case_id' => 'synthetic_case',
        'layout_type' => 'single_column',
        'language' => 'en',
        'ocr_text' => "Name: Synthetic Candidate\nMobile: 9876543210",
        'expected_fields' => [
            'full_name' => 'Synthetic Candidate',
        ],
    ], $overrides);

    return json_encode($case, JSON_UNESCAPED_SLASHES).PHP_EOL;
}

function ocrRegressionParsed(string $name, string $phone): array
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
