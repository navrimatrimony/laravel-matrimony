<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakPlan;
use App\Models\SuchakPlanPayment;
use App\Modules\Suchak\Services\SuchakPlanPaymentService;
use App\Support\PayuHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlanPaymentController extends Controller
{
    public function start(
        Request $request,
        SuchakPlan $suchakPlan,
        SuchakPlanPaymentService $payments,
    ): View|RedirectResponse {
        $account = $request->user()
            ->suchakAccount()
            ->with('user')
            ->firstOrFail();

        try {
            $checkout = $payments->startCheckout(
                $account,
                $request->user(),
                $suchakPlan,
                $request->ip(),
                Str::limit((string) $request->userAgent(), 512, ''),
            );
        } catch (HttpException| \InvalidArgumentException $exception) {
            return redirect()
                ->route('suchak.dashboard')
                ->with('error', $exception->getMessage());
        }

        return view('payments.payu_redirect', [
            'action' => $checkout['action'],
            'fields' => $checkout['fields'],
            'payuAutoSubmitDelayMs' => 6000,
        ]);
    }

    public function success(Request $request, SuchakPlanPaymentService $payments): RedirectResponse
    {
        try {
            $payment = $payments->completeSuccessfulCallback($request->all(), 'redirect');
        } catch (HttpException $exception) {
            return redirect()
                ->route('suchak.dashboard')
                ->with('error', $exception->getMessage());
        }

        $this->restoreSuchakSession($request, $payment);

        return redirect()
            ->route('suchak.dashboard')
            ->with('success', $payments->successMessage($payment));
    }

    public function failure(Request $request, SuchakPlanPaymentService $payments): RedirectResponse
    {
        try {
            $payment = $payments->completeFailureCallback($request->all(), 'redirect');
            $this->restoreSuchakSession($request, $payment);
        } catch (HttpException $exception) {
            return redirect()
                ->route('suchak.dashboard')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('suchak.dashboard')
            ->with('error', 'Suchak plan payment failed. Subscription was not activated.');
    }

    public function webhook(Request $request, SuchakPlanPaymentService $payments): JsonResponse
    {
        try {
            $payment = $payments->handleWebhook($request->all());
        } catch (HttpException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode());
        }

        return response()->json([
            'status' => $payment->payment_status,
            'txnid' => $payment->txnid,
        ]);
    }

    public function testSuccessSimulate(
        Request $request,
        SuchakPlan $suchakPlan,
        SuchakPlanPaymentService $payments,
    ): RedirectResponse {
        if (! app()->environment(['local', 'development', 'testing'])) {
            abort(403);
        }

        $account = $request->user()
            ->suchakAccount()
            ->with('user')
            ->firstOrFail();

        try {
            $checkout = $payments->startCheckout(
                $account,
                $request->user(),
                $suchakPlan,
                $request->ip(),
                Str::limit((string) $request->userAgent(), 512, ''),
            );
            $fields = $checkout['fields'];
            $fields['status'] = 'success';
            $fields['mihpayid'] = 'TEST_SUCHAK';
            $fields['mode'] = 'TEST';
            $fields['hash'] = PayuHasher::paymentResponseHash(
                (string) config('payu.merchant_salt', ''),
                'success',
                $fields['email'],
                $fields['firstname'],
                $fields['productinfo'],
                $fields['amount'],
                $fields['txnid'],
                $fields['key'],
                $fields['udf1'],
                $fields['udf2'],
                $fields['udf3'],
                $fields['udf4'],
                $fields['udf5'],
            );

            $payment = $payments->completeSuccessfulCallback($fields, 'test_mode');
        } catch (HttpException| \InvalidArgumentException $exception) {
            return redirect()
                ->route('suchak.dashboard')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('suchak.dashboard')
            ->with('success', $payments->successMessage($payment));
    }

    private function restoreSuchakSession(Request $request, SuchakPlanPayment $payment): void
    {
        $userId = (int) $payment->initiated_by_user_id;
        if ($userId <= 0) {
            return;
        }

        Auth::guard('web')->loginUsingId($userId, false);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
    }
}
