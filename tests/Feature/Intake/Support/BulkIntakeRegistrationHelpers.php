<?php

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use App\Services\Intake\BulkIntakeWhatsAppConsentService;

if (! function_exists('registrationAdmin')) {
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

function registrationCareerMasters(): array
{
    $workingWithType = \App\Models\WorkingWithType::query()->firstOrCreate(
        ['slug' => 'private_company'],
        ['name' => 'Private Company', 'name_mr' => 'खाजगी कंपनी', 'sort_order' => 10, 'is_active' => true]
    );
    $category = \App\Models\OccupationCategory::query()->firstOrCreate(
        ['name' => 'Test Occupation Category'],
        ['legacy_working_with_type_id' => $workingWithType->id, 'sort_order' => 1]
    );
    $occupation = \App\Models\OccupationMaster::query()->firstOrCreate(
        ['name' => 'Software Engineer'],
        [
            'name_mr' => 'सॉफ्टवेअर अभियंता',
            'normalized_name' => 'software engineer',
            'category_id' => $category->id,
            'sort_order' => 1,
        ]
    );
    $degreeCategory = \App\Models\EducationCategory::query()->firstOrCreate(
        ['slug' => 'graduation'],
        ['name' => 'Graduation', 'sort_order' => 10]
    );
    $degree = \App\Models\EducationDegree::query()->firstOrCreate(
        ['code' => 'BE-COMP'],
        ['code_mr' => 'बी.ई. कॉम्प्युटर', 'category_id' => $degreeCategory->id, 'sort_order' => 10]
    );

    return [
        'working_with_type_id' => (int) $workingWithType->id,
        'occupation_master_id' => (int) $occupation->id,
        'education_degree_id' => (int) $degree->id,
    ];
}

function registrationLocationId(): int
{
    (new \Database\Seeders\MinimalLocationSeeder)->run();

    return (int) \App\Models\City::query()->where('name', 'Pune City')->value('id');
}

function registrationIncomeCurrencyId(): int
{
    return (int) (\App\Models\MasterIncomeCurrency::query()->firstOrCreate(
        ['code' => 'INR'],
        ['is_active' => true, 'is_default' => true, 'symbol' => '₹']
    )->id);
}

/**
 * @return array{gender_id: int, mother_tongue_id: int, marital_status_id: int, religion_id: int, caste_id: int}
 */
function registrationMasterIds(): array
{
    $gender = \App\Models\MasterGender::query()->firstOrCreate(
        ['key' => 'female'],
        ['label' => 'Female', 'label_en' => 'Female', 'is_active' => true]
    );
    $motherTongue = \App\Models\MasterMotherTongue::query()->firstOrCreate(
        ['key' => 'marathi'],
        ['label' => 'Marathi', 'label_en' => 'Marathi', 'label_mr' => 'मराठी', 'is_active' => true]
    );
    $maritalStatus = \App\Models\MasterMaritalStatus::query()->firstOrCreate(
        ['key' => 'never_married'],
        ['label' => 'Never married', 'label_en' => 'Never married', 'is_active' => true]
    );
    $religion = \App\Models\Religion::query()->firstOrCreate(
        ['key' => 'hindu'],
        ['label_en' => 'Hindu', 'label' => 'Hindu', 'label_mr' => 'हिंदू', 'is_active' => true]
    );
    $caste = \App\Models\Caste::query()->firstOrCreate(
        ['key' => 'maratha'],
        ['label_en' => 'Maratha', 'label' => 'Maratha', 'label_mr' => 'मराठा', 'religion_id' => $religion->id, 'is_active' => true]
    );

    return [
        'gender_id' => (int) $gender->id,
        'mother_tongue_id' => (int) $motherTongue->id,
        'marital_status_id' => (int) $maritalStatus->id,
        'religion_id' => (int) $religion->id,
        'caste_id' => (int) $caste->id,
    ];
}

function registrationCompleteParsedJson(array $overrides = []): array
{
    $masters = registrationMasterIds();

    return array_replace_recursive([
        'parsed_json' => [
            'core' => [
                'full_name' => 'Registration Candidate',
                'primary_contact_number' => '9876543301',
                'date_of_birth' => '1998-04-15',
                'gender' => 'female',
                'gender_id' => $masters['gender_id'],
                'height_cm' => 165,
                'highest_education' => 'B.E. Computer',
                'city' => 'Pune',
                'city_text' => 'Pune',
                'mother_tongue_id' => $masters['mother_tongue_id'],
                'marital_status_id' => $masters['marital_status_id'],
                'religion_id' => $masters['religion_id'],
                'caste_id' => $masters['caste_id'],
                'working_with' => 'private_company',
                'occupation' => 'Software Engineer',
                'occupation_title' => 'Software Engineer',
            ],
        ],
    ], $overrides);
}

function registrationEligibleItem(array $intakeOverrides = [], array $itemOverrides = []): BulkIntakeBatchItem
{
    $admin = registrationAdmin();
    $batch = registrationBatch($admin);
    $defaults = registrationCompleteParsedJson();
    $intake = registrationIntake(array_replace_recursive($defaults, $intakeOverrides));

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

}
