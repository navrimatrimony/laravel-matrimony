<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class SuchakCollaborationRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ADMIN_REVIEW = 'admin_review';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
        self::STATUS_ADMIN_REVIEW,
    ];

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_ADMIN_REVIEW,
    ];

    protected $table = 'suchak_collaboration_requests';

    protected $fillable = [
        'requesting_suchak_account_id',
        'target_suchak_account_id',
        'requesting_matrimony_profile_id',
        'target_matrimony_profile_id',
        'requesting_representation_id',
        'target_representation_id',
        'status',
        'message',
        'requested_at',
        'responded_at',
        'expires_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function requestingSuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'requesting_suchak_account_id');
    }

    public function targetSuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'target_suchak_account_id');
    }

    public function requestingMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'requesting_matrimony_profile_id');
    }

    public function targetMatrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'target_matrimony_profile_id');
    }

    public function requestingRepresentation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'requesting_representation_id');
    }

    public function targetRepresentation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'target_representation_id');
    }

    public function commissionAgreement(): HasOne
    {
        return $this->hasOne(SuchakCommissionAgreement::class, 'collaboration_request_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SuchakLedgerEntry::class, 'collaboration_request_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak collaboration request records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak collaboration request records cannot be deleted.');
    }
}
