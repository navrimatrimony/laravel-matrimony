<?php

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeSourceContext;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\AiVisionExtractionService;
use App\Services\Intake\IntakeExtractionReuseResolver;
use App\Services\MutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

test('admin can view manual transcript form for item with linked intake', function () {
    $admin = manualTranscriptAdminUser();
    $batch = manualTranscriptBatch($admin);
    $intake = manualTranscriptIntake(null, [
        'parse_status' => 'error',
        'last_error' => 'empty_text',
    ]);
    $item = manualTranscriptItem($batch, $intake);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.items.manual-transcript', [$batch, $item]))
        ->assertOk()
        ->assertSee('This does not overwrite raw OCR text', false)
        ->assertSee('Manual transcript', false);
});

test('admin can save manual transcript without overwriting raw ocr text', function () {
    Queue::fake();

    $admin = manualTranscriptAdminUser();
    $batch = manualTranscriptBatch($admin);
    $intake = manualTranscriptIntake(null, [
        'raw_ocr_text' => '',
        'parsed_json' => ['core' => ['full_name' => 'Existing Parsed']],
        'parse_status' => 'error',
        'last_error' => 'reparse_no_canonical_or_raw_ocr',
    ]);
    $item = manualTranscriptItem($batch, $intake, [
        'item_status' => BulkIntakeBatchItem::STATUS_FAILED,
    ]);
    $transcript = manualTranscriptText();

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.manual-transcript.store', [$batch, $item]), [
            'transcript' => $transcript,
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    $intake->refresh();
    $attempt = BiodataIntakeOcrAttempt::query()->where('intake_id', $intake->id)->sole();
    $context = IntakeSourceContext::query()->where('biodata_intake_id', $intake->id)->sole();

    expect($intake->raw_ocr_text)->toBe('')
        ->and($intake->last_parse_input_text)->toBe($transcript)
        ->and($intake->parse_status)->toBe('pending')
        ->and($intake->last_error)->toBeNull()
        ->and($intake->parsed_json)->toBe(['core' => ['full_name' => 'Existing Parsed']])
        ->and($item->fresh()->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and($attempt->engine)->toBe(BiodataIntakeOcrAttempt::ENGINE_MANUAL_TRANSCRIPT)
        ->and($attempt->source)->toBe('bulk_manual_transcript')
        ->and((bool) $attempt->is_primary)->toBeTrue()
        ->and($attempt->raw_text)->toBe($transcript)
        ->and($context->source_meta_json['action'])->toBe('manual_transcript_saved')
        ->and($context->source_meta_json['queue_parse'])->toBeFalse()
        ->and($context->source_meta_json['raw_ocr_text_unchanged'])->toBeTrue();

    Queue::assertNotPushed(ParseIntakeJob::class);
});

test('save manual transcript and queue parse dispatches parse input only job', function () {
    Queue::fake();

    $admin = manualTranscriptAdminUser();
    $batch = manualTranscriptBatch($admin);
    $intake = manualTranscriptIntake(null, [
        'parse_status' => 'error',
        'last_error' => 'empty_text',
    ]);
    $item = manualTranscriptItem($batch, $intake, [
        'item_status' => BulkIntakeBatchItem::STATUS_FAILED,
    ]);
    $transcript = manualTranscriptText('Queue Parse Candidate');

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.manual-transcript.store', [$batch, $item]), [
            'transcript' => $transcript,
            'queue_parse' => '1',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    expect(IntakeExtractionReuseResolver::peekParseInputOnlyFlag((int) $intake->id))->toBeTrue()
        ->and($intake->fresh()->last_parse_input_text)->toBe($transcript)
        ->and($item->fresh()->item_status)->toBe(BulkIntakeBatchItem::STATUS_PARSE_QUEUED)
        ->and($item->fresh()->item_meta_json['parse_queue_mode'])->toBe('manual_transcript_parse_input_only');

    Queue::assertPushed(ParseIntakeJob::class, function (ParseIntakeJob $job) use ($intake): bool {
        return (int) $job->intakeId === (int) $intake->id && $job->forceRecompute === true;
    });
});

test('transcript required and min length enforced', function () {
    $admin = manualTranscriptAdminUser();
    $batch = manualTranscriptBatch($admin);
    $intake = manualTranscriptIntake(null);
    $item = manualTranscriptItem($batch, $intake);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.manual-transcript.store', [$batch, $item]), [
            'transcript' => '',
        ])
        ->assertSessionHasErrors('transcript');

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.manual-transcript.store', [$batch, $item]), [
            'transcript' => 'too short',
        ])
        ->assertSessionHasErrors('transcript');

    expect(BiodataIntakeOcrAttempt::count())->toBe(0)
        ->and($intake->fresh()->last_parse_input_text)->toBeNull();
});

test('non admin cannot access manual transcript flow', function () {
    $admin = manualTranscriptAdminUser();
    $member = manualTranscriptMemberUser();
    $batch = manualTranscriptBatch($admin);
    $intake = manualTranscriptIntake(null);
    $item = manualTranscriptItem($batch, $intake);

    $this->actingAs($member)
        ->get(route('admin.bulk-intakes.items.manual-transcript', [$batch, $item]))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('admin.bulk-intakes.items.manual-transcript.store', [$batch, $item]), [
            'transcript' => manualTranscriptText(),
            'queue_parse' => '1',
        ])
        ->assertForbidden();

    expect(BiodataIntakeOcrAttempt::count())->toBe(0)
        ->and($intake->fresh()->last_parse_input_text)->toBeNull();
});

test('manual transcript does not call paid vision provider or profile apply', function () {
    Queue::fake();

    $this->partialMock(AiVisionExtractionService::class, function ($mock): void {
        $mock->shouldNotReceive('extractTextForIntake');
    });
    $this->partialMock(MutationService::class, function ($mock): void {
        $mock->shouldNotReceive('createDraftProfileForUser');
        $mock->shouldNotReceive('applyManualSnapshot');
    });

    $admin = manualTranscriptAdminUser();
    $batch = manualTranscriptBatch($admin);
    $intake = manualTranscriptIntake(null, [
        'parse_status' => 'error',
        'last_error' => 'empty_text',
    ]);
    $item = manualTranscriptItem($batch, $intake);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.manual-transcript.store', [$batch, $item]), [
            'transcript' => manualTranscriptText('No Paid Vision Candidate'),
            'queue_parse' => '1',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    expect(MatrimonyProfile::count())->toBe(0)
        ->and($intake->fresh()->parsed_json)->toBe([]);
});

test('manual transcript can recover item from parse error state to queued pending parse', function () {
    Queue::fake();

    $admin = manualTranscriptAdminUser();
    $batch = manualTranscriptBatch($admin);
    $intake = manualTranscriptIntake(null, [
        'parse_status' => 'error',
        'last_error' => 'reparse_no_canonical_or_raw_ocr',
    ]);
    $item = manualTranscriptItem($batch, $intake, [
        'item_status' => BulkIntakeBatchItem::STATUS_FAILED,
        'failure_code' => 'parse_failed',
        'failure_message' => 'reparse_no_canonical_or_raw_ocr',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.manual-transcript.store', [$batch, $item]), [
            'transcript' => manualTranscriptText('Recovered Candidate'),
            'queue_parse' => '1',
        ])
        ->assertRedirect(route('admin.bulk-intakes.show', $batch));

    $intake->refresh();
    $item->refresh();

    expect($intake->parse_status)->toBe('pending')
        ->and($intake->last_error)->toBeNull()
        ->and($item->item_status)->toBe(BulkIntakeBatchItem::STATUS_PARSE_QUEUED)
        ->and($item->failure_code)->toBeNull()
        ->and($item->failure_message)->toBeNull();
});

function manualTranscriptAdminUser(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function manualTranscriptMemberUser(): User
{
    return User::factory()->create([
        'is_admin' => false,
        'admin_role' => null,
    ]);
}

function manualTranscriptBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Manual transcript batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function manualTranscriptItem(BulkIntakeBatch $batch, BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'manual-transcript.jpg',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}

function manualTranscriptIntake(?User $owner, array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => $owner?->id,
        'raw_ocr_text' => '',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function manualTranscriptText(string $name = 'Manual Transcript Candidate'): string
{
    return $name."\nजन्म तारीख : 12/03/1996\nमोबाईल : 9876543210\nशिक्षण : B.Com\nनोकरी : Private Service\nधर्म : Hindu\nजात : Maratha";
}
