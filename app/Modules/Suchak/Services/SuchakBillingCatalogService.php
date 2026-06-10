<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakPlan;
use App\Models\SuchakPlanFeature;
use App\Models\SuchakSubscription;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SuchakBillingCatalogService
{
    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
    ) {
    }

    /**
     * @return Collection<int, SuchakPlan>
     */
    public function visibleCatalogForSuchak(SuchakAccount $account, User $actor): Collection
    {
        $this->assertVerifiedOwner($account, $actor);

        return SuchakPlan::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->with(['enabledFeatures'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, SuchakPlan>
     */
    public function catalogForAdmin(User $admin): Collection
    {
        $this->assertAdmin($admin);

        return SuchakPlan::query()
            ->with(['features'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function assignManualSubscription(
        SuchakAccount $account,
        SuchakPlan $plan,
        User $admin,
        string $reason,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakSubscription {
        $this->assertAdmin($admin);
        $this->assertAssignablePlan($plan);

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Suchak billing assignment reason is required.');
        }

        $startsAt ??= now();
        if ($endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('Suchak subscription end date must be after start date.');
        }

        return DB::transaction(function () use ($account, $plan, $admin, $reason, $startsAt, $endsAt, $ipAddress, $userAgent): SuchakSubscription {
            $account->refresh();
            $plan->refresh();

            $cancelledCount = SuchakSubscription::query()
                ->where('suchak_account_id', $account->id)
                ->where('status', SuchakSubscription::STATUS_ACTIVE)
                ->update([
                    'status' => SuchakSubscription::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);

            $subscription = SuchakSubscription::query()->create([
                'suchak_account_id' => $account->id,
                'suchak_plan_id' => $plan->id,
                'assigned_by_user_id' => $admin->id,
                'status' => SuchakSubscription::STATUS_ACTIVE,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'assigned_at' => now(),
                'notes' => $reason,
            ]);

            $adminAuditLog = $this->writeAdminAuditLog($admin, $account, $subscription, $plan, $reason, $cancelledCount);

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $admin->id,
                'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
                'action_type' => SuchakActivityLog::ACTION_BILLING_LIMIT_CHANGED,
                'target_type' => 'suchak_subscription',
                'target_id' => $subscription->id,
                'admin_audit_log_id' => $adminAuditLog->id,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata_json' => [
                    'context' => 'suchak_manual_subscription_assigned',
                    'suchak_plan_id' => $plan->id,
                    'suchak_plan_slug' => $plan->slug,
                    'cancelled_previous_active_count' => $cancelledCount,
                    'has_ends_at' => $endsAt !== null,
                    'payment_execution' => false,
                ],
            ]);

            return $subscription->fresh(['suchakAccount', 'suchakPlan.enabledFeatures', 'assignedByUser']);
        });
    }

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

    /**
     * @return array<string, int|bool|string|null>
     */
    public function currentFeatureLimits(SuchakAccount $account, ?CarbonInterface $at = null): array
    {
        $subscription = $this->activeSubscriptionFor($account, $at);
        if ($subscription === null || $subscription->suchakPlan === null) {
            return [];
        }

        $limits = [];
        foreach ($subscription->suchakPlan->enabledFeatures as $feature) {
            if (! in_array($feature->feature_key, SuchakPlanFeature::FEATURE_KEYS, true)) {
                continue;
            }

            $limits[$feature->feature_key] = $feature->typedValue();
        }

        return $limits;
    }

    public function currentFeatureValue(SuchakAccount $account, string $featureKey, mixed $default = null): mixed
    {
        if (! in_array($featureKey, SuchakPlanFeature::FEATURE_KEYS, true)) {
            throw new InvalidArgumentException('Invalid Suchak billing feature key.');
        }

        $limits = $this->currentFeatureLimits($account);

        return array_key_exists($featureKey, $limits) ? $limits[$featureKey] : $default;
    }

    private function assertVerifiedOwner(SuchakAccount $account, User $actor): void
    {
        if ((int) $account->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the owning Suchak account can view Suchak billing catalog.');
        }

        if (! $account->isVerified()) {
            throw new InvalidArgumentException('Only verified Suchak accounts can view Suchak billing catalog.');
        }
    }

    private function assertAdmin(User $admin): void
    {
        if (! (bool) $admin->is_admin) {
            throw new InvalidArgumentException('Only admins can manage Suchak billing catalog foundation.');
        }
    }

    private function assertAssignablePlan(SuchakPlan $plan): void
    {
        $plan->refresh();

        if (! $plan->is_active) {
            throw new InvalidArgumentException('Only active Suchak plans can be assigned.');
        }
    }

    private function writeAdminAuditLog(
        User $admin,
        SuchakAccount $account,
        SuchakSubscription $subscription,
        SuchakPlan $plan,
        string $reason,
        int $cancelledCount,
    ): AdminAuditLog {
        return AuditLogService::log(
            $admin,
            'suchak_billing_subscription_assigned',
            'SuchakSubscription',
            $subscription->id,
            $reason.' | suchak_account_id='.(int) $account->id.' | suchak_plan_id='.(int) $plan->id.' | cancelled_previous_active_count='.$cancelledCount,
            false,
        );
    }
}
