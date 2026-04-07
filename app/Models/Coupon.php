<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Coupon extends Model
{
    public const TYPE_PERCENT = 'percent';

    /** Same as {@see self::TYPE_FLAT} in pricing logic (amount off). */
    public const TYPE_FIXED = 'fixed';

    /** Alias of fixed / “flat ₹ off” in product copy. */
    public const TYPE_FLAT = 'flat';

    /** Extra subscription days after checkout (whole days from {@code value}). */
    public const TYPE_DAYS = 'days';

    /** Temporary entitlement grant; details in {@see $feature_payload}. */
    public const TYPE_FEATURE = 'feature';

    protected $fillable = [
        'code',
        'type',
        'value',
        'max_redemptions',
        'redemptions_count',
        'valid_from',
        'valid_until',
        'is_active',
        'min_purchase_amount',
        'applicable_plan_ids',
        'applicable_duration_types',
        'description',
        'feature_payload',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'max_redemptions' => 'integer',
        'redemptions_count' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
        'min_purchase_amount' => 'decimal:2',
        'applicable_plan_ids' => 'array',
        'applicable_duration_types' => 'array',
        'feature_payload' => 'array',
    ];

    public function appliesToPlan(int $planId): bool
    {
        $ids = $this->applicable_plan_ids;
        if ($ids === null || $ids === []) {
            return true;
        }

        return in_array($planId, array_map('intval', $ids), true);
    }

    public function appliesToDurationType(string $durationType): bool
    {
        $types = $this->applicable_duration_types;
        if ($types === null || $types === []) {
            return true;
        }

        return in_array($durationType, $types, true);
    }

    public function isUsableNow(?Carbon $at = null): bool
    {
        $at ??= now();
        if (! $this->is_active) {
            return false;
        }
        if ($this->valid_from && $at->lt($this->valid_from)) {
            return false;
        }
        if ($this->valid_until && $at->gt($this->valid_until)) {
            return false;
        }
        if ($this->max_redemptions !== null && (int) $this->redemptions_count >= (int) $this->max_redemptions) {
            return false;
        }

        return true;
    }
}
