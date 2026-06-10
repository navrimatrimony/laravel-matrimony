<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakPlatformPayoutDetail extends Model
{
    use HasFactory;

    public const METHOD_BANK_TRANSFER = 'bank_transfer';
    public const METHOD_UPI = 'upi';
    public const METHOD_MANUAL_REVIEW = 'manual_review';

    public const METHODS = [
        self::METHOD_BANK_TRANSFER,
        self::METHOD_UPI,
        self::METHOD_MANUAL_REVIEW,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ON_HOLD = 'on_hold';

    public const VERIFICATION_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
        self::STATUS_ON_HOLD,
    ];

    protected $table = 'suchak_platform_payout_details';

    protected $fillable = [
        'platform_payout_id',
        'suchak_account_id',
        'payout_method',
        'payout_detail_reference',
        'beneficiary_name',
        'account_last_four',
        'ifsc_code',
        'upi_handle_masked',
        'verification_status',
        'verification_note',
        'created_by_user_id',
        'verified_by_user_id',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function platformPayout(): BelongsTo
    {
        return $this->belongsTo(SuchakPlatformPayout::class, 'platform_payout_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak platform payout detail records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak platform payout detail records cannot be deleted.');
    }
}
