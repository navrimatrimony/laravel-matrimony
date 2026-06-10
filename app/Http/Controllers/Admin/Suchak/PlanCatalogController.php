<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakPlan;
use App\Models\SuchakPlanFeature;
use App\Modules\Suchak\Services\SuchakBillingCatalogService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class PlanCatalogController extends Controller
{
    public function index(
        Request $request,
        SuchakBillingCatalogService $catalogService,
        SuchakPolicyService $policyService,
    ): View {
        return view('admin.suchak.plans.index', [
            'plans' => $catalogService->catalogForAdmin($request->user()),
            'featureDefinitions' => $this->featureDefinitions(),
            'policySummary' => [
                'free_trial_days' => $policyService->freeTrialDays(),
                'grace_period_days' => $policyService->gracePeriodDays(),
                'pricing_mode' => $policyService->planPricingMode(),
                'payment_mode' => $policyService->paymentMode(),
            ],
        ]);
    }

    public function store(Request $request, SuchakBillingCatalogService $catalogService): RedirectResponse
    {
        $validated = $this->validatedPlanPayload($request);

        try {
            $catalogService->createPlan(
                $request->user(),
                $validated,
                $validated['features'],
                $validated['reason'],
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.suchak.plans.index')
            ->with('success', 'Suchak plan created.');
    }

    public function update(
        Request $request,
        SuchakPlan $suchakPlan,
        SuchakBillingCatalogService $catalogService,
    ): RedirectResponse {
        $validated = $this->validatedPlanPayload($request, $suchakPlan);

        try {
            $catalogService->updatePlan(
                $suchakPlan,
                $request->user(),
                $validated,
                $validated['features'],
                $validated['reason'],
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.suchak.plans.index')
            ->with('success', 'Suchak plan updated.');
    }

    public function assignAccountPlan(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakBillingCatalogService $catalogService,
    ): RedirectResponse {
        $validated = $request->validate([
            'suchak_plan_id' => ['required', 'integer', Rule::exists('suchak_plans', 'id')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $startsAt = filled($validated['starts_at'] ?? null) ? Carbon::parse($validated['starts_at']) : null;
        $endsAt = filled($validated['ends_at'] ?? null) ? Carbon::parse($validated['ends_at']) : null;

        if ($endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt ?? now())) {
            return back()
                ->withInput()
                ->withErrors(['ends_at' => 'Subscription end date must be after start date.']);
        }

        try {
            $catalogService->assignManualSubscription(
                $suchakAccount,
                SuchakPlan::query()->findOrFail((int) $validated['suchak_plan_id']),
                $request->user(),
                $validated['reason'],
                $startsAt,
                $endsAt,
                $request->ip(),
                Str::limit((string) $request->userAgent(), 512, ''),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.suchak.accounts.show', $suchakAccount)
            ->with('success', 'Suchak plan assigned.');
    }

    /**
     * @return array<string, array{label: string, type: string, default: string}>
     */
    private function featureDefinitions(): array
    {
        return [
            SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT => [
                'label' => 'Active profile limit',
                'type' => SuchakPlanFeature::TYPE_INTEGER,
                'default' => '25',
            ],
            SuchakPlanFeature::FEATURE_MONTHLY_UPLOAD_LIMIT => [
                'label' => 'Monthly upload limit',
                'type' => SuchakPlanFeature::TYPE_INTEGER,
                'default' => '50',
            ],
            SuchakPlanFeature::FEATURE_LEAD_REQUEST_LIMIT => [
                'label' => 'Open lead request limit',
                'type' => SuchakPlanFeature::TYPE_INTEGER,
                'default' => '20',
            ],
            SuchakPlanFeature::FEATURE_COLLABORATION_REQUEST_LIMIT => [
                'label' => 'Open collaboration limit',
                'type' => SuchakPlanFeature::TYPE_INTEGER,
                'default' => '10',
            ],
            SuchakPlanFeature::FEATURE_PDF_DOWNLOAD_SHARE_LIMIT => [
                'label' => 'Daily PDF/QR limit',
                'type' => SuchakPlanFeature::TYPE_INTEGER,
                'default' => '20',
            ],
            SuchakPlanFeature::FEATURE_LEDGER_FEATURES => [
                'label' => 'Ledger features',
                'type' => SuchakPlanFeature::TYPE_BOOLEAN,
                'default' => 'true',
            ],
            SuchakPlanFeature::FEATURE_CRM_FEATURES => [
                'label' => 'CRM features',
                'type' => SuchakPlanFeature::TYPE_BOOLEAN,
                'default' => 'true',
            ],
            SuchakPlanFeature::FEATURE_PRIORITY_SUPPORT => [
                'label' => 'Priority support',
                'type' => SuchakPlanFeature::TYPE_BOOLEAN,
                'default' => 'false',
            ],
            SuchakPlanFeature::FEATURE_BULK_UPLOAD_ACCESS => [
                'label' => 'Bulk upload access',
                'type' => SuchakPlanFeature::TYPE_BOOLEAN,
                'default' => 'false',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPlanPayload(Request $request, ?SuchakPlan $plan = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('suchak_plans', 'slug')->ignore($plan?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'currency' => ['nullable', 'string', 'size:3', 'required_with:price_amount'],
            'is_active' => ['required', 'boolean'],
            'is_visible' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'features' => ['required', 'array', 'min:1'],
            'features.*.feature_key' => ['required', 'string', Rule::in(SuchakPlanFeature::FEATURE_KEYS)],
            'features.*.value_type' => ['required', 'string', Rule::in([
                SuchakPlanFeature::TYPE_INTEGER,
                SuchakPlanFeature::TYPE_BOOLEAN,
                SuchakPlanFeature::TYPE_STRING,
            ])],
            'features.*.feature_value' => ['nullable', 'string', 'max:255'],
            'features.*.is_enabled' => ['required', 'boolean'],
        ]);
    }
}
