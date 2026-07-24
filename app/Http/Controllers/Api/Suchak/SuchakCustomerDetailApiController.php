<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakCustomerListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class SuchakCustomerDetailApiController extends Controller
{
    public function show(
        Request $request,
        int $representation,
        SuchakCustomerListService $customerListService,
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

        $rows = $customerListService->rowsForAccount($account);
        foreach ($rows as $row) {
            if ((int) ($row['representation_id'] ?? 0) === $representation) {
                $sortAt = $row['sort_at'] ?? null;

                return response()->json([
                    'success' => true,
                    'message' => 'Customer detail loaded.',
                    'data' => [
                        'customer' => [
                            'row_key' => $row['row_key'] ?? null,
                            'kind' => $row['kind'] ?? null,
                            'profile_id' => $row['profile_id'] ?? null,
                            'representation_id' => $row['representation_id'] ?? null,
                            'intake_id' => $row['intake_id'] ?? null,
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
                            'paid' => $row['paid'] ?? null,
                            'view_url' => $row['view_url'] ?? null,
                            'edit_url' => $row['edit_url'] ?? null,
                            'manage_url' => $row['manage_url'] ?? null,
                            'review_url' => $row['review_url'] ?? null,
                            'sort_at' => $sortAt instanceof \Illuminate\Support\Carbon
                                ? $sortAt->toIso8601String()
                                : null,
                        ],
                    ],
                ]);
            }
        }

        return response()->json(['success' => false, 'message' => 'Customer not found for this Suchak account.'], 404);
    }
}

