<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakLedgerEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin mobile adapter: recent Suchak ledger entries for the owning account.
 */
class SuchakPaymentsApiController extends Controller
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

        $entries = SuchakLedgerEntry::query()
            ->where('suchak_account_id', $account->id)
            ->latest('id')
            ->limit(50)
            ->get([
                'id',
                'matrimony_profile_id',
                'entry_type',
                'amount',
                'currency',
                'status',
                'note',
                'created_at',
            ])
            ->map(static fn (SuchakLedgerEntry $entry): array => [
                'id' => $entry->id,
                'matrimony_profile_id' => $entry->matrimony_profile_id,
                'entry_type' => $entry->entry_type,
                'amount' => $entry->amount,
                'currency' => $entry->currency,
                'status' => $entry->status,
                'note' => $entry->note,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Suchak payment ledger loaded.',
            'data' => [
                'account_id' => $account->id,
                'ledger_entries' => $entries,
            ],
        ]);
    }
}
