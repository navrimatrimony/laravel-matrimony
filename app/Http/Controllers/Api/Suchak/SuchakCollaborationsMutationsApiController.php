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

class SuchakCollaborationsMutationsApiController extends Controller
{
    public function store(Request $request, SuchakCollaborationService $collaborationService): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }
        $account = $user->suchakAccount;

        $validated = $request->validate([
            'requesting_representation_id' => ['required', 'integer', 'exists:suchak_profile_representations,id'],
            'target_representation_id' => ['required', 'integer', 'exists:suchak_profile_representations,id'],
            'message' => ['nullable', 'string', 'max:2000'],
            'commission_ack' => ['accepted'],
            'split_type' => ['nullable', 'string', Rule::in(SuchakCommissionAgreement::SPLIT_TYPES)],
            'groom_side_share' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bride_side_share' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        try {
            $created = $collaborationService->createRequest(
                $account,
                $user,
                SuchakProfileRepresentation::query()->findOrFail((int) $validated['requesting_representation_id']),
                SuchakProfileRepresentation::query()->findOrFail((int) $validated['target_representation_id']),
                [
                    'message' => $validated['message'] ?? null,
                    'split_type' => $validated['split_type'] ?? null,
                    'groom_side_share' => $validated['groom_side_share'] ?? null,
                    'bride_side_share' => $validated['bride_side_share'] ?? null,
                    'fixed_amount' => $validated['fixed_amount'] ?? null,
                    'currency' => $validated['currency'] ?? null,
                ],
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        /** @var SuchakCollaborationRequest $collaboration */
        $collaboration = $created['request'];

        return response()->json([
            'success' => true,
            'message' => 'Collaboration request sent.',
            'data' => ['collaboration_id' => $collaboration->id],
        ], 201);
    }

    public function accept(
        Request $request,
        SuchakCollaborationRequest $collaboration,
        SuchakCollaborationService $collaborationService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        try {
            $collaborationService->acceptRequest(
                $collaboration,
                $user->suchakAccount,
                $user,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Collaboration request accepted.']);
    }

    public function reject(
        Request $request,
        SuchakCollaborationRequest $collaboration,
        SuchakCollaborationService $collaborationService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        try {
            $collaborationService->rejectRequest(
                $collaboration,
                $user->suchakAccount,
                $user,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Collaboration request rejected.']);
    }
}
