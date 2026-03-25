<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\ProfileKycSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class AdminKycController extends Controller
{
    public function stream(MatrimonyProfile $profile, ProfileKycSubmission $submission): Response
    {
        if ((int) $submission->matrimony_profile_id !== (int) $profile->id) {
            abort(404);
        }
        if (! Storage::disk('local')->exists($submission->id_document_path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($submission->id_document_path));
    }

    public function approve(Request $request, MatrimonyProfile $profile, ProfileKycSubmission $submission): RedirectResponse
    {
        if ((int) $submission->matrimony_profile_id !== (int) $profile->id) {
            abort(404);
        }
        if ($submission->status !== ProfileKycSubmission::STATUS_PENDING) {
            return redirect()->route('admin.profiles.show', $profile->id)
                ->with('error', __('admin.kyc_not_pending'));
        }

        $request->validate([
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $submission->update([
            'status' => ProfileKycSubmission::STATUS_APPROVED,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
            'admin_note' => $request->input('admin_note'),
        ]);

        return redirect()->route('admin.profiles.show', $profile->id)
            ->with('success', __('admin.kyc_approved'));
    }

    public function reject(Request $request, MatrimonyProfile $profile, ProfileKycSubmission $submission): RedirectResponse
    {
        if ((int) $submission->matrimony_profile_id !== (int) $profile->id) {
            abort(404);
        }
        if ($submission->status !== ProfileKycSubmission::STATUS_PENDING) {
            return redirect()->route('admin.profiles.show', $profile->id)
                ->with('error', __('admin.kyc_not_pending'));
        }

        $request->validate([
            'admin_note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $submission->update([
            'status' => ProfileKycSubmission::STATUS_REJECTED,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
            'admin_note' => $request->input('admin_note'),
        ]);

        return redirect()->route('admin.profiles.show', $profile->id)
            ->with('success', __('admin.kyc_rejected'));
    }
}
