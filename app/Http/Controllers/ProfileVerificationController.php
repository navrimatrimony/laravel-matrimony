<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Models\ProfileKycSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileVerificationController extends Controller
{
    public function showKyc(Request $request, int $matrimony_profile_id): View
    {
        $profile = MatrimonyProfile::query()->findOrFail($matrimony_profile_id);
        $this->ensureOwner($request, $profile);

        $kycApproved = false;
        $latestKyc = null;
        if (\Illuminate\Support\Facades\Schema::hasTable('profile_kyc_submissions')) {
            $kycApproved = ProfileKycSubmission::query()
                ->where('matrimony_profile_id', $profile->id)
                ->where('status', ProfileKycSubmission::STATUS_APPROVED)
                ->exists();
            $latestKyc = ProfileKycSubmission::query()
                ->where('matrimony_profile_id', $profile->id)
                ->orderByDesc('id')
                ->first();
        }

        $hasPendingSubmission = $latestKyc && $latestKyc->status === ProfileKycSubmission::STATUS_PENDING;

        return view('matrimony.verification.kyc', [
            'profile' => $profile,
            'kycApproved' => $kycApproved,
            'latestKyc' => $latestKyc,
            'hasPendingSubmission' => $hasPendingSubmission,
        ]);
    }

    public function storeKyc(Request $request, int $matrimony_profile_id): RedirectResponse
    {
        $request->validate([
            'id_document' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);

        $profile = MatrimonyProfile::query()->findOrFail($matrimony_profile_id);
        $this->ensureOwner($request, $profile);

        $pending = ProfileKycSubmission::query()
            ->where('matrimony_profile_id', $profile->id)
            ->where('status', ProfileKycSubmission::STATUS_PENDING)
            ->exists();

        if ($pending) {
            return redirect()->route('matrimony.verification.kyc', $profile->id)
                ->with('info', __('profile.kyc_pending_already'));
        }

        $file = $request->file('id_document');
        $ext = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
        $path = $file->storeAs(
            'kyc/'.$profile->id,
            'doc_'.uniqid('', true).'.'.$ext,
            'local'
        );

        ProfileKycSubmission::query()->create([
            'matrimony_profile_id' => $profile->id,
            'id_document_path' => $path,
            'status' => ProfileKycSubmission::STATUS_PENDING,
        ]);

        return redirect()->route('matrimony.verification.kyc', $profile->id)
            ->with('success', __('profile.kyc_uploaded'));
    }

    private function ensureOwner(Request $request, MatrimonyProfile $profile): void
    {
        $user = $request->user();
        if (! $user || ! $user->matrimonyProfile || (int) $user->matrimonyProfile->id !== (int) $profile->id) {
            abort(403);
        }
    }
}
