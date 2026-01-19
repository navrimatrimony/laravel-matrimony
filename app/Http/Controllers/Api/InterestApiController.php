<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Interest;
use App\Models\MatrimonyProfile;

class InterestApiController extends Controller
{
    /**
     * Send interest to a matrimony profile
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // Guard: MatrimonyProfile must exist
        if (!$user || !$user->matrimonyProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Please create your matrimony profile first.',
            ], 403);
        }

        // Validate receiver_profile_id
        $request->validate([
            'receiver_profile_id' => 'required|exists:matrimony_profiles,id',
        ]);

        $senderProfile = $user->matrimonyProfile;
        $receiverProfile = MatrimonyProfile::find($request->receiver_profile_id);

        // Guard: Cannot send interest to own profile
        if ($senderProfile->id === $receiverProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot send interest to your own profile.',
            ], 403);
        }

        // Safety check
        if (!$senderProfile || !$receiverProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Matrimony profile missing.',
            ], 403);
        }

        // Check if interest already exists
        $existingInterest = Interest::where('sender_profile_id', $senderProfile->id)
            ->where('receiver_profile_id', $receiverProfile->id)
            ->first();

        if ($existingInterest) {
            return response()->json([
                'success' => false,
                'message' => 'Interest already sent.',
                'data' => [
                    'id' => $existingInterest->id,
                    'status' => $existingInterest->status,
                ],
            ], 409);
        }

        // Create new interest
        $interest = Interest::create([
            'sender_profile_id' => $senderProfile->id,
            'receiver_profile_id' => $receiverProfile->id,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Interest sent successfully.',
            'data' => $interest,
        ], 200);
    }

    /**
     * Get sent interests
     */
    public function sent(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->matrimonyProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Please create your matrimony profile first.',
            ], 403);
        }

        $myProfileId = $user->matrimonyProfile->id;

        $sentInterests = Interest::with('receiverProfile')
            ->where('sender_profile_id', $myProfileId)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'sent' => $sentInterests,
            ],
        ]);
    }

    /**
     * Get received interests
     */
    public function received(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->matrimonyProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Please create your matrimony profile first.',
            ], 403);
        }

        $myProfileId = $user->matrimonyProfile->id;

        $receivedInterests = Interest::with('senderProfile')
            ->where('receiver_profile_id', $myProfileId)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'received' => $receivedInterests,
            ],
        ]);
    }

    /**
     * Accept interest
     */
    public function accept($id)
    {
        $user = request()->user();

        if (!$user || !$user->matrimonyProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Please create your matrimony profile first.',
            ], 403);
        }

        $interest = Interest::find($id);

        if (!$interest) {
            return response()->json([
                'success' => false,
                'message' => 'Interest not found.',
            ], 404);
        }

        // Guard: Only receiver can accept
        if ($interest->receiver_profile_id !== $user->matrimonyProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only receiver can accept interest.',
            ], 403);
        }

        // Guard: Only pending interest can be accepted
        if ($interest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This interest is already processed.',
            ], 403);
        }

        $interest->update([
            'status' => 'accepted',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Interest accepted.',
            'data' => $interest,
        ]);
    }

    /**
     * Reject interest
     */
    public function reject($id)
    {
        $user = request()->user();

        if (!$user || !$user->matrimonyProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Please create your matrimony profile first.',
            ], 403);
        }

        $interest = Interest::find($id);

        if (!$interest) {
            return response()->json([
                'success' => false,
                'message' => 'Interest not found.',
            ], 404);
        }

        // Guard: Only receiver can reject
        if ($interest->receiver_profile_id !== $user->matrimonyProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only receiver can reject interest.',
            ], 403);
        }

        // Guard: Only pending interest can be rejected
        if ($interest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This interest is already processed.',
            ], 403);
        }

        $interest->update([
            'status' => 'rejected',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Interest rejected.',
            'data' => $interest,
        ]);
    }

    /**
     * Withdraw interest
     */
    public function withdraw($id)
    {
        $user = request()->user();

        if (!$user || !$user->matrimonyProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Please create your matrimony profile first.',
            ], 403);
        }

        $interest = Interest::find($id);

        if (!$interest) {
            return response()->json([
                'success' => false,
                'message' => 'Interest not found.',
            ], 404);
        }

        // Guard: Only sender can withdraw
        if ($interest->sender_profile_id !== $user->matrimonyProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only sender can withdraw interest.',
            ], 403);
        }

        // Guard: Only pending interest can be withdrawn
        if ($interest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending interests can be withdrawn.',
            ], 403);
        }

        $interest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Interest withdrawn successfully.',
        ]);
    }
}
