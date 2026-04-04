<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Services\Matching\MatchingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MatchController extends Controller
{
    public function myMatches(Request $request, MatchingService $matching): View
    {
        $user = $request->user();
        $profile = $user?->matrimonyProfile;
        if (! $profile) {
            abort(404, 'No matrimony profile for this account.');
        }

        $matches = $matching->findMatches($profile, 20);

        return view('matches.index', [
            'subjectProfile' => $profile,
            'matches' => $matches,
        ]);
    }

    public function show(Request $request, int $matrimony_profile_id, MatchingService $matching): View
    {
        $user = $request->user();
        $profile = MatrimonyProfile::query()->findOrFail($matrimony_profile_id);

        if (! ($user->is_admin ?? false) && (int) $profile->user_id !== (int) $user->id) {
            abort(403);
        }

        $matches = $matching->findMatches($profile, 20);

        return view('matches.index', [
            'subjectProfile' => $profile,
            'matches' => $matches,
        ]);
    }
}
