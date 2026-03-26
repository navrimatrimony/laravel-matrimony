<?php

namespace App\Services\Chat;

use App\Models\Message;

class ChatMessageModerationService
{
    protected const SEVERE_WEIGHT = 10;

    protected const MEDIUM_WEIGHT = 4;

    /**
     * @return array{
     *   normalized_text: string,
     *   severity: 'clean'|'warn'|'block',
     *   matched_categories: array<int, string>,
     *   user_safe_message: string,
     *   should_mask_after_save: bool
     * }
     */
    public function moderate(string $text): array
    {
        $normalized = $this->normalizeForCheck($text);
        $compactLatin = $this->compactLatinLetters($normalized);

        $matchedCategories = [];
        $score = 0;

        $patterns = config('chat_moderation.patterns', []);
        foreach ($patterns as $category => $levels) {
            $severeHit = false;
            foreach ($levels['severe'] ?? [] as $pattern) {
                if ($this->patternMatches($pattern, $normalized, $compactLatin)) {
                    $matchedCategories[] = (string) $category;
                    $score += self::SEVERE_WEIGHT;
                    $severeHit = true;
                    break;
                }
            }
            if ($severeHit) {
                continue;
            }
            $mediumHit = false;
            foreach ($levels['medium'] ?? [] as $pattern) {
                if ($this->patternMatches($pattern, $normalized, $compactLatin)) {
                    $mediumHit = true;
                    break;
                }
            }
            if ($mediumHit) {
                $matchedCategories[] = (string) $category;
                $score += self::MEDIUM_WEIGHT;
            }
        }

        $matchedCategories = array_values(array_unique($matchedCategories));

        $blockAt = (int) config('chat_moderation.block_score_threshold', 8);
        $warnAt = (int) config('chat_moderation.warn_score_threshold', 4);

        $severity = 'clean';
        if ($score >= $blockAt) {
            $severity = 'block';
        } elseif ($score >= $warnAt) {
            $severity = 'warn';
        }

        $userMsg = $severity === 'block'
            ? (string) config('chat_moderation.user_messages.block')
            : ((string) config('chat_moderation.user_messages.warn'));

        if ($severity === 'clean') {
            $userMsg = '';
        }

        $shouldMask = $severity === 'block' || $severity === 'warn';

        return [
            'normalized_text' => $normalized,
            'severity' => $severity,
            'matched_categories' => $matchedCategories,
            'user_safe_message' => $userMsg,
            'should_mask_after_save' => $shouldMask,
        ];
    }

    public function shouldMaskForDisplay(string $text): bool
    {
        $r = $this->moderate($text);

        return $r['should_mask_after_save'] === true;
    }

    public function maskPlaceholder(): string
    {
        return (string) config('chat_moderation.mask_placeholder');
    }

    public function adminBadgeLabel(): string
    {
        return (string) config('chat_moderation.admin_badge_label');
    }

    /**
     * @return array{text: string, show_filtered_badge: bool}
     */
    public function bodyTextForViewer(Message $message, int $viewerProfileId, bool $isAdminContext): array
    {
        $type = (string) ($message->message_type ?? Message::TYPE_TEXT);
        $raw = (string) ($message->body_text ?? '');

        if ($type === Message::TYPE_IMAGE) {
            $masked = $raw !== '' && $this->shouldMaskForDisplay($raw);

            if ($isAdminContext) {
                return [
                    'text' => $raw !== '' ? $raw : '',
                    'show_filtered_badge' => $masked,
                ];
            }

            $isSender = (int) $message->sender_profile_id === (int) $viewerProfileId;
            if ($isSender || ! $masked) {
                return ['text' => $raw, 'show_filtered_badge' => false];
            }

            return ['text' => $this->maskPlaceholder(), 'show_filtered_badge' => false];
        }

        if ($type !== Message::TYPE_TEXT) {
            return ['text' => $raw, 'show_filtered_badge' => false];
        }

        $masked = $this->shouldMaskForDisplay($raw);

        if ($isAdminContext) {
            return [
                'text' => $raw,
                'show_filtered_badge' => $masked,
            ];
        }

        $isSender = (int) $message->sender_profile_id === (int) $viewerProfileId;
        if ($isSender) {
            return ['text' => $raw, 'show_filtered_badge' => false];
        }

        if ($masked) {
            return ['text' => $this->maskPlaceholder(), 'show_filtered_badge' => false];
        }

        return ['text' => $raw, 'show_filtered_badge' => false];
    }

    public function normalizeForCheck(string $text): string
    {
        $t = mb_strtolower(trim($text));
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($t, \Normalizer::FORM_C);
            if (is_string($n)) {
                $t = $n;
            }
        }

        $t = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = preg_replace('/([!?\.])\1{2,}/u', '$1', $t) ?? $t;

        return trim((string) $t);
    }

    protected function compactLatinLetters(string $normalized): string
    {
        $only = preg_replace('/[^\p{Latin}\s]/u', '', $normalized) ?? '';
        $only = preg_replace('/\s+/u', '', $only) ?? '';

        return mb_strtolower((string) $only);
    }

    protected function patternMatches(string $pattern, string $normalized, string $compactLatin): bool
    {
        try {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
            if ($compactLatin !== '' && preg_match($pattern, $compactLatin) === 1) {
                return true;
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
