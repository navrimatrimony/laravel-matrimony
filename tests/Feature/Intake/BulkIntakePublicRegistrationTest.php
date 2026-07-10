<?php

use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Services\Intake\BulkIntakePublicRegistrationService;
use App\Services\Intake\BulkIntakeRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require __DIR__.'/Support/BulkIntakeRegistrationHelpers.php';

test('public registration page opens with token after consent received', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $url = app(BulkIntakePublicRegistrationService::class)->publicUrl($item);

    $this->get($url)
        ->assertOk()
        ->assertSee('बायोडाटा नोंदणी पुष्टी')
        ->assertSee('Registration Candidate');
});

test('public registration save stores cm height and marks registration complete', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $masters = registrationMasterIds();
    $service = app(BulkIntakePublicRegistrationService::class);
    $url = $service->publicUrl($item);

    $this->post($url, [
        'full_name' => 'Updated Candidate',
        'mobile' => '9876543301',
        'date_of_birth' => '1998-04-15',
        'height_cm' => 170,
        'gender' => 'female',
        'mother_tongue_id' => $masters['mother_tongue_id'],
        'marital_status_id' => $masters['marital_status_id'],
        'religion_id' => $masters['religion_id'],
        'caste_id' => $masters['caste_id'],
        'location' => 'Pune',
        'education' => 'B.E. Computer',
        'working_with' => 'private_company',
        'occupation' => 'Software Engineer',
    ])->assertRedirect($url);

    $item->refresh();
    $intake = $item->biodataIntake?->fresh();
    expect($intake)->not->toBeNull()
        ->and(data_get($intake->approval_snapshot_json, 'core.full_name'))->toBe('Updated Candidate')
        ->and((int) data_get($intake->approval_snapshot_json, 'core.height_cm'))->toBe(170)
        ->and((string) data_get($intake->approval_snapshot_json, 'core.height'))->toBe("5'7\"")
        ->and(app(BulkIntakeRegistrationService::class)->registrationStatus($item))
        ->toBe(BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE);
});
