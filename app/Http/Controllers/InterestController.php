<?php

namespace App\Http\Controllers;

use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Notifications\InterestAcceptedNotification;
use App\Notifications\InterestRejectedNotification;
use App\Notifications\InterestSentNotification;
use App\Services\ProfileCompletenessService;
use App\Services\ProfileLifecycleService;
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
    | POST /interests/send/{matrimony_profile}
    |
    | Meaning:
    | - Logged-in user च्या MatrimonyProfile कडून
    | - समोरच्या user च्या MatrimonyProfile ला
    |
    */
    

   // 🔒 SSOT-COMPLIANT ROUTE MODEL BINDING
// Route param: {matrimony_profile_id}

public function store(MatrimonyProfile $matrimony_profile_id)
{
    // 🔁 Internal SSOT variable alias
    $matrimonyProfile = $matrimony_profile_id;

    // 🔒 AUTH USER (authentication only)
    $authUser = auth()->user();

    // 🔒 GUARD: MatrimonyProfile must exist
    if (!$authUser || !$authUser->matrimonyProfile) {
        return redirect()
            ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
            ->with('error', 'Please create your matrimony profile first.');
    }

    // 🔒 Sender & Receiver Profiles (SSOT)
    $senderProfile   = $authUser->matrimonyProfile;
    $receiverProfile = $matrimonyProfile;

    // 🔒 GUARD: Cannot send interest to own profile
    if ($senderProfile->id === $receiverProfile->id) {
        return back()->with(
            'error',
            'You cannot send interest to your own profile.'
        );
    }

    // 🔒 Safety check (defensive)
    if (!$senderProfile || !$receiverProfile) {
        abort(403, 'Matrimony profile missing');
    }

    // Day 7: Sender lifecycle — Archived/Suspended/Demo-Hidden cannot send interest
    if (!ProfileLifecycleService::canInitiateInteraction($senderProfile)) {
        return back()->with('error', 'Your profile cannot send interest in its current state.');
    }

    // 🔒 70% completeness required for send and receive
    if (!ProfileCompletenessService::meetsThreshold($senderProfile)) {
        return back()->with('error', 'Your profile must be at least 70% complete to send interest.');
    }
    if (!ProfileCompletenessService::meetsThreshold($receiverProfile)) {
        return back()->with('error', 'You cannot send interest to this profile.');
    }

    // Day 7: Archived/Suspended/Demo-Hidden → interest blocked
    if (!ProfileLifecycleService::canReceiveInterest($receiverProfile)) {
        return back()->with('error', 'You cannot send interest to this profile.');
    }

    // 🔁 Duplicate interest protection
    $interest = Interest::firstOrCreate(
        [
            'sender_profile_id'   => $senderProfile->id,
            'receiver_profile_id' => $receiverProfile->id,
        ],
        [
            'status' => 'pending',
        ]
    );

    if ($interest->wasRecentlyCreated) {
        $receiverOwner = $receiverProfile->user;
        if ($receiverOwner) {
            $receiverOwner->notify(new InterestSentNotification($senderProfile));
        }
    }

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
        ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
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
        ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
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

    // 🔒 70% completeness required to receive (accept) interest
    $receiverProfile = $interest->receiverProfile;
    if (!$receiverProfile || !ProfileCompletenessService::meetsThreshold($receiverProfile)) {
        return back()->with('error', 'Your profile must be at least 70% complete to accept interest.');
    }

    // ✅ Accept
    $interest->update([
        'status' => 'accepted',
    ]);

    // Phase-5: Grant contact visibility via normalized table (replaces contact_visible_to JSON)
    $senderProfile = $interest->senderProfile;
    if ($senderProfile && $receiverProfile->contact_unlock_mode === 'after_interest_accepted') {
        \Illuminate\Support\Facades\DB::table('profile_contact_visibility')->insertOrIgnore([
            'owner_profile_id' => $receiverProfile->id,
            'viewer_profile_id' => $senderProfile->id,
            'granted_via' => 'interest_accept',
            'granted_at' => now(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('contact_access_log')->insert([
            'owner_profile_id' => $receiverProfile->id,
            'viewer_profile_id' => $senderProfile->id,
            'source' => 'interest',
            'unlocked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $senderOwner = $interest->senderProfile?->user;
    if ($senderOwner) {
        $senderOwner->notify(new InterestAcceptedNotification($receiverProfile));
    }

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

    $senderOwner = $interest->senderProfile?->user;
    if ($senderOwner) {
        $senderOwner->notify(new InterestRejectedNotification($user->matrimonyProfile));
    }

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
