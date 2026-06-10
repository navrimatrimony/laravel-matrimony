<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakCustomerOverdueServiceAction extends Model
{
    use HasFactory;

    public const TYPE_PAYMENT_FOLLOWUP = 'payment_followup';
    public const TYPE_SERVICE_PAUSE_WARNING = 'service_pause_warning';
    public const TYPE_SUCHAK_SERVICE_PAUSED = 'suchak_service_paused';

    public const TYPES = [
        self::TYPE_PAYMENT_FOLLOWUP,
        self::TYPE_SERVICE_PAUSE_WARNING,
        self::TYPE_SUCHAK_SERVICE_PAUSED,
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_RESOLVED,
        self::STATUS_CANCELLED,
    ];

    public const POLICY_SUCHAK_SERVICE_ONLY = 'suchak_service_only';

    protected $table = 'suchak_customer_overdue_service_actions';

    protected $fillable = [
        'customer_payment_id',
        'suchak_account_id',
        'customer_context_id',
        'payment_request_id',
        'action_type',
        'action_status',
        'action_policy',
        'due_amount',
        'currency',
        'reason',
        'created_by_user_id',
        'resolved_by_user_id',
        'resolved_at',
        'resolution_note',
    ];

    protected $casts = [
        'due_amount' => 'decimal:2',
        'resolved_at' => 'datetime',
    ];

    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerPayment::class, 'customer_payment_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentRequest::class, 'payment_request_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak customer overdue service actions cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak customer overdue service actions cannot be deleted.');
    }
}
