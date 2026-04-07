<?php

namespace App\Models;

use App\Services\EntitlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PENDING = 'pending';

    protected $fillable = [
        'user_id',
        'plan_id',
        'plan_term_id',
        'plan_price_id',
        'coupon_id',
        'starts_at',
        'ends_at',
        'status',
        'meta',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * Marks every active subscription row for this user as cancelled
     * (history preserved). Optionally skip one subscription id (e.g. the row being saved).
     *
     * @return int Rows updated
     */
    public static function deactivateActiveSubscriptionsForUserId(int $userId, ?int $exceptSubscriptionId = null): int
    {
        $query = static::query()
            ->where('user_id', $userId)
            ->where('status', self::STATUS_ACTIVE);

        if ($exceptSubscriptionId !== null) {
            $query->where('id', '!=', $exceptSubscriptionId);
        }

        return $query->update([
            'status' => self::STATUS_CANCELLED,
            'updated_at' => now(),
        ]);
    }

    /**
     * Paid period active OR within grace_days after ends_at (status must still be {@see self::STATUS_ACTIVE}).
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeEffectivelyActiveForAccess(Builder $query): Builder
    {
        $grace = (int) config('subscription.grace_days', 0);

        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function ($q) use ($grace) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now())
                    ->orWhere(function ($q2) use ($grace) {
                        if ($grace <= 0) {
                            return;
                        }
                        $q2->whereNotNull('ends_at')
                            ->where('ends_at', '<=', now())
                            ->where('ends_at', '>', now()->subDays($grace));
                    });
            });
    }

    protected static function booted(): void
    {
        static::saving(function (Subscription $subscription) {
            if ($subscription->status !== self::STATUS_ACTIVE) {
                return;
            }

            $exceptId = $subscription->exists ? (int) $subscription->getKey() : null;
            static::deactivateActiveSubscriptionsForUserId((int) $subscription->user_id, $exceptId);
        });

        static::created(function (Subscription $subscription) {
            app(EntitlementService::class)
                ->assignFromSubscription($subscription);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function planTerm(): BelongsTo
    {
        return $this->belongsTo(PlanTerm::class);
    }

    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function isActiveNow(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        if ($this->ends_at === null) {
            return true;
        }
        if ($this->ends_at->isFuture()) {
            return true;
        }

        $grace = (int) config('subscription.grace_days', 0);
        if ($grace <= 0) {
            return false;
        }

        return $this->ends_at->greaterThan(now()->subDays($grace));
    }
}
