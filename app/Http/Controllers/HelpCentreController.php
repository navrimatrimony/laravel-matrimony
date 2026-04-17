<?php

namespace App\Http\Controllers;

use App\Models\HelpCentreTicket;
use App\Services\HelpCentre\HelpCentreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HelpCentreController extends Controller
{
    public function __construct(
        protected HelpCentreService $helpCentre,
    ) {}

    public function index(): View
    {
        $user = auth()->user();
        $recentTickets = collect();
        if ($user) {
            $recentTickets = HelpCentreTicket::query()
                ->where('user_id', (int) $user->id)
                ->latest('id')
                ->limit(10)
                ->get();
        }

        return view('help-centre.index', [
            'quickPrompts' => [
                __('help_centre.quick_payment'),
                __('help_centre.quick_contact_unlock'),
                __('help_centre.quick_mediation'),
                __('help_centre.quick_chat_issue'),
            ],
            'recentTickets' => $recentTickets,
        ]);
    }

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:500'],
        ]);

        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $result = $this->helpCentre->respond($user, (string) $validated['message']);

        return response()->json([
            'ok' => true,
            'reply' => $result['reply'],
            'intent' => $result['intent'],
            'escalated' => $result['escalated'],
            'ticket_id' => $result['ticket_id'],
        ]);
    }
}
