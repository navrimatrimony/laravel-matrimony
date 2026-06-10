<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakPlatformLeadAllocation extends Model
{
    use HasFactory;

    public const STATUS_ALLOCATED = 'allocated';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_CONVERTED = 'converted';

    public const STATUSES = [
        self::STATUS_ALLOCATED,
        self::STATUS_ACCEPTED,
        self::STATUS_DECLINED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
        self::STATUS_CONVERTED,
    ];

    public const OPEN_STATUSES = [
        self::STATUS_ALLOCATED,
        self::STATUS_ACCEPTED,
    ];

    public const MATCH_NONE = 'none';
    public const MATCH_DISTRICT = 'district';
    public const MATCH_TALUKA = 'taluka';
    public const MATCH_CITY = 'city';
    public const MATCH_RELIGION = 'religion';
    public const MATCH_CASTE = 'caste';
    public const MATCH_SUB_CASTE = 'sub_caste';

    protected $table = 'suchak_platform_lead_allocations';

    protected $fillable = [
        'platform_lead_id',
        'suchak_account_id',
        'customer_context_id',
        'payment_context_id',
        'allocation_status',
        'allocation_policy',
        'rotation_bucket_key',
        'rotation_sequence',
        'matched_area_level',
        'matched_community_level',
        'plan_limit_snapshot',
        'allocated_by_admin_user_id',
        'allocated_at',
        'sla_expires_at',
        'accepted_by_user_id',
        'accepted_at',
        'acceptance_note',
        'declined_by_user_id',
        'declined_at',
        'decline_reason',
        'expired_at',
        'cancelled_by_admin_user_id',
        'cancelled_at',
        'status_note',
    ];

    protected $casts = [
        'rotation_sequence' => 'integer',
        'plan_limit_snapshot' => 'integer',
        'allocated_at' => 'datetime',
        'sla_expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
        'expired_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function platformLead(): BelongsTo
    {
        return $this->belongsTo(SuchakPlatformLead::class, 'platform_lead_id');
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

    public function allocatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by_admin_user_id');
    }

    public function acceptedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function declinedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declined_by_user_id');
    }

    public function cancelledByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_admin_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SuchakLeadAllocationEvent::class, 'lead_allocation_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak platform lead allocation records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak platform lead allocation records cannot be deleted.');
    }
}
