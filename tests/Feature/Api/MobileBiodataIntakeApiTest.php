<?php

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('mobile biodata intake store creates owner scoped rules only intake from OCR text', function () {
    Queue::fake();

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $rawText = "नाव : राहुल पाटील\nजन्म तारीख : १२/०४/१९९६\nशिक्षण : B.Com\nमोबाईल : 9876543210";

    $response = $this->postJson('/api/v1/biodata-intakes', [
        'raw_text' => $rawText,
        'parse_now' => false,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('intake.parse_status', 'pending')
        ->assertJsonPath('intake.parser_version', 'rules_only');

    $intakeId = (int) $response->json('intake.id');
    $this->assertDatabaseHas('biodata_intakes', [
        'id' => $intakeId,
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $rawText,
        'parser_version' => 'rules_only',
    ]);

    Queue::assertPushed(ParseIntakeJob::class);
});

test('mobile biodata intake list and show are scoped to the authenticated user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $ownIntake = BiodataIntake::create([
        'uploaded_by' => $owner->id,
        'raw_ocr_text' => 'owner biodata text',
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);
    $otherIntake = BiodataIntake::create([
        'uploaded_by' => $other->id,
        'raw_ocr_text' => 'other biodata text',
        'intake_status' => 'uploaded',
        'parse_status' => 'pending',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/biodata-intakes')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'intakes')
        ->assertJsonPath('intakes.0.id', $ownIntake->id);

    $this->getJson('/api/v1/biodata-intakes/'.$ownIntake->id)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('intake.id', $ownIntake->id);

    $this->getJson('/api/v1/biodata-intakes/'.$otherIntake->id)
        ->assertNotFound();
});

test('mobile biodata intake preview returns parsed snapshot and normalized draft', function () {
    $user = User::factory()->create();
    $rawText = "नाव : सुलोचना शिंदे\nजन्म तारीख : १४-०६-२००१\nशिक्षण : B.A.";
    $snapshot = [
        'snapshot_schema_version' => 1,
        'core' => [
            'full_name' => 'सुलोचना शिंदे',
            'date_of_birth' => '2001-06-14',
            'highest_education' => 'B.A.',
        ],
        'contacts' => [],
        'children' => [],
    ];

    $intake = BiodataIntake::create([
        'uploaded_by' => $user->id,
        'raw_ocr_text' => $rawText,
        'last_parse_input_text' => $rawText,
        'parsed_json' => $snapshot,
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parsed_at' => now(),
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/biodata-intakes/'.$intake->id.'/preview')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('ready', true)
        ->assertJsonPath('preview.parsed_snapshot.core.full_name', 'सुलोचना शिंदे')
        ->assertJsonPath('preview.normalized_draft.available', true)
        ->assertJsonPath('preview.source', 'parse_snapshot');
});
