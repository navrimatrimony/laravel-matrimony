<?php

namespace App\Services\Showcase;

use App\Models\AdminSetting;
use App\Models\Block;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Notifications\InterestSentNotification;
use App\Services\AdminActivityNotificationGate;
use App\Services\InterestPriorityService;
use App\Services\InterestSendLimitService;
use App\Services\ProfileCompletenessService;
use App\Services\ProfileLifecycleService;

/**
 * Automated showcase -> real interest sender.
 *
 * This service uses existing policy + lifecycle/completeness guards and never creates duplicate
 * sender/receiver pairs (firstOrCreate).
 */
class ShowcaseOutgoingInterestSenderService
{
    public function __construct(
        private readonly ShowcaseInterestPolicyService $policy,
        private readonly InterestPriorityService $priority,
        private readonly InterestSendLimitService $sendLimit,
    ) {}

    /**
     * @return array{created:int, skipped:int}
     */
    public function run(int $batch = 50): array
    {
        if (! AdminSetting::getBool(ShowcaseInterestPolicyService::KEY_PREFIX.'outgoing_auto_send_enabled', false)) {
            return ['created' => 0, 'skipped' => 0];
        }

        $batch = max(1, min(2000, $batch));
        $perShowcase = max(1, min(20, (int) AdminSetting::getValue(
            ShowcaseInterestPolicyService::KEY_PREFIX.'outgoing_auto_max_sends_per_showcase_per_run',
            '1'
        )));
        $candidatePool = max(10, min(1000, (int) AdminSetting::getValue(
            ShowcaseInterestPolicyService::KEY_PREFIX.'outgoing_auto_candidate_pool',
            '120'
        )));

        $showcases = MatrimonyProfile::query()
            ->whereShowcase()
            ->where('lifecycle_state', 'active')
            ->where('is_suspended', false)
            ->with('user')
            ->limit($batch)
            ->get();

        $created = 0;
        $skipped = 0;

        foreach ($showcases as $showcase) {
            if (! ProfileLifecycleService::canInitiateInteraction($showcase) || ! ProfileCompletenessService::meetsThreshold($showcase)) {
                $skipped++;

                continue;
            }

            $sentForThisShowcase = 0;

            $candidates = MatrimonyProfile::query()
                ->whereNonShowcase()
                ->where('id', '<>', $showcase->id)
                ->where('lifecycle_state', 'active')
                ->where('is_suspended', false)
                ->inRandomOrder()
                ->limit($candidatePool)
                ->get();

            $weighted = $candidates
                ->map(function (MatrimonyProfile $candidate) use ($showcase) {
                    $bd = $this->policy->matchWeightBreakdown($showcase, $candidate);

                    return ['candidate' => $candidate, 'ratio' => $bd['ratio']];
                })
                ->sortByDesc('ratio')
                ->values();

            foreach ($weighted as $row) {
                if ($sentForThisShowcase >= $perShowcase) {
                    break;
                }

                /** @var MatrimonyProfile $receiver */
                $receiver = $row['candidate'];

                if (! ProfileLifecycleService::canReceiveInterest($receiver) || ! ProfileCompletenessService::meetsThreshold($receiver)) {
                    continue;
                }

                if (Block::query()->where('blocker_profile_id', $receiver->id)->where('blocked_profile_id', $showcase->id)->exists()) {
                    continue;
                }
                if (Block::query()->where('blocker_profile_id', $showcase->id)->where('blocked_profile_id', $receiver->id)->exists()) {
                    continue;
                }

                $eval = $this->policy->evaluateSendInterest($showcase, $receiver);
                if (! $eval['ok']) {
                    continue;
                }

                $actor = $showcase->user;
                if (! $actor) {
                    continue;
                }

                $already = Interest::query()
                    ->where('sender_profile_id', $showcase->id)
                    ->where('receiver_profile_id', $receiver->id)
                    ->exists();

                if (! $already && ! ($eval['bypass_plan_quota'] ?? false)) {
                    try {
                        $this->sendLimit->assertCanSend($actor);
                    } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                        continue;
                    }
                }

                $interest = Interest::query()->firstOrCreate(
                    [
                        'sender_profile_id' => $showcase->id,
                        'receiver_profile_id' => $receiver->id,
                    ],
                    [
                        'status' => 'pending',
                        'priority_score' => $this->priority->baseScoreForSender($actor),
                    ]
                );

                if (! $interest->wasRecentlyCreated) {
                    continue;
                }

                if (! ($eval['bypass_plan_quota'] ?? false)) {
                    $this->sendLimit->recordSuccessfulSend($actor);
                }

                $receiverOwner = $receiver->user;
                if ($receiverOwner && AdminActivityNotificationGate::allowsPeerActivityNotification($actor)) {
                    $receiverOwner->notify(new InterestSentNotification($showcase));
                }

                $created++;
                $sentForThisShowcase++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
