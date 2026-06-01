<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\City;
use App\Models\Message;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\ShowcaseChatSetting;
use App\Models\User;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShowcaseAdminTakeoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        $this->seed(MasterLookupSeeder::class);
        $this->seedFreePlanQuotaPolicies();
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_admin_can_reply_as_showcase_and_message_stored_normally(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $realUser = User::factory()->create();
        $showUser = User::factory()->create();

        $real = $this->createActiveProfileWithResidence($realUser);
        $showcase = $this->createActiveProfileWithResidence($showUser, ['is_showcase' => true]);

        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'ai_assisted_replies_enabled' => true,
            'business_hours_enabled' => false,
        ]);

        // Start conversation and send a user message.
        $this->actingAs($realUser)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();
        $this->actingAs($realUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), ['body_text' => 'Hello'])->assertRedirect();

        // Admin replies as showcase via admin route.
        $this->actingAs($admin)->post(route('admin.showcase-conversations.reply', ['conversation' => $conv->id]), [
            'showcase_profile_id' => $showcase->id,
            'body_text' => 'Thanks for your message.',
        ])->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'sender_profile_id' => $showcase->id,
            'receiver_profile_id' => $real->id,
            'message_type' => 'text',
        ]);
    }

    public function test_admin_reply_as_showcase_marks_user_messages_read_like_automated_flow(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $realUser = User::factory()->create();
        $showUser = User::factory()->create();

        $real = $this->createActiveProfileWithResidence($realUser);
        $showcase = $this->createActiveProfileWithResidence($showUser, ['is_showcase' => true]);

        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'ai_assisted_replies_enabled' => true,
            'business_hours_enabled' => false,
        ]);

        $this->actingAs($realUser)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();
        $this->actingAs($realUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), ['body_text' => 'Hello from user'])->assertRedirect();

        $userMsg = Message::query()
            ->where('conversation_id', $conv->id)
            ->where('receiver_profile_id', $showcase->id)
            ->firstOrFail();
        $this->assertNull($userMsg->read_at);

        $this->actingAs($admin)->post(route('admin.showcase-conversations.reply', ['conversation' => $conv->id]), [
            'showcase_profile_id' => $showcase->id,
            'body_text' => 'Admin showcase reply.',
        ])->assertRedirect();

        $userMsg->refresh();
        $this->assertNotNull($userMsg->read_at);
        $this->assertSame('read', $userMsg->delivery_status);
    }

    /**
     * @param  array<string, mixed>  $factoryAttributes
     */
    private function createActiveProfileWithResidence(User $user, array $factoryAttributes = []): MatrimonyProfile
    {
        $profile = MatrimonyProfile::factory()->for($user)->create(array_merge([
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $factoryAttributes));

        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;
        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $leafId]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
        }

        $profile->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $profile->fresh();
    }

    private function seedFreePlanQuotaPolicies(): void
    {
        $plan = Plan::query()->firstOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'price' => 0,
                'discount_percent' => 0,
                'duration_days' => 0,
                'is_active' => true,
                'is_visible' => true,
                'sort_order' => 0,
                'highlight' => false,
                'applies_to_gender' => 'all',
                'gst_inclusive' => true,
                'grace_period_days' => 0,
                'leftover_quota_carry_window_days' => null,
            ],
        );

        foreach (PlanQuotaPolicyKeys::ordered() as $featureKey) {
            $attributes = PlanQuotaPolicy::defaultsForNewPlan($featureKey);
            if ($featureKey === PlanFeatureKeys::CHAT_SEND_LIMIT) {
                $attributes['is_enabled'] = true;
                $attributes['refresh_type'] = PlanQuotaPolicy::REFRESH_DAILY;
                $attributes['limit_value'] = 10;
                $attributes['policy_meta'] = ['chat_initiate_new_chats_only' => false];
            }

            PlanQuotaPolicy::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'feature_key' => $featureKey],
                $attributes,
            );
        }
    }
}

