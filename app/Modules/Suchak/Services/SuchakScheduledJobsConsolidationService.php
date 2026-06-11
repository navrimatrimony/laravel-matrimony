<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakConsentEvent;
use App\Models\SuchakGrowthAttribution;
use App\Models\SuchakGrowthReward;
use App\Models\SuchakGrowthRewardRule;
use App\Models\SuchakLoyaltyTierSnapshot;
use App\Models\SuchakMonthlyValueReport;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakPlatformPayoutSettlement;
use App\Models\SuchakQrToken;
use App\Models\SuchakScheduledJobRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class SuchakScheduledJobsConsolidationService
{
    private const PER_JOB_LIMIT = 500;

    public function __construct(
        private readonly SuchakPaymentRequestService $paymentRequestService,
        private readonly SuchakWorkflowAutomationService $workflowAutomationService,
        private readonly SuchakPlatformPayoutService $platformPayoutService,
        private readonly SuchakGrowthRewardService $growthRewardService,
        private readonly SuchakRetentionCampaignService $retentionCampaignService,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function run(
        ?User $admin = null,
        ?SuchakAccount $account = null,
        ?Carbon $at = null,
        ?string $month = null,
    ): array {
        $at ??= now();
        $month = $this->month($month ?? $at->copy()->subMonthNoOverflow()->format('Y-m'));

        return [
            SuchakScheduledJobRun::JOB_OVERDUE_PAYMENTS => $this->runTrackedJob(
                SuchakScheduledJobRun::JOB_OVERDUE_PAYMENTS,
                $at,
                null,
                $admin,
                $account,
                fn (): array => $this->expireOverduePayments($account, $at),
            ),
            SuchakScheduledJobRun::JOB_PAYOUT_CYCLES => $this->runTrackedJob(
                SuchakScheduledJobRun::JOB_PAYOUT_CYCLES,
                $at,
                $month,
                $admin,
                $account,
                fn (): array => $this->generatePayoutCycles($admin, $account, $month),
            ),
            SuchakScheduledJobRun::JOB_REWARD_QUALIFICATION => $this->runTrackedJob(
                SuchakScheduledJobRun::JOB_REWARD_QUALIFICATION,
                $at,
                null,
                $admin,
                $account,
                fn (): array => $this->qualifyRewards($admin, $account, $at),
            ),
            SuchakScheduledJobRun::JOB_CONSENT_EXPIRY => $this->runTrackedJob(
                SuchakScheduledJobRun::JOB_CONSENT_EXPIRY,
                $at,
                null,
                $admin,
                $account,
                fn (): array => $this->expireConsents($account, $at),
            ),
            SuchakScheduledJobRun::JOB_QR_EXPIRY => $this->runTrackedJob(
                SuchakScheduledJobRun::JOB_QR_EXPIRY,
                $at,
                null,
                $admin,
                $account,
                fn (): array => $this->expireQrTokens($account, $at),
            ),
            SuchakScheduledJobRun::JOB_FOLLOW_UP_REMINDERS => $this->runTrackedJob(
                SuchakScheduledJobRun::JOB_FOLLOW_UP_REMINDERS,
                $at,
                null,
                $admin,
                $account,
                fn (): array => $this->generateFollowUpReminders($account, $at),
            ),
            SuchakScheduledJobRun::JOB_MONTHLY_REPORTS => $this->runTrackedJob(
                SuchakScheduledJobRun::JOB_MONTHLY_REPORTS,
                $at,
                $month,
                $admin,
                $account,
                fn (): array => $this->generateMonthlyReports($admin, $account, $month),
            ),
            SuchakScheduledJobRun::JOB_LOYALTY_RECALCULATION => $this->runTrackedJob(
                SuchakScheduledJobRun::JOB_LOYALTY_RECALCULATION,
                $at,
                $month,
                $admin,
                $account,
                fn (): array => $this->recalculateLoyalty($admin, $account, $month),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expireOverduePayments(?SuchakAccount $account, Carbon $at): array
    {
        $requests = SuchakPaymentRequest::query()
            ->when($account, fn (Builder $query) => $query->where('suchak_account_id', $account->id))
            ->whereIn('payment_status', [
                SuchakPaymentRequest::STATUS_SENT,
                SuchakPaymentRequest::STATUS_OPENED,
                SuchakPaymentRequest::STATUS_PENDING,
                SuchakPaymentRequest::STATUS_PARTIALLY_PAID,
                SuchakPaymentRequest::STATUS_OVERDUE,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(self::PER_JOB_LIMIT)
            ->get();

        $expired = 0;
        foreach ($requests as $request) {
            $fresh = $this->paymentRequestService->expire($request);
            if ($fresh->payment_status === SuchakPaymentRequest::STATUS_EXPIRED) {
                $expired++;
            }
        }

        return [
            'evaluated_at' => $at->toIso8601String(),
            'candidates' => $requests->count(),
            'expired_requests' => $expired,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generatePayoutCycles(?User $admin, ?SuchakAccount $account, string $month): array
    {
        if (! $admin instanceof User) {
            return $this->adminMissingMetrics($month);
        }

        [$start, $end] = $this->period($month);
        $accountIds = SuchakPlatformPayout::query()
            ->when($account, fn (Builder $query) => $query->where('suchak_account_id', $account->id))
            ->whereIn('payout_status', [SuchakPlatformPayout::STATUS_PAID, SuchakPlatformPayout::STATUS_REVERSED])
            ->whereBetween('paid_at', [$start, $end])
            ->distinct()
            ->pluck('suchak_account_id');

        $generated = 0;
        $skippedExisting = 0;
        $failed = 0;

        foreach ($accountIds as $accountId) {
            $exists = SuchakPlatformPayoutSettlement::query()
                ->where('suchak_account_id', $accountId)
                ->where('statement_month', $month)
                ->exists();

            if ($exists) {
                $skippedExisting++;
                continue;
            }

            $payoutAccount = SuchakAccount::query()->find($accountId);
            if (! $payoutAccount instanceof SuchakAccount) {
                $failed++;
                continue;
            }

            try {
                $this->platformPayoutService->generateMonthlySettlementStatement($payoutAccount, $admin, $month);
                $generated++;
            } catch (InvalidArgumentException) {
                $failed++;
            }
        }

        return [
            'statement_month' => $month,
            'accounts_considered' => $accountIds->count(),
            'settlements_generated' => $generated,
            'skipped_existing' => $skippedExisting,
            'failed_accounts' => $failed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function qualifyRewards(?User $admin, ?SuchakAccount $account, Carbon $at): array
    {
        if (! $admin instanceof User) {
            return $this->adminMissingMetrics(null);
        }

        $rules = SuchakGrowthRewardRule::query()
            ->where('reward_trigger', SuchakGrowthRewardRule::TRIGGER_PLATFORM_PAYMENT_CONFIRMED)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at);
            })
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $at);
            })
            ->orderBy('id')
            ->get()
            ->groupBy('attribution_policy');

        $attributions = SuchakGrowthAttribution::query()
            ->with('paymentContext')
            ->when($account, fn (Builder $query) => $query->where('suchak_account_id', $account->id))
            ->where('attribution_status', SuchakGrowthAttribution::STATUS_ACTIVE)
            ->where('fraud_status', SuchakGrowthAttribution::FRAUD_CLEAR)
            ->whereNotNull('payment_context_id')
            ->whereHas('paymentContext', function (Builder $query): void {
                $query
                    ->where('context_status', SuchakPaymentContext::STATUS_ACTIVE)
                    ->where('source_owner', SuchakPaymentContext::SOURCE_PLATFORM)
                    ->where('payment_collector', SuchakPaymentContext::COLLECTOR_PLATFORM);
            })
            ->orderBy('id')
            ->limit(self::PER_JOB_LIMIT)
            ->get();

        $qualified = 0;
        $skippedNoRule = 0;
        $skippedExisting = 0;
        $skippedInvalid = 0;

        foreach ($attributions as $attribution) {
            /** @var Collection<int, SuchakGrowthRewardRule>|null $rulesForPolicy */
            $rulesForPolicy = $rules->get($attribution->attribution_policy);
            $rule = $rulesForPolicy?->first();

            if (! $rule instanceof SuchakGrowthRewardRule) {
                $skippedNoRule++;
                continue;
            }

            $exists = SuchakGrowthReward::query()
                ->where('growth_attribution_id', $attribution->id)
                ->where('payment_context_id', $attribution->payment_context_id)
                ->where('reward_rule_id', $rule->id)
                ->exists();

            if ($exists) {
                $skippedExisting++;
                continue;
            }

            if (! $attribution->paymentContext instanceof SuchakPaymentContext) {
                $skippedInvalid++;
                continue;
            }

            try {
                $this->growthRewardService->qualifyRewardFromPlatformPayment(
                    $attribution,
                    $attribution->paymentContext,
                    $rule,
                    $admin,
                    [
                        'qualification_note' => 'Scheduled reward qualification from platform-confirmed payment context.',
                    ],
                );
                $qualified++;
            } catch (InvalidArgumentException) {
                $skippedInvalid++;
            }
        }

        return [
            'evaluated_at' => $at->toIso8601String(),
            'candidates' => $attributions->count(),
            'rewards_qualified' => $qualified,
            'skipped_no_rule' => $skippedNoRule,
            'skipped_existing' => $skippedExisting,
            'skipped_invalid' => $skippedInvalid,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expireConsents(?SuchakAccount $account, Carbon $at): array
    {
        $consents = SuchakConsent::query()
            ->when($account, fn (Builder $query) => $query->where('suchak_account_id', $account->id))
            ->where(function (Builder $query) use ($at): void {
                $query->where(function (Builder $query) use ($at): void {
                    $query
                        ->whereIn('consent_status', SuchakConsent::PENDING_ACTION_STATUSES)
                        ->whereNotNull('token_expires_at')
                        ->where('token_expires_at', '<=', $at);
                })->orWhere(function (Builder $query) use ($at): void {
                    $query
                        ->where('consent_status', SuchakConsent::STATUS_ACCEPTED)
                        ->whereNull('revoked_at')
                        ->whereNotNull('valid_until')
                        ->where('valid_until', '<=', $at);
                });
            })
            ->orderBy('id')
            ->limit(self::PER_JOB_LIMIT)
            ->get();

        $expired = 0;
        foreach ($consents as $consent) {
            $expired += DB::transaction(function () use ($consent, $at): int {
                /** @var SuchakConsent $locked */
                $locked = SuchakConsent::query()
                    ->whereKey($consent->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->consent_status === SuchakConsent::STATUS_EXPIRED
                    || $locked->consent_status === SuchakConsent::STATUS_REVOKED
                    || $locked->consent_status === SuchakConsent::STATUS_REJECTED) {
                    return 0;
                }

                $locked->forceFill([
                    'consent_status' => SuchakConsent::STATUS_EXPIRED,
                ])->save();

                SuchakConsentEvent::query()->create([
                    'consent_id' => $locked->id,
                    'event_type' => SuchakConsentEvent::EVENT_CONSENT_EXPIRED,
                    'event_note' => 'Consent expired by consolidated Suchak scheduled job.',
                    'actor_type' => SuchakConsentEvent::ACTOR_SYSTEM,
                    'actor_id' => null,
                    'created_at' => $at,
                ]);

                return 1;
            });
        }

        return [
            'evaluated_at' => $at->toIso8601String(),
            'candidates' => $consents->count(),
            'expired_consents' => $expired,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expireQrTokens(?SuchakAccount $account, Carbon $at): array
    {
        $tokens = SuchakQrToken::query()
            ->when($account, fn (Builder $query) => $query->where('suchak_account_id', $account->id))
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(self::PER_JOB_LIMIT)
            ->get();

        $revoked = 0;
        foreach ($tokens as $token) {
            $revoked += DB::transaction(function () use ($token, $at): int {
                /** @var SuchakQrToken $locked */
                $locked = SuchakQrToken::query()
                    ->whereKey($token->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->revoked_at !== null || ! $locked->isExpired($at)) {
                    return 0;
                }

                $locked->forceFill([
                    'revoked_at' => $at,
                    'revoked_reason' => 'Expired by consolidated Suchak scheduled job.',
                ])->save();

                return 1;
            });
        }

        return [
            'evaluated_at' => $at->toIso8601String(),
            'candidates' => $tokens->count(),
            'revoked_qr_tokens' => $revoked,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generateFollowUpReminders(?SuchakAccount $account, Carbon $at): array
    {
        $reminders = $this->workflowAutomationService->generateDueReminders($account, $at);

        return [
            'evaluated_at' => $at->toIso8601String(),
            'reminders_returned' => $reminders->count(),
            'provider_delivery' => 'pending_credentials',
            'private_contact_leak_check' => 'workflow_template_guard',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generateMonthlyReports(?User $admin, ?SuchakAccount $account, string $month): array
    {
        if (! $admin instanceof User) {
            return $this->adminMissingMetrics($month);
        }

        $accounts = $this->verifiedAccounts($account)->get();
        $generated = 0;
        $skippedExisting = 0;
        $failed = 0;

        foreach ($accounts as $reportAccount) {
            $exists = SuchakMonthlyValueReport::query()
                ->where('suchak_account_id', $reportAccount->id)
                ->where('report_month', $month)
                ->exists();

            if ($exists) {
                $skippedExisting++;
                continue;
            }

            try {
                $this->retentionCampaignService->generateMonthlyValueReport($reportAccount, $admin, $month);
                $generated++;
            } catch (InvalidArgumentException) {
                $failed++;
            }
        }

        return [
            'report_month' => $month,
            'accounts_considered' => $accounts->count(),
            'reports_generated' => $generated,
            'skipped_existing' => $skippedExisting,
            'failed_accounts' => $failed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recalculateLoyalty(?User $admin, ?SuchakAccount $account, string $month): array
    {
        $accounts = $this->verifiedAccounts($account)->get();
        $created = 0;
        $skippedExisting = 0;
        $failed = 0;

        foreach ($accounts as $loyaltyAccount) {
            $exists = SuchakLoyaltyTierSnapshot::query()
                ->where('suchak_account_id', $loyaltyAccount->id)
                ->where('snapshot_month', $month)
                ->exists();

            try {
                $this->retentionCampaignService->generateLoyaltySnapshot($loyaltyAccount, $admin, $month);
                $exists ? $skippedExisting++ : $created++;
            } catch (InvalidArgumentException) {
                $failed++;
            }
        }

        return [
            'snapshot_month' => $month,
            'accounts_considered' => $accounts->count(),
            'snapshots_created' => $created,
            'skipped_existing' => $skippedExisting,
            'failed_accounts' => $failed,
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function runTrackedJob(
        string $jobKey,
        Carbon $at,
        ?string $month,
        ?User $admin,
        ?SuchakAccount $account,
        callable $callback,
    ): array {
        $runKey = $this->runKey($jobKey, $at, $month, $account);
        $existing = SuchakScheduledJobRun::query()
            ->where('run_key', $runKey)
            ->first();

        if ($existing instanceof SuchakScheduledJobRun && $existing->job_status !== SuchakScheduledJobRun::STATUS_FAILED) {
            return [
                'job_status' => $existing->job_status,
                'existing_run' => true,
                'run_id' => $existing->id,
                'metrics' => $existing->metrics_json ?? [],
            ];
        }

        $run = $existing instanceof SuchakScheduledJobRun
            ? tap($existing, function (SuchakScheduledJobRun $run) use ($admin): void {
                $run->forceFill([
                    'job_status' => SuchakScheduledJobRun::STATUS_RUNNING,
                    'triggered_by' => $admin instanceof User ? SuchakScheduledJobRun::TRIGGER_ADMIN : SuchakScheduledJobRun::TRIGGER_SYSTEM,
                    'triggered_by_user_id' => $admin?->id,
                    'metrics_json' => null,
                    'started_at' => now(),
                    'completed_at' => null,
                ])->save();
            })
            : SuchakScheduledJobRun::query()->create([
                'run_key' => $runKey,
                'job_key' => $jobKey,
                'job_status' => SuchakScheduledJobRun::STATUS_RUNNING,
                'triggered_by' => $admin instanceof User ? SuchakScheduledJobRun::TRIGGER_ADMIN : SuchakScheduledJobRun::TRIGGER_SYSTEM,
                'triggered_by_user_id' => $admin?->id,
                'account_scope_id' => $account?->id,
                'run_for_date' => $at->toDateString(),
                'run_month' => $month,
                'metrics_json' => null,
                'started_at' => now(),
            ]);

        try {
            $metrics = $callback();
            $status = (string) ($metrics['job_status'] ?? SuchakScheduledJobRun::STATUS_COMPLETED);
            unset($metrics['job_status']);

            if (! in_array($status, SuchakScheduledJobRun::STATUSES, true) || $status === SuchakScheduledJobRun::STATUS_RUNNING) {
                $status = SuchakScheduledJobRun::STATUS_COMPLETED;
            }

            $run->forceFill([
                'job_status' => $status,
                'metrics_json' => $metrics,
                'completed_at' => now(),
            ])->save();

            return [
                'job_status' => $status,
                'existing_run' => false,
                'run_id' => $run->id,
                'metrics' => $metrics,
            ];
        } catch (Throwable $throwable) {
            $run->forceFill([
                'job_status' => SuchakScheduledJobRun::STATUS_FAILED,
                'metrics_json' => [
                    'error_class' => class_basename($throwable),
                    'error_message' => Str::limit($throwable->getMessage(), 300, ''),
                ],
                'completed_at' => now(),
            ])->save();

            throw $throwable;
        }
    }

    /**
     * @return Builder<SuchakAccount>
     */
    private function verifiedAccounts(?SuchakAccount $account): Builder
    {
        return SuchakAccount::query()
            ->when($account, fn (Builder $query) => $query->whereKey($account->id))
            ->where('verification_status', SuchakAccount::VERIFICATION_VERIFIED)
            ->orderBy('id')
            ->limit(self::PER_JOB_LIMIT);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminMissingMetrics(?string $month): array
    {
        return [
            'job_status' => SuchakScheduledJobRun::STATUS_SKIPPED,
            'admin_required' => true,
            'run_month' => $month,
            'skipped_reason' => 'admin_user_required',
        ];
    }

    private function runKey(string $jobKey, Carbon $at, ?string $month, ?SuchakAccount $account): string
    {
        return implode(':', [
            'suchak-scheduled-job',
            $jobKey,
            $at->toDateString(),
            $month ?? 'daily',
            $account?->id ?? 'all',
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $this->month($month).'-01 00:00:00')->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    private function month(string $month): string
    {
        $month = trim($month);
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new InvalidArgumentException('Suchak scheduled job month must use YYYY-MM format.');
        }

        Carbon::createFromFormat('Y-m-d', $month.'-01');

        return $month;
    }
}
