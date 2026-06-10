<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakPlatformPayout;
use App\Models\SuchakPlatformPayoutSettlement;
use App\Modules\Suchak\Services\SuchakPlatformPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class PayoutController extends Controller
{
    public function index(Request $request, SuchakPlatformPayoutService $payoutService): View
    {
        $statementMonth = $this->statementMonth((string) $request->query('statement_month', now()->format('Y-m')));
        $status = (string) $request->query('status', '');
        $status = in_array($status, SuchakPlatformPayout::STATUSES, true) ? $status : null;

        return view('admin.suchak.payouts.index', [
            'statementMonth' => $statementMonth,
            'status' => $status,
            'statuses' => SuchakPlatformPayout::STATUSES,
            'report' => $payoutService->adminReportBundle($statementMonth),
            'payouts' => SuchakPlatformPayout::query()
                ->with(['suchakAccount', 'paymentContext', 'details', 'settlementStatement'])
                ->when($status, fn ($query) => $query->where('payout_status', $status))
                ->latest('liability_recognized_at')
                ->paginate(20)
                ->withQueryString(),
            'settlements' => SuchakPlatformPayoutSettlement::query()
                ->with('suchakAccount')
                ->latest('generated_at')
                ->limit(10)
                ->get(),
            'accounts' => SuchakAccount::query()
                ->where('verification_status', SuchakAccount::VERIFICATION_VERIFIED)
                ->orderBy('suchak_name')
                ->limit(50)
                ->get(),
        ]);
    }

    public function approve(Request $request, SuchakPlatformPayout $payout, SuchakPlatformPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'status_note' => ['required', 'string', 'min:10', 'max:1000'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->runPayoutAction(
            fn () => $payoutService->approvePayout($payout, $request->user(), $validated, $request->ip(), (string) $request->userAgent()),
            'Suchak platform payout approved.'
        );
    }

    public function pay(Request $request, SuchakPlatformPayout $payout, SuchakPlatformPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'payout_reference_number' => ['required', 'string', 'min:3', 'max:160'],
            'payout_reference_note' => ['nullable', 'string', 'max:1000'],
            'paid_at' => ['nullable', 'date'],
        ]);

        return $this->runPayoutAction(
            fn () => $payoutService->markPayoutPaid($payout, $request->user(), $validated, $request->ip(), (string) $request->userAgent()),
            'Suchak platform payout marked paid.'
        );
    }

    public function reverse(Request $request, SuchakPlatformPayout $payout, SuchakPlatformPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'reversal_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return $this->runPayoutAction(
            fn () => $payoutService->reversePayout($payout, $request->user(), $validated, $request->ip(), (string) $request->userAgent()),
            'Suchak platform payout reversed.'
        );
    }

    public function cancel(Request $request, SuchakPlatformPayout $payout, SuchakPlatformPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return $this->runPayoutAction(
            fn () => $payoutService->cancelPayout($payout, $request->user(), $validated['cancellation_reason'], $request->ip(), (string) $request->userAgent()),
            'Suchak platform payout cancelled.'
        );
    }

    public function generateSettlement(Request $request, SuchakPlatformPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'suchak_account_id' => ['required', 'integer', 'exists:suchak_accounts,id'],
            'statement_month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $account = SuchakAccount::query()->findOrFail((int) $validated['suchak_account_id']);

        return $this->runPayoutAction(
            fn () => $payoutService->generateMonthlySettlementStatement(
                $account,
                $request->user(),
                $validated['statement_month'],
                $request->ip(),
                (string) $request->userAgent(),
            ),
            'Suchak payout settlement statement generated.'
        );
    }

    private function runPayoutAction(callable $callback, string $successMessage): RedirectResponse
    {
        try {
            $callback();
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.suchak.payouts.index')
            ->with('success', $successMessage);
    }

    private function statementMonth(string $month): string
    {
        return preg_match('/^\d{4}-\d{2}$/', $month) ? $month : now()->format('Y-m');
    }
}
