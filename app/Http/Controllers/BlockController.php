<?php

namespace App\Http\Controllers;

use App\Models\Block;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\Shortlist;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| BlockController (SSOT Day-5 — Recovery-Day-R2)
|--------------------------------------------------------------------------
|
| Block / unblock between MatrimonyProfiles. No notifications.
| On block: cancel interests, remove shortlist entries, exclude from search/view.
| On unblock: restore NOTHING.
|
*/
class BlockController extends Controller
{
    /**
     * List profiles the current user has blocked (owner-only).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->matrimonyProfile) {
            return redirect()->route('matrimony.profile.create')
                ->with('error', 'Create your profile first.');
        }

        $entries = Block::with('blockedProfile')
            ->where('blocker_profile_id', $user->matrimonyProfile->id)
            ->latest()
            ->get();

        return view('blocks.index', compact('entries'));
    }

    /**
     * Block a profile. Guard: no self-block, no duplicate.
     * Effects: delete interests both ways, remove shortlist both ways, create block.
     */
    public function store(Request $request, MatrimonyProfile $matrimony_profile_id)
    {
        $blocker = $request->user()->matrimonyProfile;
        if (!$blocker) {
            return redirect()->route('matrimony.profile.create')
                ->with('error', 'Create your profile first.');
        }

        $blocked = $matrimony_profile_id;

        if ($blocker->id === $blocked->id) {
            return back()->with('error', 'You cannot block yourself.');
        }

        if (Block::where('blocker_profile_id', $blocker->id)->where('blocked_profile_id', $blocked->id)->exists()) {
            return back()->with('error', 'Already blocked.');
        }

        Interest::where(function ($q) use ($blocker, $blocked) {
            $q->where('sender_profile_id', $blocker->id)->where('receiver_profile_id', $blocked->id);
        })->orWhere(function ($q) use ($blocker, $blocked) {
            $q->where('sender_profile_id', $blocked->id)->where('receiver_profile_id', $blocker->id);
        })->delete();

        Shortlist::where(function ($q) use ($blocker, $blocked) {
            $q->where('owner_profile_id', $blocker->id)->where('shortlisted_profile_id', $blocked->id);
        })->orWhere(function ($q) use ($blocker, $blocked) {
            $q->where('owner_profile_id', $blocked->id)->where('shortlisted_profile_id', $blocker->id);
        })->delete();

        Block::create([
            'blocker_profile_id' => $blocker->id,
            'blocked_profile_id' => $blocked->id,
        ]);

        return redirect()->route('blocks.index')->with('success', 'Profile blocked.');
    }

    /**
     * Unblock. No restore of interests, connections, or shortlists.
     */
    public function destroy(Request $request, MatrimonyProfile $matrimony_profile_id)
    {
        $blocker = $request->user()->matrimonyProfile;
        if (!$blocker) {
            return redirect()->route('matrimony.profile.create')
                ->with('error', 'Create your profile first.');
        }

        $block = Block::where('blocker_profile_id', $blocker->id)
            ->where('blocked_profile_id', $matrimony_profile_id->id)
            ->first();

        if ($block) {
            $block->delete();
        }

        return back()->with('success', 'Profile unblocked.');
    }
}
