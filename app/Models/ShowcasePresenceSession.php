<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShowcasePresenceSession extends Model
{
    protected $table = 'showcase_presence_sessions';

    protected $fillable = [
        'showcase_profile_id',
        'conversation_id',
        'started_at',
        'scheduled_end_at',
        'ended_at',
        'trigger_type',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function showcaseProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'showcase_profile_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}

