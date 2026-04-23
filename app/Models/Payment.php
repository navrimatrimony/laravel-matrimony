<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'plan_term_id',
        'txnid',
        'payu_txnid',
        'plan_key',
        'billing_key',
        'amount',
        'amount_paid',
        'currency',
        'status',
        'payment_status',
        'gateway',
        'payload',
        'meta',
        'source',
        'is_processed',
        'webhook_is_final',
        'refunded_at',
        'refund_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'meta' => 'array',
        'payload' => 'array',
        'is_processed' => 'boolean',
        'webhook_is_final' => 'boolean',
        'refunded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(PaymentDispute::class);
    }

    public function refund(?string $reason = null): bool
    {
        if ($this->payment_status === 'refunded') {
            return false;
        }

        if ($this->payment_status !== 'success') {
            return false;
        }

        $this->payment_status = 'refunded';
        $this->refunded_at = now();
        $this->refund_reason = $reason;
        $this->save();

        Log::info('Payment refunded', [
            'txnid' => $this->txnid,
            'user_id' => $this->user_id,
        ]);

        return true;
    }
}
