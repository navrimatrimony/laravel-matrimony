<?php

namespace App\Services\Showcase;

use App\Models\AdminSetting;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use Carbon\Carbon;

/**
 * Central rules for showcase ↔ real (and showcase ↔ showcase) interest flows.
 *
 * Admin keys (stored in {@see \App\Models\AdminSetting}, prefix {@see self::KEY_PREFIX}):
 * - rules_enabled — master toggle for direction caps / accept / withdraw rules (not bypass).
 * - bypass_plan_send_quota_for_showcase_sender — skip plan daily send quota for showcase senders.
 * - allow_showcase_to_real, allow_real_to_showcase, allow_showcase_to_showcase_send
 * - require_opposite_gender_when_any_showcase
 * - showcase_sender_min_seconds_between_sends, showcase_sender_max_sends_per_24h, showcase_sender_max_sends_per_7d
 * - real_sender_max_to_showcase_per_24h
 * - allow_real_receiver_accept_from_showcase_sender, allow_showcase_receiver_accept_from_real_sender, allow_accept_when_both_showcase
 * - allow_showcase_sender_withdraw, allow_real_sender_withdraw_to_showcase
 * - stochastic_gates_enabled + prob_* (send only if sender is showcase; accept/reject only if acting receiver is showcase — real members never random-blocked on real→showcase send)
 * - weight_age, weight_religion, weight_caste, weight_district + age_match_max_year_diff + scale_prob_by_match_weight
 * - showcase_sender_max_distinct_receivers_24h (0 = off) — limits how many different receivers a showcase sender can target per 24h
 */
class ShowcaseInterestPolicyService
{
    public const KEY_PREFIX = 'showcase_interest_';

    /**
     * @return array{score: float, max: float, ratio: float}
     */
    public function matchWeightBreakdown(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $wAge = max(0, (int) AdminSetting::getValue(self::KEY_PREFIX.'weight_age', '25'));
        $wRel = max(0, (int) AdminSetting::getValue(self::KEY_PREFIX.'weight_religion', '25'));
        $wCas = max(0, (int) AdminSetting::getValue(self::KEY_PREFIX.'weight_caste', '25'));
        $wDist = max(0, (int) AdminSetting::getValue(self::KEY_PREFIX.'weight_district', '25'));
        $max = (float) ($wAge + $wRel + $wCas + $wDist);
        if ($max <= 0.0) {
            return ['score' => 0.0, 'max' => 1.0, 'ratio' => 1.0];
        }

        $score = 0.0;
        if ($this->profilesAgeWithinGap($a, $b)) {
            $score += (float) $wAge;
        }
        if ($a->religion_id !== null && (int) $a->religion_id === (int) $b->religion_id) {
            $score += (float) $wRel;
        }
        if ($a->caste_id !== null && (int) $a->caste_id === (int) $b->caste_id) {
            $score += (float) $wCas;
        }
        if ($a->native_district_id !== null && (int) $a->native_district_id === (int) $b->native_district_id) {
            $score += (float) $wDist;
        }

        $ratio = $score / $max;

        return ['score' => $score, 'max' => $max, 'ratio' => $ratio];
    }

    /**
     * @return array{ok: bool, bypass_plan_quota: bool, message: ?string}
     */
    public function evaluateSendInterest(MatrimonyProfile $sender, MatrimonyProfile $receiver): array
    {
        $bypassPlan = $this->shouldBypassPlanSendQuota($sender);

        if (! AdminSetting::getBool(self::KEY_PREFIX.'rules_enabled', false)) {
            if ($msg = $this->maybeDenyByStochastic('send', $sender, $receiver)) {
                return $this->deny($msg, $bypassPlan);
            }

            return ['ok' => true, 'bypass_plan_quota' => $bypassPlan, 'message' => null];
        }

        $senderShowcase = $sender->isShowcaseProfile();
        $receiverShowcase = $receiver->isShowcaseProfile();

        if ($senderShowcase && $receiverShowcase) {
            if (! AdminSetting::getBool(self::KEY_PREFIX.'allow_showcase_to_showcase_send', true)) {
                return $this->deny(__('interest.showcase_policy_showcase_to_showcase_blocked'), $bypassPlan);
            }
        } elseif ($senderShowcase && ! $receiverShowcase) {
            if (! AdminSetting::getBool(self::KEY_PREFIX.'allow_showcase_to_real', true)) {
                return $this->deny(__('interest.showcase_policy_showcase_to_real_blocked'), $bypassPlan);
            }
        } elseif (! $senderShowcase && $receiverShowcase) {
            if (! AdminSetting::getBool(self::KEY_PREFIX.'allow_real_to_showcase', true)) {
                return $this->deny(__('interest.showcase_policy_real_to_showcase_blocked'), $bypassPlan);
            }
        }

        if (AdminSetting::getBool(self::KEY_PREFIX.'require_opposite_gender_when_any_showcase', true)
            && ($senderShowcase || $receiverShowcase)
            && ! $this->profilesAreOppositeGender($sender, $receiver)
        ) {
            return $this->deny(__('interest.showcase_policy_opposite_gender_required'), $bypassPlan);
        }

        if ($senderShowcase) {
            $cooldownSec = (int) AdminSetting::getValue(self::KEY_PREFIX.'showcase_sender_min_seconds_between_sends', '0');
            if ($cooldownSec > 0) {
                $lastAt = Interest::query()
                    ->where('sender_profile_id', $sender->id)
                    ->max('created_at');
                if ($lastAt !== null) {
                    $last = Carbon::parse($lastAt);
                    if ($last->diffInSeconds(now()) < $cooldownSec) {
                        return $this->deny(__('interest.showcase_policy_showcase_sender_cooldown'), $bypassPlan);
                    }
                }
            }

            $dayCap = (int) AdminSetting::getValue(self::KEY_PREFIX.'showcase_sender_max_sends_per_24h', '0');
            if ($dayCap > 0) {
                $count = Interest::query()
                    ->where('sender_profile_id', $sender->id)
                    ->where('created_at', '>=', now()->subDay())
                    ->count();
                if ($count >= $dayCap) {
                    return $this->deny(__('interest.showcase_policy_showcase_daily_cap'), $bypassPlan);
                }
            }

            $weekCap = (int) AdminSetting::getValue(self::KEY_PREFIX.'showcase_sender_max_sends_per_7d', '0');
            if ($weekCap > 0) {
                $count = Interest::query()
                    ->where('sender_profile_id', $sender->id)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count();
                if ($count >= $weekCap) {
                    return $this->deny(__('interest.showcase_policy_showcase_weekly_cap'), $bypassPlan);
                }
            }
        }

        if (! $senderShowcase && $receiverShowcase) {
            $cap = (int) AdminSetting::getValue(self::KEY_PREFIX.'real_sender_max_to_showcase_per_24h', '0');
            if ($cap > 0) {
                $count = Interest::query()
                    ->where('sender_profile_id', $sender->id)
                    ->where('created_at', '>=', now()->subDay())
                    ->whereHas('receiverProfile', fn ($q) => $q->whereShowcase())
                    ->count();
                if ($count >= $cap) {
                    return $this->deny(__('interest.showcase_policy_real_to_showcase_daily_cap'), $bypassPlan);
                }
            }
        }

        if ($senderShowcase) {
            $distinctCap = (int) AdminSetting::getValue(self::KEY_PREFIX.'showcase_sender_max_distinct_receivers_24h', '0');
            if ($distinctCap > 0) {
                $receiverIds = Interest::query()
                    ->where('sender_profile_id', $sender->id)
                    ->where('created_at', '>=', now()->subDay())
                    ->pluck('receiver_profile_id')
                    ->unique()
                    ->values();
                $already = $receiverIds->contains(fn ($id) => (int) $id === (int) $receiver->id);
                if (! $already && $receiverIds->count() >= $distinctCap) {
                    return $this->deny(__('interest.showcase_policy_showcase_distinct_receivers_cap'), $bypassPlan);
                }
            }
        }

        if ($msg = $this->maybeDenyByStochastic('send', $sender, $receiver)) {
            return $this->deny($msg, $bypassPlan);
        }

        return ['ok' => true, 'bypass_plan_quota' => $bypassPlan, 'message' => null];
    }

    /**
     * Receiver profile = logged-in user who is accepting (must match interest receiver).
     */
    public function validateAcceptInterest(MatrimonyProfile $receiver, Interest $interest, bool $ignoreStochastic = false): ?string
    {
        $interest->loadMissing('senderProfile', 'receiverProfile');

        if ((int) $interest->receiver_profile_id !== (int) $receiver->id) {
            return null;
        }

        $sender = $interest->senderProfile;
        if (! $sender) {
            return null;
        }

        if (! AdminSetting::getBool(self::KEY_PREFIX.'rules_enabled', false)) {
            if ($ignoreStochastic) {
                return null;
            }

            return $this->maybeDenyByStochastic('accept', $sender, $receiver);
        }

        $senderShowcase = $sender->isShowcaseProfile();
        $receiverShowcase = $receiver->isShowcaseProfile();

        if ($senderShowcase && $receiverShowcase) {
            if (! AdminSetting::getBool(self::KEY_PREFIX.'allow_accept_when_both_showcase', true)) {
                return __('interest.showcase_policy_accept_both_showcase_blocked');
            }
        } elseif ($senderShowcase && ! $receiverShowcase) {
            if (! AdminSetting::getBool(self::KEY_PREFIX.'allow_real_receiver_accept_from_showcase_sender', true)) {
                return __('interest.showcase_policy_real_cannot_accept_from_showcase');
            }
        } elseif (! $senderShowcase && $receiverShowcase) {
            if (! AdminSetting::getBool(self::KEY_PREFIX.'allow_showcase_receiver_accept_from_real_sender', true)) {
                return __('interest.showcase_policy_showcase_cannot_accept_from_real');
            }
        }

        if (! $ignoreStochastic && ($msg = $this->maybeDenyByStochastic('accept', $sender, $receiver)) !== null) {
            return $msg;
        }

        return null;
    }

    public function validateRejectInterest(MatrimonyProfile $receiver, Interest $interest, bool $ignoreStochastic = false): ?string
    {
        $interest->loadMissing('senderProfile', 'receiverProfile');

        if ((int) $interest->receiver_profile_id !== (int) $receiver->id) {
            return null;
        }

        $sender = $interest->senderProfile;
        if (! $sender) {
            return null;
        }

        if ($ignoreStochastic) {
            return null;
        }

        return $this->maybeDenyByStochastic('reject', $sender, $receiver);
    }

    public function validateWithdrawInterest(MatrimonyProfile $withdrawerSender, Interest $interest): ?string
    {
        $interest->loadMissing('receiverProfile');

        if (! AdminSetting::getBool(self::KEY_PREFIX.'rules_enabled', false)) {
            return null;
        }

        if ((int) $interest->sender_profile_id !== (int) $withdrawerSender->id) {
            return null;
        }

        $receiver = $interest->receiverProfile;
        if (! $receiver) {
            return null;
        }

        $senderShowcase = $withdrawerSender->isShowcaseProfile();
        $receiverShowcase = $receiver->isShowcaseProfile();

        if ($senderShowcase && ! AdminSetting::getBool(self::KEY_PREFIX.'allow_showcase_sender_withdraw', true)) {
            return __('interest.showcase_policy_showcase_sender_withdraw_blocked');
        }

        if (! $senderShowcase && $receiverShowcase
            && ! AdminSetting::getBool(self::KEY_PREFIX.'allow_real_sender_withdraw_to_showcase', true)) {
            return __('interest.showcase_policy_real_sender_withdraw_to_showcase_blocked');
        }

        return null;
    }

    public function shouldBypassPlanSendQuota(MatrimonyProfile $sender): bool
    {
        return $sender->isShowcaseProfile()
            && AdminSetting::getBool(self::KEY_PREFIX.'bypass_plan_send_quota_for_showcase_sender', false);
    }

    /**
     * @return array{ok: false, bypass_plan_quota: bool, message: string}|array{ok: true, bypass_plan_quota: bool, message: null}
     */
    private function deny(string $message, bool $bypassPlan): array
    {
        return ['ok' => false, 'bypass_plan_quota' => $bypassPlan, 'message' => $message];
    }

    private function profilesAreOppositeGender(MatrimonyProfile $a, MatrimonyProfile $b): bool
    {
        $ga = $a->gender_id;
        $gb = $b->gender_id;
        if ($ga === null || $gb === null) {
            return true;
        }

        return (int) $ga !== (int) $gb;
    }

    /**
     * @param  'send'|'accept'|'reject'  $kind
     */
    private function maybeDenyByStochastic(string $kind, ?MatrimonyProfile $a, ?MatrimonyProfile $b): ?string
    {
        if (! $a || ! $b) {
            return null;
        }

        if (! AdminSetting::getBool(self::KEY_PREFIX.'stochastic_gates_enabled', false)) {
            return null;
        }

        /*
         * Stochastic gates are meant to throttle/simulate *showcase-driven* behaviour — not to block real members
         * from contacting showcase profiles or from accepting/rejecting normally.
         *
         * - send: only when the **sender** is showcase (showcase → anyone).
         * - accept / reject: only when the **receiver** (logged-in actor) is showcase.
         */
        $applies = match ($kind) {
            'send' => $a->isShowcaseProfile(),
            'accept', 'reject' => $b->isShowcaseProfile(),
        };
        if (! $applies) {
            return null;
        }

        $baseKey = match ($kind) {
            'send' => 'prob_send_pct',
            'accept' => 'prob_accept_pct',
            'reject' => 'prob_reject_pct',
        };
        $base = max(0, min(100, (int) AdminSetting::getValue(self::KEY_PREFIX.$baseKey, '100')));

        $scale = AdminSetting::getBool(self::KEY_PREFIX.'scale_prob_by_match_weight', true);
        $breakdown = $this->matchWeightBreakdown($a, $b);
        $ratio = $scale ? $breakdown['ratio'] : 1.0;
        $effective = (int) max(0, min(100, (int) round($base * $ratio)));

        if ($effective <= 0) {
            return match ($kind) {
                'send' => __('interest.showcase_policy_stochastic_send_denied'),
                'accept' => __('interest.showcase_policy_stochastic_accept_denied'),
                'reject' => __('interest.showcase_policy_stochastic_reject_denied'),
            };
        }

        $roll = random_int(1, 100);
        if ($roll > $effective) {
            return match ($kind) {
                'send' => __('interest.showcase_policy_stochastic_send_denied'),
                'accept' => __('interest.showcase_policy_stochastic_accept_denied'),
                'reject' => __('interest.showcase_policy_stochastic_reject_denied'),
            };
        }

        return null;
    }

    private function profilesAgeWithinGap(MatrimonyProfile $a, MatrimonyProfile $b): bool
    {
        if (! $a->date_of_birth || ! $b->date_of_birth) {
            return false;
        }

        $gapYears = max(0, (int) AdminSetting::getValue(self::KEY_PREFIX.'age_match_max_year_diff', '5'));

        try {
            $ca = Carbon::parse($a->date_of_birth);
            $cb = Carbon::parse($b->date_of_birth);
            $diff = abs($ca->age - $cb->age);
        } catch (\Throwable) {
            return false;
        }

        return $diff <= $gapYears;
    }
}
