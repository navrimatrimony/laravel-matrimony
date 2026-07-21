<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Minimum marriage age enforcement (PO decision 2026-07-22): female 18 /
 * male 21, applied server-side through the shared MarriageAgePolicy on both
 * Suchak write surfaces — the step engine (save-step) and the full PUT.
 * Before this, every surface accepted any past or even future DOB.
 */
class SuchakMarriageAgePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_underage_male_rejected_on_save_step(): void
    {
        [$representationId] = $this->createRepresentation('9876505801', '9876505802');
        $maleId = $this->gender('male');

        $this->postJson("/api/v1/suchak/nxt/{$representationId}/profile/save-step", [
            'step' => 'basic_info',
            'data' => [
                'full_name' => 'Young Male Candidate',
                'gender_id' => $maleId,
                'date_of_birth' => now()->subYears(20)->format('Y-m-d'), // 20 < 21
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['date_of_birth']);
    }

    public function test_adult_male_accepted_on_save_step(): void
    {
        [$representationId] = $this->createRepresentation('9876505803', '9876505804');
        $maleId = $this->gender('male');

        $this->postJson("/api/v1/suchak/nxt/{$representationId}/profile/save-step", [
            'step' => 'basic_info',
            'data' => [
                'full_name' => 'Adult Male Candidate',
                'gender_id' => $maleId,
                'date_of_birth' => now()->subYears(22)->format('Y-m-d'),
            ],
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_female_between_18_and_21_accepted_but_under_18_rejected(): void
    {
        [$representationId, $femaleId] = $this->createRepresentation('9876505805', '9876505806');

        $this->postJson("/api/v1/suchak/nxt/{$representationId}/profile/save-step", [
            'step' => 'basic_info',
            'data' => [
                'full_name' => 'Young Female Candidate',
                'gender_id' => $femaleId,
                'date_of_birth' => now()->subYears(19)->format('Y-m-d'), // 19 >= 18: ok
            ],
        ])->assertOk();

        $this->postJson("/api/v1/suchak/nxt/{$representationId}/profile/save-step", [
            'step' => 'basic_info',
            'data' => [
                'full_name' => 'Underage Female Candidate',
                'gender_id' => $femaleId,
                'date_of_birth' => now()->subYears(17)->format('Y-m-d'), // 17 < 18
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['date_of_birth']);
    }

    public function test_future_dob_rejected_on_full_put(): void
    {
        [$representationId, $femaleId] = $this->createRepresentation('9876505807', '9876505808');

        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'full_name' => 'Future DOB Candidate',
            'gender_id' => $femaleId,
            'date_of_birth' => now()->addYear()->format('Y-m-d'),
        ])->assertStatus(422)->assertJsonValidationErrors(['date_of_birth']);
    }

    public function test_underage_rejected_on_full_put_using_request_gender(): void
    {
        [$representationId] = $this->createRepresentation('9876505809', '9876505810');
        $maleId = $this->gender('male');

        // Candidate was created female; the PUT switches gender to male —
        // the stricter male minimum must apply from the REQUEST gender.
        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'full_name' => 'Switched Gender Candidate',
            'gender_id' => $maleId,
            'date_of_birth' => now()->subYears(19)->format('Y-m-d'), // ok for F, not for M
        ])->assertStatus(422)->assertJsonValidationErrors(['date_of_birth']);
    }

    /**
     * @return array{0: int, 1: int} representation id, female gender id
     */
    private function createRepresentation(string $suchakMobile, string $candidateMobile): array
    {
        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );
        $femaleId = $this->gender('female');

        $user = User::factory()->create([
            'mobile' => $suchakMobile,
            'mobile_verified_at' => now(),
        ]);
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Age Policy Candidate',
            'candidate_mobile' => $candidateMobile,
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        return [(int) $create->json('data.representation_id'), $femaleId];
    }

    private function gender(string $key): int
    {
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
}
