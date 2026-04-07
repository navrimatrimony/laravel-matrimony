<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserWallet;
use App\Services\UserWalletService;
use Illuminate\Http\Request;
use Throwable;

class UserWalletController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->input('q', ''));

        $wallets = UserWallet::query()
            ->with(['user:id,name,mobile,email'])
            ->when($q !== '', function ($query) use ($q) {
                $query->whereHas('user', function ($uq) use ($q) {
                    $uq->where('id', $q)
                        ->orWhere('mobile', 'like', '%'.$q.'%')
                        ->orWhere('name', 'like', '%'.$q.'%')
                        ->orWhere('email', 'like', '%'.$q.'%');
                });
            })
            ->orderByDesc('balance_paise')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.wallets.index', compact('wallets', 'q'));
    }

    public function credit(Request $request, UserWalletService $wallets)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount_rupees' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $paise = (int) round((float) $data['amount_rupees'] * 100);

        try {
            $wallets->credit((int) $data['user_id'], $paise, $data['note'] ?? 'admin_credit');
        } catch (Throwable $e) {
            return redirect()
                ->route('admin.wallets.index')
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.wallets.index', ['q' => $data['user_id']])
            ->with('success', __('admin_monetization.wallet_credit_success', [
                'amount' => number_format((float) $data['amount_rupees'], 2, '.', ''),
            ]));
    }
}
