<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\ShowcaseChatSetting;
use App\Models\User;
use App\Services\ShowcaseChat\ShowcaseChatSettingsService;
use App\Services\ShowcaseChat\ShowcaseOrchestrationService;
use App\Services\ShowcaseChat\ShowcaseReplySchedulerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseChatDebugTest extends TestCase
{
    use RefreshDatabase;

    public function test_debug_snapshot_returns_expected_structure(): void
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
            'reply_probability_percent' => 100,
            'personality_preset' => 'balanced',
        ]);

        $this->actingAs($realUser)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $orch = app(ShowcaseOrchestrationService::class);
        $state = $orch->ensureState($conv, $showcase);
        $setting = app(ShowcaseChatSettingsService::class)->getOrCreateForProfile($showcase);

        $snap = $orch->buildDebugSnapshot($state, $setting);

        $this->assertSame($showcase->id, $snap['profile_id']);
        $this->assertSame($conv->id, $snap['conversation_id']);
        foreach ([
            'is_active_lock',
            'pending_read_at',
            'pending_typing_at',
            'pending_reply_at',
            'pending_offline_at',
            'unanswered_incoming_count',
            'last_incoming_at',
            'base_probability',
            'fatigue_penalty',
            'spam_penalty',
            'personality_modifier',
            'final_probability',
            'blocked_by_unanswered_cap',
            'can_reply_now',
        ] as $k) {
            $this->assertArrayHasKey($k, $snap);
        }
    }

    public function test_probability_breakdown_matches_compute_final(): void
    {
        /** @var ShowcaseReplySchedulerService $svc */
        $svc = app(ShowcaseReplySchedulerService::class);
        $now = Carbon::parse('2026-03-25 10:00:00');
        $prev = $now->copy()->subMinute();

        $b = $svc->computeProbabilityBreakdown(50, 1, $prev, $now, 'balanced');
        $f = $svc->computeFinalProbabilityPercent(50, 1, $prev, $now, 'balanced');
        $this->assertSame($f, $b['final_probability']);

        $b2 = $svc->computeProbabilityBreakdown(100, 6, null, $now, 'warm');
        $f2 = $svc->computeFinalProbabilityPercent(100, 6, null, $now, 'warm');
        $this->assertSame($f2, $b2['final_probability']);
        $this->assertSame(0, $b2['final_probability']);
    }

    public function test_admin_debug_page_loads(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $realUser = User::factory()->create();
        $showUser = User::factory()->create();
        MatrimonyProfile::factory()->create(['user_id' => $realUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false]);
        $showcase = MatrimonyProfile::factory()->create(['user_id' => $showUser->id, 'lifecycle_state' => 'active', 'is_suspended' => false, 'is_demo' => true]);

        ShowcaseChatSetting::create([
            'matrimony_profile_id' => $showcase->id,
            'enabled' => true,
            'business_hours_enabled' => false,
        ]);

        $this->actingAs($realUser)->post(route('chat.start', ['matrimony_profile' => $showcase->id]))->assertRedirect();
        $conv = Conversation::firstOrFail();

        $this->actingAs($admin)->get(route('admin.showcase-chat.debug', ['conversation' => $conv->id]))
            ->assertOk()
            ->assertSee('Probability breakdown', false);
    }
}
