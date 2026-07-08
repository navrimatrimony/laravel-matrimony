<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command compares two batches by item sequence and outputs mismatches', function () {
    $admin = comparisonCommandAdminUser();
    $cleanBatch = comparisonCommandBatch($admin, 'Clean text batch');
    $imageBatch = comparisonCommandBatch($admin, 'Image OCR batch');

    $cleanIntake = comparisonCommandIntake([
        'raw_ocr_text' => 'Name: Mismatch Candidate Mobile: 9876543210 Education: MBA',
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Mismatch Candidate',
                'primary_contact_number' => '9876543210',
                'highest_education' => 'MBA',
            ],
        ],
    ]);
    $imageIntake = comparisonCommandIntake([
        'raw_ocr_text' => 'Noisy OCR scan contains wrong candidate and wrong phone.',
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Wrong Candidate',
                'primary_contact_number' => '9000000000',
                'highest_education' => 'MBA',
            ],
        ],
    ]);

    comparisonCommandItem($cleanBatch, $cleanIntake, ['item_sequence' => 1]);
    comparisonCommandItem($imageBatch, $imageIntake, ['item_sequence' => 1]);

    $exitCode = Artisan::call('bulk-intake:compare-candidates', [
        'cleanTextBatchId' => $cleanBatch->id,
        'imageOcrBatchId' => $imageBatch->id,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('Bulk intake candidate comparison')
        ->toContain('Mismatch Candidate')
        ->toContain('Wrong Candidate')
        ->toContain('name,mobile')
        ->toContain('ocr_noisy_text')
        ->toContain('total compared: 1')
        ->toContain('education matched count: 1');
});

test('mobile comparison normalizes devanagari digits before comparing', function () {
    $admin = comparisonCommandAdminUser();
    $cleanBatch = comparisonCommandBatch($admin, 'Clean mobile batch');
    $imageBatch = comparisonCommandBatch($admin, 'Image mobile batch');

    $cleanIntake = comparisonCommandIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Mobile Candidate',
                'primary_contact_number' => '9876543210',
            ],
        ],
    ]);
    $imageIntake = comparisonCommandIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Mobile Candidate',
                'primary_contact_number' => '९८७६५४३२१०',
            ],
        ],
    ]);

    comparisonCommandItem($cleanBatch, $cleanIntake, ['item_sequence' => 1]);
    comparisonCommandItem($imageBatch, $imageIntake, ['item_sequence' => 1]);

    $exitCode = Artisan::call('bulk-intake:compare-candidates', [
        'cleanTextBatchId' => $cleanBatch->id,
        'imageOcrBatchId' => $imageBatch->id,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('mobile matched count: 1')
        ->toContain('matched')
        ->not->toContain('mobile,');
});

test('command matches gender and occupation from canonical parsed core fields', function () {
    $admin = comparisonCommandAdminUser();
    $cleanBatch = comparisonCommandBatch($admin, 'Clean canonical fields batch');
    $imageBatch = comparisonCommandBatch($admin, 'Image canonical fields batch');

    $cleanIntake = comparisonCommandIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Canonical Candidate',
                'gender' => 'male',
                'occupation_title' => 'ICICI Bank, Karad',
            ],
        ],
    ]);
    $imageIntake = comparisonCommandIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Canonical Candidate',
                'gender' => 'male',
                'occupation_title' => 'ICICI Bank, Karad',
            ],
        ],
    ]);

    comparisonCommandItem($cleanBatch, $cleanIntake, ['item_sequence' => 1]);
    comparisonCommandItem($imageBatch, $imageIntake, ['item_sequence' => 1]);

    $exitCode = Artisan::call('bulk-intake:compare-candidates', [
        'cleanTextBatchId' => $cleanBatch->id,
        'imageOcrBatchId' => $imageBatch->id,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('gender matched count: 1')
        ->toContain('occupation matched count: 1')
        ->toContain('none')
        ->toContain('matched');
});

test('command is read only for intakes and bulk items', function () {
    $admin = comparisonCommandAdminUser();
    $cleanBatch = comparisonCommandBatch($admin, 'Read only clean batch');
    $imageBatch = comparisonCommandBatch($admin, 'Read only image batch');

    $cleanIntake = comparisonCommandIntake([
        'raw_ocr_text' => 'Name: Read Only Candidate Mobile: 9876543210',
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Read Only Candidate']],
    ]);
    $imageIntake = comparisonCommandIntake([
        'raw_ocr_text' => 'Name: Read Only Candidate Mobile: 9876543210',
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Read Only Candidate']],
    ]);
    $cleanItem = comparisonCommandItem($cleanBatch, $cleanIntake, [
        'item_sequence' => 1,
        'item_meta_json' => ['before' => 'clean'],
    ]);
    $imageItem = comparisonCommandItem($imageBatch, $imageIntake, [
        'item_sequence' => 1,
        'item_meta_json' => ['before' => 'image'],
    ]);

    $before = [
        'clean_raw' => $cleanIntake->raw_ocr_text,
        'clean_parsed' => $cleanIntake->parsed_json,
        'image_raw' => $imageIntake->raw_ocr_text,
        'image_parsed' => $imageIntake->parsed_json,
        'clean_meta' => $cleanItem->item_meta_json,
        'image_meta' => $imageItem->item_meta_json,
    ];

    $exitCode = Artisan::call('bulk-intake:compare-candidates', [
        'cleanTextBatchId' => $cleanBatch->id,
        'imageOcrBatchId' => $imageBatch->id,
    ]);

    expect($exitCode)->toBe(0);
    expect($cleanIntake->fresh()->raw_ocr_text)->toBe($before['clean_raw'])
        ->and($cleanIntake->fresh()->parsed_json)->toEqual($before['clean_parsed'])
        ->and($imageIntake->fresh()->raw_ocr_text)->toBe($before['image_raw'])
        ->and($imageIntake->fresh()->parsed_json)->toEqual($before['image_parsed'])
        ->and($cleanItem->fresh()->item_meta_json)->toEqual($before['clean_meta'])
        ->and($imageItem->fresh()->item_meta_json)->toEqual($before['image_meta']);
});

test('missing image or clean intake is reported safely', function () {
    $admin = comparisonCommandAdminUser();
    $cleanBatch = comparisonCommandBatch($admin, 'Missing clean batch');
    $imageBatch = comparisonCommandBatch($admin, 'Missing image batch');

    $cleanIntake = comparisonCommandIntake([
        'parse_status' => 'parsed',
        'parsed_json' => ['core' => ['full_name' => 'Present Candidate']],
    ]);

    comparisonCommandItem($cleanBatch, $cleanIntake, ['item_sequence' => 1]);
    comparisonCommandItem($imageBatch, null, ['item_sequence' => 1]);

    $exitCode = Artisan::call('bulk-intake:compare-candidates', [
        'cleanTextBatchId' => $cleanBatch->id,
        'imageOcrBatchId' => $imageBatch->id,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('missing_image_intake')
        ->toContain('unknown')
        ->toContain('total compared: 1');
});

test('display trust gate hidden values are classified without crashing', function () {
    $admin = comparisonCommandAdminUser();
    $cleanBatch = comparisonCommandBatch($admin, 'Display gate clean batch');
    $imageBatch = comparisonCommandBatch($admin, 'Display gate image batch');

    $cleanIntake = comparisonCommandIntake([
        'raw_ocr_text' => 'Name: Display Gate Candidate',
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => ['full_name' => 'Display Gate Candidate'],
        ],
    ]);
    $imageIntake = comparisonCommandIntake([
        'raw_ocr_text' => 'Name: मामा Display Gate Candidate',
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => ['full_name' => 'मामा Display Gate Candidate'],
        ],
    ]);

    comparisonCommandItem($cleanBatch, $cleanIntake, ['item_sequence' => 1]);
    comparisonCommandItem($imageBatch, $imageIntake, ['item_sequence' => 1]);

    $exitCode = Artisan::call('bulk-intake:compare-candidates', [
        'cleanTextBatchId' => $cleanBatch->id,
        'imageOcrBatchId' => $imageBatch->id,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('display_trust_gate_hidden')
        ->toContain('suspected display issues count: 1');
});

function comparisonCommandAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function comparisonCommandBatch(User $admin, string $name): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => $name,
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function comparisonCommandIntake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'Name: Comparison Candidate',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function comparisonCommandItem(BulkIntakeBatch $batch, ?BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake?->id,
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
        'summary_text' => 'Comparison item',
    ], $overrides));
}
