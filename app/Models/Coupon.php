<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Coupon extends Model
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

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
