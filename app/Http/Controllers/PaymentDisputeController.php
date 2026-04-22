<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentDispute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentDisputeController extends Controller
{
    /**
     * Member: open a dispute for a payment they own.
     */
    public function store(Request $request, string $txnid): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:10000'],
        ]);

        $payment = Payment::query()->where('txnid', $txnid)->firstOrFail();

        if ((int) $payment->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        PaymentDispute::query()->create([
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'reason' => $validated['reason'],
            'status' => PaymentDispute::STATUS_OPEN,
        ]);

        return redirect()
            ->back()
            ->with('success', 'Your dispute has been submitted.');
    }

    /**
     * Admin: mark dispute resolved and optionally add a note.
     */
    public function resolve(Request $request, PaymentDispute $payment_dispute): RedirectResponse
    {
        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:10000'],
        ]);

        $payment_dispute->status = PaymentDispute::STATUS_RESOLVED;
        $payment_dispute->admin_note = $validated['admin_note'] ?? null;
        $payment_dispute->save();

        return redirect()
            ->back()
            ->with('success', 'Dispute marked as resolved.');
    }
}
