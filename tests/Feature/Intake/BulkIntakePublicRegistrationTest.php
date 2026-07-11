<?php

use App\Models\EducationDegree;
use App\Models\MasterDiet;
use App\Models\MatrimonyProfile;
use App\Models\OccupationMaster;
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

test('public registration form uses whatsapp consent mobile when biodata lists multiple numbers', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson([
        'parsed_json' => [
            'core' => [
                'full_name' => 'सचिन रामदास शिंदे',
                'primary_contact_number' => '9664444909',
                'all_contact_numbers' => ['9664444909', '9820655506'],
            ],
        ],
        'raw_ocr_text' => 'संपर्क 9664444909 9820655506',
    ]));
    $service = app(BulkIntakePublicRegistrationService::class);
    $token = $service->ensureToken($item);
    $masters = registrationMasterIds();
    $career = registrationCareerMasters();

    $payload = $service->formPayload($item->fresh());
    expect($payload['mobile'])->toBe('9664444909')
        ->and($payload['consent_mobile_locked'] ?? false)->toBeTrue();

    $this->get($service->publicUrl($item))
        ->assertOk()
        ->assertSee('9664444909', false)
        ->assertDontSee('9664444909, 9820655506', false)
        ->assertSee('WhatsApp परवानगी', false);

    $this->post(route('bulk-intake.register.store', ['token' => $token]), [
        'full_name' => 'सचिन रामदास शिंदे',
        'mobile' => '9664444909',
        'date_of_birth' => '1987-09-09',
        'height_cm' => 173,
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
    ])->assertRedirect(route('bulk-intake.register.complete', ['token' => $token]));
});

test('public registration form loads quickly with large relative snapshot without full normalization timeout', function () {
    $relatives = [];
    for ($i = 1; $i <= 80; $i++) {
        $relatives[] = [
            'relation_type' => 'uncle',
            'contact_name' => 'Relative '.$i,
            'phone_number' => '98765'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
            'address_line' => 'Address line '.$i.' Pune Maharashtra India',
        ];
    }

    $item = registrationConsentReceivedItem(registrationCompleteParsedJson([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Large Snapshot Candidate',
                'primary_contact_number' => '9876543301',
            ],
            'relatives' => $relatives,
            'contacts' => array_merge([
                [
                    'phone_number' => '9876543301',
                    'relation_type' => 'self',
                    'contact_name' => 'Large Snapshot Candidate',
                    'is_primary' => 1,
                ],
            ], $relatives),
        ],
        'raw_ocr_text' => str_repeat('मराठी बायोडाटा शिक्षण उंची ', 200),
    ]));
    $url = app(BulkIntakePublicRegistrationService::class)->publicUrl($item);

    $startedAt = microtime(true);

    $this->get($url)
        ->assertOk()
        ->assertSee('बायोडाटा नोंदणी पुष्टी')
        ->assertSee('Large Snapshot Candidate');

    expect(microtime(true) - $startedAt)->toBeLessThan(8.0);

    $payload = app(BulkIntakePublicRegistrationService::class)->formPayload($item->fresh());
    expect($payload['candidate_name'] ?? null)->toBe('Large Snapshot Candidate');
});

test('public registration redirects completed item away from editable form', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $service = app(BulkIntakePublicRegistrationService::class);
    $token = $service->ensureToken($item);
    $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
    $meta['registration'] = array_merge(is_array($meta['registration'] ?? null) ? $meta['registration'] : [], [
        'status' => BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE,
        'completed_via' => 'public_web_form',
    ]);
    $item->forceFill(['item_meta_json' => $meta])->save();

    $this->get(route('bulk-intake.register.show', ['token' => $token]))
        ->assertRedirect(route('bulk-intake.register.complete', ['token' => $token]));
});

test('public registration shows biodata income accurately on form', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson([
        'parsed_json' => [
            'core' => [
                'annual_income' => '600000',
                'income_amount' => '600000',
                'company_name' => 'Test Company Pvt Ltd',
            ],
        ],
    ]));

    $payload = app(BulkIntakePublicRegistrationService::class)->formPayload($item->fresh());

    expect((string) $payload['profile']->annual_income)->toBe('600000')
        ->and((string) $payload['profile']->income_amount)->toBe('600000')
        ->and($payload['profile']->company_name)->toBe('Test Company Pvt Ltd');
});

test('public registration resolves religion caste occupation and education from biodata text', function () {
    $masters = registrationMasterIds();
    $career = registrationCareerMasters();
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson([
        'parsed_json' => [
            'core' => [
                'religion' => 'Hindu',
                'caste' => 'Maratha',
                'marital_status' => 'never_married',
                'highest_education' => 'B.E. Computer',
                'occupation' => 'Software Engineer',
                'working_with' => 'private_company',
                'community' => 'Hindu - Maratha',
            ],
        ],
    ]));

    $payload = app(BulkIntakePublicRegistrationService::class)->formPayload($item->fresh());
    $profile = $payload['profile'];

    expect((int) $profile->religion_id)->toBe($masters['religion_id'])
        ->and((int) $profile->caste_id)->toBe($masters['caste_id'])
        ->and((int) $profile->marital_status_id)->toBe($masters['marital_status_id'])
        ->and((int) $profile->occupation_master_id)->toBe($career['occupation_master_id'])
        ->and((string) $profile->highest_education)->toContain('B.E');
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
    ])->assertRedirect(route('bulk-intake.register.complete', ['token' => app(BulkIntakePublicRegistrationService::class)->ensureToken($item->fresh())]));

    $item->refresh();
    $intake = $item->biodataIntake?->fresh();
    expect($intake)->not->toBeNull()
        ->and(data_get($intake->approval_snapshot_json, 'core.full_name'))->toBe('Updated Candidate')
        ->and((int) data_get($intake->approval_snapshot_json, 'core.height_cm'))->toBe(170)
        ->and((string) data_get($intake->approval_snapshot_json, 'core.height'))->toBe("5'7\"")
        ->and((int) data_get($intake->approval_snapshot_json, 'core.location_id'))->toBe(registrationLocationId())
        ->and((int) data_get($intake->approval_snapshot_json, 'core.occupation_master_id'))->toBe($career['occupation_master_id'])
        ->and(app(BulkIntakeRegistrationService::class)->registrationStatus($item))
        ->toBe(BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE)
        ->and($intake->matrimony_profile_id)->not->toBeNull();

    $profile = MatrimonyProfile::query()->find((int) $intake->matrimony_profile_id);
    expect($profile)->not->toBeNull()
        ->and($profile->full_name)->toBe('Updated Candidate')
        ->and((int) $profile->height_cm)->toBe(170)
        ->and($profile->lifecycle_state)->toBe('draft');
});

test('public registration complete page shows success and photo upload after form save', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $service = app(BulkIntakePublicRegistrationService::class);
    $token = $service->ensureToken($item);
    $masters = registrationMasterIds();
    $career = registrationCareerMasters();

    $this->post(route('bulk-intake.register.store', ['token' => $token]), [
        'full_name' => 'Photo Flow Candidate',
        'mobile' => '9876543301',
        'date_of_birth' => '1998-04-15',
        'height_cm' => 165,
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
    ])->assertRedirect(route('bulk-intake.register.complete', ['token' => $token]));

    $this->get(route('bulk-intake.register.complete', ['token' => $token]))
        ->assertOk()
        ->assertSee('प्रोफाइल फोटो')
        ->assertSee('नोंदणी माहिती जतन झाली');
});

test('public registration photo upload stores candidate and opens preferences', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $service = app(BulkIntakePublicRegistrationService::class);
    $token = $service->ensureToken($item);
    $masters = registrationMasterIds();
    $career = registrationCareerMasters();

    $this->post(route('bulk-intake.register.store', ['token' => $token]), [
        'full_name' => 'Prefs Flow Candidate',
        'mobile' => '9876543301',
        'date_of_birth' => '1998-04-15',
        'height_cm' => 165,
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
    ]);

    $photo = \Illuminate\Http\UploadedFile::fake()->image('candidate.jpg', 640, 800);

    $this->post(route('bulk-intake.register.photo.store', ['token' => $token]), [
        'profile_photo' => $photo,
    ])->assertRedirect(route('bulk-intake.register.preferences', ['token' => $token]));

    $item->refresh();
    $intake = $item->biodataIntake?->fresh();
    expect($intake)->not->toBeNull()
        ->and(data_get($item->item_meta_json, 'registration.photo_completed_at'))->not->toBeNull()
        ->and(app(\App\Services\Intake\IntakePhotoCandidateCropService::class)->exists($intake))->toBeTrue();

    $this->get(route('bulk-intake.register.preferences', ['token' => $token]))
        ->assertOk()
        ->assertSee('जोडीदार प्राधान्ये');
});

test('public registration preferences save to snapshot and finish on done page', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $service = app(BulkIntakePublicRegistrationService::class);
    $token = $service->ensureToken($item);
    $masters = registrationMasterIds();
    $career = registrationCareerMasters();

    $this->post(route('bulk-intake.register.store', ['token' => $token]), [
        'full_name' => 'Done Flow Candidate',
        'mobile' => '9876543301',
        'date_of_birth' => '1998-04-15',
        'height_cm' => 165,
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
    ]);

    $photo = \Illuminate\Http\UploadedFile::fake()->image('candidate.jpg', 640, 800);
    $this->post(route('bulk-intake.register.photo.store', ['token' => $token]), [
        'profile_photo' => $photo,
    ]);

    $allEducationIds = EducationDegree::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    $allOccupationIds = OccupationMaster::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    $allDietIds = MasterDiet::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();

    $this->post(route('bulk-intake.register.preferences.store', ['token' => $token]), [
        'preferred_age_min' => 24,
        'preferred_age_max' => 32,
        'preferred_religion_ids' => [$masters['religion_id']],
        'preferred_caste_ids' => [$masters['caste_id']],
        'preferred_education_degree_ids' => $allEducationIds,
        'preferred_occupation_master_ids' => $allOccupationIds,
        'preferred_diet_ids' => $allDietIds,
        'preferred_income_min' => 0,
        'preferred_income_max' => 50000000,
    ])->assertRedirect(route('bulk-intake.register.done', ['token' => $token]));

    $intake = $item->fresh()->biodataIntake?->fresh();
    expect($intake)->not->toBeNull()
        ->and((int) data_get($intake->approval_snapshot_json, 'preferences.preferred_age_min'))->toBe(24)
        ->and((int) data_get($intake->approval_snapshot_json, 'preferences.preferred_age_max'))->toBe(32)
        ->and(data_get($intake->approval_snapshot_json, 'preferences.preferred_income_min'))->toBeNull()
        ->and(data_get($intake->approval_snapshot_json, 'preferences.preferred_income_max'))->toBeNull()
        ->and(data_get($intake->approval_snapshot_json, 'preferences.preferred_education_degree_ids'))->toBe([])
        ->and(data_get($intake->approval_snapshot_json, 'preferences.preferred_occupation_master_ids'))->toBe([])
        ->and(data_get($intake->approval_snapshot_json, 'preferences.preferred_diet_ids'))->toBe([])
        ->and(data_get($item->fresh()->item_meta_json, 'registration.preferences_completed_at'))->not->toBeNull()
        ->and($intake->matrimony_profile_id)->not->toBeNull();

    $profile = MatrimonyProfile::query()->find((int) $intake->matrimony_profile_id);
    expect($profile)->not->toBeNull()
        ->and($profile->preferenceCriteria)->not->toBeNull()
        ->and((int) $profile->preferenceCriteria->preferred_age_min)->toBe(24)
        ->and((int) $profile->preferenceCriteria->preferred_age_max)->toBe(32);

    $this->get(route('bulk-intake.register.done', ['token' => $token]))
        ->assertOk()
        ->assertSee('नोंदणी पूर्ण झाली');
});

test('bulk registration preferences default education occupation income and diet to open to all', function () {
    $item = registrationConsentReceivedItem(registrationCompleteParsedJson());
    $service = app(BulkIntakePublicRegistrationService::class);
    $bridge = app(\App\Services\Intake\BulkIntakeRegistrationPreferencesBridgeService::class);
    $token = $service->ensureToken($item);
    $masters = registrationMasterIds();
    $career = registrationCareerMasters();

    $this->post(route('bulk-intake.register.store', ['token' => $token]), [
        'full_name' => 'Open Prefs Candidate',
        'mobile' => '9876543301',
        'date_of_birth' => '1998-04-15',
        'height_cm' => 165,
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
    ]);

    $photo = \Illuminate\Http\UploadedFile::fake()->image('candidate.jpg', 640, 800);
    $this->post(route('bulk-intake.register.photo.store', ['token' => $token]), [
        'profile_photo' => $photo,
    ]);

    $intake = $item->fresh()->biodataIntake?->fresh();
    $snapshot = is_array($intake?->approval_snapshot_json) ? $intake->approval_snapshot_json : [];
    $defaults = $bridge->suggestForBulkRegistration(
        app(\App\Services\Intake\BulkIntakeRegistrationFormBridgeService::class)->profileFromSnapshot($snapshot, $item->fresh())
    );

    expect($defaults['preferred_education_degree_ids'])->toBe([])
        ->and($defaults['preferred_occupation_master_ids'])->toBe([])
        ->and($defaults['preferred_diet_ids'])->toBe([])
        ->and($defaults['preferred_mother_tongue_ids'])->toBe([])
        ->and($defaults['preferred_income_min'])->toBeNull()
        ->and($defaults['preferred_income_max'])->toBeNull()
        ->and($defaults['preferred_religion_ids'])->toBe([$masters['religion_id']])
        ->and($defaults['preferred_caste_ids'])->toBe([$masters['caste_id']])
        ->and($defaults['preferred_age_min'])->not->toBeNull();

    $payload = $service->preferencesPayload($item->fresh());
    $allEducationIds = EducationDegree::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    $allOccupationIds = OccupationMaster::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    $allDietIds = MasterDiet::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect($payload['bulkPreferencesEducationOpenToAll'] ?? false)->toBeTrue()
        ->and($payload['bulkPreferencesOccupationOpenToAll'] ?? false)->toBeTrue()
        ->and($payload['bulkPreferencesDietOpenToAll'] ?? false)->toBeTrue()
        ->and($payload['preferredEducationDegreeIds'])->toBe($allEducationIds)
        ->and($payload['preferredOccupationMasterIds'])->toBe($allOccupationIds)
        ->and($payload['preferredDietIds'])->toBe($allDietIds)
        ->and($payload['preferenceCriteria']->preferred_income_min ?? null)->toBeNull();

    $this->get(route('bulk-intake.register.preferences', ['token' => $token]))
        ->assertOk()
        ->assertSee('कोणतीही शैक्षणिक पात्रता चालेल')
        ->assertSee('कोणतीही उत्पन्न श्रेणी');
});
