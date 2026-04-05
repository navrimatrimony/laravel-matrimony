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

    public const PRIORITY_SCORE_FREE = 1;

    public const PRIORITY_SCORE_PAID = 10;

    /*
    |--------------------------------------------------------------------------
    | Mass assignable fields
    |--------------------------------------------------------------------------
    */
    protected $fillable = [
        'sender_profile_id',
        'receiver_profile_id',
        'status',
        'priority_score',
    ];

    protected function casts(): array
    {
        return [
            'priority_score' => 'integer',
        ];
    }

    /**
     * Received inbox: higher {@see $priority_score} first, then newest.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeReceivedInboxOrder($query)
    {
        return $query->orderByDesc('priority_score')->orderByDesc('created_at');
    }

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
