<?php

namespace Tests\Feature\Api;

use App\Models\Caste;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\MobileOnboardingDraft;
use App\Models\ProfilePhoto;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Models\User;
use App\Services\MutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class MobileOnboardingPhase2ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_onboarding_endpoints_return_401(): void
    {
        $this->postJson('/api/v1/onboarding/start', [])->assertUnauthorized();
        $this->getJson('/api/v1/onboarding/status')->assertUnauthorized();
        $this->getJson('/api/v1/onboarding/draft')->assertUnauthorized();
        $this->patchJson('/api/v1/onboarding/draft/basic_info', ['data' => []])->assertUnauthorized();
        $this->postJson('/api/v1/onboarding/profile/save-step', [])->assertUnauthorized();
        $this->getJson('/api/v1/onboarding/activation-checklist')->assertUnauthorized();
    }

    public function test_start_onboarding_creates_one_draft_and_is_idempotent(): void
    {
        $user = $this->verifiedAccount();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/onboarding/start', [
            'profile_for_whom' => 'self',
        ])->assertOk();

        $first->assertJsonPath('success', true)
            ->assertJsonPath('has_existing_profile', false)
            ->assertJsonPath('last_completed_step', 'profile_for_whom')
            ->assertJsonPath('current_step', 'basic_info')
            ->assertJsonPath('next_step', 'basic_info');

        $this->postJson('/api/v1/onboarding/start', [
            'profile_for_whom' => 'self',
        ])->assertOk();

        $this->assertSame(1, MobileOnboardingDraft::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('mobile_onboarding_drafts', [
            'user_id' => $user->id,
            'last_completed_step' => 'profile_for_whom',
            'current_step' => 'basic_info',
        ]);
    }

    public function test_existing_profile_resumes_instead_of_creating_duplicate_profile(): void
    {
        $user = $this->verifiedAccount();
        $profile = MatrimonyProfile::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/onboarding/start', [
            'profile_for_whom' => 'daughter',
        ])->assertOk();

        $response->assertJsonPath('has_existing_profile', true)
            ->assertJsonPath('profile_id', $profile->id);

        $this->assertSame(1, MatrimonyProfile::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('mobile_onboarding_drafts', [
            'user_id' => $user->id,
            'matrimony_profile_id' => $profile->id,
        ]);
    }

    public function test_profile_for_whom_values_are_accepted_and_stored_in_draft(): void
    {
        foreach (['self', 'son', 'daughter', 'brother', 'sister', 'relative', 'friend'] as $value) {
            $user = $this->verifiedAccount(['mobile' => fake()->unique()->numerify('9#########')]);
            Sanctum::actingAs($user);

            $this->postJson('/api/v1/onboarding/start', [
                'profile_for_whom' => $value,
            ])->assertOk();

            $draft = MobileOnboardingDraft::query()->where('user_id', $user->id)->firstOrFail();
            $this->assertSame($value, data_get($draft->draft_data, 'profile_for_whom.profile_for_whom'));
        }
    }

    public function test_status_returns_email_optional_non_blocking_and_searchability_aliases(): void
    {
        $user = $this->verifiedAccount(['email' => null, 'email_verified_at' => null]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/onboarding/status')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('account.email_present', false)
            ->assertJsonPath('account.email_verified', false)
            ->assertJsonPath('has_profile', false)
            ->assertJsonPath('profile_status', null)
            ->assertJsonPath('is_searchable', false);

        $emailItem = collect($response->json('activation_checklist'))->firstWhere('key', 'email_added_optional');
        $this->assertNotNull($emailItem);
        $this->assertFalse((bool) $emailItem['blocking']);
        $this->assertSame('optional', $emailItem['status']);
    }

    public function test_draft_save_stores_step_data_and_applies_dependent_clears(): void
    {
        $user = $this->verifiedAccount();
        Sanctum::actingAs($user);
        [$religionOne, $casteOne, $subCasteOne, $religionTwo] = $this->religionCasteFixture();
        $neverMarriedId = $this->maritalStatus('never_married');

        $this->patchJson('/api/v1/onboarding/draft/religion_caste', [
            'data' => [
                'religion_id' => $religionOne->id,
                'caste_id' => $casteOne->id,
                'sub_caste_id' => $subCasteOne->id,
            ],
        ])->assertOk();

        $response = $this->patchJson('/api/v1/onboarding/draft/religion_caste', [
            'data' => [
                'religion_id' => $religionTwo->id,
            ],
        ])->assertOk();

        $response->assertJsonPath('last_completed_step', 'religion_caste')
            ->assertJsonPath('current_step', 'location')
            ->assertJsonPath('next_step', 'location');

        $draft = MobileOnboardingDraft::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNull(data_get($draft->draft_data, 'religion_caste.caste_id'));
        $this->assertNull(data_get($draft->draft_data, 'religion_caste.sub_caste_id'));

        $this->patchJson('/api/v1/onboarding/draft/basic_info', [
            'data' => [
                'marital_status_id' => $neverMarriedId,
                'has_children' => true,
                'children' => [['gender' => 'male', 'age' => 5]],
            ],
        ])->assertOk();

        $draft->refresh();
        $this->assertFalse(data_get($draft->draft_data, 'basic_info.has_children'));
        $this->assertSame([], data_get($draft->draft_data, 'basic_info.children'));

        $this->patchJson('/api/v1/onboarding/draft/career', [
            'data' => [
                'occupation_master_id' => null,
                'working_with' => 'private',
            ],
        ])->assertOk();
        $draft->refresh();
        $this->assertNull(data_get($draft->draft_data, 'career.occupation_master_id'));
    }

    public function test_profile_save_step_uses_mutation_service_and_does_not_duplicate_profile(): void
    {
        $user = $this->verifiedAccount();
        Sanctum::actingAs($user);

        $mock = Mockery::mock(MutationService::class)->makePartial();
        $mock->shouldReceive('createDraftProfileForUser')->once()->passthru();
        $mock->shouldReceive('applyManualSnapshot')->twice()->passthru();
        $this->app->instance(MutationService::class, $mock);

        $response = $this->postJson('/api/v1/onboarding/profile/save-step', [
            'step' => 'basic_info',
            'data' => [
                'full_name' => 'Candidate Name',
            ],
        ])->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('profile.profile_status', 'draft')
            ->assertJsonPath('profile.is_searchable', false);

        $this->assertSame(1, MatrimonyProfile::query()->where('user_id', $user->id)->count());

        $this->postJson('/api/v1/onboarding/profile/save-step', [
            'step' => 'basic_info',
            'data' => [
                'full_name' => 'Candidate Name Updated',
            ],
        ])->assertOk();

        $this->assertSame(1, MatrimonyProfile::query()->where('user_id', $user->id)->count());
    }

    public function test_activation_checklist_blocks_missing_unapproved_photo_and_invalid_location(): void
    {
        $user = $this->verifiedAccount();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'location_id' => null,
            'profile_photo' => '',
            'photo_approved' => false,
            'lifecycle_state' => 'draft',
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/onboarding/activation-checklist')->assertOk();
        $this->assertFalse((bool) $response->json('is_searchable'));
        $this->assertFalse((bool) collect($response->json('items'))->firstWhere('key', 'photo_uploaded')['complete']);
        $this->assertFalse((bool) collect($response->json('items'))->firstWhere('key', 'location_valid')['complete']);

        $profile->forceFill([
            'profile_photo' => 'pending/mobile-upload.jpg',
            'photo_approved' => false,
        ])->save();

        ProfilePhoto::query()->create([
            'profile_id' => $profile->id,
            'file_path' => 'pending/mobile-upload.jpg',
            'is_primary' => true,
            'uploaded_via' => 'user_mobile',
            'approved_status' => 'pending',
            'watermark_detected' => false,
        ]);

        $response = $this->getJson('/api/v1/onboarding/activation-checklist')->assertOk();
        $this->assertTrue((bool) collect($response->json('items'))->firstWhere('key', 'photo_uploaded')['complete']);
        $this->assertFalse((bool) collect($response->json('items'))->firstWhere('key', 'photo_approved')['complete']);
        $this->assertFalse((bool) $response->json('is_searchable'));
    }

    public function test_location_must_be_active_final_node_for_searchability_checklist(): void
    {
        $user = $this->verifiedAccount();
        $location = $this->locationLeaf(true);
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $user->id,
            'location_id' => $location->id,
            'profile_photo' => 'processed.jpg',
            'photo_approved' => true,
            'lifecycle_state' => 'draft',
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/onboarding/status')->assertOk();
        $this->assertTrue((bool) $response->json('profile.location_valid'));

        $location->forceFill(['is_active' => false])->save();
        $response = $this->getJson('/api/v1/onboarding/status')->assertOk();
        $this->assertFalse((bool) $response->json('profile.location_valid'));
        $this->assertFalse((bool) $response->json('is_searchable'));
    }

    public function test_profile_save_rejects_phase_two_forbidden_and_arbitrary_text_fields(): void
    {
        $user = $this->verifiedAccount();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/onboarding/profile/save-step', [
            'step' => 'family',
            'data' => [
                'family_type_id' => 1,
            ],
        ])->assertStatus(422);

        $this->postJson('/api/v1/onboarding/profile/save-step', [
            'step' => 'education',
            'data' => [
                'highest_education' => 'Arbitrary Degree',
            ],
        ])->assertStatus(422);

        $this->postJson('/api/v1/onboarding/profile/save-step', [
            'step' => 'career',
            'data' => [
                'occupation' => 'Arbitrary Occupation',
            ],
        ])->assertStatus(422);

        $this->postJson('/api/v1/onboarding/profile/save-step', [
            'step' => 'basic_info',
            'data' => [
                'mother_tongue_id' => 1,
            ],
        ])->assertStatus(422);
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
     * @return array{0: Religion, 1: Caste, 2: SubCaste, 3: Religion}
     */
    private function religionCasteFixture(): array
    {
        $religionOne = Religion::query()->create([
            'key' => 'hindu',
            'label' => 'Hindu',
            'is_active' => true,
        ]);
        $religionTwo = Religion::query()->create([
            'key' => 'buddhist',
            'label' => 'Buddhist',
            'is_active' => true,
        ]);
        $caste = Caste::query()->create([
            'religion_id' => $religionOne->id,
            'key' => 'maratha',
            'label' => 'Maratha',
            'is_active' => true,
        ]);
        $subCaste = SubCaste::query()->create([
            'caste_id' => $caste->id,
            'key' => 'sub',
            'label' => 'Sub',
            'is_active' => true,
        ]);

        return [$religionOne, $caste, $subCaste, $religionTwo];
    }

    private function maritalStatus(string $key): int
    {
        $existing = DB::table('master_marital_statuses')->where('key', $key)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('master_marital_statuses')->insertGetId([
            'key' => $key,
            'label' => str_replace('_', ' ', $key),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function locationLeaf(bool $active): Location
    {
        $suffix = strtolower(str_replace('.', '-', uniqid('mobile-onboarding-', true)));
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
            'is_active' => $active,
        ]);
    }
}
