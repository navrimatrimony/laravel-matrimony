<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-Suchak REUSABLE customer plan preset.
 *
 * - preset_key NULL  => a fully custom, Suchak-authored reusable plan.
 * - preset_key set   => an OVERRIDE row for a code-defined preset
 *   (App\Modules\Suchak\Support\SuchakDefaultPlans). The preset stays
 *   code-defined; this row only overrides price / visibility / order.
 *
 * This table is never FK-linked from the send-time model. On send a chosen plan
 * materializes into suchak_service_packages via SuchakPackageCatalogService.
 * It is unrelated to the platform subscription catalog `suchak_plans`.
 */
class SuchakCustomerPlan extends Model
{
    use HasFactory;

    public const DURATION_SIX_MONTHS = 'six_months';
    public const DURATION_ONE_YEAR = 'one_year';
    public const DURATION_TILL_MARRIAGE = 'till_marriage';

    public const DURATIONS = [
        self::DURATION_SIX_MONTHS,
        self::DURATION_ONE_YEAR,
        self::DURATION_TILL_MARRIAGE,
    ];

    public const MODE_AS_WISHED = 'as_wished';
    public const MODE_FIXED = 'fixed';
    public const MODE_NONE = 'none';

    public const POST_MARRIAGE_FEE_MODES = [
        self::MODE_AS_WISHED,
        self::MODE_FIXED,
        self::MODE_NONE,
    ];

    protected $table = 'suchak_customer_plans';

    protected $fillable = [
        'suchak_account_id',
        'preset_key',
        'name',
        'name_mr',
        'price_amount',
        'currency',
        'duration',
        'services_json',
        'per_meeting_fee_amount',
        'per_meeting_online_fee_amount',
        'post_marriage_fee_mode',
        'post_marriage_fee_amount',
        'original_price_amount',
        'private_note',
        'is_visible',
        'sort_order',
    ];

    protected $casts = [
        'services_json' => 'array',
        'is_visible' => 'boolean',
        'price_amount' => 'decimal:2',
        'per_meeting_fee_amount' => 'decimal:2',
        'per_meeting_online_fee_amount' => 'decimal:2',
        'post_marriage_fee_amount' => 'decimal:2',
        'original_price_amount' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    /**
     * Every plan for a Suchak, ordered for display.
     */
    public function scopeForSuchak(Builder $query, SuchakAccount|int $account): Builder
    {
        $accountId = $account instanceof SuchakAccount ? $account->id : $account;

        return $query
            ->where('suchak_account_id', $accountId)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function isPresetOverride(): bool
    {
        return $this->preset_key !== null;
    }

    public function isCustom(): bool
    {
        return $this->preset_key === null;
    }
}
