<?php

namespace App\Services\WhoViewed;

use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Services\Interest\ReceivedInterestTeaserBuilder;
use App\Services\Interest\ReceivedInterestTeaserPolicy;
use App\Support\LatinDigits;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Renders a page of stored locked-teaser notifications in the READER's language.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 * ─────────────────────────────────────────────────────────────────────────────
 * A notification row's `message` is stored in BOTH languages (`message` +
 * `message_mr`) and picked per reader. Its `teaser` was stored in exactly ONE —
 * whichever locale happened to be active when the row was written. A
 * profile-view notification is written inside the VIEWER's request, so it
 * inherited the viewer's language: an English-preferring member viewing a
 * Marathi member's profile handed that Marathi member an English teaser card.
 * It flips both ways and is invisible until someone with the opposite language
 * triggers it. Confirmed on production (recipient user 174, a Marathi user,
 * whose card read "A woman · 33 years · Viewed 0 seconds ago").
 *
 * Storing a second copy would have matched the `message` convention, but the
 * teaser is not just words — it also carries a RELATIVE TIME, which freezes at
 * write time. Every locked card on production reads "Viewed 0 seconds ago"
 * forever, because that is genuinely what it was when the row was written.
 * Re-rendering fixes both defects with one mechanism and stops the row carrying
 * presentation at all.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE ENGINE ALREADY EXISTED — IT WAS JUST UNREACHABLE
 * ─────────────────────────────────────────────────────────────────────────────
 * `NotificationController` has re-rendered teasers at read time since it was
 * written. It never ran: it looked the actor up under `viewer_profile_id` /
 * `sender_profile_id`, and a LOCKED payload deliberately omits both (that is
 * the whole point of a locked row). So `$actor` was always null, the loop
 * always `continue`d, and the blade silently fell back to the frozen
 * wrong-language teaser. The fix is to record the actor under a key that is
 * unmistakably server-side — see {@see self::ACTOR_PROFILE_ID_KEY} — and to
 * make both surfaces share this one renderer instead of two inline loops.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OLD ROWS MUST NOT BREAK
 * ─────────────────────────────────────────────────────────────────────────────
 * Rows written before that key existed cannot be re-rendered — the actor is not
 * recoverable from them. They keep their stored teaser ({@see self::storedTeaser}),
 * which is exactly what they render today. A member's history does not go blank.
 */
final class NotificationTeaserRenderer
{
    /**
     * Where a locked notification records the profile its teaser was built from.
     *
     * Deliberately NOT `viewer_profile_id` / `sender_profile_id`. Those keys mean
     * "the actor this reader is allowed to see": `MobileNotificationApiController`
     * scans them to build a tappable `profile_id`, and the web blade scans them to
     * build a profile link. Writing the actor into one of them would hand every
     * locked row a working link to the person the paywall is hiding. This key is
     * in no scanner, so it cannot be surfaced by accident — it exists only so the
     * server can rebuild the same masked card it already built once.
     */
    public const ACTOR_PROFILE_ID_KEY = 'teaser_actor_profile_id';

    /** Types whose locked payload carries a teaser. */
    private const TEASER_TYPES = ['interest_sent', 'profile_viewed'];

    /**
     * The `senderProfile.` prefix on the canonical eager-load list, stripped here.
     *
     * @see self::profileEagerLoads()
     */
    private const SENDER_RELATION_PREFIX = 'senderProfile.';

    public function __construct(
        private readonly WhoViewedTeaserPresenter $presenter,
    ) {}

    /**
     * Freshly rendered teasers for a page, keyed by notification id.
     *
     * Absent key = could not be re-rendered; the caller falls back to
     * {@see self::storedTeaser}. Never throws on a malformed row.
     *
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return array<string, array<string, mixed>>
     */
    public function forPage(Collection $notifications, ?MatrimonyProfile $ownerProfile): array
    {
        if ($ownerProfile === null) {
            return [];
        }

        /** @var array<string, array{type: string, actor_id: int, notification: DatabaseNotification}> $targets */
        $targets = [];

        foreach ($notifications as $notification) {
            if (! $notification instanceof DatabaseNotification) {
                continue;
            }

            $data = is_array($notification->data) ? $notification->data : [];
            $type = (string) ($data['type'] ?? '');

            if (! $this->isLockedTeaserRow($data, $type)) {
                continue;
            }

            $actorId = (int) ($data[self::ACTOR_PROFILE_ID_KEY] ?? 0);
            if ($actorId <= 0) {
                // Written before the key existed — keep the stored teaser.
                continue;
            }

            $targets[(string) $notification->id] = [
                'type' => $type,
                'actor_id' => $actorId,
                'notification' => $notification,
            ];
        }

        if ($targets === []) {
            return [];
        }

        $actorIds = array_values(array_unique(array_column($targets, 'actor_id')));

        $profiles = MatrimonyProfile::query()
            ->with(self::profileEagerLoads())
            ->whereIn('id', $actorIds)
            ->get()
            ->keyBy('id');

        $viewCounts = $this->viewCountsFor($ownerProfile, $targets, $actorIds);

        // One AdminSetting read per policy per page, never per row.
        $policies = [];
        $rendered = [];

        foreach ($targets as $notificationId => $target) {
            $actor = $profiles->get($target['actor_id']);
            if (! $actor instanceof MatrimonyProfile) {
                continue;
            }

            $type = $target['type'];
            $policies[$type] ??= $this->policyFor($type);

            // A row that cannot be rebuilt must fall out of the map, not out of the
            // page. The caller reads "absent" as "use the stored teaser", so the
            // worst case for a malformed profile is the card a member sees today —
            // never an empty one, and never a 500 that takes the whole list with it.
            try {
                $teaser = $this->presenter->presentFromMatrimonyProfile(
                    $actor,
                    $target['notification']->created_at,
                    $policies[$type],
                    [
                        'owner_profile' => $ownerProfile,
                        'viewer_view_count' => $viewCounts[$target['actor_id']] ?? 1,
                        'teaser_time_line' => $type === 'interest_sent' ? 'interest_received' : 'profile_view',
                    ],
                );
            } catch (Throwable $e) {
                Log::warning('Notification teaser re-render failed; falling back to stored teaser.', [
                    'notification_id' => $notificationId,
                    'type' => $type,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $rendered[$notificationId] = WhoViewedTeaserPresenter::displayPayload($teaser);
        }

        return $rendered;
    }

    /**
     * The "Open who viewed me" / "Open received interests" line, in the READER's
     * language.
     *
     * Stored on the row as `teaser_context_label` and carrying exactly the teaser's
     * defect: written once, in the WRITER's locale, with no `_mr` twin to pick from.
     * Both languages exist as lang keys, so it is simply rebuilt — the stored string
     * is consulted only if the key itself has gone missing, which keeps a row that
     * predates the keys from rendering a bare button.
     *
     * @param  array<string, mixed>  $data
     */
    public function contextLabel(string $type, array $data = []): string
    {
        $key = $type === 'interest_sent'
            ? 'notifications.teaser_open_received_interests'
            : 'notifications.teaser_open_who_viewed';

        $label = trim((string) __($key));
        if ($label !== '' && $label !== $key) {
            return $label;
        }

        $stored = trim((string) ($data['teaser_context_label'] ?? ''));

        // A button with no words on it is worse than a button in the wrong language.
        return $stored !== '' ? $stored : trim((string) __('notifications.open'));
    }

    /**
     * The frozen teaser a row was written with, narrowed to the nine-key contract.
     *
     * The fallback for rows this renderer cannot rebuild. Digits are normalised
     * here too — an old row was written before that guard existed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function storedTeaser(array $data): ?array
    {
        $type = (string) ($data['type'] ?? '');
        if (! $this->isLockedTeaserRow($data, $type)) {
            return null;
        }

        $teaser = $data['teaser'] ?? null;
        if (! is_array($teaser)) {
            return null;
        }

        $display = WhoViewedTeaserPresenter::displayPayload($teaser);

        foreach ($display as $key => $value) {
            if (is_string($value)) {
                $display[$key] = LatinDigits::normalize($value);

                continue;
            }

            if ($key === 'lines' && is_array($value)) {
                $display[$key] = array_map(
                    static fn (string $line): string => LatinDigits::normalize($line),
                    $value,
                );
            }
        }

        return $display;
    }

    /**
     * Relations the presenter reads for ONE profile — eager-loaded for the whole page.
     *
     * Derived from {@see ReceivedInterestTeaserBuilder::SENDER_PROFILE_EAGER_LOADS}
     * rather than restated. That const is already the canonical answer to "what does
     * {@see WhoViewedTeaserPresenter} touch while building a card", written for a
     * query rooted at Interest; this query is rooted at MatrimonyProfile itself, so
     * only the relation prefix differs. One list, two roots — a second literal copy
     * would silently drift the day the presenter reads one more relation.
     *
     * @return list<string>
     */
    private static function profileEagerLoads(): array
    {
        return array_values(array_map(
            static fn (string $path): string => Str::after($path, self::SENDER_RELATION_PREFIX),
            ReceivedInterestTeaserBuilder::SENDER_PROFILE_EAGER_LOADS,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isLockedTeaserRow(array $data, string $type): bool
    {
        // A STORED teaser is still required, even though re-rendering no longer needs
        // one. It is what makes this strictly an upgrade of a card the member already
        // sees: a locked row that never got a teaser (owner profile missing at write
        // time) keeps rendering as the plain message it renders today, instead of
        // suddenly growing a teaser card with no CTA behind it.
        return ($data['revealed'] ?? true) === false
            && in_array($type, self::TEASER_TYPES, true)
            && is_array($data['teaser'] ?? null);
    }

    /**
     * How many times each viewer has viewed the owner's profile — ONE grouped
     * query for the page.
     *
     * The write path counted this per notification, and the web read path
     * hard-coded 1, which silently dropped the "viewed your profile 7 times"
     * accent line the moment re-rendering started working.
     *
     * @param  array<string, array{type: string, actor_id: int, notification: DatabaseNotification}>  $targets
     * @param  list<int>  $actorIds
     * @return array<int, int>
     */
    private function viewCountsFor(MatrimonyProfile $ownerProfile, array $targets, array $actorIds): array
    {
        $needsCount = [];
        foreach ($targets as $target) {
            if ($target['type'] === 'profile_viewed') {
                $needsCount[] = $target['actor_id'];
            }
        }

        $needsCount = array_values(array_intersect($actorIds, array_unique($needsCount)));
        if ($needsCount === []) {
            return [];
        }

        return ProfileView::query()
            ->where('viewed_profile_id', $ownerProfile->id)
            ->whereIn('viewer_profile_id', $needsCount)
            ->groupBy('viewer_profile_id')
            ->selectRaw('viewer_profile_id, COUNT(*) as views_total')
            ->pluck('views_total', 'viewer_profile_id')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function policyFor(string $type): array
    {
        return $type === 'interest_sent'
            ? ReceivedInterestTeaserPolicy::forLockedPresentation(ReceivedInterestTeaserPolicy::normalized())
            : WhoViewedTeaserPolicy::forWhoViewedLockedTeasers(WhoViewedTeaserPolicy::normalized());
    }
}
