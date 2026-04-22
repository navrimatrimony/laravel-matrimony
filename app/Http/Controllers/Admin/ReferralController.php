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
use App\Services\SubscriptionService;
use Carbon\Carbon;
use InvalidArgumentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
        if (! in_array($tab, ['engine', 'reward-plans', 'reports', 'audit'], true)) {
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
        ];

        $reportSummary = [
            'total' => (clone $referralsQuery)->count(),
            'rewarded' => (clone $referralsQuery)->where('reward_applied', true)->count(),
            'pending' => (clone $referralsQuery)->where('reward_applied', false)->count(),
        ];
        $reportSummary['conversion_rate'] = $reportSummary['total'] > 0
            ? round(($reportSummary['rewarded'] * 100) / $reportSummary['total'], 2)
            : 0.0;

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
            'topReferrers',
            'selectedPlanSlug',
            'selectedRewardRule',
            'auditAction',
            'auditReferrerId',
            'ledgers',
            'auditActionTypes',
        ));
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
            'who_viewed_me_days_bonus' => ['nullable', 'integer', 'min:0'],
            'who_viewed_me_preview_limit_bonus' => ['nullable', 'integer', 'min:0'],
        ]);

        $slug = strtolower(trim((string) $validated['plan_slug']));

        ReferralRewardRule::query()->updateOrCreate(
            ['plan_slug' => $slug],
            [
                'is_active' => $request->boolean('is_active'),
                'bonus_days' => (int) ($validated['bonus_days'] ?? 0),
                'chat_send_limit_bonus' => (int) ($validated['chat_send_limit_bonus'] ?? 0),
                'contact_view_limit_bonus' => (int) ($validated['contact_view_limit_bonus'] ?? 0),
                'interest_send_limit_bonus' => (int) ($validated['interest_send_limit_bonus'] ?? 0),
                'daily_profile_view_limit_bonus' => (int) ($validated['daily_profile_view_limit_bonus'] ?? 0),
                'who_viewed_me_days_bonus' => (int) ($validated['who_viewed_me_days_bonus'] ?? 0),
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
            'enabled' => ['sometimes', 'boolean'],
            'paid_only' => ['sometimes', 'boolean'],
        ]);

        AdminSetting::setValue(self::ENGINE_KEYS['enabled'], $request->boolean('enabled') ? '1' : '0');
        AdminSetting::setValue(self::ENGINE_KEYS['paid_only'], $request->boolean('paid_only') ? '1' : '0');
        AdminSetting::setValue(self::ENGINE_KEYS['min_plan_amount'], (string) ((int) $validated['min_plan_amount']));
        AdminSetting::setValue(self::ENGINE_KEYS['monthly_cap'], (string) ((int) $validated['monthly_cap']));

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

        $rows = $query->orderByDesc('id')->get();
        $filename = 'referral-report-'.now()->format('Ymd-His').'.csv';

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
            'who_viewed_me_days_bonus' => ['nullable', 'integer', 'min:0', 'max:100000'],
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
     * @param array<string, mixed> $validated
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
            'who_viewed_me_days' => (int) $request->input('who_viewed_me_days_bonus', 0),
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
}
