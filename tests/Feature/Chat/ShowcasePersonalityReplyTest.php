<?php

namespace Tests\Feature\Chat;

use App\Services\ShowcaseChat\ShowcaseReplyExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcasePersonalityReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_warm_preset_tends_longer_than_reserved_for_same_input(): void
    {
        /** @var ShowcaseReplyExecutionService $exec */
        $exec = app(ShowcaseReplyExecutionService::class);
        $incoming = 'Hello there, how are you?';

        $warm = $exec->countWordsInReply($incoming, 'warm', 4, 80, true, 99);
        $reserved = $exec->countWordsInReply($incoming, 'reserved', 4, 80, true, 99);

        $this->assertGreaterThan($reserved, $warm);
    }

    public function test_reserved_stays_short_within_bounds(): void
    {
        /** @var ShowcaseReplyExecutionService $exec */
        $exec = app(ShowcaseReplyExecutionService::class);
        $incoming = 'Thanks for your message about work.';

        $n = $exec->countWordsInReply($incoming, 'reserved', 4, 12, true, 5);
        $this->assertLessThanOrEqual(12, $n);
        $this->assertGreaterThanOrEqual(4, $n);
    }

    public function test_style_variation_disabled_is_deterministic_for_preset(): void
    {
        /** @var ShowcaseReplyExecutionService $exec */
        $exec = app(ShowcaseReplyExecutionService::class);
        $incoming = 'Hello';

        $a = $exec->buildAutoReplyText($incoming, new \App\Models\ShowcaseChatSetting([
            'personality_preset' => 'balanced',
            'reply_length_min_words' => 4,
            'reply_length_max_words' => 40,
            'style_variation_enabled' => false,
        ]), 1);

        $b = $exec->buildAutoReplyText($incoming, new \App\Models\ShowcaseChatSetting([
            'personality_preset' => 'balanced',
            'reply_length_min_words' => 4,
            'reply_length_max_words' => 40,
            'style_variation_enabled' => false,
        ]), 999);

        $this->assertSame($a, $b);
    }
}
