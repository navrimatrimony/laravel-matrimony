<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentDispute;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminPaymentController extends Controller
{
    public function index(Request $request): View
    {
        $txnid = trim((string) $request->query('txnid', ''));

        $payments = Payment::query()
            ->with(['user:id,name,email'])
            ->when($txnid !== '', fn ($q) => $q->where('txnid', 'like', '%'.$txnid.'%'))
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        return view('admin.payments.index', compact('payments'));
    }

    public function disputes(): View
    {
        $disputes = PaymentDispute::query()
            ->with(['user:id,name,email'])
            ->orderByDesc('id')
            ->paginate(40);

        return view('admin.disputes.index', compact('disputes'));
    }
}
