<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCustomerListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin mobile adapter over SuchakCustomerListService::rowsForAccount().
 */
class SuchakCustomersApiController extends Controller
{
    public function __invoke(
        Request $request,
        SuchakCustomerListService $customerListService,
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

        $rows = $customerListService->rowsForAccount($account);

        // Drop Carbon instances / non-JSON-safe values for transport.
        $payload = array_map(static function (array $row): array {
            $sortAt = $row['sort_at'] ?? null;

            return [
                'row_key' => $row['row_key'] ?? null,
                'kind' => $row['kind'] ?? null,
                'profile_id' => $row['profile_id'] ?? null,
                'representation_id' => $row['representation_id'] ?? null,
                'intake_id' => $row['intake_id'] ?? null,
                'source_link_id' => $row['source_link_id'] ?? null,
                'photo_url' => $row['photo_url'] ?? null,
                'name' => $row['name'] ?? null,
                'age' => $row['age'] ?? null,
                'gender' => $row['gender'] ?? null,
                'address' => $row['address'] ?? null,
                'status_label' => $row['status_label'] ?? null,
                'consent_label' => $row['consent_label'] ?? null,
                'consent_status' => $row['consent_status'] ?? null,
                'has_pending_consent' => (bool) ($row['has_pending_consent'] ?? false),
                'pending_consent_id' => $row['pending_consent_id'] ?? null,
                'has_active_consent' => (bool) ($row['has_active_consent'] ?? false),
                'can_request_consent' => (bool) ($row['can_request_consent'] ?? false),
                'can_renew_consent' => (bool) ($row['can_renew_consent'] ?? false),
                'default_consent_mobile' => $row['default_consent_mobile'] ?? null,
                'default_consent_giver_name' => $row['default_consent_giver_name'] ?? null,
                'lifecycle_label' => $row['lifecycle_label'] ?? null,
                // Lets the app flag a half-finished profile in the list and
                // resume onboarding at the section it stopped at.
                'completion_percent' => (int) ($row['completion_percent'] ?? 0),
                'incomplete_sections' => array_values((array) ($row['incomplete_sections'] ?? [])),
                'view_url' => $row['view_url'] ?? null,
                'edit_url' => $row['edit_url'] ?? null,
                'manage_url' => $row['manage_url'] ?? null,
                'review_url' => $row['review_url'] ?? null,
                'sort_at' => $sortAt instanceof \Illuminate\Support\Carbon
                    ? $sortAt->toIso8601String()
                    : null,
            ];
        }, $rows);

        return response()->json([
            'success' => true,
            'message' => 'Suchak customers loaded.',
            'data' => [
                'account_id' => $account->id,
                'customers' => $payload,
            ],
        ]);
    }
}
