<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakWhiteLabelSharingKitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin mobile adapter: one candidate's masked WhatsApp share card (photo + text)
 * so the Suchak app can send it to a customer — the same masked card the web
 * sharing kit builds. Reuses SuchakWhiteLabelSharingKitService + the candidate
 * masking engine; no duplicate share/masking logic here.
 */
class SuchakCustomerShareCardApiController extends Controller
{
    public function __invoke(
        Request $request,
        int $representation,
        SuchakWhiteLabelSharingKitService $sharingKitService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        // find() on the account's own representations enforces ownership.
        /** @var SuchakProfileRepresentation|null $rep */
        $rep = $account->profileRepresentations()
            ->with(['matrimonyProfile'])
            ->find($representation);

        if ($rep === null) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found for this Suchak account.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Share card ready.',
            'data' => $sharingKitService->profileShareCard($account, $rep),
        ]);
    }
}
