<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\BulkIntakeRegistrationService;
use App\Services\Intake\BulkIntakeWhatsAppConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('registration summary is blocked until consent received', function () {
    $admin = registrationAdmin();
    $item = registrationEligibleItem();

    expect(fn () => app(BulkIntakeRegistrationService::class)->sendRegistrationSummary($item, $admin))
        ->toThrow(ValidationException::class);
});

test('consent received candidate gets fast path when all fields look ready', function () {
    $item = registrationConsentReceivedItem();

    $summary = app(BulkIntakeRegistrationService::class)->summaryForItem($item);

    expect($summary['path'])->toBe(BulkIntakeRegistrationService::PATH_FAST)
        ->and($summary['warning_count'])->toBe(0)
        ->and(collect($summary['fields'])->every(fn (array $field): bool => $field['icon'] === '✓'))->toBeTrue();
});

test('missing mobile marks targeted or full registration path', function () {
    $item = registrationEligibleItem([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Missing Mobile Registration',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
            ],
        ],
    ]);
    $item = registrationMarkConsentReceived($item);

    $summary = app(BulkIntakeRegistrationService::class)->summaryForItem($item);

    expect($summary['path'])->not->toBe(BulkIntakeRegistrationService::PATH_FAST)
        ->and(collect($summary['fields'])->firstWhere('key', 'mobile')['status'])
        ->toBe(BulkIntakeRegistrationService::FIELD_MISSING);
});

test('admin can send registration summary after consent received', function () {
    $admin = registrationAdmin();
    $item = registrationConsentReceivedItem();

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.send-registration-summary', [
            'bulkIntakeBatch' => $item->bulk_intake_batch_id,
            'bulkIntakeBatchItem' => $item->id,
        ]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(app(BulkIntakeRegistrationService::class)->registrationStatus($item->fresh()))
        ->toBe(BulkIntakeRegistrationService::STATUS_SUMMARY_SENT);
});

test('fast path registration complete can be simulated after summary sent', function () {
    $admin = registrationAdmin();
    $item = registrationConsentReceivedItem();
    $registrationService = app(BulkIntakeRegistrationService::class);
    $registrationService->sendRegistrationSummary($item, $admin);

    $this->actingAs($admin)
        ->post(route('admin.bulk-intakes.items.simulate-registration-complete', [
            'bulkIntakeBatch' => $item->bulk_intake_batch_id,
            'bulkIntakeBatchItem' => $item->id,
        ]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($registrationService->registrationStatus($item->fresh()))
        ->toBe(BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE);
});

test('batch show renders registration summary block after consent received', function () {
    $admin = registrationAdmin();
    $item = registrationConsentReceivedItem();
    $batch = BulkIntakeBatch::query()->findOrFail((int) $item->bulk_intake_batch_id);

    $this->actingAs($admin)
        ->get(route('admin.bulk-intakes.show', $batch))
        ->assertOk()
        ->assertSee('data-testid="bulk-registration-summary-preview"', false)
        ->assertSee('data-testid="bulk-send-registration-summary"', false)
        ->assertSee('data-testid="bulk-open-registration-whatsapp-test"', false)
        ->assertSee('data-testid="bulk-registration-path-badge"', false)
        ->assertSee('Fast', false);
});

test('registration summary message uses display text not raw codes', function () {
    $item = registrationConsentReceivedItem();
    $message = app(BulkIntakeRegistrationService::class)->buildSummaryMessage($item);

    expect($message)->toContain('✓ नाव:')
        ->and($message)->not->toContain('gender_id')
        ->and($message)->toContain('नोंदणी पूर्ण करा');
});

function registrationAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
}

function registrationBatch(User $admin): BulkIntakeBatch
{
    return BulkIntakeBatch::create([
        'uploaded_by_user_id' => $admin->id,
        'uploaded_by_actor_type' => BulkIntakeBatch::ACTOR_ADMIN,
        'source_surface' => BulkIntakeBatch::SURFACE_ADMIN_PANEL,
        'batch_name' => 'Registration batch',
        'batch_status' => BulkIntakeBatch::STATUS_COMPLETED,
    ]);
}

function registrationIntake(array $overrides = []): BiodataIntake
{
    return BiodataIntake::create(array_merge([
        'uploaded_by' => null,
        'raw_ocr_text' => 'Registration OCR',
        'parsed_json' => [],
        'intake_status' => 'uploaded',
        'parse_status' => 'parsed',
        'parser_version' => 'rules_only',
        'snapshot_schema_version' => 1,
        'approved_by_user' => false,
        'intake_locked' => false,
    ], $overrides));
}

function registrationItem(BulkIntakeBatch $batch, BiodataIntake $intake, array $overrides = []): BulkIntakeBatchItem
{
    return BulkIntakeBatchItem::create(array_merge([
        'bulk_intake_batch_id' => $batch->id,
        'biodata_intake_id' => $intake->id,
        'item_sequence' => ((int) BulkIntakeBatchItem::where('bulk_intake_batch_id', $batch->id)->max('item_sequence')) + 1,
        'input_type' => BulkIntakeBatchItem::INPUT_FILE,
        'original_filename' => 'registration.pdf',
        'item_status' => BulkIntakeBatchItem::STATUS_INTAKE_CREATED,
    ], $overrides));
}

function registrationEligibleItem(array $intakeOverrides = [], array $itemOverrides = []): BulkIntakeBatchItem
{
    $admin = registrationAdmin();
    $batch = registrationBatch($admin);
    $intake = registrationIntake(array_merge([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Registration Candidate',
                'primary_contact_number' => '9876543301',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
                'height_cm' => 165,
                'highest_education' => 'B.E. Computer',
                'city' => 'Pune',
            ],
        ],
    ], $intakeOverrides));

    return registrationItem($batch, $intake, $itemOverrides);
}

function registrationConsentReceivedItem(array $intakeOverrides = [], array $itemOverrides = []): BulkIntakeBatchItem
{
    $admin = registrationAdmin();
    $item = registrationEligibleItem($intakeOverrides, $itemOverrides);
    $consentService = app(BulkIntakeWhatsAppConsentService::class);
    $consentService->sendPermission($item, $admin);
    $sessionId = (int) (data_get($item->fresh()->item_meta_json, 'whatsapp_consent.intake_whatsapp_session_id') ?? 0);
    $session = \App\Models\IntakeWhatsAppSession::query()->findOrFail($sessionId);
    $consentService->processInboundReply($session, 'हो', BulkIntakeWhatsAppConsentService::REPLY_YES);

    return $item->fresh();
}

function registrationMarkConsentReceived(BulkIntakeBatchItem $item): BulkIntakeBatchItem
{
    $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
    $meta['whatsapp_consent'] = array_merge(is_array($meta['whatsapp_consent'] ?? null) ? $meta['whatsapp_consent'] : [], [
        'status' => BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED,
        'reply_choice' => BulkIntakeWhatsAppConsentService::REPLY_YES,
        'reply_at' => now()->toISOString(),
    ]);
    $item->forceFill(['item_meta_json' => $meta])->save();

    return $item->fresh();
}
