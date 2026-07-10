<?php

use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Services\Intake\BulkIntakePublicRegistrationService;
use App\Services\Intake\BulkIntakeRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require __DIR__.'/Support/BulkIntakeRegistrationHelpers.php';

test('public registration page opens with token after consent received', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson([
        'parsed_json' => [
            'core' => [
                'full_name' => 'अनुजा महादेव पाटील',
                'primary_contact_number' => '9876543301',
            ],
        ],
        'raw_ocr_text' => 'अनुजा महादेव पाटील पुणे मराठी बायोडाटा शिक्षण उंची',
    ]));
    $masters = registrationMasterIds();
    $url = app(BulkIntakePublicRegistrationService::class)->publicUrl($item);

    $this->get($url)
        ->assertOk()
        ->assertSee('बायोडाटा नोंदणी पुष्टी')
        ->assertSee('अनुजा महादेव पाटील')
        ->assertDontSee('नोंदणी पूर्ण झाली आहे')
        ->assertSee('occupation-engine-root', false)
        ->assertSee('location-typeahead-wrapper', false)
        ->assertSee('education-multiselect-root-bulk-registration-education', false);

    $payload = app(BulkIntakePublicRegistrationService::class)->formPayload($item->fresh());
    expect($payload['mother_tongue_id'] ?? null)->toBe($masters['mother_tongue_id'])
        ->and($payload['prefer_marathi_labels'] ?? false)->toBeTrue();
});

test('public registration keeps income empty and hides premature completion banner when already marked complete', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson([
        'parsed_json' => [
            'core' => [
                'annual_income' => '600000',
                'income_amount' => '600000',
                'company_name' => 'Test Company Pvt Ltd',
            ],
        ],
    ]));
    $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
    $meta['registration'] = array_merge(is_array($meta['registration'] ?? null) ? $meta['registration'] : [], [
        'status' => BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE,
        'completed_via' => 'public_web_form',
    ]);
    $item->forceFill(['item_meta_json' => $meta])->save();

    $url = app(BulkIntakePublicRegistrationService::class)->publicUrl($item->fresh());
    $payload = app(BulkIntakePublicRegistrationService::class)->formPayload($item->fresh());

    $this->get($url)
        ->assertOk()
        ->assertDontSee('नोंदणी पूर्ण झाली आहे')
        ->assertSee('Test Company Pvt Ltd');

    expect($payload['profile']->annual_income)->toBeNull()
        ->and($payload['profile']->income_amount)->toBeNull()
        ->and($payload['profile']->company_name)->toBe('Test Company Pvt Ltd');
});

test('public registration save stores cm height and marks registration complete', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $masters = registrationMasterIds();
    $career = registrationCareerMasters();
    $service = app(BulkIntakePublicRegistrationService::class);
    $url = $service->publicUrl($item);

    $this->post($url, [
        'full_name' => 'Updated Candidate',
        'mobile' => '9876543301',
        'date_of_birth' => '1998-04-15',
        'height_cm' => 170,
        'gender_id' => $masters['gender_id'],
        'mother_tongue_id' => $masters['mother_tongue_id'],
        'marital_status_id' => $masters['marital_status_id'],
        'religion_id' => $masters['religion_id'],
        'caste_id' => $masters['caste_id'],
        'location_id' => registrationLocationId(),
        'education_degree_ids' => [$career['education_degree_id']],
        'occupation_master_id' => $career['occupation_master_id'],
        'income_period' => 'annual',
        'income_value_type' => 'exact',
        'income_amount' => '500000',
        'income_currency_id' => registrationIncomeCurrencyId(),
        'marriages' => [[
            'marriage_year' => '',
            'divorce_year' => '',
            'separation_year' => '',
            'spouse_death_year' => '',
            'divorce_status' => '',
        ]],
    ])->assertRedirect($url);

    $item->refresh();
    $intake = $item->biodataIntake?->fresh();
    expect($intake)->not->toBeNull()
        ->and(data_get($intake->approval_snapshot_json, 'core.full_name'))->toBe('Updated Candidate')
        ->and((int) data_get($intake->approval_snapshot_json, 'core.height_cm'))->toBe(170)
        ->and((string) data_get($intake->approval_snapshot_json, 'core.height'))->toBe("5'7\"")
        ->and((int) data_get($intake->approval_snapshot_json, 'core.location_id'))->toBe(registrationLocationId())
        ->and((int) data_get($intake->approval_snapshot_json, 'core.occupation_master_id'))->toBe($career['occupation_master_id'])
        ->and(app(BulkIntakeRegistrationService::class)->registrationStatus($item))
        ->toBe(BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE);
});
