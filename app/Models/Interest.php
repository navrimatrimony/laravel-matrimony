<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Interest Model (SSOT v3.1)
|--------------------------------------------------------------------------
|
| 👉 Interest = MatrimonyProfile → MatrimonyProfile
| 👉 User कधीही involved नाही
|
*/

class Interest extends Model
{
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | Mass assignable fields
    |--------------------------------------------------------------------------
    */
    protected $fillable = [
        'sender_profile_id',
        'receiver_profile_id',
        'status',
    ];

    /*
    |--------------------------------------------------------------------------
    | Sender Matrimony Profile
    |--------------------------------------------------------------------------
    |
    | वापर:
    | $interest->senderProfile->full_name
    |
    */
    public function senderProfile()
    {
        return $this->belongsTo(
            MatrimonyProfile::class,
            'sender_profile_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Receiver Matrimony Profile
    |--------------------------------------------------------------------------
    |
    | वापर:
    | $interest->receiverProfile->full_name
    |
    */
    public function receiverProfile()
    {
        return $this->belongsTo(
            MatrimonyProfile::class,
            'receiver_profile_id'
        );
    }
}
