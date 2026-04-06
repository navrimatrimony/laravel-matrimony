<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\ContactAccessService;
use App\Services\FeatureUsageService;
use App\Services\MediationRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileContactActionController extends Controller
{
    public function __construct(
        protected ContactAccessService $contactAccess,
        protected MediationRequestService $mediationRequestService,
    ) {}

    public function revealContact(Request $request, MatrimonyProfile $matrimony_profile): RedirectResponse|JsonResponse
    {
        $user = auth()->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }
        if ($user->matrimonyProfile->id === $matrimony_profile->id) {
            abort(403);
        }

        /** @var int Same authenticated user id as {@see MatrimonyProfileController::show} (GET). */
        $userId = (int) $user->id;

        $wantsJson = $request->wantsJson()
            || $request->ajax()
            || $request->header('X-Contact-Reveal') === '1';

        $visibilitySettings = DB::table('profile_visibility_settings')
            ->where('profile_id', $matrimony_profile->id)
            ->first();

        $featureUsage = app(FeatureUsageService::class);

        if (! $featureUsage->shouldBypassUsageLimits($user)) {
            if (! $featureUsage->canUse($userId, FeatureUsageService::FEATURE_CONTACT_VIEW_LIMIT)) {
                if ($wantsJson) {
                    return response()->json(['message' => __('contact_access.upgrade_required')], 422);
                }

                return redirect()->route('plans.index');
            }
        }

        try {
            $result = $this->contactAccess->consumePaidContactReveal($user, $matrimony_profile, $visibilitySettings);
        } catch (\InvalidArgumentException $e) {
            if ($wantsJson) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('matrimony.profile.show', $matrimony_profile)
                ->with('error', $e->getMessage());
        }

        if ($wantsJson) {
            return response()->json([
                'ok' => true,
                'message' => __('contact_access.reveal_success'),
                'phone' => $result['phone'] ?? '',
                'email' => $result['email'] ?? null,
                'contact_usage' => $this->contactUsagePayloadForJson($user, $featureUsage),
            ]);
        }

        // No global success flash: unlocked state is visible on full page reload; avoids header jump + top banner.
        return redirect()->route('matrimony.profile.show', $matrimony_profile);
    }

    /**
     * @return array{line1: string, line2: string, low_warning: bool, low_warning_text: ?string}
     */
    private function contactUsagePayloadForJson(User $user, FeatureUsageService $featureUsage): array
    {
        $snap = $featureUsage->getContactViewUsageSnapshot($user);

        if (! empty($snap['is_unlimited'])) {
            return [
                'line1' => __('profile.usage_contacts_used_line', [
                    'used' => $snap['used'],
                    'limit' => '∞',
                ]),
                'line2' => __('profile.usage_contacts_remaining_unlimited'),
                'low_warning' => false,
                'low_warning_text' => null,
            ];
        }

        $low = is_numeric($snap['limit'])
            && (int) $snap['limit'] > 0
            && is_numeric($snap['remaining'])
            && (int) $snap['remaining'] <= 2;

        return [
            'line1' => __('profile.usage_contacts_used_line', [
                'used' => $snap['used'],
                'limit' => $snap['limit'],
            ]),
            'line2' => __('profile.usage_contacts_remaining_line', [
                'remaining' => $snap['remaining'],
            ]),
            'low_warning' => $low,
            'low_warning_text' => $low ? __('profile.usage_contacts_low_warning') : null,
        ];
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
