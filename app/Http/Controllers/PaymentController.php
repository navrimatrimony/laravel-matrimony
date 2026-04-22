<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function refund(Request $request, string $txnid): RedirectResponse
    {
        $payment = Payment::query()->where('txnid', $txnid)->firstOrFail();

        $reason = $request->input('refund_reason');
        if (is_string($reason)) {
            $reason = trim($reason) === '' ? null : $reason;
        } else {
            $reason = null;
        }

        if (! $payment->refund($reason)) {
            return redirect()
                ->back()
                ->with('error', 'Refund is only allowed for successful payments that are not already refunded.');
        }

        $user = User::query()->find($payment->user_id);
        if ($user) {
            $user->plan = null;
            $user->plan_status = 'cancelled';
            $user->plan_expires_at = now();
            $user->save();
        }

        return redirect()
            ->back()
            ->with('success', 'Refund processed.');
    }
}
