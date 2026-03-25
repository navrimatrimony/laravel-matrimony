<?php

namespace App\Http\Controllers\Payments;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class PayuController extends Controller
{
    public function success(Request $request)
    {
        // PayU कडून आलेला data
        $data = $request->all();

        // debug साठी
        \Log::info('PayU Success:', $data);

        return "Payment Successful ✅";
    }

    public function failure(Request $request)
    {
        $data = $request->all();

        \Log::info('PayU Failure:', $data);

        return "Payment Failed ❌";
    }

    public function webhook(Request $request)
    {
        $data = $request->all();

        \Log::info('PayU Webhook:', $data);

        return response()->json(['status' => 'ok']);
    }
}