<?php

namespace App\Services\Push;

use App\Services\Chat\ChatTeaserPolicy;
use App\Services\WhoViewed\WhoViewedTeaserPresenter;

/**
 * "There is a person behind this push — what may it say about them?"
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 * ─────────────────────────────────────────────────────────────────────────────
 * The paywall rests on one idea: hide the identity, show the person. A blurred
 * photo, no name, but real attributes — enough curiosity to pay. The in-app card
 * has done that since the teaser presenter shipped; the push notification was
 * still saying "someone showed interest", which is the exact bland fallback the
 * teaser exists to replace.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IT NEVER RE-DERIVES PRIVACY
 * ─────────────────────────────────────────────────────────────────────────────
 * This service does NOT decide what may be revealed. It reads the teaser the
 * notification already stored — the output of {@see WhoViewedTeaserPresenter},
 * built under the admin's policy for that surface — and reflows those same
 * strings into one line. If the policy masked the name, the push carries the
 * masked name. If the policy resolved Renavi to the taluka "Khanapur", the push
 * says Khanapur. There is no second copy of the wording rules here, so the push
 * and the card can never disagree about what a member is allowed to see.
 *
 * Consequently:
 *   • locked row  → teaser-level detail only (identity-free by construction)
 *   • unlocked row → the real name, which that member's plan already revealed
 *   • neither     → null, and PushDispatchService falls back to the generic
 *                   reviewed line rather than emitting something half-built
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THE BODY IS ORDERED THE WAY IT IS
 * ─────────────────────────────────────────────────────────────────────────────
 * Android shows roughly 40–50 characters of the body while collapsed. The title
 * already says WHAT happened ("नवीन स्वारस्य"), so the body spends its whole
 * budget on WHO: headline (who / where), then the teaser lines (age, marital
 * status, occupation), then the match score last — it is the longest fragment
 * and the least load-bearing, so it is the right thing to lose to truncation.
 *
 * Deliberately NOT included: `viewed_summary` (relative time — Carbon localizes
 * it and it burns the collapsed budget on the least interesting fact),
 * `interest_hint` (a CTA the title already implies) and `accent_line` (repeat-view
 * accent, off for interests anyway).
 */
final class PushTeaserCopyService
{
    /**
     * Expanded notifications show far more than this; the cap only stops a very
     * chatty policy (occupation + education + marital status + match) from
     * producing a paragraph.
     */
    private const MAX_BODY_LENGTH = 140;

    private const MAX_CHAT_PREVIEW_LENGTH = 60;

    /**
     * Placeholder these payloads write when a profile has no usable name. It must
     * never reach a member: "Someone यांनी तुम्हाला संदेश पाठवला" reads worse than
     * the generic line it would replace.
     */
    private const NAME_PLACEHOLDER = 'Someone';

    /** Devanagari digits are FROZEN out of every user-facing string. */
    private const DEVANAGARI_DIGITS = [
        '०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4',
        '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9',
    ];

    /**
     * Push body for one notification, or null to keep the generic reviewed line.
     *
     * @param  array<string, mixed>  $data  the notification's stored `data` array
     */
    public function body(string $pushKey, array $data): ?string
    {
        $locked = ($data['revealed'] ?? true) === false;

        // 1. A stored teaser is the privacy decision, already made. Reflow it.
        $teaserLine = $this->teaserLine($data['teaser'] ?? null);
        if ($teaserLine !== null) {
            return $this->finish($teaserLine);
        }

        if ($locked) {
            // No teaser for this locked row. Only the locked-chat surface has its
            // own admin-owned copy to fall back to; everything else keeps the
            // generic line rather than inventing detail it was not given.
            return $pushKey === 'chat_message_locked'
                ? $this->finish($this->lockedChatLine())
                : null;
        }

        // 2. Unlocked: this member's plan already revealed the person, so the
        //    name is the strongest pull we are allowed to use.
        $name = $this->revealedName($data);
        if ($name === null) {
            return null;
        }

        return match ($pushKey) {
            'new_interest' => $this->finish(__('push.types.new_interest.body_named', ['name' => $name])),
            'interest_accepted' => $this->finish(__('push.types.interest_accepted.body_named', ['name' => $name])),
            'profile_viewed' => $this->finish(__('push.types.profile_viewed.body_named', ['name' => $name])),
            'new_chat_message' => $this->finish($this->chatMessageLine($name, $data)),
            default => null,
        };
    }

    /**
     * Strip Devanagari numerals from any push string.
     *
     * The teaser builds its numbers from PHP ints, so they are already Latin —
     * this is the guard that keeps them that way if a future locale file, Carbon
     * translation or admin-entered string smuggles in ०-९.
     */
    public function normalizeDigits(string $value): string
    {
        return strtr($value, self::DEVANAGARI_DIGITS);
    }

    /**
     * One line out of a teaser payload, or null when it carries nothing to say.
     *
     * @param  mixed  $teaser  expected to be a {@see WhoViewedTeaserPresenter} array
     */
    private function teaserLine(mixed $teaser): ?string
    {
        if (! is_array($teaser)) {
            return null;
        }

        // Narrowed through the same contract the mobile surfaces use, so a
        // malformed stored blob cannot put anything unexpected in a push.
        $teaser = WhoViewedTeaserPresenter::displayPayload($teaser);

        $parts = [];

        $headline = trim((string) ($teaser['headline'] ?? ''));
        if ($headline !== '') {
            $parts[] = $headline;
        }

        foreach ((array) ($teaser['lines'] ?? []) as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $parts[] = $line;
            }
        }

        if ($parts === []) {
            return null;
        }

        $body = implode(', ', $parts);

        $matchLine = trim((string) ($teaser['match_line'] ?? ''));
        if ($matchLine !== '') {
            $body .= ' — '.$matchLine;
        }

        return $body;
    }

    /**
     * Locked chat has its own admin dial ({@see ChatTeaserPolicy}) whose default
     * keeps the sender anonymous. Use ITS strings rather than a person teaser: the
     * admin who set "anonymous" there did not consent to attributes leaking out
     * through a push.
     */
    private function lockedChatLine(): string
    {
        $policy = ChatTeaserPolicy::normalized();

        $parts = array_filter([
            trim(ChatTeaserPolicy::lockedPreviewText($policy)),
            trim(ChatTeaserPolicy::lockedSubline($policy)),
        ], static fn (string $part): bool => $part !== '');

        return implode('. ', $parts);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function chatMessageLine(string $name, array $data): string
    {
        $preview = trim((string) ($data['message_preview'] ?? ''));
        if ($preview === '') {
            return (string) __('push.types.new_chat_message.body_named', ['name' => $name]);
        }

        if (mb_strlen($preview) > self::MAX_CHAT_PREVIEW_LENGTH) {
            $preview = rtrim(mb_substr($preview, 0, self::MAX_CHAT_PREVIEW_LENGTH - 1)).'…';
        }

        return (string) __('push.types.new_chat_message.body_named_preview', [
            'name' => $name,
            'preview' => $preview,
        ]);
    }

    /**
     * The actor's name, but ONLY from payloads that flagged themselves revealed.
     *
     * @param  array<string, mixed>  $data
     */
    private function revealedName(array $data): ?string
    {
        foreach (['sender_name', 'accepter_name', 'viewer_name'] as $key) {
            $name = trim((string) ($data[$key] ?? ''));
            if ($name !== '' && $name !== self::NAME_PLACEHOLDER) {
                return $name;
            }
        }

        return null;
    }

    private function finish(string $body): string
    {
        $body = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeDigits($body)));

        if ($body === '') {
            return '';
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            $body = rtrim(mb_substr($body, 0, self::MAX_BODY_LENGTH - 1)).'…';
        }

        return $body;
    }
}
