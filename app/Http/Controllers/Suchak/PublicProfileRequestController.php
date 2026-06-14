<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\SuchakProfileRepresentation;
use App\Services\FeatureUsageService;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PublicProfileRequestController extends Controller
{
    public function store(
        Request $request,
        MatrimonyProfile $matrimony_profile,
        SuchakProfileRepresentation $representation,
        SuchakRequestPipelineService $pipelineService,
    ): RedirectResponse {
        $user = $request->user();
        $requestingProfile = $user?->matrimonyProfile;

        if (! $user || ! $requestingProfile) {
            abort(403);
        }

        if ((int) $requestingProfile->id === (int) $matrimony_profile->id) {
            abort(403, 'Cannot create a Suchak request for your own profile.');
        }

        if ((int) $representation->matrimony_profile_id !== (int) $matrimony_profile->id) {
            abort(404);
        }

        $validated = $request->validate([
            'request_reason' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $featureUsage = app(FeatureUsageService::class);
        if (! $featureUsage->shouldBypassUsageLimits($user)
            && ! $featureUsage->canUse((int) $user->id, FeatureUsageService::FEATURE_CHAT_SEND_LIMIT)) {
            return back()
                ->withInput()
                ->with('error', __('profile.suchak_contact_message_quota_empty'));
        }

        try {
            DB::transaction(function () use ($featureUsage, $pipelineService, $user, $requestingProfile, $representation, $validated, $request): void {
                $pipelineService->createRequest(
                    $user,
                    $requestingProfile,
                    $representation,
                    $validated,
                    $request->ip(),
                    $request->userAgent(),
                );

                if (! $featureUsage->shouldBypassUsageLimits($user)
                    && ! $featureUsage->consume((int) $user->id, FeatureUsageService::FEATURE_CHAT_SEND_LIMIT)) {
                    throw new InvalidArgumentException(__('profile.suchak_contact_message_quota_empty'));
                }
            });
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return back()->with('success', __('profile.suchak_contact_request_success'));
    }
}
