<?php

namespace Tests\Feature\Chat;

use App\Services\ShowcaseChat\ShowcaseChatSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcasePersonalitySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_personality_preset_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(ShowcaseChatSettingsService::class)->validatePersonalityAndReplyLength(['personality_preset' => 'bold']);
    }

    public function test_reply_length_min_greater_than_max_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(ShowcaseChatSettingsService::class)->validatePersonalityAndReplyLength([
            'reply_length_min_words' => 20,
            'reply_length_max_words' => 10,
        ]);
    }

    public function test_valid_personality_and_word_range_passes(): void
    {
        $out = app(ShowcaseChatSettingsService::class)->validateTimingPairs([
            'personality_preset' => 'warm',
            'reply_length_min_words' => 4,
            'reply_length_max_words' => 24,
            'reply_probability_percent' => 50,
            'initiate_probability_percent' => 10,
        ]);
        $this->assertSame('warm', $out['personality_preset']);
    }
}
