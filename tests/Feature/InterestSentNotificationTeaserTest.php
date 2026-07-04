<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Notifications\InterestSentNotification;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InterestSentNotificationTeaserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MinimalLocationSeeder::class);
        $this->seed(MasterLookupSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
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

    public function test_locked_interest_notification_includes_admin_style_teaser_without_sender_id(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $receiver = User::factory()->create(['is_admin' => false]);
        $receiverProfile = $this->createActiveProfileWithResidence($receiver);

        $senders = [];
        for ($i = 0; $i < 4; $i++) {
            $u = User::factory()->create();
            $senders[] = $this->createActiveProfileWithResidence($u);
        }

        foreach ($senders as $sp) {
            Interest::query()->create([
                'sender_profile_id' => $sp->id,
                'receiver_profile_id' => $receiverProfile->id,
                'status' => 'pending',
                'priority_score' => 1,
            ]);
        }

        $lockedSender = $senders[3];
        $n = new InterestSentNotification($lockedSender);
        $payload = $n->toArray($receiver->fresh());

        $this->assertFalse($payload['revealed']);
        $this->assertNull($payload['sender_profile_id'] ?? null);
        $this->assertSame(__('interests.notification_blurred_sender'), $payload['message']);
        $this->assertIsArray($payload['teaser'] ?? null);
        $this->assertArrayHasKey('headline', $payload['teaser']);
        $this->assertArrayHasKey('lines', $payload['teaser']);
        $this->assertArrayHasKey('viewed_summary', $payload['teaser']);
        $this->assertArrayHasKey('photo_url', $payload['teaser']);
        $this->assertArrayHasKey('avatar_style', $payload['teaser']);
        $this->assertArrayHasKey('blur_photo_class', $payload['teaser']);
    }

    public function test_web_notifications_index_renders_locked_interest_teaser_with_received_interest_policy(): void
    {
        $receiver = User::factory()->create(['is_admin' => false]);
        $receiverProfile = $this->createActiveProfileWithResidence($receiver);
        $sender = User::factory()->create(['is_admin' => false]);
        $senderProfile = $this->createActiveProfileWithResidence($sender);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => InterestSentNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $receiver->id,
            'data' => json_encode([
                'type' => 'interest_sent',
                'message' => __('interests.notification_blurred_sender'),
                'sender_profile_id' => $senderProfile->id,
                'receiver_profile_id' => $receiverProfile->id,
                'revealed' => false,
                'teaser' => [
                    'headline' => 'Locked interest teaser',
                    'lines' => ['Pune district'],
                    'viewed_summary' => 'Interested recently',
                    'photo_url' => null,
                    'avatar_style' => 'silhouette',
                    'blur_photo_class' => 'blur-md scale-110 opacity-90',
                ],
            ], JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($receiver)
            ->get(route('notifications.index'))
            ->assertOk();
    }
}
