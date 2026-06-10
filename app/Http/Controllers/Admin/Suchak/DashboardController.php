<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.suchak.dashboard', [
            'stats' => [
                'pending' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_PENDING)->count(),
                'verified' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_VERIFIED)->count(),
                'suspended' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_SUSPENDED)->count(),
                'archived' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_ARCHIVED)->count(),
                'public_active' => SuchakAccount::query()->where('public_status', SuchakAccount::PUBLIC_ACTIVE)->count(),
            ],
            'recentAccounts' => SuchakAccount::query()
                ->with('user')
                ->latest()
                ->limit(8)
                ->get(),
            'recentActivity' => SuchakActivityLog::query()
                ->with('suchakAccount')
                ->latest('occurred_at')
                ->limit(8)
                ->get(),
        ]);
    }
}
