<?php

namespace Tests\Feature\Suchak;

use App\Models\BiodataIntake;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakIntakeApplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuchakIntakeApplyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_intake_approval_creates_candidate_profile_and_links_source(): void
    {
        $genderId = $this->seedMasterGender('female', 'Female');
        $addressTypeId = $this->seedMasterAddressType('current', 'Current');
        $locationId = $this->seedLocation('Sangli');

        $suchakUser = User::factory()->create([
            'name' => 'Suchak Owner',
            'mobile' => '2222222229',
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'verified_at' => now(),
        ]);

        $intake = BiodataIntake::query()->create([
            'uploaded_by' => $suchakUser->id,
            'raw_ocr_text' => 'Candidate biodata',
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
            'approved_by_user' => false,
            'intake_locked' => false,
            'snapshot_schema_version' => 1,
            'parsed_json' => [
                'snapshot_schema_version' => 1,
                'core' => [
                    'full_name' => 'Suchak Intake Candidate',
                    'gender' => 'female',
                    'gender_id' => $genderId,
                    'date_of_birth' => '1998-01-15',
                ],
                'addresses' => [
                    [
                        'type' => 'current',
                        'address_line' => 'Sangli, Maharashtra',
                        'location_id' => $locationId,
                    ],
                ],
            ],
        ]);

        $sourceLink = SuchakBiodataIntakeLink::query()->create([
            'suchak_account_id' => $account->id,
            'biodata_intake_id' => $intake->id,
            'matrimony_profile_id' => null,
            'source_status' => SuchakBiodataIntakeLink::STATUS_INTAKE_UPLOADED,
            'created_by_user_id' => $suchakUser->id,
        ]);

        $result = app(SuchakIntakeApplyService::class)->approveAndApply(
            $intake,
            $suchakUser,
            [
                'snapshot_schema_version' => 1,
                'core' => [
                    'full_name' => 'Suchak Intake Candidate',
                    'gender' => 'female',
                    'gender_id' => $genderId,
                    'date_of_birth' => '1998-01-15',
                ],
            ],
            '127.0.0.1',
            'feature-test',
        );

        $this->assertTrue($result['mutation_success']);
        $this->assertFalse($result['conflict_detected']);
        $this->assertNotNull($result['profile_id']);
        $this->assertNotNull($result['created_user_id']);

        $profile = MatrimonyProfile::query()->findOrFail((int) $result['profile_id']);
        $this->assertNotSame($suchakUser->id, $profile->user_id);
        $this->assertSame('Suchak Intake Candidate', $profile->full_name);
        $this->assertSame('active', $profile->lifecycle_state);

        $this->assertDatabaseHas('profile_addresses', [
            'profile_id' => $profile->id,
            'address_scope' => 'self',
            'address_type_id' => $addressTypeId,
            'location_id' => $locationId,
            'address_line' => 'Sangli, Maharashtra',
        ]);

        $this->assertDatabaseHas('biodata_intakes', [
            'id' => $intake->id,
            'matrimony_profile_id' => $profile->id,
            'intake_status' => 'applied',
            'approved_by_user' => true,
            'intake_locked' => true,
        ]);

        $this->assertDatabaseHas('suchak_biodata_intake_links', [
            'id' => $sourceLink->id,
            'matrimony_profile_id' => $profile->id,
            'source_status' => SuchakBiodataIntakeLink::STATUS_CREATED_NEW_PROFILE,
        ]);

        $representation = SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $account->id)
            ->where('matrimony_profile_id', $profile->id)
            ->firstOrFail();
        $this->assertSame(SuchakProfileRepresentation::MODE_UPLOADED_BY_SUCHAK, $representation->representation_mode);
        $this->assertSame(SuchakProfileRepresentation::STATUS_PENDING, $representation->representation_status);

        $this->assertDatabaseHas('suchak_customer_contexts', [
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'source_link_id' => $sourceLink->id,
            'representation_id' => $representation->id,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_INTAKE_UPLOAD,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_CANDIDATE_IDENTIFIED,
        ]);
    }

    public function test_suchak_intake_preview_does_not_overlay_suchak_owner_profile(): void
    {
        $suchakUser = User::factory()->create([
            'name' => 'Suchak Owner',
            'mobile' => '2222222231',
        ]);
        $ownerProfile = MatrimonyProfile::factory()->create([
            'user_id' => $suchakUser->id,
            'full_name' => 'Suchak Owner Personal Profile',
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'verified_at' => now(),
        ]);
        $intake = BiodataIntake::query()->create([
            'uploaded_by' => $suchakUser->id,
            'raw_ocr_text' => 'Candidate biodata',
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
        ]);
        SuchakBiodataIntakeLink::query()->create([
            'suchak_account_id' => $account->id,
            'biodata_intake_id' => $intake->id,
            'source_status' => SuchakBiodataIntakeLink::STATUS_INTAKE_UPLOADED,
            'created_by_user_id' => $suchakUser->id,
        ]);

        $resolved = app(\App\Services\Intake\IntakePreviewLinkedProfileResolver::class)->resolve($intake);

        $this->assertNull($resolved);
        $this->assertSame($ownerProfile->id, $suchakUser->fresh()->matrimonyProfile?->id);
    }

    public function test_suchak_intake_approval_route_creates_candidate_profile_and_links_source(): void
    {
        $genderId = $this->seedMasterGender('female', 'Female');
        $addressTypeId = $this->seedMasterAddressType('current', 'Current');
        $locationId = $this->seedLocation('Miraj');

        $suchakUser = User::factory()->create([
            'name' => 'Route Suchak Owner',
            'mobile' => '2222222230',
        ]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'verified_at' => now(),
        ]);

        $intake = BiodataIntake::query()->create([
            'uploaded_by' => $suchakUser->id,
            'raw_ocr_text' => 'Route candidate biodata',
            'intake_status' => 'uploaded',
            'parse_status' => 'parsed',
            'approved_by_user' => false,
            'intake_locked' => false,
            'snapshot_schema_version' => 1,
            'parsed_json' => [
                'snapshot_schema_version' => 1,
                'core' => [
                    'full_name' => 'Route Suchak Candidate',
                    'gender' => 'female',
                    'gender_id' => $genderId,
                    'date_of_birth' => '1999-02-20',
                ],
                'addresses' => [
                    [
                        'type' => 'current',
                        'address_line' => 'Miraj, Maharashtra',
                        'location_id' => $locationId,
                    ],
                ],
            ],
        ]);

        $sourceLink = SuchakBiodataIntakeLink::query()->create([
            'suchak_account_id' => $account->id,
            'biodata_intake_id' => $intake->id,
            'matrimony_profile_id' => null,
            'source_status' => SuchakBiodataIntakeLink::STATUS_INTAKE_UPLOADED,
            'created_by_user_id' => $suchakUser->id,
        ]);

        $response = $this->actingAs($suchakUser)
            ->withSession(['preview_seen_'.$intake->id => true])
            ->post(route('intake.approve', $intake), [
                'snapshot' => [
                    'snapshot_schema_version' => 1,
                    'core' => [
                        'full_name' => 'Route Suchak Candidate',
                        'gender' => 'female',
                        'gender_id' => $genderId,
                        'date_of_birth' => '1999-02-20',
                    ],
                ],
            ]);

        $response->assertRedirect(route('intake.status', $intake));
        $response->assertSessionHas('mutation_result.mutation_success', true);

        $profile = MatrimonyProfile::query()
            ->where('full_name', 'Route Suchak Candidate')
            ->firstOrFail();

        $this->assertNotSame($suchakUser->id, $profile->user_id);
        $this->assertSame('active', $profile->lifecycle_state);

        $this->assertDatabaseHas('profile_addresses', [
            'profile_id' => $profile->id,
            'address_scope' => 'self',
            'address_type_id' => $addressTypeId,
            'location_id' => $locationId,
            'address_line' => 'Miraj, Maharashtra',
        ]);

        $this->assertDatabaseHas('biodata_intakes', [
            'id' => $intake->id,
            'matrimony_profile_id' => $profile->id,
            'intake_status' => 'applied',
            'approved_by_user' => true,
            'intake_locked' => true,
        ]);

        $this->assertDatabaseHas('suchak_biodata_intake_links', [
            'id' => $sourceLink->id,
            'matrimony_profile_id' => $profile->id,
            'source_status' => SuchakBiodataIntakeLink::STATUS_CREATED_NEW_PROFILE,
        ]);

        $this->assertDatabaseHas('suchak_profile_representations', [
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_mode' => SuchakProfileRepresentation::MODE_UPLOADED_BY_SUCHAK,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('suchak_customer_contexts', [
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'source_link_id' => $sourceLink->id,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_INTAKE_UPLOAD,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_CANDIDATE_IDENTIFIED,
        ]);
    }

    private function seedMasterGender(string $key, string $label): int
    {
        $existing = DB::table('master_genders')->where('key', $key)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('master_genders')->insertGetId($this->timestamped([
            'key' => $key,
            'label' => $label,
            'is_active' => true,
        ]));
    }

    private function seedMasterAddressType(string $key, string $label): int
    {
        $existing = DB::table('master_address_types')->where('key', $key)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('master_address_types')->insertGetId($this->timestamped([
            'key' => $key,
            'label' => $label,
            'is_active' => true,
        ]));
    }

    private function seedLocation(string $name): int
    {
        $countryId = (int) DB::table('addresses')->insertGetId([
            'name' => 'India',
            'slug' => 'india-suchak-test',
            'hierarchy' => 'country',
            'level' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stateId = (int) DB::table('addresses')->insertGetId([
            'name' => 'Maharashtra',
            'slug' => 'maharashtra-suchak-test',
            'hierarchy' => 'state',
            'parent_id' => $countryId,
            'level' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $districtId = (int) DB::table('addresses')->insertGetId([
            'name' => $name.' District',
            'slug' => strtolower($name).'-district-test',
            'hierarchy' => 'district',
            'parent_id' => $stateId,
            'level' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $talukaId = (int) DB::table('addresses')->insertGetId([
            'name' => $name.' Taluka',
            'slug' => strtolower($name).'-taluka-test',
            'hierarchy' => 'taluka',
            'parent_id' => $districtId,
            'level' => 3,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('addresses')->insertGetId([
            'name' => $name,
            'slug' => strtolower($name).'-test',
            'hierarchy' => 'village',
            'tag' => 'city',
            'parent_id' => $talukaId,
            'level' => 4,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function timestamped(array $attributes): array
    {
        return array_merge($attributes, [
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
