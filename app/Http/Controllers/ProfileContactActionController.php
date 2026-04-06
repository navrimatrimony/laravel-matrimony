<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Services\ContactAccessService;
use App\Services\FeatureUsageService;
use App\Services\MediationRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileContactActionController extends Controller
{
    public function __construct(
        protected ContactAccessService $contactAccess,
        protected MediationRequestService $mediationRequestService,
    ) {}

    public function revealContact(Request $request, MatrimonyProfile $matrimony_profile): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }
        if ($user->matrimonyProfile->id === $matrimony_profile->id) {
            abort(403);
        }

        $userId = (int) $user->id;

        $visibilitySettings = DB::table('profile_visibility_settings')
            ->where('profile_id', $matrimony_profile->id)
            ->first();

        $featureUsage = app(FeatureUsageService::class);

        if (! $user->isAnyAdmin()) {
            if (! $featureUsage->canUse($userId, FeatureUsageService::FEATURE_CONTACT_VIEW_LIMIT)) {
                return redirect()->route('plans.index');
            }
        }

        try {
            DB::transaction(function () use ($user, $matrimony_profile, $visibilitySettings, $userId, $featureUsage) {
                $result = $this->contactAccess->performPaidContactRevealBilling($user, $matrimony_profile, $visibilitySettings);
                if ($result['consume_credit']) {
                    $featureUsage->consume($userId, FeatureUsageService::FEATURE_CONTACT_VIEW_LIMIT);
                }
            });
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('matrimony.profile.show', $matrimony_profile)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('matrimony.profile.show', $matrimony_profile)
            ->with('success', __('contact_access.reveal_success'));
    }

    public function mediatorRequest(Request $request, MatrimonyProfile $matrimony_profile): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }
        if ($user->matrimonyProfile->id === $matrimony_profile->id) {
            abort(403);
        }

        try {
            $this->mediationRequestService->createFromProfile($user, $matrimony_profile);
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('matrimony.profile.show', $matrimony_profile)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('matrimony.profile.show', $matrimony_profile)
            ->with('success', __('contact_access.mediator_success'));
    }
}
