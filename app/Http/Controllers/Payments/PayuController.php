<?php

namespace App\Http\Controllers\Payments;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class PayuController extends Controller
{
    public function success(Request $request)
    {
        // NOTE: A payment callback alone does not grant any entitlement.
        $data = $request->all();

        \Log::info('PayU Success:', $data);

        return redirect()
            ->route('dashboard')
            ->with('info', 'Payment callback received. Access changes only after a valid entitlement is granted.');
    }

    public function failure(Request $request)
    {
        $data = $request->all();

        \Log::info('PayU Failure:', $data);

        return redirect()
            ->route('dashboard')
            ->with('error', 'Payment failed or was not completed.');
    }

    public function webhook(Request $request)
    {
        $data = $request->all();

        \Log::info('PayU Webhook:', $data);

        return response()->json(['status' => 'ok']);
    }
}