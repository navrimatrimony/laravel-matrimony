<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakPayoutHold extends Model
{
    use HasFactory;

    public const SCOPE_DIRECT_PAYMENT_RISK = 'direct_payment_risk';
    public const SCOPE_VISIT_CONFIRMATION_DISPUTE = 'visit_confirmation_dispute';

    public const SCOPES = [
        self::SCOPE_DIRECT_PAYMENT_RISK,
        self::SCOPE_VISIT_CONFIRMATION_DISPUTE,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_RELEASED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'suchak_payout_holds';

    protected $fillable = [
        'suchak_dispute_id',
        'suchak_account_id',
        'customer_context_id',
        'payment_context_id',
        'hold_scope',
        'hold_status',
        'hold_reason',
        'created_by_user_id',
        'released_by_user_id',
        'released_at',
        'release_reason',
    ];

    protected $casts = [
        'released_at' => 'datetime',
    ];

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(SuchakDispute::class, 'suchak_dispute_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function paymentContext(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentContext::class, 'payment_context_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function releasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak payout hold records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak payout hold records cannot be deleted.');
    }
}
