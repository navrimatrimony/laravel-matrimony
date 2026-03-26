<?php

namespace App\Services\ShowcaseChat;

use App\Models\Conversation;
use App\Models\MatrimonyProfile;
use App\Models\Message;
use App\Models\ShowcaseChatSetting;
use App\Services\Chat\ChatMessageService;

class ShowcaseReplyExecutionService
{
    public function __construct(
        protected ChatMessageService $messages,
    ) {}

    public function sendShowcaseTextReply(MatrimonyProfile $showcase, MatrimonyProfile $receiver, Conversation $conversation, string $text): Message
    {
        return $this->messages->sendTextMessage($showcase, $receiver, $conversation, $text);
    }

    /**
     * Build auto-reply text for showcase orchestration. Admin manual replies use raw text and skip this.
     *
     * @param  int|null  $variationSeed  Stable per conversation (e.g. conversation id) for light variation.
     */
    public function buildAutoReplyText(?string $incomingText, ?ShowcaseChatSetting $setting = null, ?int $variationSeed = null): string
    {
        $preset = ShowcaseChatSettingsService::normalizePersonalityPreset(
            $setting ? (string) ($setting->personality_preset ?? '') : ''
        );
        $minW = $setting ? $this->effectiveMinWords($setting) : 4;
        $maxW = $setting ? $this->effectiveMaxWords($setting) : 18;
        $variationOn = $setting ? (bool) ($setting->style_variation_enabled ?? true) : true;

        $t = trim((string) $incomingText);
        $lower = mb_strtolower($t);
        $seed = $variationSeed !== null ? abs((int) crc32((string) $variationSeed.'|'.$t)) : 0;

        $draft = $this->buildIntentDraft($t, $lower, $preset, $seed, $variationOn);

        $draft = $this->fitWordBounds($draft, $minW, $maxW, $preset, $seed);

        return trim($draft);
    }

    /**
     * Deterministic comparison helper for tests: word count after shaping (same input, different presets).
     */
    public function countWordsInReply(?string $incomingText, string $preset, int $minWords, int $maxWords, bool $styleVariation, int $seed = 1): int
    {
        $s = new ShowcaseChatSetting([
            'personality_preset' => $preset,
            'reply_length_min_words' => $minWords,
            'reply_length_max_words' => $maxWords,
            'style_variation_enabled' => $styleVariation,
        ]);

        return $this->countWords($this->buildAutoReplyText($incomingText, $s, $seed));
    }

    /**
     * Light stylistic pass for admin manual replies. Does not add facts or change intent.
     */
    public function applyToneToManualText(string $text, ShowcaseChatSetting $setting, int $conversationId): string
    {
        $preset = ShowcaseChatSettingsService::normalizePersonalityPreset((string) ($setting->personality_preset ?? 'balanced'));
        $t = trim(preg_replace('/\s+/u', ' ', $text));
        if ($t === '') {
            return '';
        }

        $t = match ($preset) {
            'warm' => $this->warmToneManual($t),
            'selective' => $this->selectiveToneManual($t),
            'reserved' => $this->reservedToneManual($t),
            default => $t,
        };

        $seed = abs((int) crc32((string) $conversationId.'|'.$t));

        return trim($this->fitWordBounds($t, $this->effectiveMinWords($setting), $this->effectiveMaxWords($setting), $preset, $seed));
    }

    protected function warmToneManual(string $t): string
    {
        $l = mb_strtolower($t);
        if (! str_contains($l, 'thank')) {
            $t = rtrim($t, ". \t\n\r\0\x0B").'. Thank you.';
        }

        return $t;
    }

    protected function selectiveToneManual(string $t): string
    {
        return trim((string) preg_replace('/\b(just|really|very)\s+/iu', '', $t));
    }

    protected function reservedToneManual(string $t): string
    {
        $t = (string) preg_replace('/\b(please|kindly)\s+/iu', '', $t);

        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    public function effectiveMinWords(ShowcaseChatSetting $s): int
    {
        $v = $s->reply_length_min_words;

        return max(1, min(200, (int) ($v ?? 4)));
    }

    public function effectiveMaxWords(ShowcaseChatSetting $s): int
    {
        $min = $this->effectiveMinWords($s);
        $v = $s->reply_length_max_words;
        $max = (int) ($v ?? 18);

        return max($min, min(200, $max));
    }

    protected function buildIntentDraft(string $t, string $lower, string $preset, int $seed, bool $variationOn): string
    {
        if ($t === '') {
            return $this->pickEmptyIncoming($preset, $seed, $variationOn);
        }

        if (str_contains($lower, 'photo') || str_contains($lower, 'pic')) {
            return $this->pickPhotoReply($preset, $seed, $variationOn);
        }

        if (preg_match('/\b(hi|hello|hey|namaste|नमस्कार)\b/u', $lower)) {
            return $this->pickHelloReply($preset, $seed, $variationOn);
        }

        return $this->pickDefaultReply($preset, $seed, $variationOn);
    }

    protected function pickEmptyIncoming(string $preset, int $seed, bool $variationOn): string
    {
        $pools = [
            'warm' => [
                'Hello, and thank you for writing. I am glad we connected here.',
                'Hi there — thanks for reaching out. I hope you are doing well.',
            ],
            'balanced' => [
                'Hello. Thanks for your message.',
                'Hi — thanks for getting in touch.',
            ],
            'selective' => [
                'Thanks for the message.',
                'Hi — noted.',
            ],
            'reserved' => [
                'Hello.',
                'Thanks.',
            ],
        ];

        $list = $pools[$preset] ?? $pools['balanced'];
        $i = $variationOn ? ($seed % count($list)) : 0;

        return $list[$i];
    }

    protected function pickPhotoReply(string $preset, int $seed, bool $variationOn): string
    {
        $pools = [
            'warm' => [
                'Thanks for mentioning that. I prefer to take photos step by step — could you share a little about your family and what you do for work?',
                'I appreciate you bringing that up. I like to keep things comfortable at first. What would you like me to know about your background?',
            ],
            'balanced' => [
                'Thanks. I will share more on that in time. Could you tell me about your family and work?',
                'Noted. I prefer to go slowly with photos. A bit about your work and family would help.',
            ],
            'selective' => [
                'Thanks. We can discuss photos later. Briefly, what is your work situation?',
                'Understood. Share family and work details when you can.',
            ],
            'reserved' => [
                'Noted.',
                'Okay. Share work and family details when ready.',
            ],
        ];

        $list = $pools[$preset] ?? $pools['balanced'];
        $i = $variationOn ? ($seed % count($list)) : 0;

        return $list[$i];
    }

    protected function pickHelloReply(string $preset, int $seed, bool $variationOn): string
    {
        $pools = [
            'warm' => [
                'Hello — nice to hear from you. How have things been on your side?',
                'Hi there, thank you for saying hello. What would you like to talk about first?',
            ],
            'balanced' => [
                'Hello. Nice to connect. How are you?',
                'Hi — thanks for writing. How are things going?',
            ],
            'selective' => [
                'Hello. Thanks — how are you?',
                'Hi. Noted. What would you like to share?',
            ],
            'reserved' => [
                'Hello.',
                'Hi — thanks.',
            ],
        ];

        $list = $pools[$preset] ?? $pools['balanced'];
        $i = $variationOn ? ($seed % count($list)) : 0;

        return $list[$i];
    }

    protected function pickDefaultReply(string $preset, int $seed, bool $variationOn): string
    {
        $pools = [
            'warm' => [
                'Thank you for sharing that — it helps me understand you better. What matters most to you in a match right now?',
                'I appreciate the detail. Could you tell me a bit more about your day-to-day routine and what you are looking for here?',
            ],
            'balanced' => [
                'Thanks for sharing. Could you tell me more about your work and where you are based?',
                'Thanks — that is helpful. What would you like me to know about your family and location?',
            ],
            'selective' => [
                'Thanks. Briefly, what is your work and location?',
                'Noted. Share a bit about work and family when you can.',
            ],
            'reserved' => [
                'Thanks.',
                'Noted. Share work and location.',
            ],
        ];

        $list = $pools[$preset] ?? $pools['balanced'];
        $i = $variationOn ? ($seed % count($list)) : 0;

        return $list[$i];
    }

    protected function fitWordBounds(string $text, int $minW, int $maxW, string $preset, int $seed): string
    {
        $text = trim($text);
        $n = $this->countWords($text);

        if ($n > $maxW) {
            return $this->trimToMaxWords($text, $maxW);
        }

        if ($n < $minW) {
            return $this->extendToMinWords($text, $minW, $preset, $seed);
        }

        return $text;
    }

    protected function extendToMinWords(string $text, int $minW, string $preset, int $seed): string
    {
        $fillers = [
            'warm' => [
                ' I am glad you wrote.',
                ' I appreciate you taking the time.',
                ' Hope that works for you.',
            ],
            'balanced' => [
                ' Thanks again.',
                ' Looking forward to hearing more when you can.',
            ],
            'selective' => [
                ' Thanks.',
                ' Understood.',
            ],
            'reserved' => [
                ' Okay.',
                ' Thanks.',
            ],
        ];
        $pool = $fillers[$preset] ?? $fillers['balanced'];
        $guard = 0;

        while ($this->countWords($text) < $minW && $guard < 12) {
            $idx = abs((int) (crc32($text.(string) $guard.(string) $seed))) % count($pool);
            $text .= $pool[$idx];
            $guard++;
        }

        if ($this->countWords($text) < $minW) {
            $text .= ' Thanks.';
        }

        return trim($text);
    }

    protected function trimToMaxWords(string $text, int $maxW): string
    {
        return $this->hardTrimWords($text, $maxW);
    }

    protected function hardTrimWords(string $text, int $maxW): string
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($words)) {
            return '';
        }
        $words = array_slice($words, 0, $maxW);

        return implode(' ', $words);
    }

    public function countWords(string $text): int
    {
        $t = trim(preg_replace('/\s+/u', ' ', $text));
        if ($t === '') {
            return 0;
        }
        $parts = preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }
}
