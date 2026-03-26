<?php

namespace Tests\Feature\Chat;

use App\Services\ShowcaseChat\ShowcaseReplySchedulerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseProbabilityFatigueTest extends TestCase
{
    use RefreshDatabase;

    public function test_probability_rules_apply_fatigue_and_spam_penalties(): void
    {
        /** @var ShowcaseReplySchedulerService $svc */
        $svc = app(ShowcaseReplySchedulerService::class);

        $now = Carbon::parse('2026-03-25 10:00:00');
        $prev = $now->copy()->subMinute();

        // Base 50, first message boost +10, spam -30 => 30
        $p = $svc->computeFinalProbabilityPercent(50, 1, $prev, $now);
        $this->assertSame(30, $p);

        // Base 80, count 2 => -20, spam -30 => 30
        $p = $svc->computeFinalProbabilityPercent(80, 2, $prev, $now);
        $this->assertSame(30, $p);

        // Base 80, count 4 => -40, no spam => 40
        $p = $svc->computeFinalProbabilityPercent(80, 4, null, $now);
        $this->assertSame(40, $p);

        // 6+ unanswered => 0
        $p = $svc->computeFinalProbabilityPercent(100, 6, null, $now);
        $this->assertSame(0, $p);
    }

    public function test_personality_preset_applies_light_probability_modifier(): void
    {
        /** @var ShowcaseReplySchedulerService $svc */
        $svc = app(ShowcaseReplySchedulerService::class);

        $now = Carbon::parse('2026-03-25 10:00:00');
        $prev = $now->copy()->subMinute();

        // Same fatigue stack as first case (30) + warm (+5) => 35
        $p = $svc->computeFinalProbabilityPercent(50, 1, $prev, $now, 'warm');
        $this->assertSame(35, $p);

        // 30 + reserved (-15) => 15
        $p = $svc->computeFinalProbabilityPercent(50, 1, $prev, $now, 'reserved');
        $this->assertSame(15, $p);

        // Fatigue still wins: 6+ unanswered => 0 even with warm
        $p = $svc->computeFinalProbabilityPercent(100, 6, null, $now, 'warm');
        $this->assertSame(0, $p);
    }
}

