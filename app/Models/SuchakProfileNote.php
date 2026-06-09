<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakProfileNote extends Model
{
    use HasFactory;

    public const TYPE_GENERAL = 'general';
    public const TYPE_FOLLOW_UP = 'follow_up';
    public const TYPE_MEETING = 'meeting';
    public const TYPE_CALL = 'call';
    public const TYPE_COLLABORATION = 'collaboration';

    public const TYPES = [
        self::TYPE_GENERAL,
        self::TYPE_FOLLOW_UP,
        self::TYPE_MEETING,
        self::TYPE_CALL,
        self::TYPE_COLLABORATION,
    ];

    public const VISIBILITY_PRIVATE = 'private';

    protected $table = 'suchak_profile_notes';

    protected $fillable = [
        'suchak_account_id',
        'matrimony_profile_id',
        'collaboration_request_id',
        'note_type',
        'note_text',
        'visibility',
        'follow_up_at',
    ];

    protected $casts = [
        'follow_up_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakCollaborationRequest::class, 'collaboration_request_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak profile notes cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak profile notes cannot be deleted.');
    }
}
