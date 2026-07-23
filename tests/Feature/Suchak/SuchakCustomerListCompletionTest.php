<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
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
}
