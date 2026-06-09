<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class SuchakProfileRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_VIEWED_BY_SUCHAK = 'viewed_by_suchak';
    public const STATUS_ACCEPTED_BY_SUCHAK = 'accepted_by_suchak';
    public const STATUS_FORWARDED_TO_CANDIDATE = 'forwarded_to_candidate';
    public const STATUS_CANDIDATE_INTERESTED = 'candidate_interested';
    public const STATUS_CANDIDATE_NOT_INTERESTED = 'candidate_not_interested';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VIEWED_BY_SUCHAK,
        self::STATUS_ACCEPTED_BY_SUCHAK,
        self::STATUS_FORWARDED_TO_CANDIDATE,
        self::STATUS_CANDIDATE_INTERESTED,
        self::STATUS_CANDIDATE_NOT_INTERESTED,
        self::STATUS_CLOSED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VIEWED_BY_SUCHAK,
        self::STATUS_ACCEPTED_BY_SUCHAK,
        self::STATUS_FORWARDED_TO_CANDIDATE,
        self::STATUS_CANDIDATE_INTERESTED,
        self::STATUS_CANDIDATE_NOT_INTERESTED,
    ];

    protected $table = 'suchak_profile_requests';

    protected $fillable = [
        'requesting_user_id',
        'requesting_matrimony_profile_id',
        'target_matrimony_profile_id',
        'selected_suchak_account_id',
        'representation_id',
        'request_status',
        'request_reason',
        'message',
    ];

    public function requestingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requesting_user_id');
    }

    public function requestingMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'requesting_matrimony_profile_id');
    }

    public function targetMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'target_matrimony_profile_id');
    }

    public function selectedSuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'selected_suchak_account_id');
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'representation_id');
    }

    public function pipeline(): HasOne
    {
        return $this->hasOne(SuchakPipeline::class, 'request_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->request_status, self::OPEN_STATUSES, true);
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak profile request records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak profile request records cannot be deleted.');
    }
}
