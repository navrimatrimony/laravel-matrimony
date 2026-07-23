<?php

namespace Tests\Feature\Suchak;

use App\Console\Commands\PurgeAbandonedSuchakSignups;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * These guard a destructive command, so they assert what must NEVER happen:
 * a good Suchak being deleted. The point is that safety is structural — no
 * combination of flags can reach a verified or in-use account.
 */
class PurgeAbandonedSuchakSignupsTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $attributes = []): SuchakAccount
    {
        return SuchakAccount::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
            'public_status' => SuchakAccount::PUBLIC_HIDDEN,
            'registration_completed_at' => null,
            'created_at' => now()->subDays(60),
        ], $attributes));
    }

    public function test_dry_run_is_the_default_and_deletes_nothing(): void
    {
        $account = $this->account();

        $this->artisan('suchak:purge-abandoned-signups')->assertSuccessful();

        $this->assertDatabaseHas('suchak_accounts', ['id' => $account->id]);
    }

    public function test_force_deletes_an_abandoned_unused_signup(): void
    {
        $account = $this->account();

        $this->artisan('suchak:purge-abandoned-signups --force')->assertSuccessful();

        $this->assertDatabaseMissing('suchak_accounts', ['id' => $account->id]);
    }

    public function test_a_window_shorter_than_thirty_days_is_refused_outright(): void
    {
        $account = $this->account();

        $this->artisan('suchak:purge-abandoned-signups --days=7 --force')->assertFailed();

        $this->assertDatabaseHas('suchak_accounts', ['id' => $account->id]);
        $this->assertSame(30, PurgeAbandonedSuchakSignups::MINIMUM_AGE_DAYS);
    }

    public function test_a_verified_account_is_never_deleted_however_old(): void
    {
        $verified = $this->account([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'registration_completed_at' => now()->subDays(200),
            'created_at' => now()->subDays(400),
        ]);

        $this->artisan('suchak:purge-abandoned-signups --days=365 --force')->assertSuccessful();

        $this->assertDatabaseHas('suchak_accounts', ['id' => $verified->id]);
    }

    public function test_a_completed_signup_is_never_deleted(): void
    {
        $completed = $this->account(['registration_completed_at' => now()->subDays(50)]);

        $this->artisan('suchak:purge-abandoned-signups --force')->assertSuccessful();

        $this->assertDatabaseHas('suchak_accounts', ['id' => $completed->id]);
    }

    public function test_a_recent_signup_is_never_deleted(): void
    {
        $recent = $this->account(['created_at' => now()->subDays(3)]);

        $this->artisan('suchak:purge-abandoned-signups --force')->assertSuccessful();

        $this->assertDatabaseHas('suchak_accounts', ['id' => $recent->id]);
    }

    /**
     * The core protection: an abandoned signup that nevertheless left a trace
     * anywhere is spared, so no related record is ever orphaned.
     */
    public function test_an_account_with_any_related_record_is_spared(): void
    {
        $withTrace = $this->account();
        $withoutTrace = $this->account();

        SuchakActivityLog::factory()->create([
            'suchak_account_id' => $withTrace->id,
            'occurred_at' => now()->subDays(40),
        ]);

        $this->artisan('suchak:purge-abandoned-signups --force')->assertSuccessful();

        $this->assertDatabaseHas('suchak_accounts', ['id' => $withTrace->id]);
        $this->assertDatabaseMissing('suchak_accounts', ['id' => $withoutTrace->id]);
    }
}
