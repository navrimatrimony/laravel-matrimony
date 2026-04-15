<?php

namespace App\Http\Controllers;

use App\Models\ContactRequest;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Services\ContactRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Day-32: Contact request — create (store), cancel.
 */
class ContactRequestController extends Controller
{
    public function __construct(
        protected ContactRequestService $contactRequestService
    ) {}

    /**
     * Create contact request (sender = auth user, receiver = profile owner).
     */
    public function store(Request $request, MatrimonyProfile $profile)
    {
        $user = auth()->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }
        if ($user->matrimonyProfile->id === $profile->id) {
            abort(403, 'Cannot request your own contact.');
        }

        $receiver = $profile->user;
        if (! $receiver) {
            // For profiles without a linked user (older showcase/real data), attach a system user
            // so that contact requests can flow uniformly during testing, without creating new profiles.
            $receiver = User::firstOrCreate(
                ['email' => 'contact-profile-' . $profile->id . '@system.local'],
                [
                    'name' => $profile->full_name ?: 'Contact Profile ' . $profile->id,
                    'password' => bcrypt(str()->random(32)),
                ]
            );
            if ($profile->id) {
                DB::table('matrimony_profiles')
                    ->where('id', $profile->id)
                    ->update(['user_id' => $receiver->id]);
                $profile->setRelation('user', $receiver);
            }
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|in:talk_to_family,meet,need_more_details,discuss_marriage_timeline,other',
            'other_reason_text' => 'required_if:reason,other|nullable|string|max:500',
            'requested_scopes' => 'required|array',
            'requested_scopes.*' => 'string|in:email,phone,whatsapp',
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();
        $requestedScopes = array_values(array_unique($data['requested_scopes'] ?? []));

        try {
            $this->contactRequestService->createRequest(
                auth()->user(),
                $receiver,
                $data['reason'],
                $requestedScopes,
                $data['other_reason_text'] ?? null
            );
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage())->withErrors($e->errors());
        }

        return back()->with('success', 'Contact request sent. You will be notified when they respond.');
    }

    /**
     * Cancel pending contact request (sender only).
     */
    public function cancel(ContactRequest $contactRequest)
    {
        if ($contactRequest->sender_id !== auth()->id()) {
            abort(403);
        }

        try {
            $this->contactRequestService->cancel($contactRequest, auth()->user());
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Contact request cancelled.');
    }
}
