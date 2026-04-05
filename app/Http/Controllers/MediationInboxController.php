<?php

namespace App\Http\Controllers;

use App\Models\MediationRequest;
use App\Services\MediationRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MediationInboxController extends Controller
{
    public function __construct(
        protected MediationRequestService $mediationRequestService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $pending = MediationRequest::query()
            ->with(['sender.matrimonyProfile', 'subjectProfile', 'senderProfile', 'receiverProfile'])
            ->where('receiver_id', $user->id)
            ->where('status', MediationRequest::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->get();

        $responded = MediationRequest::query()
            ->with(['sender.matrimonyProfile', 'subjectProfile', 'senderProfile', 'receiverProfile'])
            ->where('receiver_id', $user->id)
            ->whereIn('status', [
                MediationRequest::STATUS_INTERESTED,
                MediationRequest::STATUS_NOT_INTERESTED,
                MediationRequest::STATUS_NEED_MORE_INFO,
            ])
            ->orderByDesc('responded_at')
            ->limit(50)
            ->get();

        $outgoing = MediationRequest::query()
            ->with(['receiver.matrimonyProfile', 'subjectProfile', 'senderProfile', 'receiverProfile'])
            ->where('sender_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('mediation-inbox.index', [
            'pending' => $pending,
            'responded' => $responded,
            'outgoing' => $outgoing,
        ]);
    }

    public function respond(Request $request, MediationRequest $mediation_request): RedirectResponse
    {
        $validated = $request->validate([
            'response' => 'required|string|in:interested,not_interested,need_more_info',
            'feedback' => 'nullable|string|max:2000',
        ]);

        try {
            $this->mediationRequestService->respond(
                $request->user(),
                $mediation_request,
                $validated['response'],
                $validated['feedback'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('mediation-inbox.index')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('mediation-inbox.index')
            ->with('success', __('mediation.response_recorded'));
    }
}
