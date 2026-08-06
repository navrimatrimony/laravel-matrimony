<?php

namespace App\Models;

use App\Services\EntitlementService;
use App\Services\ActivePlanResolver;
use App\Services\PlanSubscriptionTerms;
use Carbon\CarbonInterface;
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
        'coupon_id',
        'starts_at',
        'ends_at',
        'status',
        'grace_period_days',
        'leftover_quota_carry_window_days',
        'meta',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'grace_period_days' => 'integer',
        'leftover_quota_carry_window_days' => 'integer',
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
     * Paid period active OR within this subscription's frozen grace after ends_at.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeEffectivelyActiveForAccess(Builder $query): Builder
    {
        return $query->effectivelyActiveForAccessAt(now());
    }

    /**
     * Same rules as {@see scopeEffectivelyActiveForAccess} at a fixed instant (tests / previews).
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeEffectivelyActiveForAccessAt(Builder $query, CarbonInterface $at): Builder
    {
        $driver = $query->getConnection()->getDriverName();
        $graceExpr = match ($driver) {
            'mysql', 'mariadb' => 'DATE_ADD(subscriptions.ends_at, INTERVAL COALESCE(subscriptions.grace_period_days, 0) DAY)',
            'sqlite' => "datetime(subscriptions.ends_at, '+' || COALESCE(subscriptions.grace_period_days, 0) || ' days')",
            'pgsql' => "subscriptions.ends_at + (COALESCE(subscriptions.grace_period_days, 0) || ' days')::interval",
            default => 'subscriptions.ends_at',
        };

        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where(function ($q) use ($at, $graceExpr) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', $at)
                    ->orWhere(function ($grace) use ($at, $graceExpr) {
                        $grace->whereNotNull('ends_at')
                            ->where('ends_at', '<=', $at)
                            ->whereRaw($graceExpr.' > ?', [$at->toDateTimeString()]);
                    });
            });
    }

    /**
     * Authoritative query for the member's current subscription row (paid window + grace).
     * Ordering: {@code starts_at} descending — same as {@see \App\Services\SubscriptionService::getActiveSubscription()}.
     * Use {@code ->first()} at call sites; keeps selection logic out of duplicated ad-hoc queries.
     *
     * @return Builder<Subscription>
     */
    public static function queryAuthoritativeAccessForUser(User $user, ?CarbonInterface $at = null): Builder
    {
        $moment = $at ?? now();

        return static::query()
            ->where('user_id', $user->id)
            ->effectivelyActiveForAccessAt($moment)
            ->orderByDesc('starts_at')
            ->orderByDesc('id');
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
            app(ActivePlanResolver::class)
                ->enforceSingleActiveRowInvariantByUserId((int) $subscription->user_id, 'subscription_created_hook');
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

    /**
     * Immutable purchase-time pricing context (never re-read from {@see Plan} / {@see PlanTerm} for display of what was bought).
     *
     * @return array<string, mixed>
     */
    public function checkoutSnapshot(): array
    {
        $meta = $this->meta;
        if (! is_array($meta)) {
            return [];
        }
        $snap = $meta['checkout_snapshot'] ?? null;

        return is_array($snap) ? $snap : [];
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

        $grace = PlanSubscriptionTerms::gracePeriodDaysForSubscription($this);
        if ($grace <= 0) {
            return false;
        }

        return $this->ends_at->greaterThan(now()->subDays($grace));
    }
}
