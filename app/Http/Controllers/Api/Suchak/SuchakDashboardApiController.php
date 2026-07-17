<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakDailyOpportunityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Thin mobile adapter over SuchakDailyOpportunityService.
 * Serializes the existing worklist; does not invent new workflow rules.
 */
class SuchakDashboardApiController extends Controller
{
    public function __invoke(
        Request $request,
        SuchakDailyOpportunityService $dailyOpportunityService,
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

        $worklist = $dailyOpportunityService->dailyWorklist($account)
            ->map(function (array $item): array {
                $dueAt = $item['due_at'] ?? null;

                return [
                    'type' => $item['type'] ?? null,
                    'label' => $item['label'] ?? null,
                    'reason' => $item['reason'] ?? null,
                    'due_at' => $dueAt instanceof Carbon ? $dueAt->toIso8601String() : null,
                    'target_type' => $item['target_type'] ?? null,
                    'target_id' => $item['target_id'] ?? null,
                    'candidate_reference' => $item['candidate_reference'] ?? null,
                    'action_label' => $item['action_label'] ?? null,
                    // Web action URLs are retained for parity; Flutter may ignore deep web links.
                    'action_url' => $item['action_url'] ?? null,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Suchak dashboard worklist loaded.',
            'data' => [
                'account_id' => $account->id,
                'worklist' => $worklist,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
