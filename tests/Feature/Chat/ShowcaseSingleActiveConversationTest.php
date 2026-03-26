<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\ShowcaseChatSetting;
use App\Models\ShowcaseConversationState;
use App\Models\User;
use App\Services\ShowcaseChat\ShowcaseOrchestrationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseSingleActiveConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_conversations_only_one_gets_reply_other_is_read_only(): void
    {
        $realUser1 = User::factory()->create();
        $realUser2 = User::factory()->create();
        $showUser = User::factory()->create();

        $real1 = MatrimonyProfile::factory()->create(['user_id' => $realUser1->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $real2 = MatrimonyProfile::factory()->create(['user_id' => $realUser2->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $showcase = MatrimonyProfile::factory()->create(['user_id' => $showUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false, 'is_demo' => true]);

        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'ai_assisted_replies_enabled' => true,
            'business_hours_enabled' => false,
            'read_delay_min_minutes' => 1,
            'read_delay_max_minutes' => 1,
            'reply_after_read_min_minutes' => 10,
            'reply_after_read_max_minutes' => 10,
            'reply_probability_percent' => 100,
        ]);

        // Conversation 1
        $this->actingAs($realUser1)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv1 = Conversation::firstOrFail();
        $this->actingAs($realUser1)->post(route('chat.messages.text', ['conversation' => $conv1->id]), [
            'body_text' => 'Hello 1',
        ])->assertRedirect();

        $state1 = ShowcaseConversationState::query()
            ->where('conversation_id', $conv1->id)
            ->where('showcase_profile_id', $showcase->id)
            ->firstOrFail();

        $this->assertNotNull($state1->pending_read_at);
        $this->assertNotNull($state1->pending_reply_at);
        $this->assertNotNull($state1->active_lock_until);

        // Conversation 2
        $this->actingAs($realUser2)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv2 = Conversation::query()->where('id', '!=', $conv1->id)->firstOrFail();
        $this->actingAs($realUser2)->post(route('chat.messages.text', ['conversation' => $conv2->id]), [
            'body_text' => 'Hello 2',
        ])->assertRedirect();

        $state2 = ShowcaseConversationState::query()
            ->where('conversation_id', $conv2->id)
            ->where('showcase_profile_id', $showcase->id)
            ->firstOrFail();

        $this->assertNotNull($state2->pending_read_at);
        $this->assertNull($state2->pending_reply_at);
        $this->assertNull($state2->pending_typing_at);

        $activeLocks = ShowcaseConversationState::query()
            ->where('showcase_profile_id', $showcase->id)
            ->whereNotNull('active_lock_until')
            ->where('active_lock_until', '>', now())
            ->count();
        $this->assertSame(1, $activeLocks);
    }

    public function test_after_first_goes_offline_second_can_proceed(): void
    {
        $realUser1 = User::factory()->create();
        $realUser2 = User::factory()->create();
        $showUser = User::factory()->create();

        $real1 = MatrimonyProfile::factory()->create(['user_id' => $realUser1->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $real2 = MatrimonyProfile::factory()->create(['user_id' => $realUser2->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $showcase = MatrimonyProfile::factory()->create(['user_id' => $showUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false, 'is_demo' => true]);

        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'ai_assisted_replies_enabled' => true,
            'business_hours_enabled' => false,
            'read_delay_min_minutes' => 1,
            'read_delay_max_minutes' => 1,
            'reply_after_read_min_minutes' => 1,
            'reply_after_read_max_minutes' => 1,
            'reply_probability_percent' => 100,
            'online_before_read_min_seconds' => 1,
            'online_before_read_max_seconds' => 1,
            'online_linger_after_reply_min_seconds' => 1,
            'online_linger_after_reply_max_seconds' => 1,
        ]);

        // Start two conversations.
        $this->actingAs($realUser1)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv1 = Conversation::firstOrFail();
        $this->actingAs($realUser2)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv2 = Conversation::query()->where('id', '!=', $conv1->id)->firstOrFail();

        // Message in conv1 (gets lock + reply)
        $this->actingAs($realUser1)->post(route('chat.messages.text', ['conversation' => $conv1->id]), [
            'body_text' => 'Hello 1',
        ])->assertRedirect();

        // conv2 stays quiet while conv1 is active (avoids stacking fatigue on conv2 before its first reply)

        $svc = app(ShowcaseOrchestrationService::class);

        $state1 = ShowcaseConversationState::query()->where('conversation_id', $conv1->id)->where('showcase_profile_id', $showcase->id)->firstOrFail();
        $this->travelTo(Carbon::parse($state1->pending_read_at)->addMinute());
        $svc->tickConversation($conv1, $showcase, $real1);

        $state1->refresh();
        $this->travelTo(Carbon::parse($state1->pending_reply_at)->addMinute());
        $svc->tickConversation($conv1, $showcase, $real1);

        // Advance past offline to clear lock.
        $state1->refresh();
        $this->travelTo(Carbon::parse($state1->pending_offline_at)->addMinute());
        $svc->tickConversation($conv1, $showcase, $real1);

        $state1->refresh();
        $this->assertNull($state1->active_lock_until);

        // First message on conv2 after lock released: should schedule read + reply (probability 100, count 1).
        $this->actingAs($realUser2)->post(route('chat.messages.text', ['conversation' => $conv2->id]), [
            'body_text' => 'Hello 2',
        ])->assertRedirect();

        $state2 = ShowcaseConversationState::query()->where('conversation_id', $conv2->id)->where('showcase_profile_id', $showcase->id)->firstOrFail();
        $this->assertNotNull($state2->pending_read_at);
        $this->assertNotNull($state2->pending_reply_at);
        $this->assertNotNull($state2->active_lock_until);
    }

    public function test_expired_lock_does_not_block_other_conversations(): void
    {
        $realUser1 = User::factory()->create();
        $realUser2 = User::factory()->create();
        $showUser = User::factory()->create();

        $real1 = MatrimonyProfile::factory()->create(['user_id' => $realUser1->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $real2 = MatrimonyProfile::factory()->create(['user_id' => $realUser2->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $showcase = MatrimonyProfile::factory()->create(['user_id' => $showUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false, 'is_demo' => true]);

        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'ai_assisted_replies_enabled' => true,
            'business_hours_enabled' => false,
            'read_delay_min_minutes' => 1,
            'read_delay_max_minutes' => 1,
            'reply_after_read_min_minutes' => 1,
            'reply_after_read_max_minutes' => 1,
            'reply_probability_percent' => 100,
        ]);

        $this->actingAs($realUser1)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv1 = Conversation::firstOrFail();
        $this->actingAs($realUser2)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv2 = Conversation::query()->where('id', '!=', $conv1->id)->firstOrFail();

        // Manually create an expired lock on conv1 state.
        $state1 = ShowcaseConversationState::firstOrCreate(
            ['conversation_id' => $conv1->id, 'showcase_profile_id' => $showcase->id],
            ['automation_status' => ShowcaseConversationState::STATUS_ACTIVE]
        );
        $state1->active_lock_until = now()->subMinute();
        $state1->save();

        // Message in conv2 should still be able to schedule reply.
        $this->actingAs($realUser2)->post(route('chat.messages.text', ['conversation' => $conv2->id]), [
            'body_text' => 'Hello 2',
        ])->assertRedirect();

        $state2 = ShowcaseConversationState::query()->where('conversation_id', $conv2->id)->where('showcase_profile_id', $showcase->id)->firstOrFail();
        $this->assertNotNull($state2->pending_read_at);
        $this->assertNotNull($state2->pending_reply_at);
    }
}

