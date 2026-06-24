<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\RegistrationPartnerPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingPreferenceAutoDraftController extends Controller
{
    public function __construct(private readonly RegistrationPartnerPreferenceService $preferences) {}

    public function preview(Request $request): JsonResponse
    {
        return response()->json($this->preferences->preview($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'force_regenerate' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->preferences->persist(
            $request->user(),
            (bool) ($validated['force_regenerate'] ?? false)
        ));
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json(array_merge(
            ['success' => true],
            $this->preferences->statusForUser($request->user())
        ));
    }
}
