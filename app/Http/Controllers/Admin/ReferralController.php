<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\Plan;
use App\Models\ReferralRewardLedger;
use App\Models\ReferralRewardRule;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\AuditLogService;
use App\Services\EntitlementService;
use App\Services\FeatureUsageService;
use App\Services\ReferralService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReferralController extends Controller
{
    private const ENGINE_KEYS = [
        'enabled' => 'referral_engine_enabled',
        'paid_only' => 'referral_engine_paid_plans_only',
        'min_plan_amount' => 'referral_engine_min_plan_amount',
        'monthly_cap' => 'referral_engine_monthly_cap_per_referrer',
    ];

    public function index(Request $request)
    {
        $tab = (string) $request->query('tab', 'reward-plans');
        if (! in_array($tab, ['engine', 'reward-plans', 'reports', 'review', 'audit', 'supreme'], true)) {
            $tab = 'reward-plans';
        }

        $rewardFilter = (string) $request->query('reward', '');
        $fromDate = (string) $request->query('from_date', '');
        $toDate = (string) $request->query('to_date', '');
        $auditAction = (string) $request->query('audit_action', '');
        $auditReferrerId = (string) $request->query('audit_referrer_id', '');
        $selectedPlanSlug = strtolower(trim((string) ($request->query('plan_slug', old('plan_slug', '')))));

        $referralsQuery = UserReferral::query()
            ->with([
                'referrer:id,name,mobile,email,referral_code',
                'referredUser:id,name,mobile,email',
            ])
            ->when($rewardFilter === '1', fn ($q) => $q->where('reward_applied', true))
            ->when($rewardFilter === '0', fn ($q) => $q->where('reward_applied', false));

        if ($tab === 'reports') {
            if ($this->isValidDate($fromDate)) {
                $referralsQuery->whereDate('created_at', '>=', $fromDate);
            }
            if ($this->isValidDate($toDate)) {
                $referralsQuery->whereDate('created_at', '<=', $toDate);
            }
        }

        $referrals = (clone $referralsQuery)
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        $rules = ReferralRewardRule::query()
            ->orderBy('plan_slug')
            ->get();
        $selectedRewardRule = $selectedPlanSlug !== ''
            ? $rules->firstWhere('plan_slug', $selectedPlanSlug)
            : null;

        $plans = Plan::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'applies_to_gender', 'is_active']);

        $engineSettings = [
            'enabled' => $this->getBoolSetting(self::ENGINE_KEYS['enabled'], (bool) config('referral.enabled', true)),
            'paid_only' => $this->getBoolSetting(self::ENGINE_KEYS['paid_only'], true),
            'min_plan_amount' => $this->getIntSetting(self::ENGINE_KEYS['min_plan_amount'], 0),
            'monthly_cap' => $this->getIntSetting(self::ENGINE_KEYS['monthly_cap'], 0),
            'fraud_auto_hold' => $this->getBoolSetting('referral_fraud_auto_hold', (bool) config('referral.fraud.auto_hold_on_flags', true)),
            'fraud_rapid_invites' => $this->fraudRapidInvitesFromSettings(),
            'pending_claim_expiry_days' => $this->getIntSetting('referral_pending_claim_expiry_days', (int) config('referral.pending_claim_expiry_days', 90)),
            'quality_require_profile_active' => $this->getBoolSetting('referral_quality_require_profile_active', (bool) config('referral.quality_gates.require_profile_active', false)),
            'quality_require_mobile_verified' => $this->getBoolSetting('referral_quality_require_mobile_verified', (bool) config('referral.quality_gates.require_mobile_verified', false)),
            'quality_require_photo_approved' => $this->getBoolSetting('referral_quality_require_photo_approved', (bool) config('referral.quality_gates.require_photo_approved', false)),
            'quality_cooling_period_days' => $this->getIntSetting('referral_quality_cooling_period_days', (int) config('referral.quality_gates.cooling_period_days', 0)),
            'referred_checkout_enabled' => $this->getBoolSetting('referral_referred_checkout_enabled', (bool) config('referral.referred_checkout.enabled', true)),
            'referred_checkout_percent' => $this->getIntSetting('referral_referred_checkout_percent', (int) config('referral.referred_checkout.percent_off', 0)),
            'referred_checkout_extra_days' => $this->getIntSetting('referral_referred_checkout_extra_days', (int) config('referral.referred_checkout.extra_days', 0)),
            'renewal_micro_bonus_enabled' => $this->getBoolSetting('referral_renewal_micro_bonus_enabled', (bool) config('referral.growth.renewal_micro_bonus.enabled', false)),
            'renewal_micro_bonus_days' => $this->getIntSetting('referral_renewal_micro_bonus_days', (int) config('referral.growth.renewal_micro_bonus.bonus_days', 1)),
        ];

        $reviewQueue = collect();
        $reviewQueueCount = 0;
        if ($tab === 'review') {
            $reviewQueue = UserReferral::query()
                ->where('review_status', UserReferral::REVIEW_PENDING)
                ->with([
                    'referrer:id,name,mobile,email,referral_code',
                    'referredUser:id,name,mobile,email',
                ])
                ->orderBy('created_at')
                ->paginate(30)
                ->withQueryString();
        }
        $reviewQueueCount = app(ReferralService::class)->countPendingReviewReferrals();

        $supremeReferrer = null;
        $supremePanel = null;
        if ($tab === 'supreme') {
            $lookup = trim((string) $request->query('referrer_lookup', ''));
            if ($lookup !== '') {
                $query = User::query();
                if (ctype_digit($lookup)) {
                    $query->where('id', (int) $lookup);
                } else {
                    $query->where(function ($inner) use ($lookup) {
                        $inner->where('referral_code', strtoupper($lookup))
                            ->orWhere('mobile', $lookup)
                            ->orWhere('email', $lookup);
                    });
                }
                $supremeReferrer = $query->first();
                if ($supremeReferrer) {
                    $supremePanel = app(ReferralService::class)->adminReferrerSupremePanel($supremeReferrer);
                }
            }
        }

        $referralReports = null;
        $reportFrom = $tab === 'reports' && $this->isValidDate($fromDate) ? $fromDate : null;
        $reportTo = $tab === 'reports' && $this->isValidDate($toDate) ? $toDate : null;
        if ($tab === 'reports') {
            $referralReports = app(ReferralService::class)->adminReportsBundle($reportFrom, $reportTo);
        }
        $reportSummary = $referralReports['summary'] ?? [
            'total' => 0,
            'rewarded' => 0,
            'upgraded' => 0,
            'profile_ready' => 0,
            'pending' => 0,
            'conversion_rate' => 0.0,
        ];

        $topReferrers = UserReferral::query()
            ->selectRaw('referrer_id, COUNT(*) as total_referrals, SUM(CASE WHEN reward_applied = 1 THEN 1 ELSE 0 END) as rewarded_referrals')
            ->when($tab === 'reports' && $this->isValidDate($fromDate), fn ($q) => $q->whereDate('created_at', '>=', $fromDate))
            ->when($tab === 'reports' && $this->isValidDate($toDate), fn ($q) => $q->whereDate('created_at', '<=', $toDate))
            ->groupBy('referrer_id')
            ->orderByDesc('rewarded_referrals')
            ->orderByDesc('total_referrals')
            ->with('referrer:id,name,mobile,email,referral_code')
            ->limit(10)
            ->get();

        $ledgersQuery = ReferralRewardLedger::query()
            ->with(['referrer:id,name,mobile,email,referral_code', 'referredUser:id,name,mobile,email', 'performedByAdmin:id,name,email'])
            ->when(
                $tab === 'audit' && $auditAction !== '',
                fn ($q) => $q->where('action_type', $auditAction)
            )
            ->when(
                $tab === 'audit' && ctype_digit($auditReferrerId),
                fn ($q) => $q->where('referrer_id', (int) $auditReferrerId)
            );

        $ledgers = (clone $ledgersQuery)
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $auditActionTypes = ReferralRewardLedger::query()
            ->select('action_type')
            ->distinct()
            ->orderBy('action_type')
            ->pluck('action_type');

        return view('admin.referrals.index', compact(
            'referrals',
            'rewardFilter',
            'rules',
            'plans',
            'tab',
            'engineSettings',
            'fromDate',
            'toDate',
            'reportSummary',
            'referralReports',
            'topReferrers',
            'selectedPlanSlug',
            'selectedRewardRule',
            'auditAction',
            'auditReferrerId',
            'ledgers',
            'auditActionTypes',
            'reviewQueue',
            'reviewQueueCount',
            'supremeReferrer',
            'supremePanel',
        ));
    }

    public function freezeReferrer(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        app(ReferralService::class)->adminFreezeReferrerRewards($user, $request->user(), $validated['reason'] ?? null);

        return $this->supremeRedirect($user)->with('success', __('admin_monetization.referral_supreme_frozen'));
    }

    public function unfreezeReferrer(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        app(ReferralService::class)->adminUnfreezeReferrerRewards($user, $request->user(), $validated['reason'] ?? null);

        return $this->supremeRedirect($user)->with('success', __('admin_monetization.referral_supreme_unfrozen'));
    }

    public function disableReferralCode(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        app(ReferralService::class)->adminDisableReferralCode($user, $request->user(), $validated['reason'] ?? null);

        return $this->supremeRedirect($user)->with('success', __('admin_monetization.referral_supreme_code_disabled'));
    }

    public function enableReferralCode(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        app(ReferralService::class)->adminEnableReferralCode($user, $request->user(), $validated['reason'] ?? null);

        return $this->supremeRedirect($user)->with('success', __('admin_monetization.referral_supreme_code_enabled'));
    }

    public function regenerateReferralCode(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $code = app(ReferralService::class)->adminRegenerateReferralCode($user, $request->user(), $validated['reason'] ?? null);

        return $this->supremeRedirect($user)
            ->with('success', __('admin_monetization.referral_supreme_code_regenerated', ['code' => $code]));
    }

    public function saveReferrerCapOverride(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'monthly_cap_override' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'use_global_cap' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $cap = $request->boolean('use_global_cap')
            ? null
            : max(0, (int) ($validated['monthly_cap_override'] ?? 0));

        app(ReferralService::class)->adminSetReferrerMonthlyCapOverride(
            $user,
            $request->user(),
            $cap,
            $validated['reason'] ?? null,
        );

        return $this->supremeRedirect($user)->with('success', __('admin_monetization.referral_supreme_cap_saved'));
    }

    public function forcePendingClaim(Request $request, UserReferral $referral): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'return_tab' => ['nullable', 'string', Rule::in(['reports', 'supreme', 'audit'])],
        ]);

        $applied = app(ReferralService::class)->adminForceApplyPendingClaim(
            $referral,
            $request->user(),
            $validated['reason'] ?? null,
        );

        if (! $applied) {
            return $this->referralActionRedirect($request)
                ->with('error', __('admin_monetization.referral_force_pending_failed'));
        }

        return $this->referralActionRedirect($request)
            ->with('success', __('admin_monetization.referral_force_pending_success'));
    }

    public function cancelPendingClaim(Request $request, UserReferral $referral): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        app(ReferralService::class)->adminCancelPendingClaim(
            $referral,
            $request->user(),
            (string) $validated['reason'],
        );

        return $this->referralActionRedirect($request)
            ->with('success', __('admin_monetization.referral_cancel_pending_success'));
    }

    public function reassignReferral(Request $request, UserReferral $referral): RedirectResponse
    {
        $validated = $request->validate([
            'new_referrer_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $newReferrer = User::query()->findOrFail((int) $validated['new_referrer_id']);
        $ok = app(ReferralService::class)->adminReassignReferral(
            $referral,
            $newReferrer,
            $request->user(),
            (string) $validated['reason'],
        );

        if (! $ok) {
            return $this->referralActionRedirect($request)
                ->with('error', __('admin_monetization.referral_reassign_failed'));
        }

        return $this->referralActionRedirect($request)
            ->with('success', __('admin_monetization.referral_reassign_success'));
    }

    public function partialReward(Request $request, UserReferral $referral): RedirectResponse
    {
        $validated = $request->validate([
            'bonus_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'chat_send_limit_bonus' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'contact_view_limit_bonus' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'interest_send_limit_bonus' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'daily_profile_view_limit_bonus' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'who_viewed_me_preview_limit_bonus' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'mark_reward_applied' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $bonusDays = max(0, (int) ($validated['bonus_days'] ?? 0));
        $featureBonus = $this->buildFeatureBonusFromRequest($request);

        if ($bonusDays <= 0 && $featureBonus === []) {
            return $this->referralActionRedirect($request)
                ->with('error', __('admin_monetization.referral_partial_failed_empty'));
        }

        $ok = app(ReferralService::class)->adminApplyPartialReward(
            $referral,
            $request->user(),
            $bonusDays,
            $featureBonus,
            $request->boolean('mark_reward_applied'),
            (string) $validated['reason'],
        );

        if (! $ok) {
            return $this->referralActionRedirect($request)
                ->with('error', __('admin_monetization.referral_partial_failed'));
        }

        return $this->referralActionRedirect($request)
            ->with('success', __('admin_monetization.referral_partial_success'));
    }

    public function revokeAppliedReward(Request $request, UserReferral $referral): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $ok = app(ReferralService::class)->adminRevokeAppliedReward(
            $referral,
            $request->user(),
            (string) $validated['reason'],
        );

        if (! $ok) {
            return $this->referralActionRedirect($request)
                ->with('error', __('admin_monetization.referral_revoke_failed'));
        }

        return $this->referralActionRedirect($request)
            ->with('success', __('admin_monetization.referral_revoke_success'));
    }

    private function referralActionRedirect(Request $request): RedirectResponse
    {
        $tab = (string) $request->input('return_tab', 'reports');
        if (! in_array($tab, ['reports', 'supreme', 'audit'], true)) {
            $tab = 'reports';
        }

        $params = ['tab' => $tab];
        $lookup = trim((string) $request->input('referrer_lookup', ''));
        if ($tab === 'supreme' && $lookup !== '') {
            $params['referrer_lookup'] = $lookup;
        }

        return redirect()->route('admin.referrals.index', $params);
    }

    private function supremeRedirect(User $user): RedirectResponse
    {
        return redirect()->route('admin.referrals.index', [
            'tab' => 'supreme',
            'referrer_lookup' => (string) $user->id,
        ]);
    }

    public function approveReview(Request $request, UserReferral $referral): RedirectResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        app(ReferralService::class)->adminApproveReferralReview(
            $referral,
            $request->user(),
            $validated['notes'] ?? null,
        );

        return redirect()
            ->route('admin.referrals.index', ['tab' => 'review'])
            ->with('success', __('admin_monetization.referral_review_approve_success'));
    }

    public function rejectReview(Request $request, UserReferral $referral): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        app(ReferralService::class)->adminRejectReferralReview(
            $referral,
            $request->user(),
            (string) $validated['reason'],
        );

        return redirect()
            ->route('admin.referrals.index', ['tab' => 'review'])
            ->with('success', __('admin_monetization.referral_review_reject_success'));
    }

    public function saveRule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan_slug' => ['required', 'string', 'max:120', Rule::exists('plans', 'slug')],
            'is_active' => ['sometimes', 'boolean'],
            'bonus_days' => ['nullable', 'integer', 'min:0'],
            'chat_send_limit_bonus' => ['nullable', 'integer', 'min:0'],
            'contact_view_limit_bonus' => ['nullable', 'integer', 'min:0'],
            'interest_send_limit_bonus' => ['nullable', 'integer', 'min:0'],
            'daily_profile_view_limit_bonus' => ['nullable', 'integer', 'min:0'],
            'who_viewed_me_preview_limit_bonus' => ['nullable', 'integer', 'min:0'],
            'referred_checkout_excluded' => ['sometimes', 'boolean'],
            'referred_checkout_percent_off' => ['nullable', 'integer', 'min:0', 'max:100'],
            'referred_checkout_extra_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $slug = strtolower(trim((string) $validated['plan_slug']));

        ReferralRewardRule::query()->updateOrCreate(
            ['plan_slug' => $slug],
            [
                'is_active' => $request->boolean('is_active'),
                'referred_checkout_excluded' => $request->boolean('referred_checkout_excluded'),
                'referred_checkout_percent_off' => $request->filled('referred_checkout_percent_off')
                    ? (int) $validated['referred_checkout_percent_off']
                    : null,
                'referred_checkout_extra_days' => $request->filled('referred_checkout_extra_days')
                    ? (int) $validated['referred_checkout_extra_days']
                    : null,
                'bonus_days' => (int) ($validated['bonus_days'] ?? 0),
                'chat_send_limit_bonus' => (int) ($validated['chat_send_limit_bonus'] ?? 0),
                'contact_view_limit_bonus' => (int) ($validated['contact_view_limit_bonus'] ?? 0),
                'interest_send_limit_bonus' => (int) ($validated['interest_send_limit_bonus'] ?? 0),
                'daily_profile_view_limit_bonus' => (int) ($validated['daily_profile_view_limit_bonus'] ?? 0),
                'who_viewed_me_preview_limit_bonus' => (int) ($validated['who_viewed_me_preview_limit_bonus'] ?? 0),
            ]
        );

        return redirect()
            ->route('admin.referrals.index', ['tab' => 'reward-plans', 'plan_slug' => $slug])
            ->with('success', 'Referral reward rule saved.');
    }

    public function saveEngine(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'min_plan_amount' => ['required', 'integer', 'min:0', 'max:1000000'],
            'monthly_cap' => ['required', 'integer', 'min:0', 'max:10000'],
            'fraud_rapid_invites' => ['required', 'integer', 'min:0', 'max:500'],
            'pending_claim_expiry_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'quality_cooling_period_days' => ['required', 'integer', 'min:0', 'max:365'],
            'enabled' => ['sometimes', 'boolean'],
            'paid_only' => ['sometimes', 'boolean'],
            'fraud_auto_hold' => ['sometimes', 'boolean'],
            'quality_require_profile_active' => ['sometimes', 'boolean'],
            'quality_require_mobile_verified' => ['sometimes', 'boolean'],
            'quality_require_photo_approved' => ['sometimes', 'boolean'],
            'referred_checkout_enabled' => ['sometimes', 'boolean'],
            'referred_checkout_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'referred_checkout_extra_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        AdminSetting::setValue(self::ENGINE_KEYS['enabled'], $request->boolean('enabled') ? '1' : '0');
        AdminSetting::setValue(self::ENGINE_KEYS['paid_only'], $request->boolean('paid_only') ? '1' : '0');
        AdminSetting::setValue(self::ENGINE_KEYS['min_plan_amount'], (string) ((int) $validated['min_plan_amount']));
        AdminSetting::setValue(self::ENGINE_KEYS['monthly_cap'], (string) ((int) $validated['monthly_cap']));
        AdminSetting::setValue('referral_fraud_auto_hold', $request->boolean('fraud_auto_hold') ? '1' : '0');
        AdminSetting::setValue('referral_fraud_rapid_invites_per_day', (string) ((int) $validated['fraud_rapid_invites']));
        AdminSetting::setValue('referral_pending_claim_expiry_days', (string) ((int) $validated['pending_claim_expiry_days']));
        AdminSetting::setValue('referral_quality_require_profile_active', $request->boolean('quality_require_profile_active') ? '1' : '0');
        AdminSetting::setValue('referral_quality_require_mobile_verified', $request->boolean('quality_require_mobile_verified') ? '1' : '0');
        AdminSetting::setValue('referral_quality_require_photo_approved', $request->boolean('quality_require_photo_approved') ? '1' : '0');
        AdminSetting::setValue('referral_quality_cooling_period_days', (string) ((int) $validated['quality_cooling_period_days']));
        AdminSetting::setValue('referral_referred_checkout_enabled', $request->boolean('referred_checkout_enabled') ? '1' : '0');
        AdminSetting::setValue('referral_referred_checkout_percent', (string) ((int) $validated['referred_checkout_percent']));
        AdminSetting::setValue('referral_referred_checkout_extra_days', (string) ((int) $validated['referred_checkout_extra_days']));
        AdminSetting::setValue('referral_renewal_micro_bonus_enabled', $request->boolean('renewal_micro_bonus_enabled') ? '1' : '0');
        AdminSetting::setValue('referral_renewal_micro_bonus_days', (string) ((int) $validated['renewal_micro_bonus_days']));

        return redirect()
            ->route('admin.referrals.index', ['tab' => 'engine'])
            ->with('success', 'Referral engine settings updated.');
    }

    public function exportReport(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'export_type' => ['nullable', Rule::in(['reports', 'audit'])],
            'reward' => ['nullable', Rule::in(['', '0', '1'])],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'audit_action' => ['nullable', 'string', 'max:64'],
            'audit_referrer_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $exportType = (string) ($validated['export_type'] ?? 'reports');
        if ($exportType === 'audit') {
            return $this->exportAuditCsv($validated);
        }

        $query = UserReferral::query()
            ->with(['referrer:id,name,mobile,email,referral_code', 'referredUser:id,name,mobile,email'])
            ->when(($validated['reward'] ?? '') === '1', fn ($q) => $q->where('reward_applied', true))
            ->when(($validated['reward'] ?? '') === '0', fn ($q) => $q->where('reward_applied', false));

        if (! empty($validated['from_date'])) {
            $query->whereDate('created_at', '>=', (string) $validated['from_date']);
        }
        if (! empty($validated['to_date'])) {
            $query->whereDate('created_at', '<=', (string) $validated['to_date']);
        }

        $from = ! empty($validated['from_date']) ? (string) $validated['from_date'] : null;
        $to = ! empty($validated['to_date']) ? (string) $validated['to_date'] : null;
        $bundle = app(ReferralService::class)->adminReportsBundle($from, $to);

        $rows = $query->orderByDesc('id')->get();
        $filename = 'referral-report-'.now()->format('Ymd-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ];

        return response()->streamDownload(function () use ($rows, $bundle): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            $summary = $bundle['summary'] ?? [];
            $funnel = $bundle['funnel'] ?? [];
            $economics = $bundle['economics'] ?? [];
            fputcsv($output, ['metric', 'value']);
            fputcsv($output, ['funnel_invited', (string) ($funnel['invited'] ?? 0)]);
            fputcsv($output, ['funnel_profile_ready', (string) ($funnel['profile_ready'] ?? 0)]);
            fputcsv($output, ['funnel_upgraded', (string) ($funnel['upgraded'] ?? 0)]);
            fputcsv($output, ['funnel_rewarded', (string) ($funnel['rewarded'] ?? 0)]);
            fputcsv($output, ['economics_referred_revenue', (string) ($economics['referred_first_paid_revenue'] ?? 0)]);
            fputcsv($output, ['economics_invite_discount', (string) ($economics['invite_checkout_discount'] ?? 0)]);
            fputcsv($output, ['economics_referrer_bonus_days', (string) ($economics['referrer_reward_bonus_days'] ?? 0)]);
            fputcsv($output, ['economics_referrer_cost_estimate', (string) ($economics['referrer_reward_cost_estimate'] ?? 0)]);
            fputcsv($output, ['economics_net_margin_estimate', (string) ($economics['net_margin_estimate'] ?? 0)]);
            fputcsv($output, ['summary_total', (string) ($summary['total'] ?? 0)]);
            fputcsv($output, ['summary_conversion_rate', (string) ($summary['conversion_rate'] ?? 0)]);
            fputcsv($output, []);

            fputcsv($output, [
                'id',
                'referrer_id',
                'referrer_name',
                'referrer_code',
                'referred_user_id',
                'referred_name',
                'reward_applied',
                'created_at',
            ]);

            foreach ($rows as $row) {
                fputcsv($output, [
                    (string) $row->id,
                    (string) $row->referrer_id,
                    (string) ($row->referrer?->name ?? ''),
                    (string) ($row->referrer?->referral_code ?? ''),
                    (string) $row->referred_user_id,
                    (string) ($row->referredUser?->name ?? ''),
                    $row->reward_applied ? 'yes' : 'no',
                    (string) optional($row->created_at)->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($output);
        }, $filename, $headers);
    }

    public function manualOverride(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'referrer_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'referred_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'bonus_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'chat_send_limit_bonus' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'contact_view_limit_bonus' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'interest_send_limit_bonus' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'daily_profile_view_limit_bonus' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'who_viewed_me_preview_limit_bonus' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $bonusDays = max(0, (int) ($validated['bonus_days'] ?? 0));
        $featureBonus = $this->buildFeatureBonusFromRequest($request);

        if ($bonusDays <= 0 && $featureBonus === []) {
            return redirect()
                ->route('admin.referrals.index', ['tab' => 'audit'])
                ->with('error', 'Add at least one bonus value to apply manual override.');
        }

        /** @var User $referrer */
        $referrer = User::query()->findOrFail((int) $validated['referrer_id']);
        $activeSub = app(SubscriptionService::class)->getActiveSubscription($referrer);
        if (! $activeSub || ! $activeSub->ends_at) {
            return redirect()
                ->route('admin.referrals.index', ['tab' => 'audit'])
                ->with('error', 'Manual override requires an active paid subscription for the referrer.');
        }

        DB::transaction(function () use ($activeSub, $bonusDays, $featureBonus, $validated, $request): void {
            $subscription = Subscription::query()->whereKey($activeSub->id)->lockForUpdate()->first();
            if (! $subscription || ! $subscription->ends_at) {
                return;
            }

            if ($bonusDays > 0) {
                $subscription->ends_at = $subscription->ends_at->copy()->addDays($bonusDays);
            }

            if ($featureBonus !== []) {
                $meta = is_array($subscription->meta) ? $subscription->meta : [];
                $carry = is_array($meta['carry_quota'] ?? null) ? $meta['carry_quota'] : [];
                foreach ($featureBonus as $featureKey => $inc) {
                    $carry[$featureKey] = max(0, (int) ($carry[$featureKey] ?? 0)) + max(0, (int) $inc);
                }
                $meta['carry_quota'] = $carry;
                $subscription->meta = $meta;
            }

            $subscription->save();
            app(EntitlementService::class)->resyncFromActiveSubscription((int) $subscription->user_id);

            $ledger = ReferralRewardLedger::query()->create([
                'user_referral_id' => null,
                'referrer_id' => (int) $validated['referrer_id'],
                'referred_user_id' => isset($validated['referred_user_id']) ? (int) $validated['referred_user_id'] : null,
                'performed_by_admin_id' => (int) $request->user()->id,
                'action_type' => 'manual_override',
                'bonus_days' => $bonusDays,
                'feature_bonus' => $featureBonus !== [] ? $featureBonus : null,
                'reason' => (string) $validated['reason'],
                'meta' => [
                    'subscription_id' => $subscription->id,
                ],
            ]);

            AuditLogService::log(
                $request->user(),
                'referral_manual_override',
                'ReferralRewardLedger',
                (int) $ledger->id,
                (string) $validated['reason'],
                false
            );
        });

        return redirect()
            ->route('admin.referrals.index', ['tab' => 'audit'])
            ->with('success', 'Manual referral override applied and logged.');
    }

    private function getBoolSetting(string $key, bool $default): bool
    {
        return filter_var(AdminSetting::getValue($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);
    }

    private function getIntSetting(string $key, int $default): int
    {
        return max(0, (int) AdminSetting::getValue($key, (string) $default));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function exportAuditCsv(array $validated): StreamedResponse
    {
        $query = ReferralRewardLedger::query()
            ->with(['referrer:id,name,mobile,email,referral_code', 'referredUser:id,name,mobile,email', 'performedByAdmin:id,name,email'])
            ->when(
                ((string) ($validated['audit_action'] ?? '')) !== '',
                fn ($q) => $q->where('action_type', (string) $validated['audit_action'])
            )
            ->when(
                isset($validated['audit_referrer_id']) && is_numeric((string) $validated['audit_referrer_id']),
                fn ($q) => $q->where('referrer_id', (int) $validated['audit_referrer_id'])
            );

        $rows = $query->orderByDesc('id')->get();
        $filename = 'referral-audit-'.now()->format('Ymd-His').'.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ];

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            fputcsv($output, [
                'id',
                'action_type',
                'referrer_id',
                'referrer_name',
                'referred_user_id',
                'referred_name',
                'bonus_days',
                'feature_bonus_json',
                'performed_by_admin',
                'reason',
                'created_at',
            ]);

            foreach ($rows as $row) {
                fputcsv($output, [
                    (string) $row->id,
                    (string) $row->action_type,
                    (string) $row->referrer_id,
                    (string) ($row->referrer?->name ?? ''),
                    (string) ($row->referred_user_id ?? ''),
                    (string) ($row->referredUser?->name ?? ''),
                    (string) $row->bonus_days,
                    is_array($row->feature_bonus) ? json_encode($row->feature_bonus, JSON_UNESCAPED_SLASHES) : '',
                    (string) ($row->performedByAdmin?->name ?? 'System'),
                    (string) ($row->reason ?? ''),
                    (string) optional($row->created_at)->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($output);
        }, $filename, $headers);
    }

    private function isValidDate(string $value): bool
    {
        if (trim($value) === '') {
            return false;
        }

        try {
            Carbon::createFromFormat('Y-m-d', $value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, int>
     */
    private function buildFeatureBonusFromRequest(Request $request): array
    {
        $raw = [
            'chat_send_limit' => (int) $request->input('chat_send_limit_bonus', 0),
            'contact_view_limit' => (int) $request->input('contact_view_limit_bonus', 0),
            'interest_send_limit' => (int) $request->input('interest_send_limit_bonus', 0),
            'daily_profile_view_limit' => (int) $request->input('daily_profile_view_limit_bonus', 0),
            'who_viewed_me_preview_limit' => (int) $request->input('who_viewed_me_preview_limit_bonus', 0),
        ];

        $out = [];
        $normalizer = app(FeatureUsageService::class);
        foreach ($raw as $key => $value) {
            $inc = max(0, (int) $value);
            if ($inc <= 0) {
                continue;
            }
            try {
                $normalized = $normalizer->normalizeFeatureKey($key);
            } catch (InvalidArgumentException) {
                continue;
            }
            $out[$normalized] = ($out[$normalized] ?? 0) + $inc;
        }

        return $out;
    }

    private function fraudRapidInvitesFromSettings(): int
    {
        $raw = AdminSetting::getValue('referral_fraud_rapid_invites_per_day', '');

        return $raw !== '' && $raw !== null
            ? max(0, (int) $raw)
            : max(0, (int) config('referral.fraud.rapid_invites_per_day', 5));
    }
}
