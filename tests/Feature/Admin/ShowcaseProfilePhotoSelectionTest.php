<?php

namespace Tests\Feature\Admin;

use App\Models\AdminSetting;
use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\Location;
use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\Religion;
use App\Models\State;
use App\Models\Taluka;
use App\Models\User;
use App\Services\Showcase\ShowcasePhotoPoolSettings;
use App\Services\Showcase\ShowcaseProfileCreateResult;
use App\Services\Showcase\ShowcaseProfileFactory;
use App\Services\ShowcaseProfileDefaultsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseProfilePhotoSelectionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-29 10:00:00');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdFiles) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
            $this->removeEmptyParents(dirname($file));
        }

        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_exact_eng_folder_match_is_selected(): void
    {
        $gender = $this->gender('female');
        $religion = $this->religion('Hindu Test Exact');
        $marital = $this->maritalStatus('Never Married Exact');
        $relative = 'eng/female/hindu_test_exact/never_married_exact/25-30/exact.jpg';
        $this->putPublicPhoto($relative);

        $selected = ShowcaseProfileDefaultsService::showcasePhotoForAttributes([
            'gender_id' => $gender->id,
            'religion_id' => $religion->id,
            'marital_status_id' => $marital->id,
            'date_of_birth' => '1998-05-29',
        ]);

        $this->assertSame($relative, $selected);
    }

    public function test_no_any_folder_fallback_when_exact_match_is_missing(): void
    {
        $gender = $this->gender('female');
        $religion = $this->religion('Hindu Test Fallback');
        $marital = $this->maritalStatus('Never Married Fallback');
        $this->putPublicPhoto('eng/female/hindu_test_fallback/any/any/fallback.webp');

        $resolved = ShowcaseProfileDefaultsService::resolveShowcasePhotoForAttributes([
            'gender_id' => $gender->id,
            'religion_id' => $religion->id,
            'marital_status_id' => $marital->id,
            'date_of_birth' => '1998-05-29',
        ]);

        $this->assertNull($resolved['path']);
        $this->assertSame(ShowcasePhotoPoolSettings::MISSING_FOLDER, $resolved['reason']);
        $this->assertSame(
            'eng/female/hindu_test_fallback/never_married_fallback/25-30',
            $resolved['expected_folder']
        );
    }

    public function test_engagement_folder_is_ignored(): void
    {
        $gender = $this->gender('female');
        $religion = $this->religion('Hindu Test Legacy');
        $marital = $this->maritalStatus('Never Married Legacy');
        $this->putPublicPhoto('engagement/female/hindu_test_legacy/never_married_legacy/25-30/legacy.jpg');
        $this->markExistingEngPhotosUsed('female', $gender->id);

        $selected = ShowcaseProfileDefaultsService::showcasePhotoForAttributes([
            'gender_id' => $gender->id,
            'religion_id' => $religion->id,
            'marital_status_id' => $marital->id,
            'date_of_birth' => '1998-05-29',
        ]);

        $this->assertNull($selected);
    }

    public function test_duplicate_path_in_same_bucket_is_excluded_but_second_file_in_bucket_is_allowed(): void
    {
        $gender = $this->gender('female');
        $religion = $this->religion('Hindu Test Duplicate');
        $marital = $this->maritalStatus('Never Married Duplicate');
        $used = 'eng/female/hindu_test_duplicate/never_married_duplicate/25-30/shared.jpg';
        $available = 'eng/female/hindu_test_duplicate/never_married_duplicate/25-30/other.jpg';
        $this->putPublicPhoto($used);
        $this->putPublicPhoto($available);

        MatrimonyProfile::factory()->create([
            'gender_id' => $gender->id,
            'is_showcase' => true,
            'profile_photo' => $used,
        ]);

        $selected = ShowcaseProfileDefaultsService::showcasePhotoForAttributes([
            'gender_id' => $gender->id,
            'religion_id' => $religion->id,
            'marital_status_id' => $marital->id,
            'date_of_birth' => '1998-05-29',
        ]);

        $this->assertSame($available, $selected);
    }

    public function test_invalid_date_of_birth_does_not_use_any_age_fallback(): void
    {
        $gender = $this->gender('male');
        $religion = $this->religion('Buddhist Test Invalid Dob');
        $marital = $this->maritalStatus('Divorced Invalid Dob');
        $this->putPublicPhoto('eng/male/buddhist_test_invalid_dob/divorced_invalid_dob/any/invalid-dob.png');

        $resolved = ShowcaseProfileDefaultsService::resolveShowcasePhotoForAttributes([
            'gender_id' => $gender->id,
            'religion_id' => $religion->id,
            'marital_status_id' => $marital->id,
            'date_of_birth' => 'not-a-date',
        ]);

        $this->assertNull($resolved['path']);
        $this->assertSame(ShowcasePhotoPoolSettings::INVALID_CATEGORY, $resolved['reason']);
    }

    public function test_pool_exhausted_when_all_images_in_bucket_are_used(): void
    {
        $gender = $this->gender('female');
        $religion = $this->religion('Hindu Exhausted');
        $marital = $this->maritalStatus('Never Married Exhausted');
        $only = 'eng/female/hindu_exhausted/never_married_exhausted/25-30/only.jpg';
        $this->putPublicPhoto($only);

        MatrimonyProfile::factory()->create([
            'gender_id' => $gender->id,
            'is_showcase' => true,
            'profile_photo' => $only,
        ]);

        $resolved = ShowcaseProfileDefaultsService::resolveShowcasePhotoForAttributes([
            'gender_id' => $gender->id,
            'religion_id' => $religion->id,
            'marital_status_id' => $marital->id,
            'date_of_birth' => '1998-05-29',
        ]);

        $this->assertNull($resolved['path']);
        $this->assertSame(ShowcasePhotoPoolSettings::POOL_EXHAUSTED, $resolved['reason']);
    }

    public function test_allow_reuse_setting_returns_used_image_from_same_bucket(): void
    {
        AdminSetting::setValue(ShowcasePhotoPoolSettings::SETTING_KEY, json_encode([
            'missing_exact_folder_action' => ShowcasePhotoPoolSettings::ACTION_CREATE_WITHOUT_PHOTO,
            'pool_exhausted_action' => ShowcasePhotoPoolSettings::ACTION_CREATE_WITHOUT_PHOTO,
            'allow_reuse_when_bucket_exhausted' => true,
        ]));

        $gender = $this->gender('female');
        $religion = $this->religion('Hindu Reuse');
        $marital = $this->maritalStatus('Never Married Reuse');
        $only = 'eng/female/hindu_reuse/never_married_reuse/25-30/reuse.jpg';
        $this->putPublicPhoto($only);

        MatrimonyProfile::factory()->create([
            'gender_id' => $gender->id,
            'is_showcase' => true,
            'profile_photo' => $only,
        ]);

        $selected = ShowcaseProfileDefaultsService::showcasePhotoForAttributes([
            'gender_id' => $gender->id,
            'religion_id' => $religion->id,
            'marital_status_id' => $marital->id,
            'date_of_birth' => '1998-05-29',
        ]);

        $this->assertSame($only, $selected);
    }

    public function test_factory_skips_profile_when_admin_policy_is_skip(): void
    {
        AdminSetting::setValue(ShowcasePhotoPoolSettings::SETTING_KEY, json_encode([
            'missing_exact_folder_action' => ShowcasePhotoPoolSettings::ACTION_SKIP_PROFILE,
            'pool_exhausted_action' => ShowcasePhotoPoolSettings::ACTION_SKIP_PROFILE,
            'allow_reuse_when_bucket_exhausted' => false,
        ]));

        $gender = $this->gender('female');
        $religion = $this->religion('hindu');
        $marital = $this->maritalStatus('never_married');
        $location = $this->locationHierarchy();
        $actor = User::factory()->create();

        $result = app(ShowcaseProfileFactory::class)->createWithOutcome(
            0,
            'female',
            (int) $actor->id,
            [
                'full_name' => 'Skip No Photo',
                'gender_id' => $gender->id,
                'religion_id' => $religion->id,
                'marital_status_id' => $marital->id,
                'date_of_birth' => '1998-05-29',
                'country_id' => $location['country']->id,
                'state_id' => $location['state']->id,
                'district_id' => $location['district']->id,
                'taluka_id' => $location['taluka']->id,
                'city_id' => $location['city']->id,
                'work_state_id' => $location['state']->id,
                'work_city_id' => $location['city']->id,
            ],
            'draft'
        );

        $this->assertNull($result->profileId);
        $this->assertSame(ShowcaseProfileCreateResult::OUTCOME_SKIPPED_NO_PHOTO, $result->outcome);
    }

    public function test_factory_creates_without_photo_when_admin_policy_allows(): void
    {
        AdminSetting::setValue(ShowcasePhotoPoolSettings::SETTING_KEY, json_encode([
            'missing_exact_folder_action' => ShowcasePhotoPoolSettings::ACTION_CREATE_WITHOUT_PHOTO,
            'pool_exhausted_action' => ShowcasePhotoPoolSettings::ACTION_CREATE_WITHOUT_PHOTO,
            'allow_reuse_when_bucket_exhausted' => false,
        ]));

        $gender = $this->gender('female');
        $religion = $this->religion('hindu');
        $marital = $this->maritalStatus('never_married');
        $location = $this->locationHierarchy();
        $actor = User::factory()->create();

        $result = app(ShowcaseProfileFactory::class)->createWithOutcome(
            0,
            'female',
            (int) $actor->id,
            [
                'full_name' => 'No Photo Allowed',
                'gender_id' => $gender->id,
                'religion_id' => $religion->id,
                'marital_status_id' => $marital->id,
                'date_of_birth' => '1998-05-29',
                'country_id' => $location['country']->id,
                'state_id' => $location['state']->id,
                'district_id' => $location['district']->id,
                'taluka_id' => $location['taluka']->id,
                'city_id' => $location['city']->id,
                'work_state_id' => $location['state']->id,
                'work_city_id' => $location['city']->id,
            ],
            'draft'
        );

        $this->assertNotNull($result->profileId);
        $this->assertSame(ShowcaseProfileCreateResult::OUTCOME_CREATED_WITHOUT_PHOTO, $result->outcome);

        $profile = MatrimonyProfile::query()->findOrFail($result->profileId);
        $this->assertTrue(trim((string) ($profile->profile_photo ?? '')) === '');
    }

    public function test_showcase_factory_syncs_selected_photo_to_primary_gallery_row(): void
    {
        $gender = $this->gender('female');
        $religion = $this->religion('hindu');
        $marital = $this->maritalStatus('never_married');
        $relative = 'eng/female/hindu/never_married/25-30/factory-sync.jpg';
        $this->putPublicPhoto($relative);
        $location = $this->locationHierarchy();
        $actor = User::factory()->create();

        $profileId = app(ShowcaseProfileFactory::class)->create(
            0,
            'female',
            (int) $actor->id,
            [
                'full_name' => 'Factory Photo Sync',
                'gender_id' => $gender->id,
                'religion_id' => $religion->id,
                'marital_status_id' => $marital->id,
                'date_of_birth' => '1998-05-29',
                'country_id' => $location['country']->id,
                'state_id' => $location['state']->id,
                'district_id' => $location['district']->id,
                'taluka_id' => $location['taluka']->id,
                'city_id' => $location['city']->id,
                'work_state_id' => $location['state']->id,
                'work_city_id' => $location['city']->id,
            ],
            'draft'
        );

        $this->assertNotNull($profileId);

        $profile = MatrimonyProfile::query()->findOrFail($profileId);
        $this->assertSame($relative, $profile->profile_photo);

        $this->assertDatabaseHas('profile_photos', [
            'profile_id' => $profile->id,
            'file_path' => $relative,
            'is_primary' => true,
            'uploaded_via' => 'user_web',
            'approved_status' => 'approved',
        ]);
        $this->assertSame(1, ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->where('file_path', $relative)
            ->count());
        $this->assertSame(1, ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->where('is_primary', true)
            ->count());
    }

    public function test_admin_bulk_create_reports_without_photo_in_summary(): void
    {
        AdminSetting::setValue(ShowcasePhotoPoolSettings::SETTING_KEY, json_encode([
            'missing_exact_folder_action' => ShowcasePhotoPoolSettings::ACTION_CREATE_WITHOUT_PHOTO,
            'pool_exhausted_action' => ShowcasePhotoPoolSettings::ACTION_CREATE_WITHOUT_PHOTO,
            'allow_reuse_when_bucket_exhausted' => false,
        ]));

        $this->gender('female');
        $this->religion('hindu');
        $this->maritalStatus('never_married');
        $location = $this->locationHierarchy();
        $this->seedMetroLocationForBulk($location['city']);

        AdminSetting::setValue(\App\Services\Showcase\ShowcaseBulkCreateSettings::SETTING_KEY, json_encode([
            'religion_ids' => [],
            'marital_status_ids' => [],
            'age_min' => 28,
            'age_max' => 28,
            'eligible_address_tags' => ['metro'],
        ]));

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.showcase-profile.bulk-store'), [
            'count' => 2,
            'gender' => 'female',
        ]);

        $response->assertRedirect(route('admin.showcase-profile.bulk-create'));
        $bulkResult = session('showcase_bulk_result');
        $this->assertIsArray($bulkResult);
        $this->assertGreaterThanOrEqual(1, (int) ($bulkResult['summary']['without_photo'] ?? 0) + (int) ($bulkResult['summary']['skipped_no_photo'] ?? 0));
        $this->assertNotEmpty($bulkResult['grouped_warnings'] ?? []);
    }

    public function test_admin_bulk_create_flow_assigns_strict_eng_photo(): void
    {
        $gender = $this->gender('female');
        $religion = $this->religion('hindu');
        $marital = $this->maritalStatus('never_married');
        $this->putPublicPhoto('eng/female/hindu/never_married/25-30/route-bulk.jpg');

        $location = $this->locationHierarchy();
        $this->seedMetroLocationForBulk($location['city']);

        AdminSetting::setValue(\App\Services\Showcase\ShowcaseBulkCreateSettings::SETTING_KEY, json_encode([
            'religion_ids' => [(int) $religion->id],
            'marital_status_ids' => [(int) $marital->id],
            'age_min' => 28,
            'age_max' => 28,
            'eligible_address_tags' => ['metro'],
        ]));

        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.showcase-profile.bulk-store'), [
            'count' => 1,
            'gender' => 'female',
        ]);

        $response->assertRedirect(route('admin.showcase-profile.bulk-create'));
        $response->assertSessionHas('created_showcase_profile_ids');
        $response->assertSessionHas('showcase_bulk_result');

        $bulkResult = session('showcase_bulk_result');
        $this->assertIsArray($bulkResult);
        $this->assertSame(1, (int) ($bulkResult['summary']['created'] ?? 0));
        $this->assertSame(1, (int) ($bulkResult['summary']['with_photo'] ?? 0));

        $ids = session('created_showcase_profile_ids');
        $this->assertIsArray($ids);
        $this->assertCount(1, $ids);

        $profile = MatrimonyProfile::query()->findOrFail((int) $ids[0]);
        $this->assertTrue($profile->isShowcaseProfile());
        $this->assertSame('eng/female/hindu/never_married/25-30/route-bulk.jpg', $profile->profile_photo);

        $this->assertDatabaseHas('profile_photos', [
            'profile_id' => $profile->id,
            'file_path' => $profile->profile_photo,
            'is_primary' => true,
            'approved_status' => 'approved',
        ]);
    }

    private function seedMetroLocationForBulk(City $city): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable(\App\Models\Location::geoTable())) {
            return;
        }

        $state = Location::query()->create([
            'name' => 'Bulk Test State',
            'slug' => 'bulk-test-state-'.$city->id,
            'type' => 'state',
            'parent_id' => null,
            'is_active' => true,
        ]);
        $district = Location::query()->create([
            'name' => 'Bulk Test District',
            'slug' => 'bulk-test-dist-'.$city->id,
            'type' => 'district',
            'parent_id' => $state->id,
            'is_active' => true,
        ]);
        Location::query()->create([
            'id' => $city->id,
            'name' => $city->name,
            'slug' => 'bulk-test-city-'.$city->id,
            'type' => 'city',
            'parent_id' => $district->id,
            'tag' => 'metro',
            'is_active' => true,
        ]);
    }

    private function markExistingEngPhotosUsed(string $gender, int $genderId): void
    {
        $base = public_path('uploads/matrimony_photos/eng/'.$gender);
        if (! is_dir($base)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }

            $relative = 'eng/'.$gender.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            MatrimonyProfile::factory()->create([
                'gender_id' => $genderId,
                'is_showcase' => true,
                'profile_photo' => $relative,
            ]);
        }
    }

    private function gender(string $key): MasterGender
    {
        return MasterGender::query()->firstOrCreate(
            ['key' => $key],
            [
                'label' => ucfirst($key),
                'is_active' => true,
            ]
        );
    }

    private function religion(string $key): Religion
    {
        return Religion::query()->firstOrCreate(
            ['key' => $key],
            [
                'label' => $key,
                'is_active' => true,
            ]
        );
    }

    private function maritalStatus(string $key): MasterMaritalStatus
    {
        return MasterMaritalStatus::query()->firstOrCreate(
            ['key' => $key],
            [
                'label' => $key,
                'is_active' => true,
            ]
        );
    }

    /**
     * @return array{country: Country, state: State, district: District, taluka: Taluka, city: City}
     */
    private function locationHierarchy(): array
    {
        $country = Country::query()->create([
            'name' => 'India',
            'iso_alpha2' => 'IN',
            'is_active' => true,
        ]);
        $state = State::query()->create([
            'name' => 'Maharashtra',
            'country_id' => $country->id,
            'is_active' => true,
        ]);
        $district = District::query()->create([
            'name' => 'Pune',
            'state_id' => $state->id,
            'is_active' => true,
        ]);
        $taluka = Taluka::query()->create([
            'name' => 'Pune City',
            'district_id' => $district->id,
            'is_active' => true,
        ]);
        $city = City::query()->create([
            'name' => 'Pune',
            'taluka_id' => $taluka->id,
            'category' => 'metro',
            'is_active' => true,
        ]);

        return [
            'country' => $country,
            'state' => $state,
            'district' => $district,
            'taluka' => $taluka,
            'city' => $city,
        ];
    }

    private function putPublicPhoto(string $relative): void
    {
        $path = public_path('uploads/matrimony_photos/'.$relative);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, 'test-image');
        $this->createdFiles[] = $path;
    }

    private function removeEmptyParents(string $dir): void
    {
        $stop = public_path('uploads/matrimony_photos');
        while ($dir !== $stop && str_starts_with($dir, $stop) && is_dir($dir)) {
            $items = array_diff(scandir($dir) ?: [], ['.', '..']);
            if ($items !== []) {
                return;
            }
            @rmdir($dir);
            $dir = dirname($dir);
        }
    }
}
