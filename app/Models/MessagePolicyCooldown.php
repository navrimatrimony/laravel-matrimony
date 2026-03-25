<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessagePolicyCooldown extends Model
{
    protected $table = 'message_policy_cooldowns';

    protected $fillable = [
        'sender_profile_id',
        'receiver_profile_id',
        'conversation_id',
        'reason',
        'locked_until',
    ];

    protected $casts = [
        'locked_until' => 'datetime',
    ];

    public const REASON_REPLY_GATE_LIMIT = 'reply_gate_limit';
    public const REASON_ADMIN_ACTION = 'admin_action';

    public function senderProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'sender_profile_id');
    }

    public function receiverProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'receiver_profile_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
