<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

test('failure audit command runs with synthetic dataset', function () {
    $path = writeFailureAuditDataset(
        failureAuditCase([
            'case_id' => 'hidden_source_id_one',
            'ocr_text' => "Height: 5 ft 7 in\nOccupation: Synthetic Parsed Role",
            'parser_expected_fields' => [
                'height' => '5 ft 7 in',
                'occupation' => 'Different Expected Role',
            ],
        ])
    );

    try {
        [$exitCode, $output] = failureAuditCall([
            '--dataset' => $path,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('dataset_rows: 1')
            ->and($output)->toContain('Field summary')
            ->and($output)->toContain('Row mismatch inventory')
            ->and($output)->toContain('occupation');
    } finally {
        File::delete(base_path($path));
    }
});

test('failure audit console output uses safe row labels and hides raw values', function () {
    $path = writeFailureAuditDataset(
        failureAuditCase([
            'case_id' => 'private_original_case_id',
            'layout_type' => 'single_column',
            'ocr_text' => "Occupation: Synthetic Parsed Role\nEducation: Synthetic Parsed Course",
            'parser_expected_fields' => [
                'occupation' => 'Different Expected Role',
                'education' => 'Different Expected Course',
            ],
        ])
    );

    try {
        [$exitCode, $output] = failureAuditCall([
            '--dataset' => $path,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('case_001')
            ->and($output)->not->toContain('private_original_case_id')
            ->and($output)->not->toContain('Synthetic Parsed Role')
            ->and($output)->not->toContain('Synthetic Parsed Course')
            ->and($output)->not->toContain('Different Expected Role')
            ->and($output)->not->toContain('Different Expected Course');
    } finally {
        File::delete(base_path($path));
    }
});

test('failure audit json returns valid safe shape', function () {
    $path = writeFailureAuditDataset(
        failureAuditCase([
            'case_id' => 'hidden_source_id_json',
            'ocr_text' => "Height: 5 ft 7 in\nOccupation: Synthetic Parsed Role",
            'parser_expected_fields' => [
                'height' => '5 ft 7 in',
                'occupation' => 'Different Expected Role',
            ],
        ])
    );

    try {
        [$exitCode, $payload] = failureAuditJson([
            '--dataset' => $path,
        ]);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKeys([
                'dataset_rows',
                'field_filter',
                'detail_limit',
                'evaluated_fields',
                'fields',
                'rows',
                'layout_status_summary',
                'recommendation',
            ])
            ->and($payload['dataset_rows'])->toBe(1)
            ->and($payload['rows'][0]['row_label'])->toBe('case_001')
            ->and($payload['rows'][0]['mismatch_fields'])->toBe(['occupation'])
            ->and($payload['recommendation']['next_candidate_field'])->toBe('occupation');

        $jsonOutput = json_encode($payload, JSON_UNESCAPED_SLASHES);
        expect($jsonOutput)->not->toContain('hidden_source_id_json')
            ->and($jsonOutput)->not->toContain('Synthetic Parsed Role')
            ->and($jsonOutput)->not->toContain('Different Expected Role');
    } finally {
        File::delete(base_path($path));
    }
});

test('failure audit field option filters mismatch details', function () {
    $path = writeFailureAuditDataset(
        failureAuditCase([
            'case_id' => 'hidden_source_id_filter',
            'ocr_text' => "Height: 5 ft 7 in\nOccupation: Synthetic Parsed Role",
            'parser_expected_fields' => [
                'height' => '6 ft 1 in',
                'occupation' => 'Different Expected Role',
            ],
        ])
    );

    try {
        [$exitCode, $payload] = failureAuditJson([
            '--dataset' => $path,
            '--field' => 'occupation',
        ]);

        expect($exitCode)->toBe(0)
            ->and($payload['field_filter'])->toBe('occupation')
            ->and($payload['evaluated_fields'])->toBe(['occupation'])
            ->and($payload['fields'])->toHaveCount(1)
            ->and($payload['rows'][0]['mismatch_fields'])->toBe(['occupation']);
    } finally {
        File::delete(base_path($path));
    }
});

test('failure audit limit only limits row details', function () {
    $path = writeFailureAuditDataset(
        failureAuditCase([
            'case_id' => 'hidden_source_id_limit_one',
            'ocr_text' => 'Occupation: Synthetic Parsed Role One',
            'parser_expected_fields' => [
                'occupation' => 'Different Expected Role One',
            ],
        ]).
        failureAuditCase([
            'case_id' => 'hidden_source_id_limit_two',
            'ocr_text' => 'Occupation: Synthetic Parsed Role Two',
            'parser_expected_fields' => [
                'occupation' => 'Different Expected Role Two',
            ],
        ])
    );

    try {
        [$exitCode, $payload] = failureAuditJson([
            '--dataset' => $path,
            '--limit' => 1,
        ]);

        $occupation = collect($payload['fields'])->firstWhere('field', 'occupation');

        expect($exitCode)->toBe(0)
            ->and($payload['detail_limit'])->toBe(1)
            ->and($payload['rows'])->toHaveCount(1)
            ->and($occupation['mismatched'])->toBe(2);
    } finally {
        File::delete(base_path($path));
    }
});

function failureAuditJson(array $parameters): array
{
    [$exitCode, $output] = failureAuditCall(array_merge($parameters, ['--json' => true]));
    $payload = json_decode(trim($output), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE);

    return [$exitCode, $payload, $output];
}

function failureAuditCall(array $parameters): array
{
    $output = new BufferedOutput;
    $exitCode = Artisan::call('intake:ocr-regression-failure-audit', $parameters, $output);

    return [$exitCode, $output->fetch()];
}

function writeFailureAuditDataset(string $contents): string
{
    $relative = 'storage/app/testing/intake-ocr-regression-failure-audit-'.uniqid().'.jsonl';
    $path = base_path($relative);
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);

    return $relative;
}

function failureAuditCase(array $overrides = []): string
{
    $case = array_merge([
        'case_id' => 'hidden_source_id',
        'layout_type' => 'single_column',
        'language' => 'en',
        'ocr_text' => 'Height: 5 ft 7 in',
        'parser_expected_fields' => [
            'height' => '5 ft 7 in',
        ],
    ], $overrides);

    return json_encode($case, JSON_UNESCAPED_SLASHES).PHP_EOL;
}
