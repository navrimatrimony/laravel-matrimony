<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProfileBoost;
use App\Models\User;
use App\Services\ProfileBoostService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileBoostController extends Controller
{
    public function index()
    {
        $now = now();
        $activeBoosts = ProfileBoost::query()
            ->with(['user:id,name,mobile'])
            ->where('ends_at', '>', $now)
            ->orderByDesc('ends_at')
            ->paginate(30);

        $recentBoosts = ProfileBoost::query()
            ->with(['user:id,name,mobile'])
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        return view('admin.boosts.index', compact('activeBoosts', 'recentBoosts', 'now'));
    }

    public function start(Request $request, ProfileBoostService $boosts)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'duration_hours' => ['required', 'integer', 'min:1', 'max:8760'],
        ]);

        $user = User::query()->findOrFail((int) $data['user_id']);

        try {
            $boosts->startBoost($user, (int) $data['duration_hours'], 'admin');
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.boosts.index')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.boosts.index')
            ->with('success', __('admin_monetization.boost_started'));
    }
}
