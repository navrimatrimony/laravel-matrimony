<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserFeatureUsage;
use Illuminate\Support\Facades\DB;

class CommerceAnalyticsController extends Controller
{
    public function __invoke()
    {
        $totalUsers = User::query()->count();

        $activeSubscriptions = Subscription::query()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->count();

        $featureUsageTotals = UserFeatureUsage::query()
            ->select('feature_key', DB::raw('SUM(used_count) as total_used'))
            ->groupBy('feature_key')
            ->orderByDesc('total_used')
            ->get();

        return view('admin.commerce.analytics', compact(
            'totalUsers',
            'activeSubscriptions',
            'featureUsageTotals'
        ));
    }
}
