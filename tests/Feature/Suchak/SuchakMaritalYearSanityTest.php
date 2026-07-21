<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Support\MaritalDependencyRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Marital year sanity on the API surface (PO decision 2026-07-22).
 *
 * These rules existed ONLY in ProfileWizardController (web) — the mobile PUT,
 * which the Suchak native editor delegates to, silently accepted a divorce
 * year before the marriage year. Rules now live in the canonical
 * App\Support\MaritalDependencyRules consumed by both surfaces.
 */
class SuchakMaritalYearSanityTest extends TestCase
{
    use RefreshDatabase;

    public function test_rules_class_flags_impossible_year_combinations(): void
    {
        $errors = MaritalDependencyRules::yearSanityErrors([
            'marriage_year' => 2015,
            'divorce_year' => 2010,
        ]);
        $this->assertArrayHasKey('marriages.0.divorce_year', $errors);

        $future = MaritalDependencyRules::yearSanityErrors([
            'marriage_year' => (int) date('Y') + 1,
        ]);
        $this->assertArrayHasKey('marriages.0.marriage_year', $future);

        $this->assertSame([], MaritalDependencyRules::yearSanityErrors([
            'marriage_year' => 2015,
            'divorce_year' => 2018,
        ]));
    }

    public function test_status_dependency_helpers_match_canonical_list(): void
    {
        $this->assertTrue(MaritalDependencyRules::requiresMarriageDetails('divorced'));
        $this->assertTrue(MaritalDependencyRules::requiresMarriageDetails('widowed'));
        $this->assertFalse(MaritalDependencyRules::requiresMarriageDetails('never_married'));
        $this->assertTrue(MaritalDependencyRules::isNeverMarried('never_married'));

        $this->assertTrue(MaritalDependencyRules::allowsYearField('spouse_death_year', 'widowed'));
        $this->assertFalse(MaritalDependencyRules::allowsYearField('spouse_death_year', 'divorced'));
        $this->assertTrue(MaritalDependencyRules::allowsYearField('divorce_year', 'annulled'));
        $this->assertFalse(MaritalDependencyRules::allowsYearField('divorce_year', 'widowed'));
    }

    public function test_divorce_before_marriage_rejected_on_suchak_put(): void
    {
        [$representationId, $genderId, $divorcedId] = $this->createRepresentation('9876506001', '9876506002');
        if ($divorcedId === null) {
            $this->markTestSkipped('Marital status lookups not seeded.');
        }

        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'full_name' => 'Year Sanity Candidate',
            'gender_id' => $genderId,
            'marital_status_id' => $divorcedId,
            'marriages' => [
                ['marriage_year' => 2015, 'divorce_year' => 2010],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['marriages.0.divorce_year']);
    }

    public function test_valid_year_order_accepted_on_suchak_put(): void
    {
        [$representationId, $genderId, $divorcedId] = $this->createRepresentation('9876506003', '9876506004');
        if ($divorcedId === null) {
            $this->markTestSkipped('Marital status lookups not seeded.');
        }

        $this->putJson("/api/v1/suchak/nxt/{$representationId}/profile", [
            'full_name' => 'Valid Year Candidate',
            'gender_id' => $genderId,
            'marital_status_id' => $divorcedId,
            'marriages' => [
                ['marriage_year' => 2015, 'divorce_year' => 2018],
            ],
        ])->assertOk();
    }

    /**
     * @return array{0: int, 1: int, 2: int|null}
     */
    private function createRepresentation(string $suchakMobile, string $candidateMobile): array
    {
        MasterGender::query()->firstOrCreate(['key' => 'female'], ['label' => 'Female', 'is_active' => true]);
        $genderId = (int) DB::table('master_genders')->where('key', 'female')->value('id');

        $divorced = MasterMaritalStatus::query()->firstOrCreate(
            ['key' => 'divorced'],
            ['label' => 'Divorced', 'is_active' => true],
        );

        $user = User::factory()->create(['mobile' => $suchakMobile, 'mobile_verified_at' => now()]);
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/suchak/manual-profiles', [
            'candidate_name' => 'Year Sanity Candidate',
            'candidate_mobile' => $candidateMobile,
            'candidate_gender' => 'female',
            'registering_for' => 'self',
        ])->assertCreated();

        return [
            (int) $create->json('data.representation_id'),
            $genderId,
            $divorced->id !== null ? (int) $divorced->id : null,
        ];
    }
}
