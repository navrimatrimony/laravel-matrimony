<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Models\Shortlist;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| ShortlistController (SSOT Day-5 — Recovery-Day-R2)
|--------------------------------------------------------------------------
|
| Add / remove shortlist. Private: only owner views. No notifications.
| No side-effects on search, profile view, or interest.
|
*/
class ShortlistController extends Controller
{
    /**
     * Owner's shortlist. Only owner can view.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->matrimonyProfile) {
            return redirect()->route('matrimony.profile.create')
                ->with('error', 'Create your profile first.');
        }

        $entries = Shortlist::with('shortlistedProfile')
            ->where('owner_profile_id', $user->matrimonyProfile->id)
            ->latest()
            ->get();

        return view('shortlist.index', compact('entries'));
    }

    /**
     * Add to shortlist. Guard: no self, no duplicate.
     */
    public function store(Request $request, MatrimonyProfile $matrimony_profile_id)
    {
        $owner = $request->user()->matrimonyProfile;
        if (!$owner) {
            return redirect()->route('matrimony.profile.create')
                ->with('error', 'Create your profile first.');
        }

        $target = $matrimony_profile_id;

        if ($owner->id === $target->id) {
            return back()->with('error', 'You cannot shortlist yourself.');
        }

        $exists = Shortlist::where('owner_profile_id', $owner->id)
            ->where('shortlisted_profile_id', $target->id)
            ->exists();

        if ($exists) {
            return back()->with('error', 'Already in shortlist.');
        }

        Shortlist::create([
            'owner_profile_id' => $owner->id,
            'shortlisted_profile_id' => $target->id,
        ]);

        return back()->with('success', 'Added to shortlist.');
    }

    /**
     * Remove from shortlist.
     */
    public function destroy(Request $request, MatrimonyProfile $matrimony_profile_id)
    {
        $owner = $request->user()->matrimonyProfile;
        if (!$owner) {
            return redirect()->route('matrimony.profile.create')
                ->with('error', 'Create your profile first.');
        }

        Shortlist::where('owner_profile_id', $owner->id)
            ->where('shortlisted_profile_id', $matrimony_profile_id->id)
            ->delete();

        return back()->with('success', 'Removed from shortlist.');
    }
}
