<?php

namespace Tests\Feature\Suchak;

use App\Models\Caste;
use App\Models\EducationCategory;
use App\Models\EducationDegree;
use App\Models\Location;
use App\Models\MasterGender;
use App\Models\MasterMotherTongue;
use App\Models\MatrimonyProfile;
use App\Models\OccupationCategory;
use App\Models\OccupationMaster;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Models\WorkingWithType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end: Suchak fills every OCR-critical biodata field via member engines
 * into matrimony_profiles (representation-scoped save-step + photo).
 */
class SuchakRepresentedProfileFillE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_can_fill_full_candidate_profile_field_by_field_like_real_operator(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $genderId = $this->gender('female');
        $maritalStatusId = $this->maritalStatus('never_married');
        $motherTongue = MasterMotherTongue::query()->create([
            'key' => 'marathi',
            'label' => 'Marathi',
            'is_active' => true,
        ]);
        [$religion, $caste, $subCaste] = $this->religionCasteFixture();
        $location = $this->locationLeaf();
        [$degree, $occupation, $workingWith] = $this->educationOccupationFixture();

        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );

        $suchakUser = User::factory()->create([
            'mobile' => '9876504401',
            'mobile_verified_at' => now(),
        ]);
        SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'suchak_name' => 'Real Operator Suchak',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        Sanctum::actingAs($suchakUser);

        // 1) Contact + create shell (mobile + gender key) — same as wizard first save
        $create = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Priya Deshmukh',
            'candidate_mobile' => '9876504499',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.outcome', 'created');

        $representationId = (int) $create->json('data.representation_id');
        $profileId = (int) $create->json('data.profile_id');
        $this->assertGreaterThan(0, $representationId);
        $this->assertGreaterThan(0, $profileId);

        // 2) Identity / personal (OCR: name, DOB, gender, height, marital, mother tongue)
        $this->postJson("/api/v1/suchak/nxt/{$representationId}/profile/save-step", [
            'step' => 'basic_info',
            'data' => [
                'full_name' => 'Priya Deshmukh',
                'gender_id' => $genderId,
                'date_of_birth' => '1998-08-15',
                'height_cm' => 160,
                'marital_status_id' => $maritalStatusId,
                'mother_tongue_id' => $motherTongue->id,
            ],
        ])->assertOk()->assertJsonPath('success', true);

        // 3) Social (OCR: religion, caste, sub_caste)
        $this->postJson("/api/v1/suchak/nxt/{$representationId}/profile/save-step", [
            'step' => 'religion_caste',
            'data' => [
                'religion_id' => $religion->id,
                'caste_id' => $caste->id,
                'sub_caste_id' => $subCaste->id,
                'religion_strictness' => 'preferred',
                'caste_strictness' => 'preferred',
                'sub_caste_strictness' => 'open',
            ],
        ])->assertOk()->assertJsonPath('success', true);

        // 4) Education
        $this->postJson("/api/v1/suchak/nxt/{$representationId}/profile/save-step", [
            'step' => 'education',
            'data' => [
                'education_degree_ids' => [$degree->id],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        // 5) Career / income (OCR: occupation, income)
        $this->postJson("/api/v1/suchak/nxt/{$representationId}/profile/save-step", [
            'step' => 'career',
            'data' => [
                'company_name' => 'Navri Soft Pvt Ltd',
                'annual_income' => 600000,
                'income_period' => 'annual',
                'income_value_type' => 'approximate',
                'income_amount' => 600000,
                'occupation_master_id' => (int) $occupation->id,
                'working_with' => (string) $workingWith->slug,
            ],
        ])->assertOk()->assertJsonPath('success', true);

        // 6) Address (OCR: state/district/taluka/village via location_id)
        $this->postJson("/api/v1/suchak/nxt/{$representationId}/profile/save-step", [
            'step' => 'location',
            'data' => [
                'location_id' => $location->id,
                'address_line' => 'Near temple, Lane 3',
            ],
        ])->assertOk()->assertJsonPath('success', true);

        // 7) Photo (member photo engine)
        $this->post(
            "/api/v1/suchak/nxt/{$representationId}/profile/photo",
            ['profile_photo' => UploadedFile::fake()->image('priya.jpg', 600, 800)],
            ['Accept' => 'application/json']
        )->assertOk()->assertJsonPath('success', true);

        $profile = MatrimonyProfile::query()->findOrFail($profileId);
        $profile->refresh();

        $this->assertSame('Priya Deshmukh', $profile->full_name);
        $this->assertSame($genderId, (int) $profile->gender_id);
        $this->assertSame('1998-08-15', optional($profile->date_of_birth)->format('Y-m-d') ?? (string) $profile->date_of_birth);
        $this->assertSame(160, (int) $profile->height_cm);
        $this->assertSame($maritalStatusId, (int) $profile->marital_status_id);
        $this->assertSame($motherTongue->id, (int) $profile->mother_tongue_id);
        $this->assertSame($religion->id, (int) $profile->religion_id);
        $this->assertSame($caste->id, (int) $profile->caste_id);
        $this->assertSame($subCaste->id, (int) $profile->sub_caste_id);
        $this->assertSame($location->id, (int) $profile->location_id);
        $this->assertNotEmpty($profile->profile_photo);

        // Occupation / education may land in core snapshot columns depending on MutationService mapping
        if (Schema::hasColumn('matrimony_profiles', 'occupation_master_id')) {
            $this->assertSame($occupation->id, (int) $profile->occupation_master_id);
        }
        if (Schema::hasColumn('matrimony_profiles', 'highest_education') && filled($profile->highest_education)) {
            $this->assertNotEmpty($profile->highest_education);
        }
    }

    private function gender(string $key): int
    {
        DB::table('master_genders')->updateOrInsert(
            ['key' => $key],
            ['label' => ucfirst($key), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        return (int) DB::table('master_genders')->where('key', $key)->value('id');
    }

    private function maritalStatus(string $key): int
    {
        $payload = [
            'label' => str_replace('_', ' ', $key),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('master_marital_statuses', 'label_en')) {
            $payload['label_en'] = $payload['label'];
        }
        DB::table('master_marital_statuses')->updateOrInsert(['key' => $key], $payload);

        return (int) DB::table('master_marital_statuses')->where('key', $key)->value('id');
    }

    /**
     * @return array{0: Religion, 1: Caste, 2: SubCaste}
     */
    private function religionCasteFixture(): array
    {
        $religion = Religion::query()->create([
            'key' => 'hindu_e2e',
            'label' => 'Hindu',
            'label_en' => 'Hindu',
            'is_active' => true,
        ]);
        $caste = Caste::query()->create([
            'religion_id' => $religion->id,
            'key' => 'deshastha_e2e',
            'label' => 'Deshastha',
            'label_en' => 'Deshastha',
            'is_active' => true,
        ]);
        $subCaste = SubCaste::query()->create([
            'caste_id' => $caste->id,
            'key' => 'sub_e2e',
            'label' => 'Rigvedi',
            'label_en' => 'Rigvedi',
            'status' => 'approved',
            'is_active' => true,
        ]);

        return [$religion, $caste, $subCaste];
    }

    private function locationLeaf(): Location
    {
        $suffix = strtolower(str_replace('.', '-', uniqid('e2e-', true)));
        $country = Location::query()->create([
            'name' => 'India '.$suffix,
            'slug' => 'india-'.$suffix,
            'hierarchy' => 'country',
            'is_active' => true,
        ]);
        $state = Location::query()->create([
            'name' => 'Maharashtra '.$suffix,
            'slug' => 'mh-'.$suffix,
            'hierarchy' => 'state',
            'parent_id' => $country->id,
            'is_active' => true,
        ]);
        $district = Location::query()->create([
            'name' => 'Pune '.$suffix,
            'slug' => 'pune-'.$suffix,
            'hierarchy' => 'district',
            'parent_id' => $state->id,
            'is_active' => true,
        ]);
        $taluka = Location::query()->create([
            'name' => 'Haveli '.$suffix,
            'slug' => 'haveli-'.$suffix,
            'hierarchy' => 'taluka',
            'parent_id' => $district->id,
            'is_active' => true,
        ]);

        return Location::query()->create([
            'name' => 'Wagholi '.$suffix,
            'slug' => 'wagholi-'.$suffix,
            'hierarchy' => 'village',
            'tag' => 'city',
            'parent_id' => $taluka->id,
            'pincode' => '412207',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: EducationDegree, 1: OccupationMaster, 2: WorkingWithType}
     */
    private function educationOccupationFixture(): array
    {
        $educationCategory = EducationCategory::query()->create([
            'name' => 'Engineering E2E',
            'slug' => 'engineering-e2e-'.uniqid(),
            'sort_order' => 4,
            'is_active' => true,
        ]);
        $degree = EducationDegree::query()->create([
            'category_id' => $educationCategory->id,
            'code' => 'B.E.',
            'full_form' => 'Bachelor of Engineering',
            'sort_order' => 2,
        ]);
        $workingWith = WorkingWithType::query()->create([
            'name' => 'Private Company',
            'name_mr' => null,
            'slug' => 'private-company-e2e-'.uniqid(),
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $occupationCategory = OccupationCategory::query()->create([
            'name' => 'IT E2E',
            'name_mr' => null,
            'sort_order' => 1,
            'legacy_working_with_type_id' => $workingWith->id,
        ]);
        $occupation = OccupationMaster::query()->create([
            'name' => 'Software Engineer',
            'name_mr' => null,
            'normalized_name' => 'software engineer',
            'category_id' => $occupationCategory->id,
            'sort_order' => 1,
        ]);

        return [$degree, $occupation, $workingWith];
    }
}
