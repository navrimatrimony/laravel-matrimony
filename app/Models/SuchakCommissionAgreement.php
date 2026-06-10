<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakCommissionAgreement extends Model
{
    use HasFactory;

    public const TYPE_COLLABORATION_ACK = 'collaboration_commission_ack';
    public const SPLIT_TO_BE_DISCUSSED = 'to_be_discussed';
    public const SPLIT_EQUAL_PERCENT = 'equal_percent';
    public const SPLIT_CUSTOM_PERCENT = 'custom_percent';
    public const SPLIT_FIXED_AMOUNT = 'fixed_amount';

    public const SPLIT_TYPES = [
        self::SPLIT_TO_BE_DISCUSSED,
        self::SPLIT_EQUAL_PERCENT,
        self::SPLIT_CUSTOM_PERCENT,
        self::SPLIT_FIXED_AMOUNT,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const MVP_ACK_TEXT = 'मी या match साठी commission / credit sharing terms मान्य करतो.';

    protected $table = 'suchak_commission_agreements';

    protected $fillable = [
        'collaboration_request_id',
        'groom_side_suchak_account_id',
        'bride_side_suchak_account_id',
        'agreement_type',
        'split_type',
        'groom_side_share',
        'bride_side_share',
        'fixed_amount',
        'currency',
        'agreement_text_snapshot',
        'accepted_by_groom_suchak_at',
        'accepted_by_bride_suchak_at',
        'agreement_status',
    ];

    protected $casts = [
        'groom_side_share' => 'decimal:2',
        'bride_side_share' => 'decimal:2',
        'fixed_amount' => 'decimal:2',
        'accepted_by_groom_suchak_at' => 'datetime',
        'accepted_by_bride_suchak_at' => 'datetime',
    ];

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakCollaborationRequest::class, 'collaboration_request_id');
    }

    public function groomSideSuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'groom_side_suchak_account_id');
    }

    public function brideSideSuchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class, 'bride_side_suchak_account_id');
    }

    public function isAcceptedByBothSides(): bool
    {
        return $this->agreement_status === self::STATUS_ACCEPTED
            && $this->accepted_by_groom_suchak_at !== null
            && $this->accepted_by_bride_suchak_at !== null;
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak commission agreement records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak commission agreement records cannot be deleted.');
    }
}
