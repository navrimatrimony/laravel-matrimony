<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin mobile adapter for collaboration list (read path mirrors web CollaborationController::index filters).
 */
class SuchakCollaborationsApiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
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

        $status = $request->query('status');
        $status = in_array($status, SuchakCollaborationRequest::STATUSES, true) ? $status : null;
        $direction = $request->query('direction');
        $direction = in_array($direction, ['incoming', 'outgoing'], true) ? $direction : null;

        $rows = SuchakCollaborationRequest::query()
            ->with([
                'requestingSuchakAccount:id,suchak_name',
                'targetSuchakAccount:id,suchak_name',
            ])
            ->where(function ($query) use ($account): void {
                $query
                    ->where('requesting_suchak_account_id', $account->id)
                    ->orWhere('target_suchak_account_id', $account->id);
            })
            ->when($direction === 'incoming', fn ($query) => $query->where('target_suchak_account_id', $account->id))
            ->when($direction === 'outgoing', fn ($query) => $query->where('requesting_suchak_account_id', $account->id))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static function (SuchakCollaborationRequest $row) use ($account): array {
                $incoming = (int) $row->target_suchak_account_id === (int) $account->id;

                return [
                    'id' => $row->id,
                    'status' => $row->status,
                    'direction' => $incoming ? 'incoming' : 'outgoing',
                    'requesting_suchak_name' => $row->requestingSuchakAccount?->suchak_name,
                    'target_suchak_name' => $row->targetSuchakAccount?->suchak_name,
                    // A marketplace proposal arrives on this same list and is accepted with the same
                    // two routes, but it is not the same thing as a direct request: the terms are
                    // frozen from the challenge and cannot be re-quoted, and the incoming candidate
                    // is one the caller invited by publishing. Without this the app cannot tell
                    // them apart, and the challenge's own proposal inbox has no id to link to.
                    'marketplace_challenge_id' => $row->marketplace_challenge_id === null
                        ? null
                        : (int) $row->marketplace_challenge_id,
                    'marketplace_stage' => $row->marketplace_stage,
                    'created_at' => $row->created_at?->toIso8601String(),
                    'expires_at' => $row->expires_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Suchak collaborations loaded.',
            'data' => [
                'account_id' => $account->id,
                'collaborations' => $rows,
            ],
        ]);
    }
}
