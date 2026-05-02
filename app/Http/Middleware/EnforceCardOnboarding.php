<?php

namespace App\Http\Middleware;

use App\Models\MatrimonyProfile;
use App\Services\Admin\AdminSettingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While card_onboarding_resume_step is set, keep the member inside onboarding or photo handoff
 * until they explicitly finish (onboarding.complete). No dashboard/search/menu escape.
 */
class EnforceCardOnboarding
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $step = MatrimonyProfile::query()
            ->where('user_id', $user->id)
            ->value('card_onboarding_resume_step');

        if ($step === null) {
            return $next($request);
        }

        $step = (int) $step;
        if (! AdminSettingService::isOnboardingPhotoRequired()) {
            if ($step === MatrimonyProfile::CARD_ONBOARDING_PHOTO_RESUME_STEP || $step === 6 || $step === 7) {
                MatrimonyProfile::query()->where('user_id', $user->id)->update(['card_onboarding_resume_step' => null]);

                return $next($request);
            }
        }

        if ($this->isAllowedDuringOnboarding($request, $step)) {
            return $next($request);
        }

        if ($step >= 2 && $step <= 4) {
            return redirect()
                ->route('matrimony.onboarding.show', ['step' => $step])
                ->with('info', __('onboarding.resume_continue_notice'));
        }

        // Legacy: old step 5 (physical-only card) → community + height + location is now step 3.
        if ($step === 5) {
            return redirect()
                ->route('matrimony.onboarding.show', ['step' => 3])
                ->with('info', __('onboarding.resume_continue_notice'));
        }

        // Legacy: steps 6–7 (About me / Partner preference) removed from onboarding → photo handoff.
        if ($step === 6 || $step === 7) {
            return redirect()
                ->route('matrimony.profile.upload-photo', ['from' => 'onboarding'])
                ->with('info', __('onboarding.resume_photo_notice'));
        }

        if ((int) $step === MatrimonyProfile::CARD_ONBOARDING_PHOTO_RESUME_STEP) {
            return redirect()
                ->route('matrimony.profile.upload-photo', ['from' => 'onboarding'])
                ->with('info', __('onboarding.resume_photo_notice'));
        }

        return $next($request);
    }

    private function isAllowedDuringOnboarding(Request $request, int $step): bool
    {
        $route = $request->route();
        $name = $route?->getName();

        if ($name === null) {
            return $this->pathAllowedWithoutRouteName($request, $step);
        }

        $allowedPrefixes = [
            'matrimony.onboarding.',
            'logout',
            'mobile.verify',
            'verification.',
        ];

        foreach ($allowedPrefixes as $prefix) {
            if ($name === $prefix || str_starts_with($name, $prefix)) {
                return true;
            }
        }

        if ($name === 'matrimony.profile.wizard.marriage-fields') {
            return true;
        }

        if ($name === 'matrimony.internal.location.resolve-current') {
            return true;
        }

        // Paid plan checkout: allow subscribe + coupon preview while onboarding is paused
        // (GET /plans is auth-only; POST /subscribe must not be blocked here).
        if (in_array($name, ['plans.subscribe', 'plans.coupon.validate'], true)) {
            return true;
        }

        if ($step === MatrimonyProfile::CARD_ONBOARDING_PHOTO_RESUME_STEP) {
            $photoNames = [
                'matrimony.profile.upload-photo',
                'matrimony.profile.store-photo',
                'matrimony.profile.photos.make-primary',
                'matrimony.profile.photos.reorder',
                'matrimony.profile.photos.destroy',
            ];
            if (in_array($name, $photoNames, true)) {
                return true;
            }
        }

        return false;
    }

    private function pathAllowedWithoutRouteName(Request $request, int $step): bool
    {
        $path = '/'.$request->path();

        if (str_starts_with($path, '/api/castes/') || str_starts_with($path, '/api/subcastes/')) {
            return true;
        }

        return false;
    }
}
