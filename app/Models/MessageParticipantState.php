<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageParticipantState extends Model
{
    protected $table = 'message_participant_states';

    protected $fillable = [
        'conversation_id',
        'profile_id',
        'last_read_message_id',
        'last_read_at',
        'is_archived',
        'is_blocked',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
        'is_archived' => 'boolean',
        'is_blocked' => 'boolean',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_read_message_id');
    }
}
