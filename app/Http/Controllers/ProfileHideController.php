<?php

namespace App\Http\Controllers;

use App\Models\HiddenProfile;
use App\Models\MatrimonyProfile;
use Illuminate\Http\Request;

class ProfileHideController extends Controller
{
    /**
     * Hide a profile from the current viewer's search/list results only.
     */
    public function store(Request $request, MatrimonyProfile $matrimony_profile_id)
    {
        $owner = $request->user()->matrimonyProfile;
        if (! $owner) {
            return redirect()
                ->back()
                ->with('error', __('search.hide_profile_need_own_profile'));
        }

        if ((int) $owner->id === (int) $matrimony_profile_id->id) {
            return redirect()
                ->back()
                ->with('error', __('search.hide_profile_cannot_hide_self'));
        }

        HiddenProfile::firstOrCreate([
            'owner_profile_id' => $owner->id,
            'hidden_profile_id' => $matrimony_profile_id->id,
        ]);

        return redirect()
            ->back()
            ->with('success', __('search.hide_profile_success'));
    }
}
