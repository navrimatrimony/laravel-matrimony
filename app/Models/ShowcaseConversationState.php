<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShowcaseConversationState extends Model
{
    protected $table = 'showcase_conversation_states';

    protected $fillable = [
        'conversation_id',
        'showcase_profile_id',
        'automation_status',
        'pending_read_at',
        'pending_typing_at',
        'pending_reply_at',
        'pending_offline_at',
        'last_online_at',
        'last_offline_at',
        'unanswered_incoming_count',
        'last_incoming_at',
        'active_lock_until',
        'last_read_at',
        'last_auto_reply_at',
        'last_admin_reply_at',
        'last_incoming_message_id',
        'last_outgoing_message_id',
        'admin_takeover_until',
    ];

    protected $casts = [
        'pending_read_at' => 'datetime',
        'pending_typing_at' => 'datetime',
        'pending_reply_at' => 'datetime',
        'pending_offline_at' => 'datetime',
        'last_online_at' => 'datetime',
        'last_offline_at' => 'datetime',
        'last_incoming_at' => 'datetime',
        'active_lock_until' => 'datetime',
        'last_read_at' => 'datetime',
        'last_auto_reply_at' => 'datetime',
        'last_admin_reply_at' => 'datetime',
        'admin_takeover_until' => 'datetime',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ADMIN_TAKEOVER = 'admin_takeover';
    public const STATUS_SILENCED = 'silenced';

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function showcaseProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'showcase_profile_id');
    }
}

