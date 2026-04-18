<?php

namespace App\Services\Showcase;

use App\Models\AdminSetting;
use App\Models\Interest;
use App\Notifications\InterestAcceptedNotification;
use App\Notifications\InterestRejectedNotification;
use App\Services\AdminActivityNotificationGate;
use App\Services\ProfileCompletenessService;
use App\Support\SafeNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Responds to pending interests where a **real** member sent to a **showcase** receiver.
 * Showcase accounts typically cannot log in (@system.local random passwords), so interests
 * stay pending until this runs (Artisan + optional schedule).
 *
 * Admin: {@see ShowcaseInterestPolicyService::KEY_PREFIX}incoming_auto_respond_enabled, incoming_auto_accept_pct.
 */
class ShowcaseIncomingInterestResponderService
{
    public function __construct(
        private readonly ShowcaseInterestPolicyService $policy,
    ) {}

    /**
     * @return array{accepted: int, rejected: int, skipped: int}
     */
    public function processPending(int $limit = 150): array
    {
        if (! AdminSetting::getBool(ShowcaseInterestPolicyService::KEY_PREFIX.'incoming_auto_respond_enabled', false)) {
            return ['accepted' => 0, 'rejected' => 0, 'skipped' => 0];
        }

        $acceptPct = max(0, min(100, (int) AdminSetting::getValue(
            ShowcaseInterestPolicyService::KEY_PREFIX.'incoming_auto_accept_pct',
            '50'
        )));

        $rows = Interest::query()
            ->where('status', 'pending')
            ->whereHas('receiverProfile', fn ($q) => $q->whereShowcase())
            ->whereHas('senderProfile', fn ($q) => $q->whereNonShowcase())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $accepted = 0;
        $rejected = 0;
        $skipped = 0;

        foreach ($rows as $interest) {
            $interest->loadMissing('senderProfile', 'receiverProfile');
            $receiver = $interest->receiverProfile;
            if (! $receiver || ! ProfileCompletenessService::meetsInterestCompletenessRequirement($receiver)) {
                $skipped++;

                continue;
            }

            if ($this->policy->validateAcceptInterest($receiver, $interest, true) !== null) {
                $skipped++;

                continue;
            }

            $roll = random_int(1, 100);
            if ($roll <= $acceptPct) {
                $this->applyAccept($interest, $receiver);
                $accepted++;
            } else {
                if ($this->policy->validateRejectInterest($receiver, $interest, true) !== null) {
                    $skipped++;

                    continue;
                }
                $this->applyReject($interest, $receiver);
                $rejected++;
            }
        }

        return ['accepted' => $accepted, 'rejected' => $rejected, 'skipped' => $skipped];
    }

    private function applyAccept(Interest $interest, \App\Models\MatrimonyProfile $receiverProfile): void
    {
        $interest->update(['status' => 'accepted']);

        $senderProfile = $interest->senderProfile;
        if ($senderProfile && $receiverProfile->contact_unlock_mode === 'after_interest_accepted') {
            DB::table('profile_contact_visibility')->insertOrIgnore([
                'owner_profile_id' => $receiverProfile->id,
                'viewer_profile_id' => $senderProfile->id,
                'granted_via' => 'interest_accept',
                'granted_at' => now(),
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('contact_access_log')->insert([
                'owner_profile_id' => $receiverProfile->id,
                'viewer_profile_id' => $senderProfile->id,
                'source' => 'interest',
                'unlocked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $actorUser = $receiverProfile->user;
        $senderOwner = $senderProfile?->user;
        if ($senderOwner && $actorUser && AdminActivityNotificationGate::allowsPeerActivityNotification($actorUser)) {
            SafeNotifier::notify($senderOwner, new InterestAcceptedNotification($receiverProfile));
        }
    }

    private function applyReject(Interest $interest, \App\Models\MatrimonyProfile $receiverProfile): void
    {
        $interest->update(['status' => 'rejected']);

        $actorUser = $receiverProfile->user;
        $senderOwner = $interest->senderProfile?->user;
        if ($senderOwner && $actorUser && AdminActivityNotificationGate::allowsPeerActivityNotification($actorUser)) {
            SafeNotifier::notify($senderOwner, new InterestRejectedNotification($receiverProfile));
        }
    }
}
