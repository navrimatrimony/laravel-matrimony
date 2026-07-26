<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPendingConsentListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/suchak/consent-requests — read-only.
 *
 * Thin mobile adapter over SuchakPendingConsentListService::rowsForAccount().
 * Exists so the consent-first flow has no dead end: a pending claim is hidden
 * from every customer surface, so this is the ONLY place the Suchak can see it
 * and reach the existing resend endpoint. It mutates nothing.
 */
class SuchakConsentRequestsApiController extends Controller
{
    public function __invoke(
        Request $request,
        SuchakPendingConsentListService $pendingConsentListService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null) {
            return response()->json([
                'success' => false,
                'message' => 'Suchak account is required to access this section.',
            ], 403);
        }

        $rows = $pendingConsentListService->rowsForAccount($account);

        return response()->json([
            'success' => true,
            'message' => 'Pending consent requests loaded.',
            'data' => [
                'account_id' => $account->id,
                'count' => count($rows),
                'consent_requests' => $rows,
            ],
        ]);
    }
}
