<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakCampaignRule;
use App\Models\SuchakMonthlyValueReport;
use App\Models\SuchakRetentionOffer;
use App\Modules\Suchak\Services\SuchakRetentionCampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class RetentionController extends Controller
{
    public function index(Request $request, SuchakRetentionCampaignService $retentionService): View
    {
        return view('admin.suchak.retention.index', [
            'summary' => $retentionService->adminSummary($request->query('month')),
            'goals' => SuchakCampaignRule::GOALS,
            'metrics' => SuchakCampaignRule::METRICS,
            'bonusTypes' => SuchakCampaignRule::BONUS_TYPES,
            'offerTypes' => SuchakRetentionOffer::TYPES,
        ]);
    }

    public function storeCampaignRule(
        Request $request,
        SuchakRetentionCampaignService $retentionService
    ): RedirectResponse {
        $validated = $request->validate([
            'campaign_key' => ['required', 'string', 'max:96'],
            'campaign_name' => ['required', 'string', 'min:10', 'max:160'],
            'campaign_goal' => ['required', 'string', Rule::in(SuchakCampaignRule::GOALS)],
            'qualification_metric' => ['required', 'string', Rule::in(SuchakCampaignRule::METRICS)],
            'threshold_value' => ['required', 'numeric', 'min:0'],
            'bonus_type' => ['required', 'string', Rule::in(SuchakCampaignRule::BONUS_TYPES)],
            'bonus_amount' => ['required', 'numeric', 'min:0'],
            'bonus_currency' => ['required', 'string', 'size:3'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        return $this->runRetentionAction(
            fn () => $retentionService->createCampaignRule($request->user(), $validated),
            'Suchak campaign rule created.'
        );
    }

    public function qualifyCampaignBonus(
        Request $request,
        SuchakCampaignRule $campaignRule,
        SuchakRetentionCampaignService $retentionService
    ): RedirectResponse {
        $validated = $request->validate([
            'suchak_account_id' => ['required', 'integer', 'exists:suchak_accounts,id'],
            'qualification_month' => ['required', 'date_format:Y-m'],
            'metric_value' => ['nullable', 'numeric', 'min:0'],
            'qualification_note' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $account = SuchakAccount::query()->findOrFail((int) $validated['suchak_account_id']);

        return $this->runRetentionAction(
            fn () => $retentionService->qualifyCampaignBonus($campaignRule, $account, $request->user(), $validated),
            'Suchak campaign bonus qualified.'
        );
    }

    public function generateReport(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakRetentionCampaignService $retentionService
    ): RedirectResponse {
        $validated = $request->validate([
            'report_month' => ['required', 'date_format:Y-m'],
        ]);

        return $this->runRetentionAction(
            fn () => $retentionService->generateMonthlyValueReport(
                $suchakAccount,
                $request->user(),
                $validated['report_month'],
            ),
            'Suchak monthly value report generated.'
        );
    }

    public function createOffer(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakRetentionCampaignService $retentionService
    ): RedirectResponse {
        $validated = $request->validate([
            'monthly_value_report_id' => ['nullable', 'integer', 'exists:suchak_monthly_value_reports,id'],
            'offer_type' => ['required', 'string', Rule::in(SuchakRetentionOffer::TYPES)],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'revenue_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'offer_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'offer_note' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $report = isset($validated['monthly_value_report_id'])
            ? SuchakMonthlyValueReport::query()->findOrFail((int) $validated['monthly_value_report_id'])
            : null;

        return $this->runRetentionAction(
            fn () => $retentionService->createRetentionOffer($suchakAccount, $request->user(), $validated, $report),
            'Suchak retention offer recorded.'
        );
    }

    private function runRetentionAction(callable $callback, string $successMessage): RedirectResponse
    {
        try {
            $callback();
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return redirect()
            ->route('admin.suchak.retention.index')
            ->with('success', $successMessage);
    }
}
