<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('bulk list shows parsed candidate fields from linked intake', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-07 12:00:00'));

    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Display Candidate',
                'primary_contact_number' => '9876543210',
                'date_of_birth' => '1998-04-15',
                'height_cm' => 165,
                'gender' => 'female',
                'city_text' => 'Pune',
                'highest_education' => 'MCA',
                'occupation_title' => 'Software Developer',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $intake, [
        'original_filename' => 'display-candidate.pdf',
    ]);

    try {
        $this->actingAs($admin)
            ->get(route('admin.bulk-intakes.show', $batch))
            ->assertOk()
            ->assertSee('Display Candidate', false)
            ->assertSee('Mobile: 9876543210', false)
            ->assertSee('1998-04-15', false)
            ->assertSee('Age: 28', false)
            ->assertSee('165 cm', false)
            ->assertSee('Gender: Female', false)
            ->assertSee('Pune', false)
            ->assertSee('MCA', false)
            ->assertSee('Software Developer', false)
            ->assertSee('Parse: OK', false)
            ->assertSee('Parsed JSON: Yes', false);
    } finally {
        Carbon::setTestNow();
    }
});

test('bulk list handles missing candidate fields safely', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'parse_status' => 'parsed',
        'parsed_json' => [
            'core' => [
                'full_name' => 'Partial Candidate',
            ],
        ],
    ]);
    candidateDisplayItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Partial Candidate', false)
        ->assertSee('Mobile: —', false)
        ->assertSee('Age: —', false)
        ->assertSee('Gender: —', false)
        ->assertSee('Parsed JSON: Yes', false);
});

test('bulk list explains queued and pending free parse states', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $queuedIntake = candidateDisplayIntake([
        'parse_status' => 'pending',
        'parsed_json' => [],
    ]);
    candidateDisplayItem($batch, $queuedIntake, [
        'item_status' => BulkIntakeBatchItem::STATUS_PARSE_QUEUED,
    ]);
    $pendingIntake = candidateDisplayIntake([
        'parse_status' => 'pending',
        'parsed_json' => [],
    ]);
    candidateDisplayItem($batch, $pendingIntake, [
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Candidate fields appear after free parse completes. Manual transcript is only needed if OCR/free parse fails.', false)
        ->assertSee('Free parse queued', false)
        ->assertSee('Waiting for free parse', false)
        ->assertSee('Mobile: —', false);
});

test('bulk list shows OCR failure diagnostics when parsed json is missing', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $intake = candidateDisplayIntake([
        'raw_ocr_text' => '',
        'parse_status' => 'pending',
        'parsed_json' => [],
    ]);
    candidateDisplayItem($batch, $intake, [
        'item_status' => BulkIntakeBatchItem::STATUS_NEEDS_REVIEW,
        'failure_code' => 'empty_ocr_text',
        'failure_message' => 'OCR did not extract usable text from this file.',
        'item_meta_json' => [
            'ocr_text_usable' => false,
            'ocr_failure_code' => 'empty_ocr_text',
            'ocr_failure_message' => 'OCR did not extract usable text from this file.',
            'auto_parse_skipped_reason' => 'empty_ocr_text',
        ],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Mobile: —', false)
        ->assertSee('Parsed JSON: No', false)
        ->assertSee('OCR failed: no text extracted', false)
        ->assertSee('OCR failed / no text extracted', false)
        ->assertSee('Add manual transcript (OCR failed fallback)', false);
});

test('bulk list does not store parsed data on bulk item', function () {
    expect(Schema::hasColumn('bulk_intake_batch_items', 'parsed_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'profile_data_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'parsed_profile_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'normalized_profile_json'))->toBeFalse();
});

test('owner and profile actions stay hidden from main list while extraction actions remain visible', function () {
    $admin = candidateDisplayAdminUser();
    $batch = candidateDisplayBatch($admin);
    $pendingIntake = candidateDisplayIntake([
        'uploaded_by' => null,
        'parse_status' => 'pending',
        'parsed_json' => [],
    ]);
    candidateDisplayItem($batch, $pendingIntake);
    $fallbackIntake = candidateDisplayIntake([
        'uploaded_by' => null,
        'parse_status' => 'error',
        'last_error' => 'empty_text',
        'parsed_json' => [],
    ]);
    candidateDisplayItem($batch, $fallbackIntake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('Open intake review', false)
        ->assertSee('Profile Readiness details', false)
        ->assertSee('Add manual transcript (OCR failed fallback)', false)
        ->assertSee('Queue free parse item', false)
        ->assertSee('Mark needs review', false)
        ->assertDontSee('Assign owner', false)
        ->assertDontSee('Create owner', false)
        ->assertDontSee('Create draft profile', false)
        ->assertDontSee('Preview parsed fields', false);
});

test('routes for hidden owner and profile actions still exist', function () {
    expect(route('admin.bulk-intakes.items.assign-owner', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))
        ->toBe(url('/admin/bulk-intakes/123/items/456/assign-owner'))
        ->and(route('admin.bulk-intakes.items.create-owner', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))
        ->toBe(url('/admin/bulk-intakes/123/items/456/create-owner'))
        ->and(route('admin.bulk-intakes.items.bootstrap-draft-profile', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))
        ->toBe(url('/admin/bulk-intakes/123/items/456/bootstrap-draft-profile'))
        ->and(route('admin.bulk-intakes.items.apply-preview', ['bulkIntakeBatch' => 123, 'bulkIntakeBatchItem' => 456]))
        ->toBe(url('/admin/bulk-intakes/123/items/456/apply-preview'));
});

function candidateDisplayAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function candidateDisplayBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Candidate display batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function candidateDisplayIntake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'Name: Candidate Display',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function candidateDisplayItem(BulkIntakeBatch $batch, BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'candidate-display.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}
