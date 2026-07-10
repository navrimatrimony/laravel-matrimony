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

require __DIR__.'/Support/BulkIntakeRegistrationHelpers.php';

test('registration summary is blocked until consent received', function () {
    $admin = registrationAdmin();
    $item = registrationEligibleItem();

    expect(fn () => app(BulkIntakeRegistrationService::class)->sendRegistrationSummary($item, $admin))
        ->toThrow(ValidationException::class);
});

test('consent received candidate gets fast path when all fields look ready', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());

    $summary = app(BulkIntakeRegistrationService::class)->summaryForItem($item);

    expect($summary['path'])->toBe(BulkIntakeRegistrationService::PATH_FAST)
        ->and($summary['warning_count'])->toBe(0)
        ->and(collect($summary['fields'])->every(fn (array $field): bool => $field['icon'] === '✓'))->toBeTrue();
});

test('missing mobile marks targeted or full registration path', function () {
    $item = registrationEligibleItem([
        'parsed_json' => [
            'core' => array_merge(registrationCompleteParsedJson()['parsed_json']['core'], [
                'full_name' => 'Missing Mobile Registration',
                'primary_contact_number' => null,
                'all_contact_numbers' => [],
            ]),
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
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
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
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
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
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $message = app(BulkIntakeRegistrationService::class)->buildSummaryMessage($item);

    expect($message)->toContain('✓ नाव:')
        ->and($message)->not->toContain('gender_id')
        ->and($message)->toContain('नोंदणी पूर्ण करा')
        ->and($message)->toContain('/register/biodata/');
});

test('registration summary shows height in feet and inches not raw cm', function () {
    $item = registrationConsentReceivedItem([
        'parsed_json' => [
            'core' => array_merge(registrationCompleteParsedJson()['parsed_json']['core'], [
                'height_cm' => 170,
            ]),
        ],
    ]);

    $summary = app(BulkIntakeRegistrationService::class)->summaryForItem($item);
    $heightField = collect($summary['fields'])->firstWhere('key', 'height_cm');

    expect($heightField['value'] ?? null)->toBe("5'7\"")
        ->and($heightField['value'] ?? '')->not->toContain('170 cm');
});
