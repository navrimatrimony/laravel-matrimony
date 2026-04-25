<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoiceNo }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 12px; }
        .row { width: 100%; margin-bottom: 12px; }
        .left { float: left; width: 58%; }
        .right { float: right; width: 40%; text-align: right; }
        .clear { clear: both; }
        .muted { color: #4b5563; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; }
        th:last-child, td:last-child { text-align: right; }
        .summary td { font-weight: 700; }
        .section-title { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #6b7280; margin-bottom: 4px; }
    </style>
</head>
<body>
    <div class="row">
        <div class="left">
            <h2 style="margin:0 0 6px 0;">Tax Invoice</h2>
            <div class="muted">Invoice No: <strong>{{ $invoiceNo }}</strong></div>
            <div class="muted">Invoice Date: {{ optional($invoiceDate)->format('Y-m-d H:i') }}</div>
        </div>
        <div class="right">
            <div><strong>{{ $seller['legal_name'] !== '' ? $seller['legal_name'] : 'Seller details not configured' }}</strong></div>
            @if ($seller['address'] !== '')<div>{{ $seller['address'] }}</div>@endif
            @if ($seller['email'] !== '')<div>{{ $seller['email'] }}</div>@endif
            @if ($seller['phone'] !== '')<div>{{ $seller['phone'] }}</div>@endif
            @if ($seller['gstin'] !== '')<div>GSTIN: {{ $seller['gstin'] }}</div>@endif
            @if ($seller['pan'] !== '')<div>PAN: {{ $seller['pan'] }}</div>@endif
            @if ($seller['state_code'] !== '')<div>State Code: {{ $seller['state_code'] }}</div>@endif
        </div>
        <div class="clear"></div>
    </div>

    <div class="row">
        <div class="left">
            <div class="section-title">Billed To</div>
            <div><strong>{{ $payment->user?->name ?? 'User' }}</strong></div>
            <div>{{ $payment->user?->email ?? '—' }}</div>
        </div>
        <div class="right">
            <div class="section-title">Payment Ref</div>
            <div>Txn ID: {{ $payment->txnid }}</div>
            @if ($payment->payu_txnid)<div>Gateway Txn: {{ $payment->payu_txnid }}</div>@endif
            <div>Status: {{ $payment->payment_status ?? $payment->status }}</div>
        </div>
        <div class="clear"></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th>Period</th>
                <th>Amount ({{ $currency }})</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $planName }}</td>
                <td>{{ $billingKey !== '' ? $billingKey : '—' }}</td>
                <td>{{ number_format($baseAmount, 2) }}</td>
            </tr>
            @if ($discountAmount > 0)
                <tr>
                    <td>Coupon Discount</td>
                    <td>—</td>
                    <td>-{{ number_format($discountAmount, 2) }}</td>
                </tr>
            @endif
            @if ($walletUsed > 0)
                <tr>
                    <td>Wallet Used</td>
                    <td>—</td>
                    <td>-{{ number_format($walletUsed, 2) }}</td>
                </tr>
            @endif
            <tr class="summary">
                <td>Total Paid</td>
                <td>—</td>
                <td>{{ number_format($finalAmount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($seller['terms'] !== '')
        <div style="margin-top: 14px;" class="muted">{{ $seller['terms'] }}</div>
    @endif
</body>
</html>

