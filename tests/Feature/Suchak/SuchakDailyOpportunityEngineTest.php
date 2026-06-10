<?php

namespace Tests\Feature\Suchak;

use App\Models\Caste;
use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SuchakAccount;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPipeline;
use App\Models\SuchakProfileNote;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakDailyOpportunityService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuchakDailyOpportunityEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_daily_worklist_collects_deterministic_due_items_without_private_contact(): void
    {
        $now = now()->setSeconds(0);
        $this->travelTo($now);

        [$religion, $caste] = $this->community();
        $account = $this->suchakAccount();
        $ownProfile = $this->activeProfile([
            'full_name' => 'Private Candidate Day48',
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
        ]);
        $this->activeRepresentation($account, $ownProfile, [
            'consent_valid_until' => $now->copy()->addDays(2),
        ]);

        $privateNoteProfile = $this->activeProfile(['full_name' => 'Private Note Candidate Day48']);
        SuchakProfileNote::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $privateNoteProfile->id,
            'note_type' => SuchakProfileNote::TYPE_FOLLOW_UP,
            'note_text' => 'Call private number 9876543210 before meeting.',
            'follow_up_at' => $now->copy()->subHour(),
        ]);

        SuchakLedgerEntry::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $privateNoteProfile->id,
            'status' => SuchakLedgerEntry::STATUS_DUE,
            'due_date' => $now->copy()->subDay()->toDateString(),
            'note' => 'Collect from 9876543210 by UPI.',
        ]);

        SuchakPipeline::factory()->create([
            'selected_suchak_account_id' => $account->id,
            'pipeline_status' => SuchakPipeline::STATUS_PENDING,
            'lock_expires_at' => $now->copy()->addHours(2),
        ]);

        $otherAccount = $this->suchakAccount();
        $otherProfile = $this->activeProfile([
            'full_name' => 'Outside Private Candidate Day48',
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
        ]);
        $this->activeRepresentation($otherAccount, $otherProfile, [
            'consent_valid_until' => $now->copy()->addYear(),
        ]);

        $items = app(SuchakDailyOpportunityService::class)->dailyWorklist($account, $now);
        $types = $items->pluck('type')->all();

        $this->assertContains('follow_up_due', $types);
        $this->assertContains('consent_expiring', $types);
        $this->assertContains('pdf_missing', $types);
        $this->assertContains('sla_risk', $types);
        $this->assertContains('payment_due', $types);
        $this->assertContains('collaboration_opportunity', $types);

        foreach ($items as $item) {
            $this->assertNotEmpty($item['reason']);
            $this->assertArrayNotHasKey('score', $item);
            $this->assertArrayNotHasKey('ai_score', $item);

            if ($item['candidate_reference'] !== null) {
                $this->assertStringStartsWith('masked-', $item['candidate_reference']);
            }
        }

        $encodedItems = json_encode($items->all(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('9876543210', $encodedItems);
        $this->assertStringNotContainsString('Private Candidate Day48', $encodedItems);
        $this->assertStringNotContainsString('Outside Private Candidate Day48', $encodedItems);
        $this->assertStringNotContainsString('Collect from', $encodedItems);
    }

    public function test_suchak_dashboard_renders_daily_worklist_without_leaking_private_contact(): void
    {
        $now = now()->setSeconds(0);
        $this->travelTo($now);

        [$religion, $caste] = $this->community();
        $user = User::factory()->create();
        $account = $this->suchakAccount(['user_id' => $user->id]);
        $profile = $this->activeProfile([
            'full_name' => 'Dashboard Private Candidate Day48',
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
        ]);
        $this->activeRepresentation($account, $profile, [
            'consent_valid_until' => $now->copy()->addDays(2),
        ]);

        $noteProfile = $this->activeProfile(['full_name' => 'Dashboard Note Candidate Day48']);
        SuchakProfileNote::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $noteProfile->id,
            'note_type' => SuchakProfileNote::TYPE_FOLLOW_UP,
            'note_text' => 'Dashboard private 9876543210 contact.',
            'follow_up_at' => $now->copy()->subMinutes(30),
        ]);

        $this->actingAs($user)
            ->get(route('suchak.dashboard'))
            ->assertOk()
            ->assertSee('Daily Opportunities', false)
            ->assertSee('Follow-up due', false)
            ->assertSee('Consent expiring', false)
            ->assertSee('PDF missing', false)
            ->assertSee('masked-', false)
            ->assertDontSee('9876543210', false)
            ->assertDontSee('Dashboard Private Candidate Day48', false)
            ->assertDontSee('Dashboard private', false);
    }

    /**
     * @return array{0: Religion, 1: Caste}
     */
    private function community(): array
    {
        $religion = Religion::query()->create([
            'key' => 'day48_religion_'.Religion::query()->count(),
            'label' => 'Day48 Religion',
            'label_en' => 'Day48 Religion',
            'is_active' => true,
        ]);
        $caste = Caste::query()->create([
            'religion_id' => $religion->id,
            'key' => 'day48_caste_'.Caste::query()->count(),
            'label' => 'Day48 Caste',
            'label_en' => 'Day48 Caste',
            'is_active' => true,
        ]);

        return [$religion, $caste];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function suchakAccount(array $attributes = []): SuchakAccount
    {
        return SuchakAccount::factory()->create(array_merge([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes = []): MatrimonyProfile
    {
        $city = City::query()->where('name', 'Pune City')->firstOrFail();
        $profile = MatrimonyProfile::factory()->create(array_merge([
            'date_of_birth' => now()->subYears(29)->toDateString(),
            'highest_education' => 'Graduate',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes));

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $city->id]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, (int) $city->id, null, true, false);
        }

        $profile->forceFill([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ])->save();

        return $profile->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeRepresentation(
        SuchakAccount $account,
        MatrimonyProfile $profile,
        array $attributes = [],
    ): SuchakProfileRepresentation {
        return SuchakProfileRepresentation::factory()->create(array_merge([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ], $attributes));
    }
}
