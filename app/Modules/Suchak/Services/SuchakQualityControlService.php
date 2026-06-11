<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakDispute;
use App\Models\SuchakFeatureSuspension;
use App\Models\SuchakGrowthAttribution;
use App\Models\SuchakPaymentFeatureFreeze;
use App\Models\SuchakPayoutHold;
use App\Models\SuchakQrToken;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakQualityControlService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function featureLabels(): array
    {
        return [
            SuchakFeatureSuspension::FEATURE_UPLOAD => 'Upload',
            SuchakFeatureSuspension::FEATURE_PDF => 'PDF / QR',
            SuchakFeatureSuspension::FEATURE_PAYMENT => 'Payment request',
            SuchakFeatureSuspension::FEATURE_PAYOUT => 'Payout',
            SuchakFeatureSuspension::FEATURE_REFERRAL => 'Referral / coupon',
            SuchakFeatureSuspension::FEATURE_COLLABORATION => 'Collaboration',
            SuchakFeatureSuspension::FEATURE_PUBLIC_REQUEST => 'Public request',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminSummary(int $limit = 20): array
    {
        $accounts = SuchakAccount::query()
            ->with('user')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (SuchakAccount $account): array => $this->qualitySummary($account));

        return [
            'generated_at' => now(),
            'features' => $this->featureLabels(),
            'accounts' => $accounts,
            'active_suspensions' => $this->recentSuspensions($limit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function qualitySummary(SuchakAccount $account): array
    {
        $account = $account->fresh(['user']) ?? $account;
        $activeSuspensions = $this->activeSuspensions($account);
        $openDisputes = SuchakDispute::query()
            ->where('suchak_account_id', $account->id)
            ->whereIn('status', [SuchakDispute::STATUS_OPEN, SuchakDispute::STATUS_UNDER_REVIEW])
            ->count();
        $directPaymentComplaints = SuchakDispute::query()
            ->where('suchak_account_id', $account->id)
            ->where('dispute_type', SuchakDispute::TYPE_DIRECT_PAYMENT_REQUEST)
            ->count();
        $activePaymentFreezes = SuchakPaymentFeatureFreeze::query()
            ->where('suchak_account_id', $account->id)
            ->where('freeze_status', SuchakPaymentFeatureFreeze::STATUS_ACTIVE)
            ->count();
        $activePayoutHolds = SuchakPayoutHold::query()
            ->where('suchak_account_id', $account->id)
            ->where('hold_status', SuchakPayoutHold::STATUS_ACTIVE)
            ->count();
        $revokedQrTokens = SuchakQrToken::query()
            ->where('suchak_account_id', $account->id)
            ->whereNotNull('revoked_at')
            ->count();
        $growthReviewSignals = SuchakGrowthAttribution::query()
            ->where('suchak_account_id', $account->id)
            ->where(function ($query): void {
                $query->where('fraud_status', SuchakGrowthAttribution::FRAUD_REVIEW_REQUIRED)
                    ->orWhere('attribution_status', SuchakGrowthAttribution::STATUS_REVIEW_REQUIRED);
            })
            ->count();

        $score = 100;
        $reasons = [];

        if ($account->verification_status !== SuchakAccount::VERIFICATION_VERIFIED) {
            $score -= $account->verification_status === SuchakAccount::VERIFICATION_SUSPENDED ? 50 : 25;
            $reasons[] = 'Account verification is '.$account->verification_status.'.';
        }

        if ($account->public_status !== SuchakAccount::PUBLIC_ACTIVE) {
            $score -= 10;
            $reasons[] = 'Public routing is '.$account->public_status.'.';
        }

        if ($activeSuspensions->isNotEmpty()) {
            $score -= min(40, $activeSuspensions->count() * 8);
            $reasons[] = $activeSuspensions->count().' active feature suspension(s).';
        }

        if ($openDisputes > 0) {
            $score -= min(24, $openDisputes * 6);
            $reasons[] = $openDisputes.' open dispute(s).';
        }

        if ($directPaymentComplaints > 0) {
            $score -= min(20, $directPaymentComplaints * 10);
            $reasons[] = $directPaymentComplaints.' direct payment complaint(s).';
        }

        if ($activePaymentFreezes > 0 || $activePayoutHolds > 0) {
            $score -= min(20, ($activePaymentFreezes + $activePayoutHolds) * 10);
            $reasons[] = ($activePaymentFreezes + $activePayoutHolds).' payment/payout restriction(s).';
        }

        if ($revokedQrTokens > 0) {
            $score -= min(10, $revokedQrTokens * 2);
            $reasons[] = $revokedQrTokens.' revoked QR token(s).';
        }

        if ($growthReviewSignals > 0) {
            $score -= min(20, $growthReviewSignals * 10);
            $reasons[] = $growthReviewSignals.' growth review signal(s).';
        }

        $score = max(0, min(100, $score));

        return [
            'account' => $account,
            'score' => $score,
            'band' => $this->scoreBand($score),
            'reasons' => $reasons === [] ? ['No active admin risk restrictions.'] : $reasons,
            'active_suspensions' => $activeSuspensions,
            'open_disputes' => $openDisputes,
            'direct_payment_complaints' => $directPaymentComplaints,
            'active_payment_freezes' => $activePaymentFreezes,
            'active_payout_holds' => $activePayoutHolds,
            'revoked_qr_tokens' => $revokedQrTokens,
            'growth_review_signals' => $growthReviewSignals,
        ];
    }

    public function assertFeatureAvailable(SuchakAccount $account, string $featureKey): void
    {
        $featureKey = $this->featureKey($featureKey);
        $suspension = SuchakFeatureSuspension::query()
            ->activeForFeature($account, $featureKey)
            ->latest('id')
            ->first();

        if ($suspension instanceof SuchakFeatureSuspension) {
            $label = $this->featureLabels()[$featureKey] ?? Str::headline($featureKey);

            throw new InvalidArgumentException('Suchak '.$label.' capability is suspended for this account.');
        }
    }

    public function suspendFeature(
        SuchakAccount $account,
        User $admin,
        string $featureKey,
        string $reason,
    ): SuchakFeatureSuspension {
        $this->accessService->assertAdmin($admin, 'Only admins can suspend Suchak capabilities.');
        $featureKey = $this->featureKey($featureKey);
        $reason = $this->reason($reason, 'Suchak feature suspension reason is required.');

        return DB::transaction(function () use ($account, $admin, $featureKey, $reason): SuchakFeatureSuspension {
            /** @var SuchakAccount $lockedAccount */
            $lockedAccount = SuchakAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = SuchakFeatureSuspension::query()
                ->activeForFeature($lockedAccount, $featureKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SuchakFeatureSuspension) {
                throw new InvalidArgumentException('This Suchak capability is already suspended for the account.');
            }

            $suspension = SuchakFeatureSuspension::query()->create([
                'suchak_account_id' => $lockedAccount->id,
                'feature_key' => $featureKey,
                'suspension_status' => SuchakFeatureSuspension::STATUS_ACTIVE,
                'reason' => $reason,
                'created_by_admin_user_id' => $admin->id,
            ]);

            $audit = $this->audit(
                $admin,
                'suchak_feature_suspension_created',
                $suspension,
                $reason,
            );

            $suspension->forceFill(['created_admin_audit_log_id' => $audit->id])->save();

            return $suspension->fresh(['suchakAccount', 'createdByAdmin', 'createdAdminAuditLog']);
        });
    }

    public function releaseFeature(
        SuchakFeatureSuspension $suspension,
        User $admin,
        string $reason,
    ): SuchakFeatureSuspension {
        $this->accessService->assertAdmin($admin, 'Only admins can release Suchak capability suspensions.');
        $reason = $this->reason($reason, 'Suchak feature suspension release reason is required.');

        return DB::transaction(function () use ($suspension, $admin, $reason): SuchakFeatureSuspension {
            /** @var SuchakFeatureSuspension $locked */
            $locked = SuchakFeatureSuspension::query()
                ->whereKey($suspension->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->suspension_status !== SuchakFeatureSuspension::STATUS_ACTIVE) {
                throw new InvalidArgumentException('Only active Suchak capability suspensions can be released.');
            }

            $audit = $this->audit(
                $admin,
                'suchak_feature_suspension_released',
                $locked,
                $reason,
            );

            $locked->forceFill([
                'suspension_status' => SuchakFeatureSuspension::STATUS_RELEASED,
                'released_by_admin_user_id' => $admin->id,
                'released_admin_audit_log_id' => $audit->id,
                'released_at' => now(),
                'release_reason' => $reason,
            ])->save();

            return $locked->fresh(['suchakAccount', 'releasedByAdmin', 'releasedAdminAuditLog']);
        });
    }

    /**
     * @return Collection<int, SuchakFeatureSuspension>
     */
    public function activeSuspensions(SuchakAccount $account): Collection
    {
        return SuchakFeatureSuspension::query()
            ->with(['createdByAdmin', 'createdAdminAuditLog'])
            ->where('suchak_account_id', $account->id)
            ->where('suspension_status', SuchakFeatureSuspension::STATUS_ACTIVE)
            ->orderBy('feature_key')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, SuchakFeatureSuspension>
     */
    public function recentSuspensions(int $limit = 20): Collection
    {
        return SuchakFeatureSuspension::query()
            ->with(['suchakAccount.user', 'createdByAdmin', 'releasedByAdmin'])
            ->where('suspension_status', SuchakFeatureSuspension::STATUS_ACTIVE)
            ->latest()
            ->limit($limit)
            ->get();
    }

    private function featureKey(string $featureKey): string
    {
        $featureKey = trim($featureKey);
        if (! in_array($featureKey, SuchakFeatureSuspension::FEATURES, true)) {
            throw new InvalidArgumentException('Suchak feature suspension mode is invalid.');
        }

        return $featureKey;
    }

    private function reason(string $reason, string $message): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new InvalidArgumentException($message);
        }

        return Str::limit($reason, 1000, '');
    }

    private function scoreBand(int $score): string
    {
        if ($score >= 80) {
            return 'strong';
        }

        if ($score >= 50) {
            return 'review';
        }

        return 'restricted';
    }

    private function audit(
        User $admin,
        string $actionType,
        SuchakFeatureSuspension $suspension,
        string $reason,
    ): AdminAuditLog {
        return AuditLogService::log(
            $admin,
            $actionType,
            class_basename($suspension),
            $suspension->id,
            $reason,
            false,
        );
    }
}
