<?php

namespace App\Services\Account;

use App\Models\MatrimonyProfile;
use App\Models\SuchakDispute;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Notifications\DisputePartyDeletionRequestedNotification;
use App\Notifications\SuchakCustomerDeletionCancelledNotification;
use App\Notifications\SuchakCustomerDeletionRequestedNotification;
use App\Services\Maintenance\UserAccountDatabasePurger;
use App\Services\ProfileLifecycleService;
use App\Support\SafeNotifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one engine for a member removing themselves from the platform.
 *
 * Google Play requires any app that can create an account in-app to offer
 * account deletion in-app as well, plus a public web page describing it.
 *
 * Shape of the flow, decided with the product owner:
 *
 *   pause            profile archived, nothing scheduled, reversible any time
 *   request deletion profile archived AND a 30-day clock starts
 *   cancel           inside the 30 days, everything comes back untouched
 *   purge            on day 31, the erase runs and cannot be undone
 *
 * Nothing is erased before day 31. That is the whole point of the grace period:
 * a member who returns on day 20 must find their photos and conversations
 * exactly as they left them, so "hide now, erase later" is the only correct
 * reading of it.
 *
 * The erase itself is not implemented here — it is
 * {@see UserAccountDatabasePurger}, which already tears down profiles, intakes,
 * sessions, tokens and password resets. This class only decides WHEN it runs
 * and that it runs in tombstone mode, so the people the member talked to keep
 * their side of the conversation.
 */
final class MemberAccountDeletionService
{
    /** Days between the request and the irreversible erase. */
    public const GRACE_DAYS = 30;

    /**
     * Reasons offered as buttons. `other` is the only one that accepts a note.
     *
     * "Got married" is deliberately absent: that is not a deletion, it is
     * `archived_due_to_marriage`, a happy outcome the platform wants to record
     * rather than erase. Routing it here would destroy the success data.
     */
    public const REASONS = [
        'no_suitable_matches',
        'found_match_elsewhere',
        'too_many_messages',
        'privacy_concern',
        'hard_to_use',
        'other',
    ];

    /**
     * Starts the countdown and hides the profile the same moment.
     *
     * Idempotent: asking twice does not restart the clock, because a member who
     * taps twice must not silently buy themselves another 30 days.
     */
    public function requestDeletion(User $user, string $reasonKey, ?string $note = null): void
    {
        if (! in_array($reasonKey, self::REASONS, true)) {
            $reasonKey = 'other';
        }

        $noteValue = $reasonKey === 'other' ? $note : null;
        $requestedAt = now();
        $affected = 0;

        // RT-7: notify only when this atomic null→value flip wins exactly one row.
        DB::transaction(function () use ($user, $reasonKey, $noteValue, $requestedAt, &$affected): void {
            $affected = User::query()
                ->whereKey($user->id)
                ->whereNull('deletion_requested_at')
                ->whereNull('account_deleted_at')
                ->update([
                    'deletion_requested_at' => $requestedAt,
                    'deletion_reason_key' => $reasonKey,
                    'deletion_reason_note' => $noteValue,
                ]);

            if ($affected !== 1) {
                return;
            }

            $user->refresh();
            $this->hideProfile($user);
        });

        if ($affected !== 1) {
            return;
        }

        $user->refresh();

        Log::info('account.deletion_requested', [
            'user_id' => $user->id,
            'reason' => $reasonKey,
            'purge_after' => $this->purgeDueAt($user)?->toDateTimeString(),
        ]);

        $this->notifyRepresentingSuchaks(
            $user,
            fn (): SuchakCustomerDeletionRequestedNotification => new SuchakCustomerDeletionRequestedNotification(
                $this->customerDisplayName($user),
                $requestedAt->toDateString(),
            ),
        );

        $this->notifyAdminsIfOpenDisputeParty($user, $requestedAt->toDateString());
    }

    /**
     * Called when the member changes their mind inside the grace window.
     * Everything is still on disk, so this is a pure un-hide.
     */
    public function cancelDeletion(User $user): void
    {
        // A cancel racing the purge commit must not half-revive a tombstone:
        // once the erase has run there is nothing left to restore, and writing
        // to the shell would tell the member "cancelled" about an account that
        // no longer exists.
        if ($user->account_deleted_at !== null) {
            return;
        }

        $cancelledAt = now();
        $affected = 0;

        // RT-7: notify only when this atomic value→null flip wins exactly one row.
        DB::transaction(function () use ($user, &$affected): void {
            $affected = User::query()
                ->whereKey($user->id)
                ->whereNotNull('deletion_requested_at')
                ->whereNull('account_deleted_at')
                ->update([
                    'deletion_requested_at' => null,
                    'deletion_reason_key' => null,
                    'deletion_reason_note' => null,
                ]);

            if ($affected !== 1) {
                return;
            }

            $user->refresh();
            $this->restoreProfile($user);
        });

        if ($affected !== 1) {
            return;
        }

        $user->refresh();

        Log::info('account.deletion_cancelled', ['user_id' => $user->id]);

        $this->notifyRepresentingSuchaks(
            $user,
            fn (): SuchakCustomerDeletionCancelledNotification => new SuchakCustomerDeletionCancelledNotification(
                $this->customerDisplayName($user),
                $cancelledAt->toDateString(),
            ),
        );
    }

    /** Hide the profile with no deletion attached — the softer option offered first. */
    public function pause(User $user): void
    {
        $this->hideProfile($user);
    }

    public function resume(User $user): void
    {
        if ($user->deletion_requested_at !== null) {
            // Coming back from a pending deletion is a cancel, not a resume;
            // otherwise the profile would reappear while the clock kept running.
            $this->cancelDeletion($user);

            return;
        }

        $this->restoreProfile($user);
    }

    /**
     * @return array{state: string, requested_at: ?string, purge_due_at: ?string, days_left: ?int, reason_key: ?string}
     */
    public function status(User $user): array
    {
        $profile = $user->matrimonyProfile;
        $dueAt = $this->purgeDueAt($user);

        $state = match (true) {
            $user->deletion_requested_at !== null => 'deletion_pending',
            $profile !== null && $profile->lifecycle_state === 'archived' => 'paused',
            default => 'active',
        };

        return [
            'state' => $state,
            'requested_at' => $user->deletion_requested_at?->toIso8601String(),
            'purge_due_at' => $dueAt?->toIso8601String(),
            // Rounded up so the last partial day still reads as a day left,
            // never as zero while the account is in fact still recoverable.
            'days_left' => $dueAt ? max(0, (int) ceil(now()->diffInDays($dueAt, false))) : null,
            'reason_key' => $user->deletion_reason_key,
        ];
    }

    public function purgeDueAt(User $user): ?\Illuminate\Support\Carbon
    {
        return $user->deletion_requested_at?->copy()->addDays(self::GRACE_DAYS);
    }

    /**
     * Accounts whose grace period has run out.
     *
     * @return Collection<int, User>
     */
    public function dueForPurge(int $graceDays = self::GRACE_DAYS): Collection
    {
        return User::query()
            ->whereNotNull('deletion_requested_at')
            ->whereNull('account_deleted_at')
            ->where('deletion_requested_at', '<=', now()->subDays($graceDays))
            ->get();
    }

    /**
     * Runs the irreversible erase for every account past its window.
     *
     * One account failing must not strand the rest, so each is caught and logged
     * and the sweep continues — a member whose erase silently stopped would keep
     * their data forever without anyone noticing.
     *
     * @return array{purged: int, failed: int}
     */
    public function purgeDue(int $graceDays = self::GRACE_DAYS): array
    {
        $purged = 0;
        $failed = 0;

        foreach ($this->dueForPurge($graceDays) as $user) {
            try {
                // The list above was materialised before this iteration, so a
                // cancellation landing mid-sweep is invisible to the query. The
                // purge itself must therefore re-read under lock and stand down
                // if the member changed their mind — deadline day is exactly
                // when people cancel, and an erase after a successful cancel
                // would break the grace period's whole promise.
                $stillDue = DB::transaction(function () use ($user, $graceDays): bool {
                    $fresh = User::query()->whereKey($user->id)->lockForUpdate()->first();

                    if ($fresh === null
                        || $fresh->account_deleted_at !== null
                        || $fresh->deletion_requested_at === null
                        || $fresh->deletion_requested_at->gt(now()->subDays($graceDays))) {
                        return false;
                    }

                    UserAccountDatabasePurger::purgeUserAccount($fresh, keepCounterpartConversations: true);

                    return true;
                });

                if (! $stillDue) {
                    continue;
                }

                $purged++;
                Log::info('account.deletion_purged', ['user_id' => $user->id]);
            } catch (\Throwable $e) {
                $failed++;
                Log::error('account.deletion_purge_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['purged' => $purged, 'failed' => $failed];
    }

    private function hideProfile(User $user): void
    {
        $profile = $user->matrimonyProfile;
        if (! $profile instanceof MatrimonyProfile) {
            return;
        }

        if (ProfileLifecycleService::canTransitionTo($profile, 'archived')) {
            ProfileLifecycleService::transitionTo($profile, 'archived', $user);
        }
    }

    private function restoreProfile(User $user): void
    {
        $profile = $user->matrimonyProfile;
        if (! $profile instanceof MatrimonyProfile) {
            return;
        }

        if (ProfileLifecycleService::canTransitionTo($profile, 'active')) {
            ProfileLifecycleService::transitionTo($profile, 'active', $user);
        }
    }

    /**
     * Distinct Suchak users with valid consent to represent this member's profile (RT-6).
     *
     * @param  callable(): \Illuminate\Notifications\Notification  $makeNotification
     */
    private function notifyRepresentingSuchaks(User $member, callable $makeNotification): void
    {
        $profile = $member->matrimonyProfile;
        if (! $profile instanceof MatrimonyProfile) {
            return;
        }

        $suchakUserIds = SuchakProfileRepresentation::query()
            ->withValidConsent()
            ->where('matrimony_profile_id', (int) $profile->id)
            ->with('suchakAccount:id,user_id')
            ->get()
            ->map(static fn (SuchakProfileRepresentation $row): ?int => $row->suchakAccount?->user_id !== null
                ? (int) $row->suchakAccount->user_id
                : null)
            ->filter(static fn (?int $id): bool => $id !== null && $id > 0)
            ->unique()
            ->values();

        if ($suchakUserIds->isEmpty()) {
            return;
        }

        $receivers = User::query()->whereIn('id', $suchakUserIds->all())->get();
        foreach ($receivers as $receiver) {
            SafeNotifier::notify($receiver, $makeNotification());
        }
    }

    private function customerDisplayName(User $user): string
    {
        $name = trim((string) ($user->matrimonyProfile?->full_name ?? ''));

        return $name !== '' ? $name : 'Customer';
    }

    /**
     * U3 NOTIFY_ONLY: if this member's profile is on an open/under_review dispute,
     * tell each admin once. Dispute rows are never mutated.
     */
    private function notifyAdminsIfOpenDisputeParty(User $member, string $eventDate): void
    {
        $profile = $member->matrimonyProfile;
        if (! $profile instanceof MatrimonyProfile) {
            return;
        }

        $openCount = SuchakDispute::query()
            ->where('matrimony_profile_id', (int) $profile->id)
            ->whereIn('status', [
                SuchakDispute::STATUS_OPEN,
                SuchakDispute::STATUS_UNDER_REVIEW,
            ])
            ->count();

        if ($openCount < 1) {
            return;
        }

        $admins = User::query()
            ->where('is_admin', true)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        $name = $this->customerDisplayName($member);
        foreach ($admins as $admin) {
            SafeNotifier::notify(
                $admin,
                new DisputePartyDeletionRequestedNotification($name, $eventDate, $openCount),
            );
        }
    }
}
