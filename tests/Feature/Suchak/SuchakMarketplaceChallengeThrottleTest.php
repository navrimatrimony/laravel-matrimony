<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * U6: challenge publish + proposal routes are rate-limited (throttle:10,1).
 */
class SuchakMarketplaceChallengeThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_u6_publish_allows_tenth_and_rejects_eleventh_in_a_minute(): void
    {
        Sanctum::actingAs($this->operableSuchakUser());

        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/v1/suchak/marketplace/challenges', []);
            $this->assertNotSame(429, $response->status(), "publish attempt {$i} was throttled too early");
        }

        $this->postJson('/api/v1/suchak/marketplace/challenges', [])
            ->assertStatus(429);
    }

    public function test_u6_propose_allows_tenth_and_rejects_eleventh_in_a_minute(): void
    {
        Sanctum::actingAs($this->operableSuchakUser());

        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/v1/suchak/marketplace/challenges/1/proposals', []);
            $this->assertNotSame(429, $response->status(), "propose attempt {$i} was throttled too early");
        }

        $this->postJson('/api/v1/suchak/marketplace/challenges/1/proposals', [])
            ->assertStatus(429);
    }

    private function operableSuchakUser(): User
    {
        $user = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        return $user;
    }
}
