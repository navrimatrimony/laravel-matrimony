<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CollaborationController extends Controller
{
    public function store(Request $request, SuchakCollaborationService $collaborationService): RedirectResponse
    {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        $validated = $request->validate([
            'requesting_representation_id' => ['required', 'integer', 'exists:suchak_profile_representations,id'],
            'target_representation_id' => ['required', 'integer', 'exists:suchak_profile_representations,id'],
            'message' => ['nullable', 'string', 'max:2000'],
            'commission_ack' => ['accepted'],
        ]);

        try {
            $collaborationService->createRequest(
                $account,
                $request->user(),
                SuchakProfileRepresentation::query()->findOrFail((int) $validated['requesting_representation_id']),
                SuchakProfileRepresentation::query()->findOrFail((int) $validated['target_representation_id']),
                ['message' => $validated['message'] ?? null],
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Collaboration request created with commission acknowledgement.');
    }

    public function accept(
        Request $request,
        SuchakCollaborationRequest $collaborationRequest,
        SuchakCollaborationService $collaborationService,
    ): RedirectResponse {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        try {
            $collaborationService->acceptRequest(
                $collaborationRequest,
                $account,
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Collaboration request accepted.');
    }

    public function reject(
        Request $request,
        SuchakCollaborationRequest $collaborationRequest,
        SuchakCollaborationService $collaborationService,
    ): RedirectResponse {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        try {
            $collaborationService->rejectRequest(
                $collaborationRequest,
                $account,
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Collaboration request rejected.');
    }
}
