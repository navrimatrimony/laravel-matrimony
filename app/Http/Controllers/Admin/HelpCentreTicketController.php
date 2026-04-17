<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HelpCentreTicket;
use App\Models\HelpCentreTicketNote;
use App\Models\HelpCentreTicketWorkflow;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HelpCentreTicketController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'open');
        if (! in_array($status, ['open', 'resolved', 'auto_resolved', 'all'], true)) {
            $status = 'open';
        }

        $query = HelpCentreTicket::query()
            ->with(['user', 'workflow.assignedAdmin'])
            ->latest('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $tickets = $query->paginate(30)->withQueryString();

        $stats = [
            'total' => HelpCentreTicket::query()->count(),
            'open' => HelpCentreTicket::query()->where('status', 'open')->count(),
            'resolved' => HelpCentreTicket::query()->where('status', 'resolved')->count(),
            'auto_resolved' => HelpCentreTicket::query()->where('status', 'auto_resolved')->count(),
            'escalated' => HelpCentreTicket::query()->where('escalated', true)->count(),
        ];

        $intentStats = HelpCentreTicket::query()
            ->selectRaw('intent, COUNT(*) as aggregate')
            ->groupBy('intent')
            ->orderByDesc('aggregate')
            ->limit(8)
            ->get();

        $overdueCount = HelpCentreTicketWorkflow::query()
            ->whereNull('first_response_at')
            ->whereNull('resolved_at')
            ->whereNotNull('first_response_due_at')
            ->where('first_response_due_at', '<', now())
            ->count();

        return view('admin.help-centre.tickets', [
            'tickets' => $tickets,
            'statusFilter' => $status,
            'stats' => $stats,
            'intentStats' => $intentStats,
            'overdueCount' => $overdueCount,
        ]);
    }

    public function show(HelpCentreTicket $ticket): View
    {
        $ticket->load(['user', 'workflow.assignedAdmin', 'notes.adminUser']);

        $admins = User::query()
            ->where('is_admin', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('admin.help-centre.show', [
            'ticket' => $ticket,
            'admins' => $admins,
        ]);
    }

    public function assign(Request $request, HelpCentreTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_admin_id' => 'nullable|integer|exists:users,id',
            'priority' => 'required|string|in:low,normal,high,urgent',
        ]);

        HelpCentreTicketWorkflow::query()->updateOrCreate(
            ['help_centre_ticket_id' => (int) $ticket->id],
            [
                'assigned_admin_id' => $validated['assigned_admin_id'] ?? null,
                'priority' => (string) $validated['priority'],
                'first_response_due_at' => optional($ticket->workflow)->first_response_due_at
                    ?? now()->addHours(max(1, (int) config('help_centre.sla.first_response_hours', 12))),
            ]
        );

        return back()->with('success', 'Ticket assignment updated.');
    }

    public function addNote(Request $request, HelpCentreTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'note' => 'required|string|max:4000',
        ]);

        $adminId = (int) ($request->user()?->id ?? 0);
        HelpCentreTicketNote::query()->create([
            'help_centre_ticket_id' => (int) $ticket->id,
            'admin_user_id' => $adminId > 0 ? $adminId : null,
            'note' => (string) $validated['note'],
        ]);

        $workflow = HelpCentreTicketWorkflow::query()->firstOrCreate(
            ['help_centre_ticket_id' => (int) $ticket->id],
            [
                'priority' => 'normal',
                'first_response_due_at' => now()->addHours(max(1, (int) config('help_centre.sla.first_response_hours', 12))),
            ]
        );
        if (! $workflow->first_response_at) {
            $workflow->first_response_at = now();
            $workflow->save();
        }

        return back()->with('success', 'Internal note added.');
    }

    public function resolve(Request $request, HelpCentreTicket $ticket): RedirectResponse
    {
        if ($ticket->status === 'resolved') {
            return back()->with('info', __('help_centre.admin_already_resolved'));
        }

        $ticket->status = 'resolved';
        $ticket->save();

        $workflow = HelpCentreTicketWorkflow::query()->firstOrCreate(
            ['help_centre_ticket_id' => (int) $ticket->id],
            [
                'priority' => 'normal',
                'first_response_due_at' => now()->addHours(max(1, (int) config('help_centre.sla.first_response_hours', 12))),
            ]
        );
        if (! $workflow->first_response_at) {
            $workflow->first_response_at = now();
        }
        $workflow->resolved_at = now();
        $workflow->save();

        return back()->with('success', __('help_centre.admin_resolved_success'));
    }
}
