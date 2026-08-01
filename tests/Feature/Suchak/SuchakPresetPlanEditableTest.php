<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCustomerPlanService;
use App\Modules\Suchak\Support\SuchakDefaultPlans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A Suchak could not edit a ready-made plan, because there was nothing to edit:
 * SuchakDefaultPlans was a final class with two hardcoded prices, and the only
 * row that could exist beside it accepted price / name / visibility / order and
 * refused everything else. The four fee columns a preset entry READ were
 * therefore structurally always null — four dead reads.
 *
 * SuchakDefaultPlans is now SEED CONTENT. It is read once, to create the
 * Suchak's own row, and the row is then a plan they own in full.
 *
 * What is NOT relaxed and is pinned here too: the row keeps its preset_key, it
 * stays undeletable, and the freeze is untouched — the plan is the DEFAULT, the
 * send is the DECISION, the package is the FROZEN RECORD.
 */
class SuchakPresetPlanEditableTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ the seeding

    public function test_reading_a_suchaks_plans_seeds_the_ready_made_ones_as_rows_they_own(): void
    {
        $account = $this->suchakAccount();

        // Nothing exists until someone asks — the seeding is lazy, so an account
        // that predates this change is served by the same one code path.
        $this->assertSame(0, SuchakCustomerPlan::query()->where('suchak_account_id', $account->id)->count());

        app(SuchakCustomerPlanService::class)->resolveForManagement($account);

        $rows = SuchakCustomerPlan::query()
            ->where('suchak_account_id', $account->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(
            [SuchakDefaultPlans::KEY_BASIC, SuchakDefaultPlans::KEY_PREMIUM],
            $rows->pluck('preset_key')->all(),
        );

        // The row carries the seed content, so the card reads the same as before.
        $basic = $rows->firstWhere('preset_key', SuchakDefaultPlans::KEY_BASIC);
        $this->assertSame('Basic matchmaking', $basic->name);
        $this->assertSame('बेसिक जुळवणी', $basic->name_mr);
        $this->assertSame('2000.00', $basic->price_amount);
        $this->assertSame('INR', $basic->currency);
        $this->assertCount(3, $basic->services_json);

        // ...and NOT a duration or a fee. A ready-made plan fixes none until the
        // Suchak fixes one; inventing one here would be a charge nobody agreed to.
        $this->assertNull($basic->duration);
        $this->assertNull($basic->per_meeting_fee_amount);
        $this->assertNull($basic->per_meeting_online_fee_amount);
        $this->assertNull($basic->post_marriage_fee_mode);
        $this->assertNull($basic->post_marriage_fee_amount);
    }

    public function test_seeding_is_idempotent_and_never_duplicates_or_resets_a_row(): void
    {
        $account = $this->suchakAccount();
        $service = app(SuchakCustomerPlanService::class);

        $service->ensurePresetRows($account);
        $service->ensurePresetRows($account);
        $service->resolveCarousel($account);
        $service->resolveForManagement($account);

        $this->assertSame(2, SuchakCustomerPlan::query()->where('suchak_account_id', $account->id)->count());

        // An edited row survives every later read untouched — the seeder only
        // ever inserts what is MISSING.
        $service->updatePreset($account, SuchakDefaultPlans::KEY_BASIC, [
            'name' => 'माझी बेसिक योजना',
            'price_amount' => '3200',
        ]);

        $service->resolveCarousel($account);
        $service->ensurePresetRows($account);

        $basic = SuchakCustomerPlan::query()
            ->where('suchak_account_id', $account->id)
            ->where('preset_key', SuchakDefaultPlans::KEY_BASIC)
            ->firstOrFail();

        $this->assertSame('माझी बेसिक योजना', $basic->name);
        $this->assertSame('3200.00', $basic->price_amount);
        $this->assertSame(2, SuchakCustomerPlan::query()->where('suchak_account_id', $account->id)->count());
    }

    // ------------------------------------------------- the four dead reads now live

    public function test_the_four_fee_fields_can_be_written_onto_a_ready_made_plan_and_read_back(): void
    {
        [$user, $account] = $this->actor();
        Sanctum::actingAs($user);

        // Exactly what PlanEditorScreen posts, now that a preset card opens it.
        $response = $this->putJson('/api/v1/suchak/customer-plans/'.SuchakDefaultPlans::KEY_BASIC, [
            'per_meeting_fee_amount' => '750',
            'per_meeting_online_fee_amount' => '1200',
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
            'post_marriage_fee_amount' => '21000',
        ]);
        $response->assertOk();

        // Written — the columns nothing could ever write to before.
        $row = SuchakCustomerPlan::query()
            ->where('suchak_account_id', $account->id)
            ->where('preset_key', SuchakDefaultPlans::KEY_BASIC)
            ->firstOrFail();

        $this->assertSame('750.00', $row->per_meeting_fee_amount);
        $this->assertSame('1200.00', $row->per_meeting_online_fee_amount);
        $this->assertSame(SuchakCustomerPlan::MODE_FIXED, $row->post_marriage_fee_mode);
        $this->assertSame('21000.00', $row->post_marriage_fee_amount);

        // ...and READ, by both resolvers. These are the four reads that used to
        // return null no matter what.
        foreach (['data.plans', 'data.carousel'] as $path) {
            $entry = collect($response->json($path))->firstWhere('preset_key', SuchakDefaultPlans::KEY_BASIC);
            $this->assertNotNull($entry, $path.' must still carry the ready-made plan');
            $this->assertSame('750.00', $entry['per_meeting_fee_amount'], $path);
            $this->assertSame('1200.00', $entry['per_meeting_online_fee_amount'], $path);
            $this->assertSame(SuchakCustomerPlan::MODE_FIXED, $entry['post_marriage_fee_mode'], $path);
            $this->assertSame('21000.00', $entry['post_marriage_fee_amount'], $path);
        }
    }

    public function test_the_rest_of_the_field_set_stops_being_refused_too(): void
    {
        [$user, $account] = $this->actor();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/suchak/customer-plans/'.SuchakDefaultPlans::KEY_PREMIUM, [
            'name' => 'Premium plus',
            'name_mr' => 'प्रीमियम प्लस',
            'price_amount' => '6500',
            'original_price_amount' => '8000',
            'currency' => 'INR',
            'duration' => SuchakCustomerPlan::DURATION_TILL_MARRIAGE,
            'services' => [
                ['name' => 'Dedicated counselor', 'name_mr' => 'समर्पित सल्लागार'],
                ['name' => 'Weekly follow-up'],
            ],
            'include_basic' => false,
            'private_note' => 'my own note',
        ])->assertOk();

        $row = SuchakCustomerPlan::query()
            ->where('suchak_account_id', $account->id)
            ->where('preset_key', SuchakDefaultPlans::KEY_PREMIUM)
            ->firstOrFail();

        $this->assertSame('Premium plus', $row->name);
        $this->assertSame('प्रीमियम प्लस', $row->name_mr);
        $this->assertSame('6500.00', $row->price_amount);
        $this->assertSame('8000.00', $row->original_price_amount);
        $this->assertSame(SuchakCustomerPlan::DURATION_TILL_MARRIAGE, $row->duration);
        $this->assertSame('my own note', $row->private_note);
        $this->assertSame(
            ['Dedicated counselor', 'Weekly follow-up'],
            array_column($row->services_json, 'name'),
        );

        // The identity survives every one of those edits.
        $this->assertSame(SuchakDefaultPlans::KEY_PREMIUM, $row->preset_key);
        $this->assertTrue($row->isPresetOverride());

        // The management list reflects the edited row, private note included.
        $entry = collect($this->getJson('/api/v1/suchak/customer-plans')->json('data.plans'))
            ->firstWhere('preset_key', SuchakDefaultPlans::KEY_PREMIUM);
        $this->assertSame('Premium plus', $entry['name']);
        $this->assertSame('my own note', $entry['private_note']);
        $this->assertSame(SuchakCustomerPlan::DURATION_TILL_MARRIAGE, $entry['duration']);

        // ...but the customer-facing carousel still never leaks the note.
        $carouselEntry = collect($this->getJson('/api/v1/suchak/customer-plans')->json('data.carousel'))
            ->firstWhere('preset_key', SuchakDefaultPlans::KEY_PREMIUM);
        $this->assertArrayNotHasKey('private_note', $carouselEntry);
    }

    // ----------------------------------------------------- what stays refused

    public function test_a_ready_made_plan_still_cannot_be_deleted_only_hidden(): void
    {
        [$user, $account] = $this->actor();
        Sanctum::actingAs($user);

        // A visible custom plan so the last-visible guard is not what refuses.
        $this->postJson('/api/v1/suchak/customer-plans', [
            'name' => 'Keeper',
            'price_amount' => '4000',
            'duration' => SuchakCustomerPlan::DURATION_ONE_YEAR,
            'services' => [['name' => 'Service X']],
        ])->assertCreated();

        $row = SuchakCustomerPlan::query()
            ->where('suchak_account_id', $account->id)
            ->where('preset_key', SuchakDefaultPlans::KEY_BASIC)
            ->firstOrFail();

        // Refused by KEY...
        $this->deleteJson('/api/v1/suchak/customer-plans/'.SuchakDefaultPlans::KEY_BASIC)
            ->assertStatus(422);

        // ...and by the row id it now certainly has, which is the new way to ask.
        $this->deleteJson('/api/v1/suchak/customer-plans/'.$row->id)->assertStatus(422);
        $this->assertDatabaseHas('suchak_customer_plans', ['id' => $row->id]);

        // Hiding is the supported route, and it still works.
        $this->putJson('/api/v1/suchak/customer-plans/'.SuchakDefaultPlans::KEY_BASIC, ['is_visible' => false])
            ->assertOk();
        $this->assertFalse((bool) $row->fresh()->is_visible);
    }

    public function test_the_last_visible_plan_guard_still_covers_a_ready_made_plan(): void
    {
        $account = $this->suchakAccount();
        $service = app(SuchakCustomerPlanService::class);

        $service->updatePreset($account, SuchakDefaultPlans::KEY_PREMIUM, ['is_visible' => false]);

        $this->expectException(InvalidArgumentException::class);
        $service->updatePreset($account, SuchakDefaultPlans::KEY_BASIC, ['is_visible' => false]);
    }

    // -------------------------------------------------- the wire and the send

    public function test_the_send_screen_payload_carries_a_ready_made_plans_configured_fees(): void
    {
        $rep = $this->representedCandidate();

        app(SuchakCustomerPlanService::class)->updatePreset(
            $rep->suchakAccount,
            SuchakDefaultPlans::KEY_BASIC,
            [
                'duration' => SuchakCustomerPlan::DURATION_SIX_MONTHS,
                'per_meeting_fee_amount' => '600',
                'per_meeting_online_fee_amount' => '900',
                'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_AS_WISHED,
            ],
        );

        $plans = $this->getJson("/api/v1/suchak/customers/{$rep->id}/payment-request-options")
            ->assertOk()
            ->json('data.default_plans');

        $basic = collect($plans)->firstWhere('plan_key', SuchakDefaultPlans::KEY_BASIC);
        $this->assertNotNull($basic);
        $this->assertSame(SuchakCustomerPlan::DURATION_SIX_MONTHS, $basic['duration']);
        $this->assertSame('600.00', $basic['per_meeting_fee_amount']);
        $this->assertSame('900.00', $basic['per_meeting_online_fee_amount']);
        $this->assertSame(SuchakCustomerPlan::MODE_AS_WISHED, $basic['post_marriage_fee_mode']);
    }

    public function test_a_preset_send_freezes_the_fees_the_suchak_configured_on_the_plan(): void
    {
        $rep = $this->representedCandidate();

        app(SuchakCustomerPlanService::class)->updatePreset(
            $rep->suchakAccount,
            SuchakDefaultPlans::KEY_BASIC,
            [
                'price_amount' => '2800',
                'per_meeting_fee_amount' => '600',
                'per_meeting_online_fee_amount' => '900',
                'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
                'post_marriage_fee_amount' => '30000',
            ],
        );

        // The send quotes nothing of its own, so the plan's defaults stand — the
        // whole point of making the four reads live.
        $response = $this->postJson("/api/v1/suchak/customers/{$rep->id}/payment-setup", [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
        ]);
        $response->assertCreated();

        $package = SuchakServicePackage::query()->findOrFail($response->json('data.service_package_id'));

        $this->assertSame('2800.00', $package->price_amount);
        $this->assertSame('600.00', $package->per_meeting_fee_amount);
        $this->assertSame('900.00', $package->per_meeting_online_fee_amount);
        $this->assertSame(SuchakCustomerPlan::MODE_FIXED, $package->post_marriage_fee_mode);
        $this->assertSame('30000.00', $package->post_marriage_fee_amount);

        // The freeze is untouched: the package is still scoped by the preset's
        // CODE name, so re-sending Basic finds this same package rather than a
        // second one — which is what keeps preset_key load-bearing.
        $this->assertSame('Basic matchmaking', $package->package_name);
    }

    // ------------------------------------------------------------------ setup

    private function suchakAccount(): SuchakAccount
    {
        return $this->actor()[1];
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function actor(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        return [$user, $account];
    }

    private function representedCandidate(): SuchakProfileRepresentation
    {
        [$user, $account] = $this->actor();

        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Preset Plan Candidate',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        $rep = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($user);

        return $rep;
    }
}
