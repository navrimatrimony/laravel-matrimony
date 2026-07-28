<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\MemberPasswordService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules;

/**
 * Change-password screen for a signed-in member.
 *
 * Deliberately does NOT ask for the current password — see the class docblock of
 * {@see MemberPasswordService} for the decision and the two controls that pay
 * for it. Everything that happens beyond validation lives in that service.
 */
class MemberPasswordApiController extends Controller
{
    public function update(Request $request, MemberPasswordService $passwords): JsonResponse
    {
        // Same rule set as registration, the emailed reset, and the Suchak
        // set-password route: Rules\Password::defaults(). One password policy.
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        /** @var User $user */
        $user = $request->user();

        // A token-authenticated caller (both Flutter apps) has a real
        // personal_access_tokens row; a stateful/session caller gets Sanctum's
        // TransientToken, which is not a Model and has no row to spare.
        $currentToken = $user->currentAccessToken();
        $keepTokenId = $currentToken instanceof Model ? (int) $currentToken->getKey() : null;

        $passwords->changeForSignedInMember(
            $user,
            (string) $validated['password'],
            $keepTokenId,
            $request->hasSession() ? $request->session()->getId() : null,
        );

        return response()->json([
            'success' => true,
            'message' => __('passwords.changed'),
        ]);
    }
}
