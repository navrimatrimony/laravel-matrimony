<?php

namespace Tests\Feature\Api;

use App\Models\Caste;
use App\Models\EducationCategory;
use App\Models\EducationDegree;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\MobileOnboardingDraft;
use App\Models\OccupationCategory;
use App\Models\OccupationMaster;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Models\User;
use App\Models\WorkingWithType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileOnboardingPhase3ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_lookup_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/onboarding/lookups/bootstrap')->assertUnauthorized();
        $this->getJson('/api/v1/onboarding/lookups/religions')->assertUnauthorized();
        $this->postJson('/api/v1/onboarding/location-suggestions', [])->assertUnauthorized();
        $this->getJson('/api/v1/onboarding/preferences/auto-draft/preview')->assertUnauthorized();
    }

    public function test_bootstrap_returns_profile_for_whom_with_gender_mode(): void
    {
        Sanctum::actingAs($this->verifiedAccount());

        $response = $this->getJson('/api/v1/onboarding/lookups/bootstrap?locale=mr')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('profile_for_whom.0.key', 'self')
            ->assertJsonPath('profile_for_whom.0.gender_mode', 'ask')
            ->assertJsonPath('children_rules.hide_for_keys.0', 'never_married');
    }

    public function test_religion_lookup_returns_localized_label_with_english_fallback_and_pagination(): void
    {
        Sanctum::actingAs($this->verifiedAccount());
        Religion::query()->create([
            'key' => 'hindu',
            'label' => 'Hindu',
            'label_en' => 'Hindu',
            'label_mr' => 'हिंदू',
            'is_active' => true,
        ]);
        Religion::query()->create([
            'key' => 'buddhist',
            'label' => 'Buddhist',
            'label_en' => 'Buddhist',
            'label_mr' => null,
            'is_active' => true,
        ]);

        $localized = $this->getJson('/api/v1/onboarding/lookups/religions?locale=mr&q=Hin&limit=50')
            ->assertOk();
        $localized->assertJsonPath('results.0.label', 'हिंदू')
            ->assertJsonPath('results.0.translation_missing', false)
            ->assertJsonPath('pagination.limit', 50);

        $fallback = $this->getJson('/api/v1/onboarding/lookups/religions?locale=mr&q=Buddhist&limit=99')
            ->assertOk();
        $fallback->assertJsonPath('results.0.label', 'Buddhist')
            ->assertJsonPath('results.0.translation_missing', true)
            ->assertJsonPath('pagination.limit', 50);
    }

    public function test_caste_and_sub_caste_lookups_require_parent_and_filter_by_parent(): void
    {
        Sanctum::actingAs($this->verifiedAccount());
        [$religionOne, $casteOne, $subCasteOne, $religionTwo] = $this->religionCasteFixture();
        $casteTwo = Caste::query()->create([
            'religion_id' => $religionTwo->id,
            'key' => 'other',
            'label' => 'Other Caste',
            'is_active' => true,
        ]);
        SubCaste::query()->create([
            'caste_id' => $casteTwo->id,
            'key' => 'other-sub',
            'label' => 'Other Sub',
            'status' => 'approved',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/onboarding/lookups/castes')->assertStatus(422);
        $this->getJson('/api/v1/onboarding/lookups/sub-castes')->assertStatus(422);

        $castes = $this->getJson('/api/v1/onboarding/lookups/castes?religion_id='.$religionOne->id)
            ->assertOk()
            ->json('results');
        $this->assertSame([$casteOne->id], array_column($castes, 'id'));

        $subCastes = $this->getJson('/api/v1/onboarding/lookups/sub-castes?caste_id='.$casteOne->id)
            ->assertOk()
            ->json('results');
        $this->assertSame([$subCasteOne->id], array_column($subCastes, 'id'));
    }

    public function test_location_lookup_returns_hierarchy_and_pending_location_suggestion_does_not_create_master(): void
    {
        $user = $this->verifiedAccount();
        Sanctum::actingAs($user);
        $leaf = $this->locationLeaf(true);
        $district = $this->ancestor($leaf, 'district');
        $state = $this->ancestor($leaf, 'state');
        $taluka = $this->ancestor($leaf, 'taluka');

        $response = $this->getJson('/api/v1/onboarding/lookups/locations?q='.urlencode($leaf->name).'&type=city')
            ->assertOk();

        $response->assertJsonPath('results.0.location_id', $leaf->id)
            ->assertJsonPath('results.0.type', 'city')
            ->assertJsonPath('results.0.is_final_node', true)
            ->assertJsonPath('results.0.status', 'approved')
            ->assertJsonPath('results.0.state_id', $state->id)
            ->assertJsonPath('results.0.district_id', $district->id)
            ->assertJsonPath('results.0.taluka_id', $taluka->id);

        $districtResponse = $this->getJson('/api/v1/onboarding/lookups/locations?q='.urlencode($district->name))
            ->assertOk();
        $districtRow = collect($districtResponse->json('results'))->firstWhere('id', $district->id);
        $this->assertNotNull($districtRow);
        $this->assertFalse((bool) $districtRow['is_final_node']);

        $before = Location::query()->count();
        $suggestion = $this->postJson('/api/v1/onboarding/location-suggestions', [
            'type' => 'village',
            'name' => 'New Pending Village',
            'state_id' => $state->id,
            'district_id' => $district->id,
            'taluka_id' => $taluka->id,
            'pincode' => '416312',
        ])->assertOk();

        $suggestion->assertJsonPath('success', true)
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('request.type', 'village');
        $this->assertSame($before, Location::query()->count());
        $this->assertDatabaseHas('location_open_place_suggestions', [
            'raw_input' => 'New Pending Village',
            'status' => 'pending',
            'suggested_by' => $user->id,
        ]);
    }

    public function test_education_and_occupation_lookups_return_backend_categories_and_suggestions_are_pending(): void
    {
        $user = $this->verifiedAccount();
        Sanctum::actingAs($user);
        $educationCategory = EducationCategory::query()->create([
            'name' => 'Engineering',
            'name_mr' => null,
            'slug' => 'engineering',
            'sort_order' => 4,
            'is_active' => true,
        ]);
        $degree = EducationDegree::query()->create([
            'category_id' => $educationCategory->id,
            'code' => 'B.E.',
            'code_mr' => null,
            'full_form' => 'Bachelor of Engineering',
            'sort_order' => 2,
        ]);
        $workingWith = WorkingWithType::query()->create([
            'name' => 'Private Company',
            'name_mr' => null,
            'slug' => 'private-company',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $occupationCategory = OccupationCategory::query()->create([
            'name' => 'IT & Software',
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

        $education = $this->getJson('/api/v1/onboarding/lookups/education?q=Engineer&locale=mr')
            ->assertOk();
        $education->assertJsonPath('results.0.id', $degree->id)
            ->assertJsonPath('results.0.meta.category_id', $educationCategory->id)
            ->assertJsonPath('results.0.meta.category_label', 'Engineering')
            ->assertJsonPath('results.0.meta.level_rank_source', 'category_sort_order');

        $occupationResponse = $this->getJson('/api/v1/onboarding/lookups/occupations?q=Software&working_with_id='.$workingWith->id)
            ->assertOk();
        $occupationResponse->assertJsonPath('results.0.id', $occupation->id)
            ->assertJsonPath('results.0.meta.category_id', $occupationCategory->id)
            ->assertJsonPath('results.0.meta.working_with_id', $workingWith->id);

        $this->postJson('/api/v1/onboarding/education-suggestions', [
            'label' => 'Custom Education',
            'category_id' => $educationCategory->id,
        ])->assertCreated();
        $this->postJson('/api/v1/onboarding/occupation-suggestions', [
            'label' => 'Custom Occupation',
            'category_id' => $occupationCategory->id,
            'working_with_id' => $workingWith->id,
        ])->assertCreated();

        $this->assertDatabaseHas('mobile_onboarding_master_suggestions', [
            'type' => 'education',
            'label' => 'Custom Education',
            'status' => 'pending',
            'suggested_by_user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('mobile_onboarding_master_suggestions', [
            'type' => 'occupation',
            'label' => 'Custom Occupation',
            'status' => 'pending',
            'suggested_by_user_id' => $user->id,
        ]);
    }

    public function test_income_options_match_onboarding_income_contract(): void
    {
        Sanctum::actingAs($this->verifiedAccount());
        DB::table('master_income_currencies')->updateOrInsert(
            ['code' => 'INR'],
            ['symbol' => 'Rs', 'is_default' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $response = $this->getJson('/api/v1/onboarding/lookups/income-options')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('currency', 'INR')
            ->assertJsonPath('periods.0.key', 'monthly')
            ->assertJsonPath('periods.1.key', 'annual')
            ->assertJsonPath('value_types.1.key', 'approximate')
            ->assertJsonPath('privacy_default', 'private');
    }

    public function test_partner_preference_preview_and_persist_use_auto_source_and_strictness_metadata(): void
    {
        $user = $this->verifiedAccount();
        Sanctum::actingAs($user);
        [$religion, $caste] = $this->religionCasteFixture();
        $genderId = $this->gender('male');
        $maritalStatusId = $this->maritalStatus('never_married');
        $dietId = $this->diet('veg');
        $location = $this->locationLeaf(true);
        [$degree, $occupation] = $this->educationOccupationFixture();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'full_name' => 'Candidate Name',
            'gender_id' => $genderId,
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'height_cm' => 172,
            'marital_status_id' => $maritalStatusId,
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
            'location_id' => $location->id,
            'highest_education' => 'B.E.',
            'occupation_master_id' => $occupation->id,
            'diet_id' => $dietId,
            'annual_income' => 1000000,
        ]);
        MobileOnboardingDraft::query()->create([
            'user_id' => $user->id,
            'matrimony_profile_id' => $profile->id,
            'current_step' => 'activation',
            'completed_steps' => ['account', 'profile_for_whom'],
            'draft_data' => [
                'religion_caste' => [
                    'same_religion_expected' => true,
                    'same_caste_expected' => false,
                ],
            ],
            'started_at' => now(),
        ]);

        $preview = $this->getJson('/api/v1/onboarding/preferences/auto-draft/preview')
            ->assertOk();
        $preview->assertJsonPath('source', 'auto_from_registration')
            ->assertJsonPath('strictness.religion', 'must_match')
            ->assertJsonPath('strictness.caste', 'open')
            ->assertJsonPath('strictness.education', 'preferred')
            ->assertJsonPath('preferences.preferred_religion_ids.0', $religion->id)
            ->assertJsonPath('preferences.preferred_caste_ids', [])
            ->assertJsonPath('preferences.preferred_education_degree_ids.0', $degree->id)
            ->assertJsonPath('preferences.preferred_occupation_master_ids.0', $occupation->id);

        $persist = $this->postJson('/api/v1/onboarding/preferences/auto-draft', [
            'force_regenerate' => false,
        ])->assertOk();
        $persist->assertJsonPath('success', true)
            ->assertJsonPath('metadata.source', 'auto_from_registration')
            ->assertJsonPath('metadata.strictness.religion', 'must_match')
            ->assertJsonPath('editable', true);

        $this->assertDatabaseHas('partner_preference_metadata', [
            'matrimony_profile_id' => $profile->id,
            'source' => 'auto_from_registration',
            'generated_from' => 'onboarding',
        ]);
        $this->assertDatabaseHas('profile_preferred_religions', [
            'profile_id' => $profile->id,
            'religion_id' => $religion->id,
        ]);
        $this->assertDatabaseMissing('profile_preferred_castes', [
            'profile_id' => $profile->id,
            'caste_id' => $caste->id,
        ]);

        $status = $this->getJson('/api/v1/onboarding/preferences/auto-draft/status')->assertOk();
        $status->assertJsonPath('has_auto_draft', true)
            ->assertJsonPath('editable', true);

        $this->getJson('/api/v1/onboarding/status')
            ->assertOk()
            ->assertJsonPath('preferences.has_auto_draft', true);
    }

    public function test_partner_preference_auto_draft_does_not_overwrite_manual_preferences(): void
    {
        $user = $this->verifiedAccount();
        Sanctum::actingAs($user);
        MatrimonyProfile::factory()->create(['user_id' => $user->id]);
        DB::table('profile_preference_criteria')->insert([
            'profile_id' => MatrimonyProfile::query()->where('user_id', $user->id)->value('id'),
            'preferred_age_min' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/onboarding/preferences/auto-draft', [
            'force_regenerate' => true,
        ])->assertStatus(409);
    }

    private function verifiedAccount(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Creator Name',
            'mobile' => fake()->unique()->numerify('9#########'),
            'mobile_verified_at' => now(),
            'email' => null,
            'email_verified_at' => null,
        ], $overrides));
    }

    /**
     * @return array{0: Religion, 1: Caste, 2?: SubCaste, 3?: Religion}
     */
    private function religionCasteFixture(): array
    {
        $religionOne = Religion::query()->create([
            'key' => 'hindu',
            'label' => 'Hindu',
            'label_en' => 'Hindu',
            'label_mr' => 'हिंदू',
            'is_active' => true,
        ]);
        $religionTwo = Religion::query()->create([
            'key' => 'buddhist',
            'label' => 'Buddhist',
            'label_en' => 'Buddhist',
            'is_active' => true,
        ]);
        $caste = Caste::query()->create([
            'religion_id' => $religionOne->id,
            'key' => 'maratha',
            'label' => 'Maratha',
            'label_en' => 'Maratha',
            'is_active' => true,
        ]);
        $subCaste = SubCaste::query()->create([
            'caste_id' => $caste->id,
            'key' => 'sub',
            'label' => 'Sub',
            'label_en' => 'Sub',
            'status' => 'approved',
            'is_active' => true,
        ]);

        return [$religionOne, $caste, $subCaste, $religionTwo];
    }

    private function locationLeaf(bool $active): Location
    {
        $suffix = strtolower(str_replace('.', '-', uniqid('phase3-', true)));
        $country = Location::query()->create([
            'name' => 'India '.$suffix,
            'slug' => 'india-'.$suffix,
            'hierarchy' => 'country',
            'is_active' => true,
        ]);
        $state = Location::query()->create([
            'name' => 'Maharashtra '.$suffix,
            'slug' => 'maharashtra-'.$suffix,
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
            'is_active' => $active,
        ]);
    }

    private function ancestor(Location $location, string $hierarchy): Location
    {
        $current = $location;
        while ($current->parent_id !== null) {
            $current = Location::query()->findOrFail($current->parent_id);
            if ($current->hierarchy === $hierarchy) {
                return $current;
            }
        }

        $this->fail('Missing ancestor '.$hierarchy);
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
        DB::table('master_marital_statuses')->updateOrInsert(
            ['key' => $key],
            ['label' => str_replace('_', ' ', $key), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        return (int) DB::table('master_marital_statuses')->where('key', $key)->value('id');
    }

    private function diet(string $key): int
    {
        DB::table('master_diets')->updateOrInsert(
            ['key' => $key],
            ['label' => ucfirst($key), 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]
        );

        return (int) DB::table('master_diets')->where('key', $key)->value('id');
    }

    /**
     * @return array{0: EducationDegree, 1: OccupationMaster}
     */
    private function educationOccupationFixture(): array
    {
        $educationCategory = EducationCategory::query()->create([
            'name' => 'Engineering',
            'slug' => 'engineering-pref',
            'sort_order' => 4,
            'is_active' => true,
        ]);
        $degree = EducationDegree::query()->create([
            'category_id' => $educationCategory->id,
            'code' => 'B.E.',
            'full_form' => 'Bachelor of Engineering',
            'sort_order' => 2,
        ]);
        $occupationCategory = OccupationCategory::query()->create([
            'name' => 'IT & Software',
            'sort_order' => 1,
        ]);
        $occupation = OccupationMaster::query()->create([
            'name' => 'Software Engineer',
            'normalized_name' => 'software engineer',
            'category_id' => $occupationCategory->id,
            'sort_order' => 1,
        ]);

        return [$degree, $occupation];
    }
}
