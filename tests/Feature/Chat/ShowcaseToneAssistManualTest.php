<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\ShowcaseChatSetting;
use App\Models\User;
use App\Services\ShowcaseChat\ShowcaseReplyExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseToneAssistManualTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_reply_raw_when_tone_toggle_off(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $realUser = User::factory()->create();
        $showUser = User::factory()->create();
        $real = MatrimonyProfile::factory()->create(['user_id' => $realUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $showcase = MatrimonyProfile::factory()->create(['user_id' => $showUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false, 'is_showcase' => true]);

        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'business_hours_enabled' => false,
            'personality_preset' => 'warm',
            'reply_length_min_words' => 4,
            'reply_length_max_words' => 80,
        ]);

        $this->actingAs($realUser)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();
        $this->actingAs($realUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), ['body_text' => 'Hi'])->assertRedirect();

        $raw = 'Exact body 123.';
        $this->actingAs($admin)->post(route('admin.showcase-conversations.reply', ['conversation' => $conv->id]), [
            'showcase_profile_id' => $showcase->id,
            'body_text' => $raw,
            'apply_personality_tone' => '0',
        ])->assertRedirect();

        $msg = Message::query()->where('conversation_id', $conv->id)->where('sender_profile_id', $showcase->id)->latest('id')->firstOrFail();
        $this->assertSame($raw, $msg->body_text);
    }

    public function test_manual_reply_warm_tone_adds_polite_close(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $realUser = User::factory()->create();
        $showUser = User::factory()->create();
        $real = MatrimonyProfile::factory()->create(['user_id' => $realUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $showcase = MatrimonyProfile::factory()->create(['user_id' => $showUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false, 'is_showcase' => true]);

        ShowcaseChatSetting::query()->where('matrimony_profile_id', $showcase->id)->delete();
        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'business_hours_enabled' => false,
            'personality_preset' => 'warm',
            'reply_length_min_words' => 4,
            'reply_length_max_words' => 80,
        ]);

        $this->actingAs($realUser)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();
        $this->actingAs($realUser)->post(route('chat.messages.text', ['conversation' => $conv->id]), ['body_text' => 'Hi'])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.showcase-conversations.reply', ['conversation' => $conv->id]), [
            'showcase_profile_id' => $showcase->id,
            'body_text' => 'Hello there.',
            'apply_personality_tone' => '1',
        ])->assertRedirect();

        $msg = Message::query()->where('conversation_id', $conv->id)->where('sender_profile_id', $showcase->id)->latest('id')->firstOrFail();
        $this->assertStringContainsString('Hello there', $msg->body_text);
        $this->assertStringContainsString('Thank you', $msg->body_text);
    }

    public function test_apply_tone_reserved_reduces_please(): void
    {
        /** @var ShowcaseReplyExecutionService $exec */
        $exec = app(ShowcaseReplyExecutionService::class);
        $s = new ShowcaseChatSetting([
            'personality_preset' => 'reserved',
            'reply_length_min_words' => 4,
            'reply_length_max_words' => 40,
        ]);
        $out = $exec->applyToneToManualText('Please tell me more about work.', $s, 1);
        $this->assertStringNotContainsString('Please', $out);
        $this->assertStringContainsString('tell me more', $out);
    }

    public function test_apply_tone_keeps_core_content_for_warm(): void
    {
        /** @var ShowcaseReplyExecutionService $exec */
        $exec = app(ShowcaseReplyExecutionService::class);
        $s = new ShowcaseChatSetting([
            'personality_preset' => 'warm',
            'reply_length_min_words' => 4,
            'reply_length_max_words' => 80,
        ]);
        $core = 'We can meet next week.';
        $out = $exec->applyToneToManualText($core, $s, 2);
        $this->assertStringContainsString('meet next week', $out);
    }
}
