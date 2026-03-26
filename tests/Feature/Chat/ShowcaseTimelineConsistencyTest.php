<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\ShowcaseChatSetting;
use App\Models\ShowcaseConversationState;
use App\Models\User;
use App\Services\ShowcaseChat\ShowcaseOrchestrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseTimelineConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_tick_does_not_send_reply_before_read(): void
    {
        $realUser = User::factory()->create();
        $showUser = User::factory()->create();

        $real = MatrimonyProfile::factory()->create(['user_id' => $realUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $showcase = MatrimonyProfile::factory()->create(['user_id' => $showUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false, 'is_demo' => true]);

        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'ai_assisted_replies_enabled' => true,
            'business_hours_enabled' => false,
            'read_delay_min_minutes' => 10,
            'read_delay_max_minutes' => 10,
            'reply_after_read_min_minutes' => 1,
            'reply_after_read_max_minutes' => 1,
            'reply_probability_percent' => 100,
        ]);

        $this->actingAs($realUser)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $this->actingAs($realUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'Hello',
        ])->assertRedirect();

        $state = ShowcaseConversationState::query()
            ->where('conversation_id', $conv->id)
            ->where('showcase_profile_id', $showcase->id)
            ->firstOrFail();

        // Force inconsistent state: reply due now but read pending in future.
        $now = now();
        $state->pending_read_at = $now->copy()->addMinutes(10);
        $state->pending_reply_at = $now->copy()->addMinute();
        $state->pending_typing_at = $now->copy();
        $state->last_read_at = null;
        $state->save();

        app(ShowcaseOrchestrationService::class)->tickConversation($conv, $showcase, $real);

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conv->id,
            'sender_profile_id' => $showcase->id,
            'receiver_profile_id' => $real->id,
        ]);
    }

    public function test_reply_sets_pending_offline_after_send(): void
    {
        $realUser = User::factory()->create();
        $showUser = User::factory()->create();

        $real = MatrimonyProfile::factory()->create(['user_id' => $realUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
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
            'typing_duration_min_seconds' => 1,
            'typing_duration_max_seconds' => 1,
            'reply_probability_percent' => 100,
            'online_before_read_min_seconds' => 1,
            'online_before_read_max_seconds' => 1,
            'online_linger_after_reply_min_seconds' => 10,
            'online_linger_after_reply_max_seconds' => 10,
        ]);

        $this->actingAs($realUser)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $this->actingAs($realUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'Hello',
        ])->assertRedirect();

        $state = ShowcaseConversationState::query()
            ->where('conversation_id', $conv->id)
            ->where('showcase_profile_id', $showcase->id)
            ->firstOrFail();

        $this->assertNull($state->pending_offline_at);

        // Advance timeline: first tick at read time opens presence, second tick allows read,
        // then tick after reply time sends reply and sets pending_offline_at.
        $this->travelTo($state->pending_read_at->copy());
        app(ShowcaseOrchestrationService::class)->tickConversation($conv, $showcase, $real);

        $this->travelTo($state->pending_read_at->copy()->addSeconds(2));
        app(ShowcaseOrchestrationService::class)->tickConversation($conv, $showcase, $real);

        $state->refresh();
        $this->travelTo($state->pending_reply_at->copy()->addMinute());
        app(ShowcaseOrchestrationService::class)->tickConversation($conv, $showcase, $real);

        $state->refresh();

        $this->assertNotNull($state->pending_offline_at);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'sender_profile_id' => $showcase->id,
            'receiver_profile_id' => $real->id,
            'message_type' => 'text',
        ]);
    }

    public function test_read_can_happen_without_reply_when_probability_fails(): void
    {
        $realUser = User::factory()->create();
        $showUser = User::factory()->create();

        $real = MatrimonyProfile::factory()->create(['user_id' => $realUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $showcase = MatrimonyProfile::factory()->create(['user_id' => $showUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false, 'is_demo' => true]);

        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'ai_assisted_replies_enabled' => true,
            'business_hours_enabled' => false,
            'read_delay_min_minutes' => 5,
            'read_delay_max_minutes' => 5,
            'reply_probability_percent' => 0,
        ]);

        $this->actingAs($realUser)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $this->actingAs($realUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'Hello',
        ])->assertRedirect();

        $state = ShowcaseConversationState::query()
            ->where('conversation_id', $conv->id)
            ->where('showcase_profile_id', $showcase->id)
            ->firstOrFail();

        $this->assertNotNull($state->pending_read_at);
        $this->assertNull($state->pending_reply_at);
        $this->assertNull($state->pending_typing_at);
    }

    public function test_no_duplicate_reply_scheduling_when_future_reply_exists(): void
    {
        $realUser = User::factory()->create();
        $showUser = User::factory()->create();

        $real = MatrimonyProfile::factory()->create(['user_id' => $realUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
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

        $this->actingAs($realUser)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        // First message schedules a reply in the future.
        $this->actingAs($realUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'Hello 1',
        ])->assertRedirect();

        $state = ShowcaseConversationState::query()
            ->where('conversation_id', $conv->id)
            ->where('showcase_profile_id', $showcase->id)
            ->firstOrFail();

        $firstReplyAt = $state->pending_reply_at;
        $this->assertNotNull($firstReplyAt);

        // Second message should NOT reschedule/stack another reply chain (future reply already exists).
        $this->actingAs($realUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'Hello 2',
        ])->assertRedirect();

        $state->refresh();
        $this->assertNotNull($state->pending_reply_at);
        $this->assertTrue($state->pending_reply_at->equalTo($firstReplyAt));
        $this->assertSame(2, (int) $state->unanswered_incoming_count);
    }
}

