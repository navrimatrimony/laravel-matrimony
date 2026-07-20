<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Suchak native full edit: GET/PUT /suchak/nxt/{representation}/profile
 * reuses MatrimonyProfileApiController snapshot/governance payload.
 */
class SuchakRepresentedProfileFullEditApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_can_get_and_put_full_represented_profile(): void
    {
        $genderId = $this->gender('female');
        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );

        $suchakUser = User::factory()->create([
            'mobile' => '9876505501',
            'mobile_verified_at' => now(),
        ]);
        SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'suchak_name' => 'Full Edit Suchak',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        Sanctum::actingAs($suchakUser);

        $create = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Ananya Patil',
            'candidate_mobile' => '9876505599',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        $representationId = (int) $create->json('data.representation_id');
        $profileId = (int) $create->json('data.profile_id');

        $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.representation_id', $representationId)
            ->assertJsonPath('data.profile_id', $profileId)
            ->assertJsonStructure(['profile' => ['id', 'full_name']]);

        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'full_name' => 'Ananya Patil Updated',
            'gender_id' => $genderId,
            'date_of_birth' => '1997-03-12',
            'height_cm' => 158,
            'weight_kg' => 55,
            'narrative_about_me' => 'Suchak filled about me for matching.',
            'property_details' => 'Own flat in Pune.',
            'siblings' => [
                [
                    'relation_type' => 'brother',
                    'name' => 'Rohan Patil',
                    'marital_status' => 'unmarried',
                    'occupation' => 'Engineer',
                    'sort_order' => 1,
                ],
            ],
            'relatives' => [
                [
                    'relation_type' => 'paternal_uncle',
                    'relative_details' => 'Uncle in Nagpur',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('profile.full_name', 'Ananya Patil Updated');

        $profile = MatrimonyProfile::query()->findOrFail($profileId);
        $this->assertSame('Ananya Patil Updated', $profile->full_name);
        $this->assertSame(158, (int) $profile->height_cm);
        $this->assertSame(55, (int) $profile->weight_kg);
        $this->assertNotEmpty((string) ($profile->property_details ?? ''));

        $get = $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")
            ->assertOk();
        $siblings = $get->json('profile.siblings');
        $this->assertIsArray($siblings);
        $this->assertNotEmpty($siblings);
    }

    public function test_other_suchak_cannot_edit_foreign_representation(): void
    {
        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );

        $owner = User::factory()->create(['mobile' => '9876505511', 'mobile_verified_at' => now()]);
        SuchakAccount::factory()->create([
            'user_id' => $owner->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($owner);
        $create = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Owned Candidate',
            'candidate_mobile' => '9876505512',
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();
        $representationId = (int) $create->json('data.representation_id');

        $intruder = User::factory()->create(['mobile' => '9876505513', 'mobile_verified_at' => now()]);
        SuchakAccount::factory()->create([
            'user_id' => $intruder->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")->assertNotFound();
        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'full_name' => 'Hacked',
            'gender_id' => $this->gender('female'),
        ])->assertNotFound();
    }

    private function gender(string $key): int
    {
        if (Schema::hasTable('master_genders')) {
            $id = DB::table('master_genders')->where('key', $key)->value('id');
            if ($id) {
                return (int) $id;
            }
            return (int) DB::table('master_genders')->insertGetId([
                'key' => $key,
                'label' => ucfirst($key),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return 1;
    }
}
