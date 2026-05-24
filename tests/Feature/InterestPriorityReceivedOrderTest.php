<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Interest;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InterestPriorityService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InterestPriorityReceivedOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MinimalLocationSeeder::class);
        $this->seed(MasterLookupSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $factoryAttributes
     */
    private function createActiveProfileWithResidence(User $user, array $factoryAttributes = []): MatrimonyProfile
    {
        $p = MatrimonyProfile::factory()->for($user)->create(array_merge([
            'lifecycle_state' => 'draft',
        ], $factoryAttributes));
        $tbl = $p->getTable();
        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;
        if (Schema::hasColumn($tbl, 'location_id')) {
            DB::table($tbl)->where('id', $p->id)->update(['location_id' => $leafId]);
            $p->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $p->id, $leafId, null, true, false);
        }
        $p->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $p->fresh();
    }

    private function seedGenders(): array
    {
        $male = MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true]
        );
        $female = MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true]
        );

        return [(int) $male->id, (int) $female->id];
    }

    #[Test]
    public function received_inbox_lists_higher_priority_before_lower_even_if_older(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $receiver = User::factory()->create();
        $receiverProfile = $this->createActiveProfileWithResidence($receiver, [
            'gender_id' => $femaleGid,
            'full_name' => 'Receiver Person',
            'visibility_override' => true,
        ]);

        $freeSender = User::factory()->create();
        $freeProfile = $this->createActiveProfileWithResidence($freeSender, [
            'gender_id' => $maleGid,
            'full_name' => 'Free Sender Inbox',
            'visibility_override' => true,
        ]);

        $paidSender = User::factory()->create();
        $paidProfile = $this->createActiveProfileWithResidence($paidSender, [
            'gender_id' => $maleGid,
            'full_name' => 'Paid Sender Inbox',
            'visibility_override' => true,
        ]);

        Interest::query()->create([
            'sender_profile_id' => $freeProfile->id,
            'receiver_profile_id' => $receiverProfile->id,
            'status' => 'pending',
            'priority_score' => Interest::PRIORITY_SCORE_FREE,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        Interest::query()->create([
            'sender_profile_id' => $paidProfile->id,
            'receiver_profile_id' => $receiverProfile->id,
            'status' => 'pending',
            'priority_score' => Interest::PRIORITY_SCORE_PAID,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        $response = $this->actingAs($receiver)->get(route('interests.received'));
        $response->assertOk();

        $html = $response->getContent();
        $posPaid = strpos($html, 'Paid Sender Inbox');
        $posFree = strpos($html, 'Free Sender Inbox');
        $this->assertNotFalse($posPaid);
        $this->assertNotFalse($posFree);
        $this->assertLessThan($posFree, $posPaid);
    }

    #[Test]
    public function same_priority_orders_by_created_at_desc(): void
    {
        [$maleGid, $femaleGid] = $this->seedGenders();

        $receiver = User::factory()->create();
        $receiverProfile = $this->createActiveProfileWithResidence($receiver, [
            'gender_id' => $femaleGid,
            'full_name' => 'Receiver Two',
            'visibility_override' => true,
        ]);

        $a = User::factory()->create();
        $aProfile = $this->createActiveProfileWithResidence($a, [
            'gender_id' => $maleGid,
            'full_name' => 'Sender Older',
            'visibility_override' => true,
        ]);
        $b = User::factory()->create();
        $bProfile = $this->createActiveProfileWithResidence($b, [
            'gender_id' => $maleGid,
            'full_name' => 'Sender Newer',
            'visibility_override' => true,
        ]);

        Interest::query()->create([
            'sender_profile_id' => $aProfile->id,
            'receiver_profile_id' => $receiverProfile->id,
            'status' => 'pending',
            'priority_score' => Interest::PRIORITY_SCORE_FREE,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        Interest::query()->create([
            'sender_profile_id' => $bProfile->id,
            'receiver_profile_id' => $receiverProfile->id,
            'status' => 'pending',
            'priority_score' => Interest::PRIORITY_SCORE_FREE,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($receiver)->get(route('interests.received'));
        $response->assertOk();
        $html = $response->getContent();
        $posNewer = strpos($html, 'Sender Newer');
        $posOlder = strpos($html, 'Sender Older');
        $this->assertNotFalse($posNewer);
        $this->assertNotFalse($posOlder);
        $this->assertLessThan($posNewer, $posOlder, 'Newer interest should appear above older when priority_score ties');
    }

    #[Test]
    public function priority_service_marks_paid_plan_subscriber_as_ten(): void
    {
        $user = User::factory()->create();
        $basic = Plan::query()->where('slug', 'basic_male')->firstOrFail();

        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $basic->id,
            'plan_term_id' => null,
            'plan_price_id' => null,
            'coupon_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $svc = app(InterestPriorityService::class);
        $this->assertSame(Interest::PRIORITY_SCORE_PAID, $svc->baseScoreForSender($user));

        $freeOnly = User::factory()->create();
        $this->assertSame(Interest::PRIORITY_SCORE_FREE, $svc->baseScoreForSender($freeOnly));

        $freePlan = Plan::query()->where('slug', 'free_male')->firstOrFail();
        Subscription::query()->create([
            'user_id' => $freeOnly->id,
            'plan_id' => $freePlan->id,
            'plan_term_id' => null,
            'plan_price_id' => null,
            'coupon_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->assertSame(Interest::PRIORITY_SCORE_FREE, $svc->baseScoreForSender($freeOnly->fresh()));
    }
}
