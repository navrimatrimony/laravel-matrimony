<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakProfileUpdateSuggestion extends Model
{
    use HasFactory;

    public const STATUS_PENDING_CANDIDATE_CONFIRMATION = 'pending_candidate_confirmation';
    public const STATUS_APPROVED_BY_CANDIDATE = 'approved_by_candidate';
    public const STATUS_REJECTED_BY_CANDIDATE = 'rejected_by_candidate';
    public const STATUS_ADMIN_REVIEW_REQUIRED = 'admin_review_required';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING_CANDIDATE_CONFIRMATION,
        self::STATUS_APPROVED_BY_CANDIDATE,
        self::STATUS_REJECTED_BY_CANDIDATE,
        self::STATUS_ADMIN_REVIEW_REQUIRED,
        self::STATUS_APPLIED,
        self::STATUS_CANCELLED,
    ];

    public const MAX_OTP_ATTEMPTS = 5;

    protected $table = 'suchak_profile_update_suggestions';

    protected $fillable = [
        'suchak_account_id',
        'matrimony_profile_id',
        'representation_id',
        'field_key',
        'old_value',
        'suggested_value',
        'suggestion_status',
        'otp_hash',
        'otp_attempts',
        'last_otp_sent_at',
        'candidate_verified_at',
        'admin_reviewed_at',
        'applied_at',
    ];

    protected $casts = [
        'otp_attempts' => 'integer',
        'last_otp_sent_at' => 'datetime',
        'candidate_verified_at' => 'datetime',
        'admin_reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'representation_id');
    }

    public function isOpenForCandidateConfirmation(): bool
    {
        return $this->suggestion_status === self::STATUS_PENDING_CANDIDATE_CONFIRMATION;
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak profile update suggestions cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak profile update suggestions cannot be deleted.');
    }
}
