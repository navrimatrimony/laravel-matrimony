<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class InterestController extends Controller
{
    public function store(\App\Models\User $user)
{
    $sender = auth()->user();     // login user
    $receiver = $user;            // ज्याला interest जातो

    // स्वतःलाच interest जाऊ नये
    if ($sender->id === $receiver->id) {
        abort(403);
    }

    // duplicate interest टाळण्यासाठी
    \App\Models\Interest::firstOrCreate([
        'sender_id'   => $sender->id,
        'receiver_id' => $receiver->id,
    ]);

    return back()->with('success', 'Interest पाठवले गेले.');
}

}
