<?php

namespace Tests\Unit;

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\FeatureUsageService;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureUsageServiceDashboardMediatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_mediator_row_is_locked_when_contact_reveal_cap_is_zero(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $user = User::factory()->create(['is_admin' => false]);
        MatrimonyProfile::factory()->for($user)->create(['lifecycle_state' => 'active']);

        $summary = app(FeatureUsageService::class)->getDashboardUsageSummary($user->fresh());
        $this->assertIsArray($summary);
        $mediator = collect($summary['rows'] ?? [])->firstWhere('key', 'mediator_requests');
        $this->assertNotNull($mediator);
        $this->assertTrue($mediator['locked'], 'Mediator must show as unavailable when contact reveal cap is 0 (matches profile UI).');
    }
}
