<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $table = 'messages';

    protected $fillable = [
        'conversation_id',
        'sender_profile_id',
        'receiver_profile_id',
        'message_type',
        'body_text',
        'image_path',
        'sent_at',
        'read_at',
        'delivery_status',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public const TYPE_TEXT = 'text';
    public const TYPE_IMAGE = 'image';

    public const DELIVERY_SENT = 'sent';
    public const DELIVERY_READ = 'read';

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function senderProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'sender_profile_id');
    }

    public function receiverProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'receiver_profile_id');
    }
}
