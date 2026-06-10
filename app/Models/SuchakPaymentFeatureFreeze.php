<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakPaymentFeatureFreeze extends Model
{
    use HasFactory;

    public const SCOPE_DIRECT_COLLECTION = 'direct_collection';
    public const SCOPE_CUSTOMER_CONTEXT = 'customer_context';
    public const SCOPE_ACCOUNT = 'account';

    public const SCOPES = [
        self::SCOPE_DIRECT_COLLECTION,
        self::SCOPE_CUSTOMER_CONTEXT,
        self::SCOPE_ACCOUNT,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_RELEASED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'suchak_payment_feature_freezes';

    protected $fillable = [
        'suchak_dispute_id',
        'suchak_account_id',
        'customer_context_id',
        'payment_context_id',
        'freeze_scope',
        'freeze_status',
        'freeze_reason',
        'created_by_admin_user_id',
        'released_by_admin_user_id',
        'released_at',
        'release_reason',
    ];

    protected $casts = [
        'released_at' => 'datetime',
    ];

    public function scopeActiveForPaymentContext(Builder $query, SuchakPaymentContext $context): Builder
    {
        return $query
            ->where('freeze_status', self::STATUS_ACTIVE)
            ->where('suchak_account_id', $context->suchak_account_id)
            ->where(function (Builder $scope) use ($context): void {
                $scope->where('freeze_scope', self::SCOPE_ACCOUNT)
                    ->orWhere('payment_context_id', $context->id)
                    ->orWhere(function (Builder $customerScope) use ($context): void {
                        $customerScope
                            ->where('freeze_scope', self::SCOPE_CUSTOMER_CONTEXT)
                            ->whereNotNull('customer_context_id')
                            ->where('customer_context_id', $context->customer_context_id);
                    });
            });
    }

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

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_user_id');
    }

    public function releasedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_admin_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak payment feature freeze records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak payment feature freeze records cannot be deleted.');
    }
}
