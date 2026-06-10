<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakPolicy;
use App\Models\SuchakVisitConfirmation;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(SuchakPolicyService $policyService): View
    {
        return view('admin.suchak.settings', [
            'current' => $this->currentValues($policyService),
            'pricingModes' => $this->pricingModes(),
            'paymentModes' => $this->paymentModes(),
            'commissionModes' => $this->commissionModes(),
            'visitConfirmationModes' => $this->visitConfirmationModes(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'default_consent_validity_months' => ['required', 'integer', 'min:1', 'max:60'],
            'allow_two_year_consent' => ['required', 'boolean'],
            'allow_until_revoked_consent' => ['required', 'boolean'],
            'request_action_sla_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'collaboration_sla_days' => ['required', 'integer', 'min:1', 'max:365'],
            'pdf_download_limit_per_day' => ['required', 'integer', 'min:1', 'max:10000'],
            'qr_token_expiry_days' => ['required', 'integer', 'min:1', 'max:365'],
            'suchak_upload_daily_limit' => ['required', 'integer', 'min:1', 'max:10000'],
            'suchak_active_profile_limit_by_plan' => ['required', 'integer', 'min:0', 'max:100000'],
            'suchak_free_trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'suchak_grace_period_days' => ['required', 'integer', 'min:0', 'max:365'],
            'suchak_plan_pricing_mode' => ['required', 'string', Rule::in(array_keys($this->pricingModes()))],
            'suchak_payment_mode' => ['required', 'string', Rule::in(array_keys($this->paymentModes()))],
            'suchak_visit_confirmation_policy_mode' => ['required', 'string', Rule::in(array_keys($this->visitConfirmationModes()))],
            'commission_mode' => ['required', 'string', Rule::in(array_keys($this->commissionModes()))],
            'commission_default_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'commission_default_amount' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'commission_require_ack' => ['required', 'boolean'],
        ]);

        $rows = $this->policyRows($validated, $request);
        $existing = SuchakPolicy::query()
            ->whereIn('policy_key', array_keys($rows))
            ->get()
            ->keyBy('policy_key');

        $changes = [];
        foreach ($rows as $key => $row) {
            $policy = $existing->get($key);
            $oldValue = $policy?->policy_value;
            $oldType = $policy?->value_type;
            $wasActive = (bool) ($policy?->is_active ?? false);

            if ($oldValue !== $row['policy_value'] || $oldType !== $row['value_type'] || $wasActive !== true) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $row['policy_value'],
                ];
            }
        }

        if ($changes === []) {
            return back()->with('info', 'No Suchak settings changed.');
        }

        DB::transaction(function () use ($rows, $changes, $validated, $request): void {
            foreach ($rows as $key => $row) {
                SuchakPolicy::query()->updateOrCreate(
                    ['policy_key' => $key],
                    [
                        'policy_value' => $row['policy_value'],
                        'value_type' => $row['value_type'],
                        'description' => $row['description'],
                        'is_active' => true,
                    ],
                );
            }

            AuditLogService::log(
                $request->user(),
                'suchak_settings_update',
                'suchak_policy',
                null,
                'Suchak settings update. Reason: '.trim((string) $validated['reason']).'. Changes: '.json_encode($changes, JSON_THROW_ON_ERROR),
                false,
            );
        });

        return back()->with('success', 'Suchak settings updated and audited.');
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(SuchakPolicyService $policyService): array
    {
        $commissionRules = array_merge(
            SuchakPolicyService::DEFAULT_SUCHAK_COMMISSION_RULES,
            $policyService->commissionRules(),
        );

        return [
            SuchakPolicyService::KEY_DEFAULT_CONSENT_VALIDITY_MONTHS => $policyService->consentValidityMonths(),
            SuchakPolicyService::KEY_ALLOW_TWO_YEAR_CONSENT => $policyService->allowsTwoYearConsent(),
            SuchakPolicyService::KEY_ALLOW_UNTIL_REVOKED_CONSENT => $policyService->allowsUntilRevokedConsent(),
            SuchakPolicyService::KEY_REQUEST_ACTION_SLA_HOURS => $policyService->requestActionSlaHours(),
            SuchakPolicyService::KEY_COLLABORATION_SLA_DAYS => $policyService->collaborationSlaDays(),
            SuchakPolicyService::KEY_PDF_DOWNLOAD_LIMIT_PER_DAY => $policyService->pdfDownloadLimitPerDay(),
            SuchakPolicyService::KEY_QR_TOKEN_EXPIRY_DAYS => $policyService->qrTokenExpiryDays(),
            SuchakPolicyService::KEY_SUCHAK_UPLOAD_DAILY_LIMIT => $policyService->uploadDailyLimit(),
            SuchakPolicyService::KEY_SUCHAK_ACTIVE_PROFILE_LIMIT_BY_PLAN => $policyService->activeProfileFallbackLimit(),
            SuchakPolicyService::KEY_SUCHAK_FREE_TRIAL_DAYS => $policyService->freeTrialDays(),
            SuchakPolicyService::KEY_SUCHAK_GRACE_PERIOD_DAYS => $policyService->gracePeriodDays(),
            SuchakPolicyService::KEY_SUCHAK_PLAN_PRICING_MODE => $policyService->planPricingMode(),
            SuchakPolicyService::KEY_SUCHAK_PAYMENT_MODE => $policyService->paymentMode(),
            SuchakPolicyService::KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE => $policyService->visitConfirmationPolicyMode(),
            'commission_mode' => (string) $commissionRules['mode'],
            'commission_default_percent' => (int) $commissionRules['default_percent'],
            'commission_default_amount' => (float) $commissionRules['default_amount'],
            'commission_require_ack' => (bool) $commissionRules['require_ack'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, array{policy_value: string, value_type: string, description: string}>
     */
    private function policyRows(array $validated, Request $request): array
    {
        $commissionRules = [
            'mode' => (string) $validated['commission_mode'],
            'default_percent' => (int) $validated['commission_default_percent'],
            'default_amount' => (float) $validated['commission_default_amount'],
            'require_ack' => $request->boolean('commission_require_ack'),
        ];

        return [
            SuchakPolicyService::KEY_DEFAULT_CONSENT_VALIDITY_MONTHS => $this->integerRow($validated, 'default_consent_validity_months', 'Default consent validity in months.'),
            SuchakPolicyService::KEY_ALLOW_TWO_YEAR_CONSENT => $this->booleanRow($request, 'allow_two_year_consent', 'Allow two-year consent validity option.'),
            SuchakPolicyService::KEY_ALLOW_UNTIL_REVOKED_CONSENT => $this->booleanRow($request, 'allow_until_revoked_consent', 'Allow until-revoked consent validity option.'),
            SuchakPolicyService::KEY_REQUEST_ACTION_SLA_HOURS => $this->integerRow($validated, 'request_action_sla_hours', 'Request action SLA in hours.'),
            SuchakPolicyService::KEY_COLLABORATION_SLA_DAYS => $this->integerRow($validated, 'collaboration_sla_days', 'Collaboration response SLA in days.'),
            SuchakPolicyService::KEY_PDF_DOWNLOAD_LIMIT_PER_DAY => $this->integerRow($validated, 'pdf_download_limit_per_day', 'PDF download/share limit per day.'),
            SuchakPolicyService::KEY_QR_TOKEN_EXPIRY_DAYS => $this->integerRow($validated, 'qr_token_expiry_days', 'QR token expiry in days.'),
            SuchakPolicyService::KEY_SUCHAK_UPLOAD_DAILY_LIMIT => $this->integerRow($validated, 'suchak_upload_daily_limit', 'Suchak upload limit per day.'),
            SuchakPolicyService::KEY_SUCHAK_ACTIVE_PROFILE_LIMIT_BY_PLAN => $this->integerRow($validated, 'suchak_active_profile_limit_by_plan', 'Fallback active profile limit when plan feature is missing.'),
            SuchakPolicyService::KEY_SUCHAK_FREE_TRIAL_DAYS => $this->integerRow($validated, 'suchak_free_trial_days', 'Default free trial days for Suchak plans.'),
            SuchakPolicyService::KEY_SUCHAK_GRACE_PERIOD_DAYS => $this->integerRow($validated, 'suchak_grace_period_days', 'Default grace period days after Suchak plan expiry.'),
            SuchakPolicyService::KEY_SUCHAK_PLAN_PRICING_MODE => $this->stringRow($validated, 'suchak_plan_pricing_mode', 'Suchak plan pricing mode.'),
            SuchakPolicyService::KEY_SUCHAK_PAYMENT_MODE => $this->stringRow($validated, 'suchak_payment_mode', 'Suchak platform payment mode.'),
            SuchakPolicyService::KEY_SUCHAK_VISIT_CONFIRMATION_POLICY_MODE => $this->stringRow($validated, 'suchak_visit_confirmation_policy_mode', 'Confirmation policy required before platform visit payouts can be qualified.'),
            SuchakPolicyService::KEY_SUCHAK_COMMISSION_RULES_JSON => [
                'policy_value' => json_encode($commissionRules, JSON_THROW_ON_ERROR),
                'value_type' => SuchakPolicy::TYPE_JSON,
                'description' => 'Default Suchak collaboration commission rule.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{policy_value: string, value_type: string, description: string}
     */
    private function integerRow(array $validated, string $key, string $description): array
    {
        return [
            'policy_value' => (string) (int) $validated[$key],
            'value_type' => SuchakPolicy::TYPE_INTEGER,
            'description' => $description,
        ];
    }

    /**
     * @return array{policy_value: string, value_type: string, description: string}
     */
    private function booleanRow(Request $request, string $key, string $description): array
    {
        return [
            'policy_value' => $request->boolean($key) ? 'true' : 'false',
            'value_type' => SuchakPolicy::TYPE_BOOLEAN,
            'description' => $description,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{policy_value: string, value_type: string, description: string}
     */
    private function stringRow(array $validated, string $key, string $description): array
    {
        return [
            'policy_value' => (string) $validated[$key],
            'value_type' => SuchakPolicy::TYPE_STRING,
            'description' => $description,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pricingModes(): array
    {
        return [
            'manual_catalog' => 'Manual catalog',
            'free_trial_then_manual' => 'Free trial then manual',
            'paid_required' => 'Paid required',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function paymentModes(): array
    {
        return [
            'manual_only' => 'Manual only',
            'payu_test_mode' => 'PayU test mode',
            'live_credentials_pending' => 'Live credentials pending',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function commissionModes(): array
    {
        return [
            'to_be_discussed' => 'To be discussed',
            'none' => 'No commission',
            'percentage' => 'Percentage',
            'fixed_amount' => 'Fixed amount',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function visitConfirmationModes(): array
    {
        return [
            SuchakVisitConfirmation::POLICY_USER_AND_ADMIN => 'User and admin confirmation',
            SuchakVisitConfirmation::POLICY_ADMIN_ONLY => 'Admin confirmation only',
            SuchakVisitConfirmation::POLICY_USER_ONLY => 'User confirmation only',
        ];
    }
}
