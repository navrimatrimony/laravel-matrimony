<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\QuotaEngineService;
use Illuminate\View\View;

class AdminUserPlanController extends Controller
{
    public function show(User $user, QuotaEngineService $quotaEngine): View
    {
        $quotaSummary = $quotaEngine->getUserQuotaSummary($user);

        return view('admin.users.plan', [
            'user' => $user,
            'quotaSummary' => $quotaSummary,
        ]);
    }
}
