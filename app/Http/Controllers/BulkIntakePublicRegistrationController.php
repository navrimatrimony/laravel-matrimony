<?php

namespace App\Http\Controllers;

use App\Services\Intake\BulkIntakePublicRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BulkIntakePublicRegistrationController extends Controller
{
    public function show(string $token, BulkIntakePublicRegistrationService $registrationService): View
    {
        $item = $registrationService->itemForToken($token);
        abort_unless($item !== null, 404);

        $gate = $registrationService->accessGate($item);
        abort_unless($gate['allowed'], 403, $registrationService->accessDeniedMessage((string) $gate['reason']));

        $payload = $registrationService->formPayload($item);

        return view('bulk-intake.register', [
            'token' => $token,
            'payload' => $payload,
        ]);
    }

    public function store(string $token, Request $request, BulkIntakePublicRegistrationService $registrationService): RedirectResponse
    {
        $item = $registrationService->itemForToken($token);
        abort_unless($item !== null, 404);

        $gate = $registrationService->accessGate($item);
        abort_unless($gate['allowed'], 403);

        $registrationService->save($item, $request);

        return redirect()
            ->route('bulk-intake.register.show', ['token' => $token])
            ->with('success', 'नोंदणी माहिती जतन झाली. धन्यवाद!');
    }
}
