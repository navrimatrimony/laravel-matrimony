<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\BulkIntakeIdentityHistory;
use App\Models\User;
use App\Services\Intake\BulkIntakeCandidateScreeningReviewService;
use App\Services\Intake\BulkIntakeDuplicateGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('identity history auto blocks candidate with same mobile', function () {
    $admin = duplicateGateAdmin();
    $batch = duplicateGateBatch($admin);

    $blockedItem = duplicateGateItem($batch, duplicateGateIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Married Candidate',
                'primary_contact_number' => '9876543210',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]));

    BulkIntakeIdentityHistory::query()->create([
        'reason_code' => BulkIntakeIdentityHistory::REASON_ALREADY_MARRIED,
        'normalized_mobile' => '9876543210',
        'normalized_name' => 'married candidate',
        'normalized_dob' => '19980415',
        'normalized_gender' => 'female',
        'source_type' => BulkIntakeIdentityHistory::SOURCE_ADMIN_SCREENING,
        'source_bulk_intake_batch_item_id' => $blockedItem->id,
        'recorded_by_user_id' => $admin->id,
    ]);

    $newIntake = duplicateGateIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'New Upload Same Mobile',
                'primary_contact_number' => '9876543210',
                'date_of_birth' => '2000-01-01',
                'gender' => 'female',
            ],
        ],
    ]);
    duplicateGateItem($batch, $newIntake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-pipeline-badge"', false)
        ->assertSee('Blocked', false)
        ->assertDontSee('data-testid="bulk-screening-badge"', false)
        ->assertSee('Already married', false)
        ->assertSee('data-testid="bulk-identity-history-block"', false)
        ->assertSee('data-testid="bulk-override-duplicate-block"', false);
});

test('admin can override duplicate history auto block and proceed', function () {
    $admin = duplicateGateAdmin();
    $batch = duplicateGateBatch($admin);

    BulkIntakeIdentityHistory::query()->create([
        'reason_code' => BulkIntakeIdentityHistory::REASON_NOT_INTERESTED,
        'normalized_mobile' => '9876543290',
        'source_type' => BulkIntakeIdentityHistory::SOURCE_ADMIN_SCREENING,
        'recorded_by_user_id' => $admin->id,
    ]);

    $intake = duplicateGateIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Override Candidate',
                'primary_contact_number' => '9876543290',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    $item = duplicateGateItem($batch, $intake);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.override-duplicate-block', [$batch, $item]), [
            'reason' => 'Different person with recycled number',
        ])
        ->assertRedirect();

    $item->refresh();

    expect(data_get($item->item_meta_json, 'duplicate_gate_override.status'))->toBe(BulkIntakeDuplicateGateService::OVERRIDE_PROCEED);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-duplicate-override-badge"', false)
        ->assertSee('Override: proceed', false)
        ->assertSee('data-testid="bulk-clear-duplicate-override"', false)
        ->assertDontSee('data-testid="bulk-override-duplicate-block"', false);
});

test('stopped screening already married records permanent identity history', function () {
    $admin = duplicateGateAdmin();
    $batch = duplicateGateBatch($admin);
    $intake = duplicateGateIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'History Source Candidate',
                'primary_contact_number' => '9876543288',
                'date_of_birth' => '1995-06-20',
                'gender' => 'male',
            ],
        ],
    ]);
    $item = duplicateGateItem($batch, $intake);

    app(BulkIntakeCandidateScreeningReviewService::class)->saveReview($item, $admin, [
        'status' => BulkIntakeCandidateScreeningReviewService::STATUS_STOPPED,
        'reason_key' => 'already_married',
        'note' => 'Confirmed married on call',
    ]);

    expect(BulkIntakeIdentityHistory::query()->where('reason_code', 'already_married')->count())->toBe(1);

    $history = BulkIntakeIdentityHistory::query()->first();
    expect($history?->normalized_mobile)->toBe('9876543288')
        ->and($history?->normalized_name)->toBe('history source candidate')
        ->and($history?->source_bulk_intake_batch_item_id)->toBe($item->id);
});

test('same mobile with matching identity auto stops instead of only review', function () {
    $admin = duplicateGateAdmin();
    $batch = duplicateGateBatch($admin);

    duplicateGateIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Priya Kadam',
                'primary_contact_number' => '9876543277',
                'date_of_birth' => '2001-09-07',
                'gender' => 'female',
                'highest_education' => 'B.Sc.',
            ],
        ],
    ]);

    $currentIntake = duplicateGateIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Priya Kadam',
                'primary_contact_number' => '9876543277',
                'date_of_birth' => '2001-09-07',
                'gender' => 'female',
                'highest_education' => 'B.Sc.',
            ],
        ],
    ]);
    duplicateGateItem($batch, $currentIntake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-pipeline-badge"', false)
        ->assertSee('Blocked', false)
        ->assertDontSee('data-testid="bulk-screening-badge"', false)
        ->assertSee('data-testid="bulk-auto-duplicate-block"', false)
        ->assertSee('Same mobile', false);
});

test('same mobile with different identity stays needs review not auto stop', function () {
    $admin = duplicateGateAdmin();
    $batch = duplicateGateBatch($admin);

    duplicateGateIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Existing Candidate',
                'primary_contact_number' => '9876543210',
            ],
        ],
    ]);

    $currentIntake = duplicateGateIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Different Name Candidate',
                'primary_contact_number' => '9876543210',
            ],
        ],
    ]);
    duplicateGateItem($batch, $currentIntake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-pipeline-badge"', false)
        ->assertSee('Needs check', false)
        ->assertSee('Possible duplicate', false)
        ->assertDontSee('data-testid="bulk-screening-badge"', false)
        ->assertDontSee('data-testid="bulk-auto-duplicate-block"', false);
});

test('duplicate gate service reports history blocks for matching identity keys', function () {
    $admin = duplicateGateAdmin();
    $batch = duplicateGateBatch($admin);
    $intake = duplicateGateIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Name Dob Match',
                'date_of_birth' => '1992-03-10',
                'gender' => 'female',
            ],
        ],
    ]);
    $item = duplicateGateItem($batch, $intake);

    BulkIntakeIdentityHistory::query()->create([
        'reason_code' => BulkIntakeIdentityHistory::REASON_DO_NOT_SUGGEST,
        'normalized_name' => 'name dob match',
        'normalized_dob' => '19920310',
        'source_type' => BulkIntakeIdentityHistory::SOURCE_ADMIN_SCREENING,
        'recorded_by_user_id' => $admin->id,
    ]);

    $gate = app(BulkIntakeDuplicateGateService::class)->evaluateForItem($item);

    expect($gate['auto_blocked'])->toBeTrue()
        ->and($gate['blocks'][0]['code'] ?? null)->toBe('do_not_suggest')
        ->and($gate['history_blocks'])->not->toBeEmpty();
});

test('manual duplicate mark records identity history', function () {
    $admin = duplicateGateAdmin();
    $batch = duplicateGateBatch($admin);
    $intake = duplicateGateIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Manual Dup Candidate',
                'primary_contact_number' => '9876543266',
            ],
        ],
    ]);
    $item = duplicateGateItem($batch, $intake);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.mark-duplicate', [$batch, $item]), [
            'reason' => 'Already exists in system',
        ])
        ->assertRedirect();

    expect(BulkIntakeIdentityHistory::query()->where('reason_code', 'do_not_suggest')->count())->toBe(1);
});

test('batch show renders record history actions for linked intake', function () {
    $admin = duplicateGateAdmin();
    $batch = duplicateGateBatch($admin);
    $intake = duplicateGateIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'History Action Candidate',
                'primary_contact_number' => '9876543255',
            ],
        ],
    ]);
    duplicateGateItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Record history', false)
        ->assertSee('data-testid="bulk-mark-already-married"', false)
        ->assertSee('data-testid="bulk-mark-not-interested"', false)
        ->assertSee('data-testid="bulk-mark-wrong-number"', false);
});

test('admin can mark already married from batch show and future same mobile auto blocks', function () {
    $admin = duplicateGateAdmin();
    $batch = duplicateGateBatch($admin);
    $sourceIntake = duplicateGateIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Source Married Candidate',
                'primary_contact_number' => '9876543244',
                'date_of_birth' => '1994-02-10',
                'gender' => 'female',
            ],
        ],
    ]);
    $sourceItem = duplicateGateItem($batch, $sourceIntake);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.save-screening-review', [$batch, $sourceItem]), [
            'status' => 'stopped',
            'reason_key' => 'already_married',
            'note' => 'Confirmed on phone',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(BulkIntakeIdentityHistory::query()->where('reason_code', 'already_married')->count())->toBe(1);

    $newIntake = duplicateGateIntake([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Future Upload Same Mobile',
                'primary_contact_number' => '9876543244',
                'date_of_birth' => '1999-01-01',
                'gender' => 'female',
            ],
        ],
    ]);
    duplicateGateItem($batch, $newIntake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Future Upload Same Mobile', false)
        ->assertSee('data-testid="bulk-identity-history-block"', false)
        ->assertSee('History: Already married', false)
        ->assertSee('data-testid="bulk-override-duplicate-block"', false);
});

function duplicateGateAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function duplicateGateBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Duplicate gate batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function duplicateGateIntake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'Duplicate gate OCR',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function duplicateGateItem(BulkIntakeBatch $batch, BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'duplicate-gate.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}
