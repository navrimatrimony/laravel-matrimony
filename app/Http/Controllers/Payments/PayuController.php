<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PayuController extends Controller
{
    public function webhook(Request $request)
    {
        $data = $request->all();

        \Log::info('PayU Webhook:', $data);

        return response()->json(['status' => 'ok']);
    }
}
