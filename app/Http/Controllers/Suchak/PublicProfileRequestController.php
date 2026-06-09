<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        try {
            $pipelineService->createRequest(
                $user,
                $requestingProfile,
                $representation,
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return back()->with('success', __('profile.suchak_contact_request_success'));
    }
}
