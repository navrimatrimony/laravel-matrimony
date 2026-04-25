<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\Payment;
use App\Models\PaymentInvoice;
use App\Models\User;
use App\Support\PaymentLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

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

    public function showMemberInvoice(Request $request, string $txnid): View
    {
        $startedAt = microtime(true);
        $payment = Payment::query()
            ->with($this->paymentWithRelations())
            ->where('txnid', $txnid)
            ->firstOrFail();

        abort_unless((int) $payment->user_id === (int) $request->user()->id, 403);

        $viewData = $this->invoiceViewData($payment, false);
        PaymentLogger::logEvent('invoice_generated', [
            'txnid' => $payment->txnid,
            'user_id' => $payment->user_id,
            'plan_id' => $payment->plan_id,
            'plan_term_id' => $payment->plan_term_id,
            'internal_status' => 'invoice_viewed',
            'source' => 'redirect',
            'amount' => (float) ($payment->amount_paid ?? $payment->amount ?? 0),
        ]);
        PaymentLogger::recordTiming('invoice_render_time_ms', (int) round((microtime(true) - $startedAt) * 1000));

        return view('payments.invoice', $viewData);
    }

    public function showAdminInvoice(Request $request, string $txnid): View
    {
        $startedAt = microtime(true);
        $payment = Payment::query()
            ->with($this->paymentWithRelations())
            ->where('txnid', $txnid)
            ->firstOrFail();

        $viewData = $this->invoiceViewData($payment, true);
        PaymentLogger::logEvent('invoice_generated', [
            'txnid' => $payment->txnid,
            'user_id' => $payment->user_id,
            'plan_id' => $payment->plan_id,
            'plan_term_id' => $payment->plan_term_id,
            'internal_status' => 'invoice_viewed_admin',
            'source' => 'redirect',
            'amount' => (float) ($payment->amount_paid ?? $payment->amount ?? 0),
        ]);
        PaymentLogger::recordTiming('invoice_render_time_ms', (int) round((microtime(true) - $startedAt) * 1000));

        return view('payments.invoice', $viewData);
    }

    public function downloadMemberInvoicePdf(Request $request, string $txnid)
    {
        $payment = Payment::query()
            ->with($this->paymentWithRelations())
            ->where('txnid', $txnid)
            ->firstOrFail();

        abort_unless((int) $payment->user_id === (int) $request->user()->id, 403);

        return $this->downloadInvoicePdf($payment, false);
    }

    public function downloadAdminInvoicePdf(Request $request, string $txnid)
    {
        $payment = Payment::query()
            ->with($this->paymentWithRelations())
            ->where('txnid', $txnid)
            ->firstOrFail();

        return $this->downloadInvoicePdf($payment, true);
    }

    /**
     * @return array<string,mixed>
     */
    private function invoiceViewData(Payment $payment, bool $isAdminView): array
    {
        $invoice = $this->resolveLockedInvoice($payment);
        $meta = is_array($payment->meta) ? $payment->meta : [];

        return [
            'layout' => $isAdminView ? 'layouts.admin' : 'layouts.app',
            'isAdminView' => $isAdminView,
            'payment' => $payment,
            'invoiceNo' => $invoice->invoice_number,
            'invoiceDate' => $payment->created_at,
            'planName' => (string) ($meta['plan_name'] ?? $payment->plan?->name ?? $payment->plan_key ?? 'Plan'),
            'billingKey' => (string) ($meta['billing_key'] ?? $payment->billing_key ?? ''),
            'baseAmount' => (float) ($meta['base_amount'] ?? $payment->amount_paid ?? $payment->amount ?? 0),
            'discountAmount' => (float) ($meta['coupon_discount'] ?? 0),
            'walletUsed' => (float) ($meta['wallet_used'] ?? 0),
            'finalAmount' => (float) ($meta['final_amount_after_coupon'] ?? $payment->amount_paid ?? $payment->amount ?? 0),
            'currency' => (string) ($payment->currency ?: 'INR'),
            'seller' => [
                'legal_name' => (string) AdminSetting::getValue('billing_legal_name', ''),
                'address' => (string) AdminSetting::getValue('billing_address', ''),
                'email' => (string) AdminSetting::getValue('billing_email', ''),
                'phone' => (string) AdminSetting::getValue('billing_phone', ''),
                'gstin' => (string) AdminSetting::getValue('billing_gstin', ''),
                'pan' => (string) AdminSetting::getValue('billing_pan', ''),
                'state_code' => (string) AdminSetting::getValue('billing_state_code', ''),
                'terms' => (string) AdminSetting::getValue('billing_invoice_terms', ''),
            ],
        ];
    }

    private function resolveLockedInvoice(Payment $payment): PaymentInvoice
    {
        if (! $this->invoiceTableReady()) {
            return $this->fallbackInvoiceForMissingTable($payment);
        }

        if ($payment->relationLoaded('invoice') && $payment->invoice) {
            return $payment->invoice;
        }

        return DB::transaction(function () use ($payment) {
            $existing = PaymentInvoice::query()
                ->where('payment_id', $payment->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $prefix = trim((string) AdminSetting::getValue('billing_invoice_prefix', 'INV'));
            $dt = $payment->created_at ?? now();
            $year = (int) $dt->format('Y');
            $month = (int) $dt->format('n');
            $fyStart = $month >= 4 ? $year : ($year - 1);
            $fyEnd = $fyStart + 1;
            $fyLabel = substr((string) $fyStart, -2).'-'.substr((string) $fyEnd, -2);

            $lastSeq = (int) PaymentInvoice::query()
                ->where('fy_label', $fyLabel)
                ->lockForUpdate()
                ->max('sequence_no');
            $nextSeq = $lastSeq + 1;
            $number = strtoupper($prefix).'/'.$fyLabel.'/'.str_pad((string) $nextSeq, 6, '0', STR_PAD_LEFT);

            return PaymentInvoice::query()->create([
                'payment_id' => $payment->id,
                'invoice_number' => $number,
                'fy_label' => $fyLabel,
                'sequence_no' => $nextSeq,
            ]);
        });
    }

    private function downloadInvoicePdf(Payment $payment, bool $isAdminView)
    {
        $startedAt = microtime(true);
        $viewData = $this->invoiceViewData($payment, $isAdminView);
        try {
            $pdf = Pdf::loadView('payments.invoice-pdf', $viewData);
        } catch (\Throwable $e) {
            PaymentLogger::logEvent('payment_failed', [
                'txnid' => $payment->txnid,
                'user_id' => $payment->user_id,
                'plan_id' => $payment->plan_id,
                'plan_term_id' => $payment->plan_term_id,
                'source' => 'redirect',
                'internal_status' => 'invoice_pdf_failed',
                'amount' => (float) ($payment->amount_paid ?? $payment->amount ?? 0),
            ]);
            throw $e;
        }
        $safeInvoiceNo = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $viewData['invoiceNo']) ?: 'invoice';
        PaymentLogger::logEvent('invoice_generated', [
            'txnid' => $payment->txnid,
            'user_id' => $payment->user_id,
            'plan_id' => $payment->plan_id,
            'plan_term_id' => $payment->plan_term_id,
            'internal_status' => 'invoice_pdf_generated',
            'source' => $isAdminView ? 'webhook' : 'redirect',
            'amount' => (float) ($payment->amount_paid ?? $payment->amount ?? 0),
        ]);
        PaymentLogger::recordTiming('invoice_render_time_ms', (int) round((microtime(true) - $startedAt) * 1000));

        return $pdf->download($safeInvoiceNo.'.pdf');
    }

    /**
     * @return list<string>
     */
    private function paymentWithRelations(): array
    {
        $rels = ['user:id,name,email', 'plan:id,name,slug'];
        if ($this->invoiceTableReady()) {
            $rels[] = 'invoice';
        }

        return $rels;
    }

    private function invoiceTableReady(): bool
    {
        return Schema::hasTable('payment_invoices');
    }

    private function fallbackInvoiceForMissingTable(Payment $payment): PaymentInvoice
    {
        $prefix = trim((string) AdminSetting::getValue('billing_invoice_prefix', 'INV'));
        $dt = $payment->created_at ?? now();
        $year = (int) $dt->format('Y');
        $month = (int) $dt->format('n');
        $fyStart = $month >= 4 ? $year : ($year - 1);
        $fyEnd = $fyStart + 1;
        $fyLabel = substr((string) $fyStart, -2).'-'.substr((string) $fyEnd, -2);
        $seq = (int) $payment->id;
        $number = strtoupper($prefix).'/'.$fyLabel.'/'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);

        return new PaymentInvoice([
            'payment_id' => (int) $payment->id,
            'invoice_number' => $number,
            'fy_label' => $fyLabel,
            'sequence_no' => $seq,
        ]);
    }
}
