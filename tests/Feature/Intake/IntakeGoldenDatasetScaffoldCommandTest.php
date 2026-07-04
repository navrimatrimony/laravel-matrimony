<?php

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('command creates synthetic JSONL under storage app intake golden datasets', function () {
    $path = goldenScaffoldPath('golden-create.jsonl');

    try {
        $exitCode = Artisan::call('intake:golden-dataset-scaffold', [
            '--output' => $path,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Synthetic golden dataset scaffold written.')
            ->and($output)->toContain($path)
            ->and(File::exists(base_path($path)))->toBeTrue()
            ->and(goldenScaffoldJsonlRows($path))->toHaveCount(3);
    } finally {
        goldenScaffoldDelete($path);
    }
});

test('created file can be used by intake ocr regression', function () {
    $path = goldenScaffoldPath('golden-regression.jsonl');

    try {
        Artisan::call('intake:golden-dataset-scaffold', [
            '--output' => $path,
        ]);

        $exitCode = Artisan::call('intake:ocr-regression', [
            '--dataset' => $path,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE)
            ->and($exitCode)->toBe(0)
            ->and($payload['success'])->toBeTrue()
            ->and($payload['summary']['regression_status'])->toBe('pass')
            ->and($payload['summary']['valid_cases'])->toBe(3)
            ->and($payload['summary']['overall_accuracy_percent'])->toBe(100)
            ->and($payload['summary']['mismatch_count'])->toBe(0)
            ->and($payload['summary']['missing_count'])->toBe(0)
            ->and(array_column($payload['rows'], 'status'))->toBe(['pass', 'pass', 'pass'])
            ->and(array_column($payload['rows'], 'profile_snapshot_present'))->toBe([true, true, true])
            ->and(array_column($payload['rows'], 'address_count'))->toBe([1, 1, 1])
            ->and(array_column($payload['rows'], 'contact_count'))->toBe([2, 2, 2])
            ->and(array_column($payload['rows'], 'family_section_present'))->toBe([true, true, true])
            ->and(array_column($payload['rows'], 'source_context_present'))->toBe([true, true, true]);
    } finally {
        goldenScaffoldDelete($path);
    }
});

test('command refuses to overwrite without force', function () {
    $path = goldenScaffoldPath('golden-existing.jsonl');

    try {
        File::ensureDirectoryExists(dirname(base_path($path)));
        File::put(base_path($path), goldenScaffoldMarker());

        $exitCode = Artisan::call('intake:golden-dataset-scaffold', [
            '--output' => $path,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Output file already exists')
            ->and(File::get(base_path($path)))->toBe(goldenScaffoldMarker());
    } finally {
        goldenScaffoldDelete($path);
    }
});

test('force overwrites output file', function () {
    $path = goldenScaffoldPath('golden-force.jsonl');

    try {
        File::ensureDirectoryExists(dirname(base_path($path)));
        File::put(base_path($path), goldenScaffoldMarker());

        $exitCode = Artisan::call('intake:golden-dataset-scaffold', [
            '--output' => $path,
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and(File::get(base_path($path)))->not->toBe(goldenScaffoldMarker())
            ->and(goldenScaffoldJsonlRows($path))->toHaveCount(3);
    } finally {
        goldenScaffoldDelete($path);
    }
});

test('output outside storage app intake golden datasets is rejected', function (string $path) {
    $exitCode = Artisan::call('intake:golden-dataset-scaffold', [
        '--output' => $path,
        '--json' => true,
    ]);
    $payload = json_decode(trim(Artisan::output()), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($exitCode)->toBe(1)
        ->and($payload['success'])->toBeFalse()
        ->and($payload['error']['code'])->toBe('output_path_not_allowed');
})->with([
    'storage/app/golden.jsonl',
    'storage/app/intake-golden-datasets/../golden.jsonl',
    'tests/Fixtures/Intake/golden_dataset_minimal.jsonl',
    '.env',
]);

test('json output is valid', function () {
    $path = goldenScaffoldPath('golden-json.jsonl');

    try {
        $exitCode = Artisan::call('intake:golden-dataset-scaffold', [
            '--output' => $path,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE)
            ->and($exitCode)->toBe(0)
            ->and($payload['success'])->toBeTrue()
            ->and($payload['output_path'])->toBe($path)
            ->and($payload['summary']['synthetic_case_count'])->toBe(3)
            ->and($payload['regression_command'])->toBe('php artisan intake:ocr-regression --dataset='.$path);
    } finally {
        goldenScaffoldDelete($path);
    }
});

test('command does not mutate intakes snapshots parsed raw OCR attempts profile routing quality or field confidence', function () {
    $path = goldenScaffoldPath('golden-no-mutation.jsonl');
    $member = User::factory()->create();
    $profile = MatrimonyProfile::factory()->create([
        'user_id' => $member->id,
        'full_name' => 'Profile Before Golden Scaffold',
    ]);
    $parsed = goldenScaffoldParsed('Parsed Candidate', '9876543210');
    $snapshot = goldenScaffoldParsed('Reviewed Candidate', '9876543210');
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
        $exitCode = Artisan::call('intake:golden-dataset-scaffold', [
            '--output' => $path,
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
            ->and($profile->full_name)->toBe('Profile Before Golden Scaffold');
    } finally {
        goldenScaffoldDelete($path);
    }
});

test('output does not contain real looking secrets provider payloads and rows are valid JSONL', function () {
    $path = goldenScaffoldPath('golden-safe-content.jsonl');

    try {
        Artisan::call('intake:golden-dataset-scaffold', [
            '--output' => $path,
        ]);
        $content = File::get(base_path($path));
        $rows = goldenScaffoldJsonlRows($path);

        expect($content)->not->toContain('sk-')
            ->and($content)->not->toContain('provider_payload')
            ->and($content)->not->toContain('image_hash')
            ->and($content)->not->toContain('secret')
            ->and($rows)->toHaveCount(3);

        foreach ($rows as $row) {
            expect($row)->toHaveKeys([
                'case_id',
                'layout_type',
                'language',
                'ocr_text',
                'parser_expected_fields',
                'expected_profile_snapshot',
                'source_context',
            ])
                ->and($row['case_id'])->toStartWith('synthetic_private_scaffold_')
                ->and($row['parser_expected_fields'])->toHaveKeys(['full_name', 'date_of_birth'])
                ->and($row['expected_profile_snapshot'])->toHaveKeys(['core', 'contacts', 'addresses', 'family', 'relatives', 'property'])
                ->and($row['source_context'])->toHaveKeys(['consent_source', 'primary_contact_source', 'primary_contact_number']);
        }
    } finally {
        goldenScaffoldDelete($path);
    }
});

function goldenScaffoldPath(string $filename): string
{
    return 'storage/app/intake-golden-datasets/'.uniqid().'-'.$filename;
}

function goldenScaffoldDelete(string $path): void
{
    File::delete(base_path($path));
}

/**
 * @return list<array<string, mixed>>
 */
function goldenScaffoldJsonlRows(string $path): array
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

function goldenScaffoldMarker(): string
{
    return '{"marker":"do-not-overwrite"}'.PHP_EOL;
}

function goldenScaffoldParsed(string $name, string $phone): array
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
