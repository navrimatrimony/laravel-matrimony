<?php

namespace App\Models;

use App\Services\EntitlementService;
use App\Services\PlanSubscriptionTerms;
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
     * Paid period active OR within plan-specific grace after ends_at.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeEffectivelyActiveForAccess(Builder $query): Builder
    {
        $now = now();
        $driver = $query->getConnection()->getDriverName();
        $graceExpr = match ($driver) {
            'mysql', 'mariadb' => 'DATE_ADD(subscriptions.ends_at, INTERVAL COALESCE(p.grace_period_days, 0) DAY)',
            'sqlite' => "datetime(subscriptions.ends_at, '+' || COALESCE(p.grace_period_days, 0) || ' days')",
            'pgsql' => "subscriptions.ends_at + (COALESCE(p.grace_period_days, 0) || ' days')::interval",
            default => 'subscriptions.ends_at',
        };

        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where(function ($q) use ($now, $graceExpr) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', $now)
                    ->orWhereExists(function ($plan) use ($now, $graceExpr) {
                        $plan->selectRaw('1')
                            ->from('plans as p')
                            ->whereColumn('p.id', 'subscriptions.plan_id')
                            ->whereNotNull('subscriptions.ends_at')
                            ->where('subscriptions.ends_at', '<=', $now)
                            ->whereRaw($graceExpr.' > ?', [$now->toDateTimeString()]);
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

        $this->loadMissing('plan');
        $grace = PlanSubscriptionTerms::gracePeriodDays($this->plan);
        if ($grace <= 0) {
            return false;
        }

        return $this->ends_at->greaterThan(now()->subDays($grace));
    }
}
