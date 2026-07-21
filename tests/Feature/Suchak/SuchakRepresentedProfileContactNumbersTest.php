<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contact numbers on represented-candidate sub-records.
 *
 * Approved product decision 2026-07-21: sibling/relative/marriage/address/
 * alliance contact numbers are editable. They were previously 'prohibited' in
 * the shared mobile profile validator, which blocked Suchaks from entering
 * contact details that are present on the physical biodata they work from.
 * Privacy is enforced by authorization scoping (a Suchak only reaches
 * representations they own), NOT by blocking the field.
 */
class SuchakRepresentedProfileContactNumbersTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_can_save_contact_numbers_on_sub_records(): void
    {
        [$representationId, $genderId] = $this->createRepresentation('9876505601', '9876505602');

        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'full_name' => 'Contact Number Candidate',
            'gender_id' => $genderId,
            'siblings' => [
                [
                    'relation_type' => 'brother',
                    'name' => 'Rohan Patil',
                    'marital_status' => 'unmarried',
                    'contact_number' => '9822012345',
                    'sort_order' => 1,
                ],
            ],
            'relatives' => [
                [
                    'relation_type' => 'paternal_uncle',
                    'relative_details' => 'Uncle in Nagpur',
                    'contact_number' => '9822054321',
                ],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $get = $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")->assertOk();

        $siblingNumbers = collect($get->json('profile.siblings') ?? [])
            ->pluck('contact_number')
            ->filter()
            ->values()
            ->all();
        $this->assertContains(
            '9822012345',
            $siblingNumbers,
            'Sibling contact_number must round-trip through save and reload.'
        );

        $relativeNumbers = collect($get->json('profile.relatives') ?? [])
            ->pluck('contact_number')
            ->filter()
            ->values()
            ->all();
        $this->assertContains(
            '9822054321',
            $relativeNumbers,
            'Relative contact_number must round-trip through save and reload.'
        );
    }

    public function test_malformed_contact_number_is_rejected(): void
    {
        [$representationId, $genderId] = $this->createRepresentation('9876505603', '9876505604');

        // Removing 'prohibited' must not mean "accept anything" — the shared
        // phone-format rule still applies.
        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'full_name' => 'Bad Contact Candidate',
            'gender_id' => $genderId,
            'siblings' => [
                [
                    'relation_type' => 'brother',
                    'name' => 'Rohan Patil',
                    'contact_number' => 'not-a-phone-number!!',
                    'sort_order' => 1,
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['siblings.0.contact_number']);
    }

    public function test_other_suchak_cannot_read_contact_numbers_of_foreign_representation(): void
    {
        [$representationId, $genderId] = $this->createRepresentation('9876505605', '9876505606');

        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'full_name' => 'Private Contact Candidate',
            'gender_id' => $genderId,
            'siblings' => [
                [
                    'relation_type' => 'brother',
                    'name' => 'Rohan Patil',
                    'contact_number' => '9822099999',
                    'sort_order' => 1,
                ],
            ],
        ])->assertOk();

        // Privacy is enforced by scoping, not by field blocking — prove it.
        $intruder = User::factory()->create([
            'mobile' => '9876505607',
            'mobile_verified_at' => now(),
        ]);
        SuchakAccount::factory()->create([
            'user_id' => $intruder->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/suchak/nxt/{$representationId}/profile")->assertNotFound();
    }

    /**
     * @return array{0: int, 1: int} representation id, gender id
     */
    private function createRepresentation(string $suchakMobile, string $candidateMobile): array
    {
        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );
        $genderId = $this->gender('female');

        $suchakUser = User::factory()->create([
            'mobile' => $suchakMobile,
            'mobile_verified_at' => now(),
        ]);
        SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'suchak_name' => 'Contact Number Suchak',
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        Sanctum::actingAs($suchakUser);

        $create = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Contact Number Candidate',
            'candidate_mobile' => $candidateMobile,
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        return [(int) $create->json('data.representation_id'), $genderId];
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
