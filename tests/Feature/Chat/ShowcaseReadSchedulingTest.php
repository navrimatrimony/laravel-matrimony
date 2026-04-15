<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\ShowcaseChatSetting;
use App\Models\ShowcaseConversationState;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseReadSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_incoming_message_to_showcase_creates_pending_read_not_instant_read(): void
    {
        $realUser = User::factory()->create();
        $showUser = User::factory()->create();

        $real = MatrimonyProfile::factory()->create(['user_id' => $realUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $showcase = MatrimonyProfile::factory()->create(['user_id' => $showUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false, 'is_showcase' => true]);

        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'ai_assisted_replies_enabled' => true,
            'read_delay_min_minutes' => 5,
            'read_delay_max_minutes' => 5,
            'business_hours_enabled' => false,
            'reply_probability_percent' => 100,
        ]);

        $this->actingAs($realUser)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $this->actingAs($realUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), [
            'body_text' => 'Hello',
        ])->assertRedirect();

        $msg = Message::query()->latest('id')->firstOrFail();
        $this->assertNull($msg->read_at, 'Showcase read must not be instant by default.');

        $state = ShowcaseConversationState::query()
            ->where('conversation_id', $conv->id)
            ->where('showcase_profile_id', $showcase->id)
            ->firstOrFail();

        $this->assertNotNull($state->pending_read_at);
        $this->assertNotNull($state->pending_typing_at);
        $this->assertNotNull($state->pending_reply_at);

        $this->assertTrue($state->pending_read_at->lessThanOrEqualTo($state->pending_typing_at));
        $this->assertTrue($state->pending_typing_at->lessThanOrEqualTo($state->pending_reply_at));
    }

    public function test_fatigue_counter_resets_after_successful_reply(): void
    {
        $realUser = User::factory()->create();
        $showUser = User::factory()->create();

        $real = MatrimonyProfile::factory()->create(['user_id' => $realUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $showcase = MatrimonyProfile::factory()->create(['user_id' => $showUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false, 'is_showcase' => true]);

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
            'typing_duration_min_seconds' => 1,
            'typing_duration_max_seconds' => 1,
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

        $this->assertSame(1, (int) $state->unanswered_incoming_count);

        // Advance timeline in the same order the orchestrator enforces:
        // read window -> complete read -> reply due.
        $svc = app(\App\Services\ShowcaseChat\ShowcaseOrchestrationService::class);

        $this->travelTo(Carbon::parse($state->pending_read_at)->addSecond());
        $svc->tickConversation($conv, $showcase, $real);

        $this->travelTo(Carbon::parse($state->pending_read_at)->addMinute());
        $svc->tickConversation($conv, $showcase, $real);

        $state->refresh();
        $this->travelTo(Carbon::parse($state->pending_reply_at)->addMinute());
        $svc->tickConversation($conv, $showcase, $real);

        $state->refresh();
        $this->assertSame(0, (int) $state->unanswered_incoming_count);
    }
}

