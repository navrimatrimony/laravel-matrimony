<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCustomerListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A Suchak who abandons a profile halfway must be able to see that in the
 * customer list and resume onboarding from where they stopped — which needs the
 * list itself to carry completion, not just the detail endpoint.
 */
class SuchakCustomerListCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_list_reports_completion_and_the_sections_still_missing(): void
    {
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'registration_completed_at' => now(),
        ]);

        $profile = MatrimonyProfile::factory()->create();
        SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
        ]);

        Sanctum::actingAs($suchakUser);

        $response = $this->getJson('/api/v1/suchak/customers')->assertOk();

        $row = collect($response->json('data.customers'))
            ->firstWhere('profile_id', $profile->id);

        $this->assertNotNull($row, 'The represented profile should appear in the list.');
        $this->assertArrayHasKey('completion_percent', $row);
        $this->assertArrayHasKey('incomplete_sections', $row);
        $this->assertIsInt($row['completion_percent']);
        $this->assertIsArray($row['incomplete_sections']);
        // A freshly created profile cannot be 100% done, so the app has
        // something to resume from.
        $this->assertLessThan(100, $row['completion_percent']);
        $this->assertNotEmpty($row['incomplete_sections']);
    }

    /**
     * Most completion sections carry weight 0 and are normally empty for a
     * perfectly good candidate. If those counted, every profile would read
     * "incomplete" forever — noise instead of a signal — so only the sections
     * the onboarding wizard actually collects are reported.
     */
    public function test_optional_empty_sections_are_not_reported_as_unfinished_onboarding(): void
    {
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'registration_completed_at' => now(),
        ]);

        $profile = MatrimonyProfile::factory()->create();
        SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
        ]);

        Sanctum::actingAs($suchakUser);

        $row = collect($this->getJson('/api/v1/suchak/customers')->assertOk()->json('data.customers'))
            ->firstWhere('profile_id', $profile->id);

        $noise = ['siblings', 'relatives', 'alliance', 'property', 'horoscope', 'about-me', 'family-details'];
        foreach ($noise as $section) {
            $this->assertNotContains(
                $section,
                $row['incomplete_sections'],
                "Optional section [$section] must not mark onboarding unfinished."
            );
        }

        // Everything reported must be a section the wizard can actually fill.
        foreach ($row['incomplete_sections'] as $section) {
            $this->assertContains($section, SuchakCustomerListService::ONBOARDING_SECTIONS);
        }
    }

    /**
     * The defect the PO hit (2026-07-26): the row said "100% पूर्ण" while also
     * reporting unfinished sections, because the percentage was scored on a
     * different section set than the one that decides "incomplete". `physical`
     * is the exact case — it decided incompleteness but carried zero weight in
     * the old percentage, so a profile missing only height read 100%.
     */
    public function test_a_profile_missing_only_physical_is_not_reported_as_one_hundred_percent(): void
    {
        $profile = $this->fullyOnboardedProfile();
        $profile->forceFill(['height_cm' => null])->save();

        $row = $this->listRowFor($profile);

        $this->assertSame(
            ['physical'],
            $row['incomplete_sections'],
            'Only the physical section should be missing in this fixture.'
        );
        $this->assertLessThan(
            100,
            $row['completion_percent'],
            'A profile with an unfinished section must never read 100%.'
        );
    }

    public function test_a_profile_with_no_incomplete_sections_reports_exactly_one_hundred_percent(): void
    {
        $row = $this->listRowFor($this->fullyOnboardedProfile());

        $this->assertSame([], $row['incomplete_sections']);
        $this->assertSame(100, $row['completion_percent']);
    }

    /**
     * The contract in one assertion, checked across every state a real list
     * shows: `completion_percent === 100` and `incomplete_sections === []` are
     * the same fact. Neither number is allowed to move without the other.
     */
    public function test_the_percentage_and_the_section_list_can_never_disagree(): void
    {
        $profile = $this->fullyOnboardedProfile();

        $drops = [
            'nothing missing' => [],
            'physical' => ['height_cm' => null],
            'education-career' => ['highest_education' => null, 'occupation_title' => null],
            'photo' => ['profile_photo' => null],
            'basic-info' => ['marital_status_id' => null],
        ];

        foreach ($drops as $label => $columns) {
            $fresh = $this->fullyOnboardedProfile();
            if ($columns !== []) {
                $fresh->forceFill($columns)->save();
            }

            $row = $this->listRowFor($fresh);

            $this->assertSame(
                $row['incomplete_sections'] === [],
                $row['completion_percent'] === 100,
                "[$label] 100% must mean exactly 'no incomplete sections'. Got "
                    ."{$row['completion_percent']}% with ["
                    .implode(', ', $row['incomplete_sections']).'].'
            );
        }

        // Keeps the fixture honest: the "complete" baseline really is complete,
        // so the loop above is not passing on empty-vs-empty every time.
        $this->assertSame(100, $this->listRowFor($profile)['completion_percent']);
    }

    /**
     * A profile that satisfies every section in
     * SuchakCustomerListService::ONBOARDING_SECTIONS — the baseline each test
     * above knocks a single section out of.
     */
    private function fullyOnboardedProfile(): MatrimonyProfile
    {
        $gender = MasterGender::query()->firstOrCreate(['key' => 'female'], ['label' => 'Female', 'is_active' => true]);
        $marital = MasterMaritalStatus::query()
            ->firstOrCreate(['key' => 'never_married'], ['label' => 'Never married', 'is_active' => true]);

        $profile = MatrimonyProfile::factory()->create();
        $profile->forceFill([
            // basic-info
            'full_name' => 'Complete Candidate',
            'gender_id' => $gender->id,
            'date_of_birth' => now()->subYears(26)->toDateString(),
            'marital_status_id' => $marital->id,
            // physical
            'height_cm' => 160,
            // education-career
            'highest_education' => 'B.Com',
            'occupation_title' => 'Accountant',
            // photo
            'profile_photo' => 'profiles/complete.jpg',
        ])->save();

        // about-preferences
        DB::table('profile_preference_criteria')->updateOrInsert(
            ['profile_id' => $profile->id],
            ['preferred_age_min' => 24, 'preferred_age_max' => 32, 'created_at' => now(), 'updated_at' => now()],
        );

        return $profile->refresh();
    }

    /**
     * The row as the app receives it — asserted through the real endpoint, not
     * the service, so the transport layer cannot reintroduce a second number.
     *
     * @return array<string, mixed>
     */
    private function listRowFor(MatrimonyProfile $profile): array
    {
        $suchakUser = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'registration_completed_at' => now(),
        ]);
        SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
        ]);

        Sanctum::actingAs($suchakUser);

        $row = collect($this->getJson('/api/v1/suchak/customers')->assertOk()->json('data.customers'))
            ->firstWhere('profile_id', $profile->id);

        $this->assertNotNull($row, 'The represented profile should appear in the list.');

        return $row;
    }
}
