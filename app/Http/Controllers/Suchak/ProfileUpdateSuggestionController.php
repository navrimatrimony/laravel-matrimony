<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Services\SuchakProfileUpdateSuggestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ProfileUpdateSuggestionController extends Controller
{
    public function store(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakProfileUpdateSuggestionService $suggestionService,
    ): RedirectResponse {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        $allowedFields = $suggestionService->allowedCoreFieldKeys();
        $validated = $request->validate([
            'field_key' => ['required', 'string', Rule::in($allowedFields)],
            'suggested_value' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $suggestionService->createCoreFieldSuggestion(
                $account,
                $request->user(),
                $representation,
                $validated['field_key'],
                $validated['suggested_value'],
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('suchak.dashboard')
            ->with('success', 'Profile update suggestion created for candidate confirmation.');
    }
}
