<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakVisitConfirmation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin mobile adapter: list visit confirmations for the owning Suchak account.
 * Read-only projection of existing suchak_visit_confirmations rows (payments-ledger pattern).
 */
class SuchakMeetingsApiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null) {
            return response()->json([
                'success' => false,
                'message' => 'Suchak account is required to access this section.',
            ], 403);
        }

        $visits = SuchakVisitConfirmation::query()
            ->where('suchak_account_id', $account->id)
            ->latest('id')
            ->limit(50)
            ->get([
                'id',
                'pipeline_id',
                'representation_id',
                'target_matrimony_profile_id',
                'requesting_matrimony_profile_id',
                'visit_status',
                'confirmation_policy_mode',
                'scheduled_for',
                'schedule_note',
                'suchak_completion_status',
                'user_confirmation_status',
                'admin_confirmation_status',
                'created_at',
            ])
            ->map(static fn (SuchakVisitConfirmation $visit): array => [
                'id' => $visit->id,
                'pipeline_id' => $visit->pipeline_id,
                'representation_id' => $visit->representation_id,
                'target_matrimony_profile_id' => $visit->target_matrimony_profile_id,
                'requesting_matrimony_profile_id' => $visit->requesting_matrimony_profile_id,
                'visit_status' => $visit->visit_status,
                'confirmation_policy_mode' => $visit->confirmation_policy_mode,
                'scheduled_for' => $visit->scheduled_for?->toIso8601String(),
                'schedule_note' => $visit->schedule_note,
                'suchak_completion_status' => $visit->suchak_completion_status,
                'user_confirmation_status' => $visit->user_confirmation_status,
                'admin_confirmation_status' => $visit->admin_confirmation_status,
                'created_at' => $visit->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Suchak visit confirmations loaded.',
            'data' => [
                'account_id' => $account->id,
                'visits' => $visits,
            ],
        ]);
    }
}
