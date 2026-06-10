<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakSubscription extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_PENDING_ADMIN_REVIEW = 'pending_admin_review';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
        self::STATUS_PENDING_ADMIN_REVIEW,
    ];

    protected $table = 'suchak_subscriptions';

    protected $fillable = [
        'suchak_account_id',
        'suchak_plan_id',
        'assigned_by_user_id',
        'status',
        'starts_at',
        'ends_at',
        'assigned_at',
        'cancelled_at',
        'expired_at',
        'notes',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'assigned_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    /**
     * @param  Builder<SuchakSubscription>  $query
     * @return Builder<SuchakSubscription>
     */
    public function scopeActiveAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('starts_at', '<=', $at)
            ->where(function (Builder $dates) use ($at): void {
                $dates->whereNull('ends_at')
                    ->orWhere('ends_at', '>', $at);
            });
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function suchakPlan(): BelongsTo
    {
        return $this->belongsTo(SuchakPlan::class);
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak subscriptions cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak subscriptions cannot be deleted.');
    }
}
