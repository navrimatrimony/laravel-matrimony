<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakSubscription;
use Carbon\CarbonInterface;

class SuchakPaymentStatusService
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING_ADMIN_REVIEW = 'pending_admin_review';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NONE = 'none';

    public function activeSubscriptionFor(SuchakAccount $account, ?CarbonInterface $at = null): ?SuchakSubscription
    {
        $at ??= now();

        return SuchakSubscription::query()
            ->where('suchak_account_id', $account->id)
            ->activeAt($at)
            ->whereHas('suchakPlan', fn ($query) => $query->where('is_active', true))
            ->with(['suchakPlan.enabledFeatures'])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    public function latestSubscriptionFor(SuchakAccount $account): ?SuchakSubscription
    {
        return SuchakSubscription::query()
            ->where('suchak_account_id', $account->id)
            ->with(['suchakPlan.enabledFeatures'])
            ->latest('id')
            ->first();
    }

    public function hasActiveSubscription(SuchakAccount $account, ?CarbonInterface $at = null): bool
    {
        return $this->activeSubscriptionFor($account, $at) !== null;
    }

    /**
     * @return array{status: string, has_active_subscription: bool, subscription: SuchakSubscription|null}
     */
    public function statusFor(SuchakAccount $account, ?CarbonInterface $at = null): array
    {
        $active = $this->activeSubscriptionFor($account, $at);
        if ($active !== null) {
            return [
                'status' => self::STATUS_ACTIVE,
                'has_active_subscription' => true,
                'subscription' => $active,
            ];
        }

        $latest = $this->latestSubscriptionFor($account);

        return [
            'status' => $latest?->status ?? self::STATUS_NONE,
            'has_active_subscription' => false,
            'subscription' => $latest,
        ];
    }
}
