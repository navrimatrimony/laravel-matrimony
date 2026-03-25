<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $table = 'conversations';

    protected $fillable = [
        'profile_one_id',
        'profile_two_id',
        'created_by_profile_id',
        'status',
        'last_message_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * Canonicalize pair IDs so (a,b) and (b,a) map to same row.
     *
     * @return array{0:int,1:int}
     */
    public static function normalizePairIds(int $a, int $b): array
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }

    public function profileOne(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_one_id');
    }

    public function profileTwo(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_two_id');
    }

    public function createdByProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'created_by_profile_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function participantStates(): HasMany
    {
        return $this->hasMany(MessageParticipantState::class, 'conversation_id');
    }

    public function participantStateForProfile(int $profileId): HasOne
    {
        return $this->hasOne(MessageParticipantState::class, 'conversation_id')->where('profile_id', $profileId);
    }
}
