<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Models\ProfileMatchTabSkip;
use App\Services\Matching\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class MatchController extends Controller
{
    public function myMatches(Request $request, MatchingService $matching): View|JsonResponse
    {
        $user = $request->user();
        $profile = $user?->matrimonyProfile;
        if (! $profile) {
            abort(404, 'No matrimony profile for this account.');
        }

        $tab = MatchingService::normalizeTab($request->query('tab'));
        $wantsExplain = $request->boolean('explain') || $request->query('explain') === '1';

        if ($wantsExplain && $request->expectsJson()) {
            $rows = $matching->findMatchesForTab($profile, $tab, 36, true);

            return response()->json([
                'profile_id' => (int) $profile->id,
                'matches' => $rows->map(static function (array $row): array {
                    /** @var MatrimonyProfile $p */
                    $p = $row['profile'];

                    return [
                        'profile_id' => (int) $p->id,
                        'full_name' => (string) ($p->full_name ?? ''),
                        'score' => (int) $row['score'],
                        'reasons' => $row['reasons'],
                        'explain' => $row['explain'] ?? [],
                    ];
                })->values()->all(),
            ]);
        }

        $matches = $matching->findMatchesForTab($profile, $tab, 36);

        return view('matches.index', [
            'subjectProfile' => $profile,
            'matches' => $matches,
            'activeTab' => $tab,
        ]);
    }

    public function show(Request $request, int $matrimony_profile_id, MatchingService $matching): View
    {
        $user = $request->user();
        $profile = MatrimonyProfile::query()->findOrFail($matrimony_profile_id);

        if (! ($user->is_admin ?? false) && (int) $profile->user_id !== (int) $user->id) {
            abort(403);
        }

        $tab = MatchingService::normalizeTab($request->query('tab'));
        $matches = $matching->findMatchesForTab($profile, $tab, 36);

        return view('matches.index', [
            'subjectProfile' => $profile,
            'matches' => $matches,
            'activeTab' => $tab,
        ]);
    }

    public function skipCandidate(Request $request): RedirectResponse
    {
        $request->validate([
            'candidate_profile_id' => 'required|integer|exists:matrimony_profiles,id',
            'tab' => 'nullable|string|max:32',
        ]);

        $user = $request->user();
        $profile = $user?->matrimonyProfile;
        if (! $profile) {
            abort(404, 'No matrimony profile for this account.');
        }

        $candidateId = (int) $request->input('candidate_profile_id');
        if ($candidateId === (int) $profile->id) {
            return redirect()->back()->withErrors(['candidate' => __('matching.skip_invalid')]);
        }

        if (Schema::hasTable('profile_match_tab_skips')) {
            ProfileMatchTabSkip::query()->create([
                'observer_profile_id' => $profile->id,
                'candidate_profile_id' => $candidateId,
            ]);
        }

        $tab = MatchingService::normalizeTab($request->input('tab'));

        return redirect()
            ->route('matches.index', ['tab' => $tab])
            ->with('success', __('matching.skip_recorded'));
    }
}
