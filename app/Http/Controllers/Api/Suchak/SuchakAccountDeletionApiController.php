<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Modules\Suchak\Services\SuchakAccountDeletionService;
use App\Services\Account\MemberAccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A Suchak closing their own business account, in-app — the path Google Play
 * requires of any app that can create an account in-app.
 *
 * Thin adapter over {@see SuchakAccountDeletionService}; no rules live here.
 */
class SuchakAccountDeletionApiController extends Controller
{
    public function store(Request $request, SuchakAccountDeletionService $deletions): JsonResponse
    {
        $validated = $request->validate([
            'confirmation' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // Checked server-side and not only in the app, so the destructive call
        // cannot be reached by a stray tap or a replayed request.
        if (Str::lower(trim($validated['confirmation'])) !== 'delete') {
            return response()->json([
                'success' => false,
                'message' => __('account.deletion_confirmation_mismatch'),
            ], 422);
        }

        $user = $request->user();
        $account = $user->suchakAccount;

        try {
            $result = $deletions->requestDeletion(
                $account,
                $user,
                trim((string) ($validated['reason'] ?? '')) ?: 'Suchak requested account deletion.',
                $request->ip(),
                (string) $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'deletion' => [
                'archived' => $result['archived'],
                'representations_revoked' => $result['representations_revoked'],
                'grace_days' => MemberAccountDeletionService::GRACE_DAYS,
                'purge_due_at' => $user->fresh()->deletion_requested_at
                    ?->copy()
                    ->addDays(MemberAccountDeletionService::GRACE_DAYS)
                    ->toIso8601String(),
            ],
        ]);
    }
}
