<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * THE PLATFORM'S OWN PRICE LIST for money the platform pays a Suchak.
 *
 * Every row is created by an admin through
 * {@see \App\Modules\Suchak\Services\SuchakGrowthRewardService::createRewardRule()},
 * carries its own audit row, and can never be deleted or repriced — a published
 * price is superseded by publishing a later one, never rewritten. That is what
 * makes it the honest source for a figure the platform owes: it is the
 * platform's number, decided before the payee's case arrived, on the record.
 *
 * THE ONE MUTATION, and it is not a reprice.
 * {@see \App\Modules\Suchak\Services\SuchakGrowthRewardService::withdrawRewardRule()}
 * sets `is_active` false and touches nothing else. Supersession can only say
 * "the price is now different"; withdrawal is the only way to say "the platform
 * no longer pays for this at all", and without it `is_active` had two readers
 * ({@see scopeInForce()}, `assertRuleQualifies()`) and no writer that could ever
 * make either of them false. It is one-way: publishing a fresh rule is the way
 * back, because un-withdrawing is a rewrite of what the platform said.
 */
class SuchakGrowthRewardRule extends Model
{
    use HasFactory;

    public const TRIGGER_PLATFORM_PAYMENT_CONFIRMED = 'platform_payment_confirmed';

    /**
     * ADDED FOR THE MEETING REWARD. Same string as
     * {@see SuchakPlatformPayout::EVENT_PLATFORM_VISIT_CONFIRMED} on purpose —
     * one event, one name, whichever table is naming it.
     *
     * No migration: `reward_trigger` is a plain `string(64)` with an index and
     * no enum or check constraint (see migration 2026_06_10_112000), so the
     * allowed set lives here and nowhere else.
     *
     * WHAT A VISIT-TRIGGER ROW MUST NOT BE READ FOR: `attribution_policy` is
     * meaningless on it. That column belongs to the referral engine, is NOT
     * NULL with a referral default, and a meeting reward has no attribution at
     * all. Nothing reads it on this trigger, and nothing may start to —
     * every consumer filters by `reward_trigger` FIRST
     * ({@see \App\Modules\Suchak\Services\SuchakScheduledJobsConsolidationService}
     * does, {@see self::visitRewardInForce()} does), which is exactly why a
     * visit rule can never be picked up and paid as a referral reward.
     */
    public const TRIGGER_PLATFORM_VISIT_CONFIRMED = 'platform_visit_confirmed';

    public const TRIGGERS = [
        self::TRIGGER_PLATFORM_PAYMENT_CONFIRMED,
        self::TRIGGER_PLATFORM_VISIT_CONFIRMED,
    ];

    public const TYPE_CASH = 'cash';
    public const TYPE_CREDIT = 'credit';
    public const TYPE_ADMIN_ACTION = 'admin_action';

    public const TYPES = [
        self::TYPE_CASH,
        self::TYPE_CREDIT,
        self::TYPE_ADMIN_ACTION,
    ];

    protected $table = 'suchak_growth_reward_rules';

    protected $fillable = [
        'rule_key',
        'reward_trigger',
        'reward_type',
        'attribution_policy',
        'reward_amount',
        'reward_currency',
        'credit_value',
        'admin_action_key',
        'is_active',
        'starts_at',
        'ends_at',
        'created_by_admin_user_id',
    ];

    protected $casts = [
        'reward_amount' => 'decimal:2',
        'credit_value' => 'decimal:2',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * The rules standing at an instant, for ONE trigger.
     *
     * `is_active` plus the two-sided window is the whole of "in force" — the
     * same three conditions
     * {@see \App\Modules\Suchak\Services\SuchakScheduledJobsConsolidationService::qualifyRewards()}
     * already applies inline for the payment trigger. It is written once here
     * so the second consumer does not become a second definition; that older
     * inline copy should be pointed at this scope rather than a third being
     * written.
     */
    public function scopeInForce(Builder $query, string $trigger, ?Carbon $at = null): Builder
    {
        $at = $at ?? Carbon::now();

        return $query
            ->where('reward_trigger', $trigger)
            ->where('is_active', true)
            ->where(fn (Builder $inner) => $inner->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $inner) => $inner->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
    }

    /**
     * THE PLATFORM VISIT REWARD IN FORCE — the one figure a meeting payout may be worth.
     *
     * Supersession, not editing. A row can never be updated or deleted, so a new
     * price is a new row and the NEWEST in-force row wins: a dated rule outranks
     * an undated one, a later `starts_at` outranks an earlier one, and the
     * higher id breaks a tie. That ordering is total, so two overlapping rules
     * can never leave the amount ambiguous — and the rule that actually applied
     * is written onto the payout trail by its `rule_key`, so a year later the
     * row can be read back.
     *
     * `TYPE_CASH` only: a credit or an admin-action rule has `reward_amount`
     * normalised to 0.00 and would qualify a payout worth nothing.
     */
    public static function visitRewardInForce(?Carbon $at = null): ?self
    {
        return static::query()
            ->inForce(self::TRIGGER_PLATFORM_VISIT_CONFIRMED, $at)
            ->where('reward_type', self::TYPE_CASH)
            // Portable NULLS-LAST. MySQL and SQLite sort NULL last under DESC,
            // PostgreSQL sorts it first; spelling it out means the price in
            // force does not depend on which database is under the app.
            ->orderByRaw('CASE WHEN starts_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_user_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(SuchakGrowthReward::class, 'reward_rule_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak growth reward rules cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak growth reward rules cannot be deleted.');
    }
}
