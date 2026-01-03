<?php

namespace App\Http\Controllers;

use App\Models\Interest;
use App\Models\User;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| InterestController (SSOT v3.1 FINAL)
|--------------------------------------------------------------------------
|
| GOLDEN RULE:
| Interest = MatrimonyProfile → MatrimonyProfile
| User = authentication only
|
*/

class InterestController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Send Interest
    |--------------------------------------------------------------------------
    |
    | Route:
    | POST /interests/send/{user}
    |
    | Meaning:
    | - Logged-in user च्या MatrimonyProfile कडून
    | - समोरच्या user च्या MatrimonyProfile ला
    |
    */
    public function store(User $user)
    {
        $authUser = auth()->user();

if (!$authUser->matrimonyProfile) {
    return redirect()
        ->route('matrimony.profile.create')
        ->with('error', 'Please create your matrimony profile first.');
}

        // Logged-in user
        $authUser = auth()->user();

        // Sender MatrimonyProfile
        $senderProfile = $authUser->matrimonyProfile;

        // Receiver MatrimonyProfile
        $receiverProfile = $user->matrimonyProfile;

        // Safety checks (५वीच्या पातळीवर)
        if (!$senderProfile || !$receiverProfile) {
            abort(403, 'Matrimony profile missing');
        }

        // स्वतःलाच interest जाऊ नये
        if ($senderProfile->id === $receiverProfile->id) {
            abort(403);
        }

        // Duplicate interest टाळण्यासाठी
        Interest::firstOrCreate(
            [
                'sender_profile_id'   => $senderProfile->id,
                'receiver_profile_id' => $receiverProfile->id,
            ],
            [
                'status' => 'pending',
            ]
        );

        return back()->with('success', 'Interest पाठवले गेले.');
    }

    /*
    |--------------------------------------------------------------------------
    | Sent Interests
    |--------------------------------------------------------------------------
    |
    | Meaning:
    | - माझ्या MatrimonyProfile ने कोणकोणाला interest पाठवला
    |
    */
    public function sent()
    {
        $authUser = auth()->user();

if (!$authUser->matrimonyProfile) {
    return redirect()
        ->route('matrimony.profile.create')
        ->with('error', 'Please create your matrimony profile first.');
}

        $myProfileId = auth()->user()->matrimonyProfile->id;

        $sentInterests = Interest::with('receiverProfile')
            ->where('sender_profile_id', $myProfileId)
            ->latest()
            ->get();

        return view('interests.sent', compact('sentInterests'));
    }

    /*
    |--------------------------------------------------------------------------
    | Received Interests
    |--------------------------------------------------------------------------
    |
    | Meaning:
    | - कोणकोणाच्या MatrimonyProfile कडून मला interest आला
    |
    */
    public function received()
    {
        $authUser = auth()->user();

if (!$authUser->matrimonyProfile) {
    return redirect()
        ->route('matrimony.profile.create')
        ->with('error', 'Please create your matrimony profile first.');
}

        $myProfileId = auth()->user()->matrimonyProfile->id;

        $receivedInterests = Interest::with('senderProfile')
            ->where('receiver_profile_id', $myProfileId)
            ->latest()
            ->get();

        return view('interests.received', compact('receivedInterests'));
    }
}
