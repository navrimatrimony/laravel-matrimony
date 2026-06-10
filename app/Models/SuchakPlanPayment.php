<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class SuchakPlanPayment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
    ];

    protected $table = 'suchak_plan_payments';

    protected $fillable = [
        'suchak_account_id',
        'suchak_plan_id',
        'suchak_subscription_id',
        'initiated_by_user_id',
        'txnid',
        'gateway_txnid',
        'plan_name',
        'plan_slug',
        'billing_period_days',
        'amount',
        'currency',
        'payment_status',
        'gateway',
        'source',
        'product_info',
        'gateway_status',
        'gateway_mode',
        'response_hash',
        'paid_at',
        'failed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'billing_period_days' => 'integer',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function suchakPlan(): BelongsTo
    {
        return $this->belongsTo(SuchakPlan::class);
    }

    public function suchakSubscription(): BelongsTo
    {
        return $this->belongsTo(SuchakSubscription::class);
    }

    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(SuchakPlanInvoice::class);
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak plan payment records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak plan payment records cannot be deleted.');
    }
}
