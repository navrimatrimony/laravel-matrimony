<?php

namespace App\Http\Controllers;

use App\Models\Interest;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\MatrimonyProfile;


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
    public function store(MatrimonyProfile $matrimonyProfile)

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
        $receiverProfile = $matrimonyProfile;


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

        return back()->with('success', 'Interest sent successfully.');
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

    /*
|--------------------------------------------------------------------------
| Accept Interest
|--------------------------------------------------------------------------
|
| 👉 Received interest accept करण्यासाठी
| 👉 Only receiver profile ला allow
|
*/
public function accept(\App\Models\Interest $interest)
{
    $user = auth()->user();

    // 🔒 Guard: login आवश्यक
    if (!$user || !$user->matrimonyProfile) {
        abort(403);
    }

    // 🔒 Guard: हा interest logged-in user चाच असला पाहिजे
    if ($interest->receiver_profile_id !== $user->matrimonyProfile->id) {
        abort(403);
    }

    // 🔒 Guard: फक्त pending interest accept करता येईल
    if ($interest->status !== 'pending') {
        return back()->with('error', 'This interest is already processed.');
    }

    // ✅ Accept
    $interest->update([
        'status' => 'accepted',
    ]);

    return back()->with('success', 'Interest accepted.');
}


/*
|--------------------------------------------------------------------------
| Reject Interest
|--------------------------------------------------------------------------
|
| 👉 Received interest reject करण्यासाठी
|
*/
public function reject(\App\Models\Interest $interest)
{
    $user = auth()->user();

    // 🔒 Guard: login आवश्यक
    if (!$user || !$user->matrimonyProfile) {
        abort(403);
    }

    // 🔒 Guard: हा interest logged-in user चाच असला पाहिजे
    if ($interest->receiver_profile_id !== $user->matrimonyProfile->id) {
        abort(403);
    }

    // 🔒 Guard: फक्त pending interest reject करता येईल
    if ($interest->status !== 'pending') {
        return back()->with('error', 'This interest is already processed.');
    }

    // ✅ Reject
    $interest->update([
        'status' => 'rejected',
    ]);

    return back()->with('success', 'Interest rejected.');
}
/*
|--------------------------------------------------------------------------
| Withdraw (Cancel) Interest
|--------------------------------------------------------------------------
|
| 👉 Sender ला pending interest cancel करण्यासाठी
|
*/
public function withdraw(\App\Models\Interest $interest)
{
    $user = auth()->user();

    // 🔒 Guard: login + profile आवश्यक
    if (!$user || !$user->matrimonyProfile) {
        abort(403);
    }

    // 🔒 Guard: फक्त sender च withdraw करू शकतो
    if ($interest->sender_profile_id !== $user->matrimonyProfile->id) {
        abort(403);
    }

    // 🔒 Guard: फक्त pending interest withdraw करता येईल
    if ($interest->status !== 'pending') {
        return back()->with('error', 'Only pending interests can be withdrawn.');
    }

    // ✅ Withdraw = delete record
    $interest->delete();

    return back()->with('success', 'Interest withdrawn successfully.');
}


}
