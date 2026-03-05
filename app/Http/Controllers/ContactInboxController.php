<?php

namespace App\Http\Controllers;

use App\Models\ContactGrant;
use App\Models\ContactRequest;
use App\Services\ContactRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Day-32: Receiver inbox — pending requests, access granted; approve, reject, revoke.
 */
class ContactInboxController extends Controller
{
    public function __construct(
        protected ContactRequestService $contactRequestService
    ) {}

    /**
     * Inbox: tabs Pending and Access Granted.
     */
    public function index()
    {
        $user = auth()->user();
        if (! $user) {
            abort(403);
        }

        $pending = ContactRequest::with(['sender.matrimonyProfile'])
            ->where('receiver_id', $user->id)
            ->where('status', ContactRequest::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->get();

        $activeGrants = ContactGrant::with(['contactRequest.sender.matrimonyProfile'])
            ->whereHas('contactRequest', fn ($q) => $q->where('receiver_id', $user->id))
            ->whereNull('revoked_at')
            ->where('valid_until', '>', now())
            ->orderByDesc('created_at')
            ->get();

        $reasons = config('communication.request_reasons', []);
        $durationOptions = [];
        $opts = config('communication.grant_duration_options', []);
        if (! empty($opts['approve_once'])) {
            $durationOptions['approve_once'] = 'Approve once (24 hours)';
        }
        if (! empty($opts['approve_7_days'])) {
            $durationOptions['approve_7_days'] = '7 days';
        }
        if (! empty($opts['approve_30_days'])) {
            $durationOptions['approve_30_days'] = '30 days';
        }
        if (empty($durationOptions)) {
            $durationOptions['approve_once'] = 'Approve once (24 hours)';
        }

        return view('contact-inbox.index', [
            'pending' => $pending,
            'activeGrants' => $activeGrants,
            'reasons' => $reasons,
            'durationOptions' => $durationOptions,
        ]);
    }

    /**
     * Approve a pending request (receiver only).
     */
    public function approve(Request $request, ContactRequest $contactRequest)
    {
        if ($contactRequest->receiver_id !== auth()->id()) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'granted_scopes' => 'required|array',
            'granted_scopes.*' => 'string|in:email,phone,whatsapp',
            'duration' => 'required|string|in:approve_once,approve_7_days,approve_30_days',
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            $this->contactRequestService->approve(
                $contactRequest,
                auth()->user(),
                $request->input('granted_scopes'),
                $request->input('duration')
            );
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage())->withErrors($e->errors());
        }

        return back()->with('success', 'Contact access granted.');
    }

    /**
     * Reject a pending request (receiver only).
     */
    public function reject(ContactRequest $contactRequest)
    {
        if ($contactRequest->receiver_id !== auth()->id()) {
            abort(403);
        }

        try {
            $this->contactRequestService->reject($contactRequest, auth()->user());
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Request rejected. Sender cannot request again until the cooling period ends.');
    }

    /**
     * Revoke an active grant (receiver only).
     */
    public function revoke(ContactGrant $contactGrant)
    {
        $contactGrant->load('contactRequest');
        if ($contactGrant->contactRequest->receiver_id !== auth()->id()) {
            abort(403);
        }

        try {
            $this->contactRequestService->revokeGrant($contactGrant, auth()->user());
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Access revoked.');
    }
}
