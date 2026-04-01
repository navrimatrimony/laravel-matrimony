<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\ProfilePhotoReport;
use Illuminate\Http\Request;

class ProfilePhotoReportController extends Controller
{
    /**
     * Submit a report for a specific gallery photo (not generic profile abuse).
     */
    public function store(Request $request, MatrimonyProfile $matrimony_profile_id)
    {
        $request->validate([
            'profile_photo_id' => ['required', 'integer', 'exists:profile_photos,id'],
            'reason' => ['required', 'string', 'min:10'],
        ]);

        $user = $request->user();
        $photo = ProfilePhoto::query()->findOrFail((int) $request->input('profile_photo_id'));

        if ((int) $photo->profile_id !== (int) $matrimony_profile_id->id) {
            return redirect()
                ->back()
                ->with('error', __('search.photo_report_invalid_photo'));
        }

        $duplicate = ProfilePhotoReport::query()
            ->where('reporter_user_id', $user->id)
            ->where('profile_photo_id', $photo->id)
            ->where('status', 'open')
            ->exists();

        if ($duplicate) {
            return redirect()
                ->back()
                ->with('error', __('search.photo_report_already_open'));
        }

        ProfilePhotoReport::create([
            'reporter_user_id' => $user->id,
            'reported_profile_id' => $matrimony_profile_id->id,
            'profile_photo_id' => $photo->id,
            'reason' => $request->input('reason'),
            'status' => 'open',
        ]);

        return redirect()
            ->back()
            ->with('success', __('search.photo_report_submitted'));
    }
}
