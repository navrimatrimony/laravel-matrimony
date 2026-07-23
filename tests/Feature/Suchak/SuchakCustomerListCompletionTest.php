<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCustomerListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
