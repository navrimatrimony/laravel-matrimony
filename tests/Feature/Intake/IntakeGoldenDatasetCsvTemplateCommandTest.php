<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

test('template creates CSV under storage app intake golden datasets', function () {
    $path = goldenCsvTemplatePath('golden-template.csv');

    try {
        $exitCode = Artisan::call('intake:golden-dataset-csv-template', [
            '--output' => $path,
            '--rows' => 2,
        ]);
        $output = Artisan::output();
        $rows = goldenCsvTemplateRows($path);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Private golden dataset CSV template written.')
            ->and($output)->toContain($path)
            ->and(File::exists(base_path($path)))->toBeTrue()
            ->and($rows)->toHaveCount(3)
            ->and($rows[0])->toBe(goldenCsvTemplateColumns())
            ->and($rows[1])->toBe(array_fill(0, count(goldenCsvTemplateColumns()), ''))
            ->and($rows[2])->toBe(array_fill(0, count(goldenCsvTemplateColumns()), ''));
    } finally {
        goldenCsvTemplateDelete($path);
    }
});

test('template refuses overwrite without force', function () {
    $path = goldenCsvTemplatePath('golden-template-existing.csv');

    try {
        File::ensureDirectoryExists(dirname(base_path($path)));
        File::put(base_path($path), goldenCsvTemplateMarker());

        $exitCode = Artisan::call('intake:golden-dataset-csv-template', [
            '--output' => $path,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Output file already exists')
            ->and(File::get(base_path($path)))->toBe(goldenCsvTemplateMarker());
    } finally {
        goldenCsvTemplateDelete($path);
    }
});

test('template rejects output outside allowed private directory', function (string $path) {
    $exitCode = Artisan::call('intake:golden-dataset-csv-template', [
        '--output' => $path,
        '--json' => true,
    ]);
    $payload = json_decode(trim(Artisan::output()), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($exitCode)->toBe(1)
        ->and($payload['success'])->toBeFalse()
        ->and($payload['error']['code'])->toBe('output_path_not_allowed');
})->with([
    'storage/app/golden-curation-template.csv',
    'storage/app/intake-golden-datasets/../golden-curation-template.csv',
    'tests/Fixtures/Intake/golden-curation-template.csv',
    '.env',
]);

test('template JSON output is valid', function () {
    $path = goldenCsvTemplatePath('golden-template-json.csv');

    try {
        $exitCode = Artisan::call('intake:golden-dataset-csv-template', [
            '--output' => $path,
            '--rows' => 1,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE)
            ->and($exitCode)->toBe(0)
            ->and($payload['success'])->toBeTrue()
            ->and($payload['output_path'])->toBe($path)
            ->and($payload['summary']['blank_rows'])->toBe(1)
            ->and($payload['summary']['column_count'])->toBe(count(goldenCsvTemplateColumns()))
            ->and($payload['convert_command'])->toBe('php artisan intake:golden-dataset-csv-to-jsonl --csv='.$path);
    } finally {
        goldenCsvTemplateDelete($path);
    }
});

function goldenCsvTemplatePath(string $filename): string
{
    return 'storage/app/intake-golden-datasets/'.uniqid().'-'.$filename;
}

function goldenCsvTemplateDelete(string $path): void
{
    File::delete(base_path($path));
}

/**
 * @return list<list<string>>
 */
function goldenCsvTemplateRows(string $path): array
{
    $lines = preg_split('/\r\n|\n|\r/', trim(File::get(base_path($path))));

    return collect($lines)
        ->filter(fn (string $line): bool => $line !== '')
        ->map(fn (string $line): array => str_getcsv($line))
        ->values()
        ->all();
}

function goldenCsvTemplateMarker(): string
{
    return 'do-not-overwrite'.PHP_EOL;
}

/**
 * @return list<string>
 */
function goldenCsvTemplateColumns(): array
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
