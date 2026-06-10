<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPlan;
use App\Models\SuchakPlanFeature;
use App\Models\SuchakProfileNote;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakSubscription;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakBillingCatalogService
{
    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakPaymentStatusService $paymentStatusService,
        private readonly SuchakEntitlementService $entitlementService,
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

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>  $features
     */
    public function createPlan(
        User $admin,
        array $attributes,
        array $features,
        string $reason,
    ): SuchakPlan {
        return $this->persistPlan(null, $admin, $attributes, $features, $reason);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>  $features
     */
    public function updatePlan(
        SuchakPlan $plan,
        User $admin,
        array $attributes,
        array $features,
        string $reason,
    ): SuchakPlan {
        return $this->persistPlan($plan, $admin, $attributes, $features, $reason);
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
        return $this->paymentStatusService->activeSubscriptionFor($account, $at);
    }

    /**
     * @return array<string, int|bool|string|null>
     */
    public function currentFeatureLimits(SuchakAccount $account, ?CarbonInterface $at = null): array
    {
        return $this->entitlementService->currentFeatureLimits($account, $at);
    }

    public function currentFeatureValue(SuchakAccount $account, string $featureKey, mixed $default = null): mixed
    {
        return $this->entitlementService->currentFeatureValue($account, $featureKey, $default);
    }

    /**
     * @return array<string, array{label: string, used: int|null, window: string|null}>
     */
    public function usageSummary(SuchakAccount $account, ?CarbonInterface $at = null): array
    {
        $at ??= now();

        return [
            SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT => [
                'label' => 'Active profile slots',
                'used' => SuchakProfileRepresentation::query()
                    ->where('suchak_account_id', $account->id)
                    ->whereIn('representation_status', [
                        SuchakProfileRepresentation::STATUS_PENDING,
                        SuchakProfileRepresentation::STATUS_CONSENT_PENDING,
                        SuchakProfileRepresentation::STATUS_ACTIVE,
                    ])
                    ->count(),
                'window' => 'current',
            ],
            SuchakPlanFeature::FEATURE_MONTHLY_UPLOAD_LIMIT => [
                'label' => 'Monthly biodata uploads',
                'used' => SuchakBiodataIntakeLink::query()
                    ->where('suchak_account_id', $account->id)
                    ->where('created_at', '>=', $at->copy()->startOfMonth())
                    ->count(),
                'window' => 'this month',
            ],
            SuchakPlanFeature::FEATURE_LEAD_REQUEST_LIMIT => [
                'label' => 'Open lead requests',
                'used' => SuchakProfileRequest::query()
                    ->where('selected_suchak_account_id', $account->id)
                    ->whereIn('request_status', SuchakProfileRequest::OPEN_STATUSES)
                    ->count(),
                'window' => 'open',
            ],
            SuchakPlanFeature::FEATURE_COLLABORATION_REQUEST_LIMIT => [
                'label' => 'Open collaboration requests',
                'used' => SuchakCollaborationRequest::query()
                    ->where('requesting_suchak_account_id', $account->id)
                    ->whereIn('status', SuchakCollaborationRequest::OPEN_STATUSES)
                    ->count(),
                'window' => 'open',
            ],
            SuchakPlanFeature::FEATURE_PDF_DOWNLOAD_SHARE_LIMIT => [
                'label' => 'PDF/QR exports',
                'used' => SuchakBiodataExport::query()
                    ->where('suchak_account_id', $account->id)
                    ->where('export_type', SuchakBiodataExport::TYPE_BIODATA_PDF)
                    ->where('created_at', '>=', $at->copy()->startOfDay())
                    ->count(),
                'window' => 'today',
            ],
            SuchakPlanFeature::FEATURE_LEDGER_FEATURES => [
                'label' => 'Ledger records',
                'used' => SuchakLedgerEntry::query()
                    ->where('suchak_account_id', $account->id)
                    ->count(),
                'window' => 'total',
            ],
            SuchakPlanFeature::FEATURE_CRM_FEATURES => [
                'label' => 'CRM notes',
                'used' => SuchakProfileNote::query()
                    ->where('suchak_account_id', $account->id)
                    ->count(),
                'window' => 'total',
            ],
            SuchakPlanFeature::FEATURE_PRIORITY_SUPPORT => [
                'label' => 'Priority support',
                'used' => null,
                'window' => null,
            ],
            SuchakPlanFeature::FEATURE_BULK_UPLOAD_ACCESS => [
                'label' => 'Bulk upload access',
                'used' => null,
                'window' => null,
            ],
        ];
    }

    private function assertVerifiedOwner(SuchakAccount $account, User $actor): void
    {
        $this->accessService->assertOwnerCanOperate(
            $account,
            $actor,
            'Only the owning Suchak account can view Suchak billing catalog.',
            'Only verified Suchak accounts can view Suchak billing catalog.',
        );
    }

    private function assertAdmin(User $admin): void
    {
        $this->accessService->assertAdmin($admin, 'Only admins can manage Suchak billing catalog foundation.');
    }

    private function assertAssignablePlan(SuchakPlan $plan): void
    {
        $plan->refresh();

        if (! $plan->is_active) {
            throw new InvalidArgumentException('Only active Suchak plans can be assigned.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>  $features
     */
    private function persistPlan(
        ?SuchakPlan $plan,
        User $admin,
        array $attributes,
        array $features,
        string $reason,
    ): SuchakPlan {
        $this->assertAdmin($admin);

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Suchak plan catalog change reason is required.');
        }

        $planAttributes = $this->normalizedPlanAttributes($attributes);
        $featurePayloads = $this->normalizedFeaturePayloads($features);
        $isCreate = $plan === null;

        return DB::transaction(function () use ($admin, $plan, $planAttributes, $featurePayloads, $reason, $isCreate): SuchakPlan {
            $plan ??= new SuchakPlan();
            $plan->fill($planAttributes);
            $plan->save();

            foreach ($featurePayloads as $featurePayload) {
                SuchakPlanFeature::query()->updateOrCreate(
                    [
                        'suchak_plan_id' => $plan->id,
                        'feature_key' => $featurePayload['feature_key'],
                    ],
                    [
                        'value_type' => $featurePayload['value_type'],
                        'feature_value' => $featurePayload['feature_value'],
                        'is_enabled' => $featurePayload['is_enabled'],
                    ],
                );
            }

            $this->writePlanAuditLog(
                $admin,
                $plan,
                $isCreate ? 'suchak_plan_catalog_created' : 'suchak_plan_catalog_updated',
                $reason,
                array_keys($featurePayloads),
            );

            return $plan->fresh(['features']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizedPlanAttributes(array $attributes): array
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Suchak plan name is required.');
        }

        $slug = Str::slug(trim((string) ($attributes['slug'] ?? $name)));
        if ($slug === '') {
            throw new InvalidArgumentException('Suchak plan slug is required.');
        }

        $description = trim((string) ($attributes['description'] ?? ''));
        $priceAmount = $attributes['price_amount'] ?? null;
        $priceAmount = $priceAmount === '' || $priceAmount === null
            ? null
            : number_format((float) $priceAmount, 2, '.', '');
        $currency = strtoupper(trim((string) ($attributes['currency'] ?? '')));
        if ($priceAmount === null) {
            $currency = null;
        } elseif ($currency === '') {
            throw new InvalidArgumentException('Currency is required when Suchak plan price is configured.');
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => $description !== '' ? $description : null,
            'price_amount' => $priceAmount,
            'currency' => $currency,
            'is_active' => filter_var($attributes['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'is_visible' => filter_var($attributes['is_visible'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'sort_order' => max(0, (int) ($attributes['sort_order'] ?? 0)),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $features
     * @return array<string, array{feature_key: string, value_type: string, feature_value: ?string, is_enabled: bool}>
     */
    private function normalizedFeaturePayloads(array $features): array
    {
        $payloads = [];

        foreach ($features as $feature) {
            $featureKey = trim((string) ($feature['feature_key'] ?? ''));
            if (! in_array($featureKey, SuchakPlanFeature::FEATURE_KEYS, true)) {
                throw new InvalidArgumentException('Invalid Suchak plan feature key.');
            }

            $valueType = trim((string) ($feature['value_type'] ?? ''));
            if (! in_array($valueType, [
                SuchakPlanFeature::TYPE_INTEGER,
                SuchakPlanFeature::TYPE_BOOLEAN,
                SuchakPlanFeature::TYPE_STRING,
            ], true)) {
                throw new InvalidArgumentException('Invalid Suchak plan feature value type.');
            }

            $isEnabled = filter_var($feature['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $payloads[$featureKey] = [
                'feature_key' => $featureKey,
                'value_type' => $valueType,
                'feature_value' => $this->normalizedFeatureValue($valueType, $feature['feature_value'] ?? null, $isEnabled),
                'is_enabled' => $isEnabled,
            ];
        }

        return $payloads;
    }

    private function normalizedFeatureValue(string $valueType, mixed $rawValue, bool $isEnabled): ?string
    {
        $value = trim((string) ($rawValue ?? ''));

        if ($valueType === SuchakPlanFeature::TYPE_BOOLEAN) {
            return filter_var($rawValue, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }

        if ($valueType === SuchakPlanFeature::TYPE_INTEGER) {
            if ($value === '') {
                $value = '0';
            }

            if (! preg_match('/^\d+$/', $value)) {
                throw new InvalidArgumentException('Suchak plan integer feature values must be zero or greater.');
            }

            return (string) ((int) $value);
        }

        if ($value === '' && ! $isEnabled) {
            return null;
        }

        return Str::limit($value, 255, '');
    }

    /**
     * @param  array<int, string>  $featureKeys
     */
    private function writePlanAuditLog(
        User $admin,
        SuchakPlan $plan,
        string $actionType,
        string $reason,
        array $featureKeys,
    ): AdminAuditLog {
        return AuditLogService::log(
            $admin,
            $actionType,
            'SuchakPlan',
            $plan->id,
            $reason.' | suchak_plan_slug='.$plan->slug.' | feature_keys='.implode(',', $featureKeys),
            false,
        );
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
