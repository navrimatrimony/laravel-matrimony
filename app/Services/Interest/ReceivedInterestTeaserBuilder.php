<?php

namespace App\Services\Interest;

use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Services\WhoViewed\WhoViewedTeaserPresenter;
use Illuminate\Support\Collection;

/**
 * The ONE place a received-interest inbox turns locked rows into teaser cards.
 *
 * Both surfaces call it — the web page ({@see \App\Http\Controllers\InterestController})
 * and the mobile list ({@see \App\Http\Controllers\Api\InterestApiController}) — so
 * "what does a member see when their plan has not revealed this sender?" has a
 * single answer. It reuses {@see WhoViewedTeaserPresenter} (the only teaser
 * presenter) and {@see ReceivedInterestTeaserPolicy} (the admin's privacy dial);
 * nothing here re-decides what may be shown.
 */
final class ReceivedInterestTeaserBuilder
{
    /**
     * Relations the presenter touches for a sender profile.
     *
     * Eager-load these for the whole page in ONE go. Loading only `gender` (which
     * is what the mobile list used to do) turns every locked row into a fistful of
     * lazy queries: user, occupation, marital status and location are all read
     * while building the card.
     *
     * @var list<string>
     */
    public const SENDER_PROFILE_EAGER_LOADS = [
        'senderProfile.user',
        'senderProfile.occupationMaster',
        'senderProfile.occupationCustom',
        'senderProfile.maritalStatus',
        'senderProfile.location',
        'senderProfile.gender',
    ];

    public function __construct(
        private readonly WhoViewedTeaserPresenter $presenter,
    ) {}

    /**
     * Teasers keyed by interest id — ONLY for rows the member's plan has locked.
     *
     * An unlocked row shows the real person, so it deliberately gets no entry: a
     * teaser next to a revealed profile is contradictory, and the caller must be
     * able to treat "has a teaser" as "is locked".
     *
     * Returns an empty map when the admin has switched rich teasers off, which is
     * what the web page has always done — the row then falls back to its plain
     * locked presentation rather than a half-built card.
     *
     * @param  Collection<int, Interest>  $interests
     * @param  array<int, bool>  $unlockById  interest id => revealed for this member
     * @param  array<string, mixed>|null  $normalizedPolicy  pass the caller's own
     *                                                       {@see ReceivedInterestTeaserPolicy::normalized()}
     *                                                       when it already read it
     * @param  bool  $displayShapeOnly  narrow to {@see WhoViewedTeaserPresenter::DISPLAY_KEYS} (mobile)
     * @return array<int, array<string, mixed>>
     */
    public function forLockedRows(
        Collection $interests,
        array $unlockById,
        ?MatrimonyProfile $receiverProfile,
        ?array $normalizedPolicy = null,
        bool $displayShapeOnly = false,
    ): array {
        if ($receiverProfile === null) {
            return [];
        }

        $normalized = $normalizedPolicy ?? ReceivedInterestTeaserPolicy::normalized();
        if (empty($normalized['rich_teaser_enabled'])) {
            return [];
        }

        $policy = ReceivedInterestTeaserPolicy::forLockedPresentation($normalized);
        $teasers = [];

        foreach ($interests as $interest) {
            if (($unlockById[$interest->id] ?? true) === true) {
                continue;
            }

            $senderProfile = $interest->senderProfile;
            if (! $senderProfile instanceof MatrimonyProfile) {
                continue;
            }

            $teaser = $this->presenter->presentFromMatrimonyProfile(
                $senderProfile,
                $interest->created_at,
                $policy,
                [
                    'owner_profile' => $receiverProfile,
                    'viewer_view_count' => 1,
                    'teaser_time_line' => 'interest_received',
                ],
            );

            $teasers[$interest->id] = $displayShapeOnly
                ? WhoViewedTeaserPresenter::displayPayload($teaser)
                : $teaser;
        }

        return $teasers;
    }
}
