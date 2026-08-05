<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * A Suchak closing their own business account.
 *
 * This orchestrates; it decides nothing. Three services already do the work and
 * this class only puts them in the right order:
 *
 * - {@see SuchakAccountLifecycleService::archive()} flips the account to
 *   archived/hidden, which is what actually protects candidates: every
 *   representation stops satisfying
 *   {@see SuchakProfileRepresentation::scopePubliclyRoutable()}, so
 *   {@see \App\Support\Suchak\SuchakContactRouting::isRouted()} turns false and
 *   contact reveal falls back to each candidate's own visibility settings.
 *   "Profile stays visible, contact is blocked" is that fallback, not code
 *   written here.
 * - {@see \App\Services\Account\MemberAccountDeletionService::dueForPurge()}
 *   already selects EVERY user carrying `deletion_requested_at`, and a Suchak
 *   account is owned by a user — so setting that one column enrols the Suchak
 *   in the sweep that already runs daily. No second scheduler.
 * - {@see \App\Services\Maintenance\UserAccountDatabasePurger} does the erase on
 *   day 31 in tombstone mode, which ends in an UPDATE rather than a delete and
 *   therefore never reaches the restrictOnDelete foreign key from
 *   `suchak_accounts.user_id`.
 *
 * Deliberately NOT calling MemberAccountDeletionService::requestDeletion():
 * that archives the actor's own matrimony profile, which is a different thing
 * from closing a Suchak business account. A Suchak who is also a member keeps
 * their member profile until they delete that separately.
 */
final class SuchakAccountDeletionService
{
    public function __construct(
        private readonly SuchakAccountLifecycleService $lifecycle,
    ) {}

    /**
     * @return array{archived: bool, representations_revoked: int}
     *
     * @throws InvalidArgumentException when the account is in a state archive() refuses
     */
    public function requestDeletion(
        SuchakAccount $account,
        User $actor,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $account->refresh();

        // Idempotent: a second tap must not restart the 30-day clock, or a
        // Suchak could keep buying themselves another month by accident.
        if ($actor->deletion_requested_at !== null) {
            return [
                'archived' => $account->verification_status === SuchakAccount::VERIFICATION_ARCHIVED,
                'representations_revoked' => 0,
            ];
        }

        // archive() enforces its own precondition (verified or suspended only)
        // and throws otherwise. Let that surface rather than restating the rule.
        $this->lifecycle->archive($account, $actor, $reason, $ipAddress, $userAgent);

        $revoked = DB::transaction(function () use ($account, $actor): int {
            $count = SuchakProfileRepresentation::query()
                ->where('suchak_account_id', $account->id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'representation_status' => SuchakProfileRepresentation::STATUS_REVOKED,
                    'updated_at' => now(),
                ]);

            $actor->forceFill(['deletion_requested_at' => now()])->save();

            return $count;
        });

        Log::info('suchak.account_deletion_requested', [
            'suchak_account_id' => $account->id,
            'user_id' => $actor->id,
            'representations_revoked' => $revoked,
        ]);

        return ['archived' => true, 'representations_revoked' => $revoked];
    }
}
