<?php

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\IntakeSourceContext;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Intake\BulkIntakeBatchService;
use App\Services\Intake\IntakeCreationService;
use App\Services\Intake\IntakeSourceContextRecorder;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('admin can create batch for existing user with raw text items', function () {
    Queue::fake();
    $admin = adminBulkIntakeAdmin();
    $member = adminBulkIntakeMember();

    $response = $this->actingAs($admin)->post(route('admin.bulk-intakes.store'), [
        'batch_name' => 'Two text biodatas',
        'owner_user_id' => $member->id,
        'raw_text' => "Name: First Candidate\nMobile: 9000000001\n---INTAKE---\nName: Second Candidate\nMobile: 9000000002",
    ]);

    $batch = BulkIntakeBatch::query()->sole();

    $response->assertRedirect(route('admin.bulk-intakes.show', $batch));
    expect($batch->fresh()->batch_status)->toBe(BulkIntakeBatch::STATUS_COMPLETED)
        ->and($batch->fresh()->total_items)->toBe(2)
        ->and($batch->fresh()->total_texts)->toBe(2)
        ->and($batch->fresh()->total_intakes_created)->toBe(2)
        ->and(BulkIntakeBatchItem::count())->toBe(2)
        ->and(BiodataIntake::count())->toBe(2)
        ->and(IntakeSourceContext::count())->toBe(2)
        ->and(MatrimonyProfile::count())->toBe(0);

    BiodataIntake::query()->get()->each(function (BiodataIntake $intake) use ($member): void {
        expect((int) $intake->uploaded_by)->toBe((int) $member->id)
            ->and($intake->parse_status)->toBe('pending');
    });

    IntakeSourceContext::query()->get()->each(function (IntakeSourceContext $context) use ($admin, $batch): void {
        expect($context->source_type)->toBe(IntakeSourceContext::SOURCE_ADMIN_BULK)
            ->and($context->source_surface)->toBe(IntakeSourceContext::SURFACE_ADMIN_PANEL)
            ->and($context->actor_type)->toBe(IntakeSourceContext::ACTOR_ADMIN)
            ->and((int) $context->actor_user_id)->toBe((int) $admin->id)
            ->and((int) $context->bulk_intake_batch_id)->toBe((int) $batch->id)
            ->and($context->bulk_intake_batch_item_id)->not->toBeNull()
            ->and($context->biodata_intake_id)->not->toBeNull();
    });

    Queue::assertNotPushed(ParseIntakeJob::class);
});

test('admin can create batch with multiple files through existing intake preparation path', function () {
    Queue::fake();
    $admin = adminBulkIntakeAdmin();
    $member = adminBulkIntakeMember();

    $this->mock(OcrService::class, function ($mock): void {
        $mock->shouldReceive('extractTextFromPath')->twice()->andReturn(
            'Name: File Candidate One',
            'Name: File Candidate Two',
        );
        $mock->shouldReceive('getLastExtractTextFromPathDebug')->twice()->andReturn([
            'kind' => 'image',
            'ocr_pipeline' => 'test',
        ]);
    });

    $response = $this->actingAs($admin)->post(route('admin.bulk-intakes.store'), [
        'batch_name' => 'Two file biodatas',
        'owner_user_id' => $member->id,
        'files' => [
            UploadedFile::fake()->createWithContent('candidate-one.jpg', 'file-one-bytes'),
            UploadedFile::fake()->createWithContent('candidate-two.jpg', 'file-two-bytes'),
        ],
    ]);

    $batch = BulkIntakeBatch::query()->sole();

    $response->assertRedirect(route('admin.bulk-intakes.show', $batch));
    expect($batch->fresh()->total_items)->toBe(2)
        ->and($batch->fresh()->total_files)->toBe(2)
        ->and($batch->fresh()->total_intakes_created)->toBe(2)
        ->and(BiodataIntake::count())->toBe(2)
        ->and(IntakeSourceContext::count())->toBe(2)
        ->and(MatrimonyProfile::count())->toBe(0);

    expect(BiodataIntake::query()->pluck('raw_ocr_text')->all())->toBe([
        'Name: File Candidate One',
        'Name: File Candidate Two',
    ]);

    Queue::assertNotPushed(ParseIntakeJob::class);
});

test('bulk intake batch item does not store parsed profile data columns', function () {
    expect(Schema::hasColumn('bulk_intake_batch_items', 'parsed_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'profile_data_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'parsed_profile_json'))->toBeFalse()
        ->and(Schema::hasColumn('bulk_intake_batch_items', 'normalized_profile_json'))->toBeFalse();
});

test('owner user cannot be admin', function () {
    Queue::fake();
    $admin = adminBulkIntakeAdmin();
    $adminOwner = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this
        ->actingAs($admin)
        ->from(route('admin.bulk-intakes.create'))
        ->post(route('admin.bulk-intakes.store'), [
            'owner_user_id' => $adminOwner->id,
            'raw_text' => 'Name: Should Not Create',
        ]);

    $response->assertRedirect(route('admin.bulk-intakes.create'));
    $response->assertSessionHasErrors('owner_user_id');
    expect(BulkIntakeBatch::count())->toBe(0)
        ->and(BulkIntakeBatchItem::count())->toBe(0)
        ->and(BiodataIntake::count())->toBe(0)
        ->and(MatrimonyProfile::count())->toBe(0);
    Queue::assertNotPushed(ParseIntakeJob::class);
});

test('item retry idempotency does not duplicate biodata intake', function () {
    Queue::fake();
    $admin = adminBulkIntakeAdmin();
    $member = adminBulkIntakeMember();
    $batchService = app(BulkIntakeBatchService::class);
    $batch = $batchService->createBatch([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
    ]);
    $item = $batchService->addItem($batch, [
        'item_sequence' => 1,
        'input_type' => BulkIntakeBatchItem::INPUT_TEXT,
        'raw_text_hash' => hash('sha256', 'Name: Retry Candidate'),
        'idempotency_key' => 'retry-bulk-item-one',
    ]);

    $first = $batchService->createIntakeForItem(
        $item,
        $member,
        $admin,
        app(IntakeCreationService::class),
        app(IntakeSourceContextRecorder::class),
        null,
        'Name: Retry Candidate'
    );
    $second = $batchService->createIntakeForItem(
        $first,
        $member,
        $admin,
        app(IntakeCreationService::class),
        app(IntakeSourceContextRecorder::class),
        null,
        'Name: Retry Candidate'
    );

    expect($second->id)->toBe($item->id)
        ->and($second->biodata_intake_id)->toBe($first->biodata_intake_id)
        ->and($second->item_status)->toBe(BulkIntakeBatchItem::STATUS_INTAKE_CREATED)
        ->and(BiodataIntake::count())->toBe(1)
        ->and(IntakeSourceContext::count())->toBe(1);
    Queue::assertNotPushed(ParseIntakeJob::class);
});

function adminBulkIntakeAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function adminBulkIntakeMember(): User
{
    return User::factory()->create([
        'is_admin' => false,
        'admin_role' => null,
    ]);
}
