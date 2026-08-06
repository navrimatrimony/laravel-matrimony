<?php

namespace App\Services\Showcase;

use App\Models\AdminSetting;
use App\Models\Interest;
use App\Services\Interest\InterestActionService;
use App\Services\RuleEngineService;

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
        private readonly RuleEngineService $ruleEngine,
        private readonly InterestActionService $interestActions,
    ) {}

    /**
     * @return array{accepted: int, rejected: int, skipped: int}
     */
    public function processPending(int $limit = 150): array
    {
        if (! app(\App\Services\FeatureFlagService::class)->isEnabled(\App\Support\FeatureFlagKey::SHOWCASE_PROFILES)) {
            return ['accepted' => 0, 'rejected' => 0, 'skipped' => 0];
        }

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
            if (! $receiver || ! $this->ruleEngine->passesInterestMandatoryCore($receiver)) {
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

    /**
     * Side effects are the shared ones ({@see InterestActionService}) so an auto-accept produces
     * exactly the rows and notifications a member accept does. The guards above stay here — this
     * path deliberately acts for a showcase profile that cannot log in.
     */
    private function applyAccept(Interest $interest, \App\Models\MatrimonyProfile $receiverProfile): void
    {
        $this->interestActions->applyAcceptEffects($interest, $receiverProfile, $receiverProfile->user);
    }

    private function applyReject(Interest $interest, \App\Models\MatrimonyProfile $receiverProfile): void
    {
        $this->interestActions->applyRejectEffects($interest, $receiverProfile, $receiverProfile->user);
    }
}
