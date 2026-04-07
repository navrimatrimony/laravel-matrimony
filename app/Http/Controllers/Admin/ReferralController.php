<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserReferral;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function index(Request $request)
    {
        $rewardFilter = $request->query('reward');

        $referrals = UserReferral::query()
            ->with([
                'referrer:id,name,mobile,email,referral_code',
                'referredUser:id,name,mobile,email',
            ])
            ->when($rewardFilter === '1', fn ($q) => $q->where('reward_applied', true))
            ->when($rewardFilter === '0', fn ($q) => $q->where('reward_applied', false))
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        return view('admin.referrals.index', compact('referrals', 'rewardFilter'));
    }
}
