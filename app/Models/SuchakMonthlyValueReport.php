<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakMonthlyValueReport extends Model
{
    use HasFactory;

    public const STATUS_GENERATED = 'generated';
    public const STATUS_PUBLISHED_INTERNAL = 'published_internal';
    public const STATUS_REPLACED = 'replaced';

    public const STATUSES = [
        self::STATUS_GENERATED,
        self::STATUS_PUBLISHED_INTERNAL,
        self::STATUS_REPLACED,
    ];

    protected $table = 'suchak_monthly_value_reports';

    protected $fillable = [
        'suchak_account_id',
        'report_month',
        'loyalty_tier_snapshot_id',
        'platform_leads_count',
        'platform_customer_value_amount',
        'suchak_customer_value_amount',
        'platform_payout_amount',
        'campaign_bonus_amount',
        'growth_reward_cash_amount',
        'unsupported_claims_count',
        'unsupported_claims_note',
        'unsupported_claims_note_mr',
        'report_status',
        'generated_by_admin_user_id',
        'admin_audit_log_id',
        'generated_at',
    ];

    protected $casts = [
        'platform_leads_count' => 'integer',
        'platform_customer_value_amount' => 'decimal:2',
        'suchak_customer_value_amount' => 'decimal:2',
        'platform_payout_amount' => 'decimal:2',
        'campaign_bonus_amount' => 'decimal:2',
        'growth_reward_cash_amount' => 'decimal:2',
        'unsupported_claims_count' => 'integer',
        'generated_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function loyaltyTierSnapshot(): BelongsTo
    {
        return $this->belongsTo(SuchakLoyaltyTierSnapshot::class, 'loyalty_tier_snapshot_id');
    }

    public function generatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_admin_user_id');
    }

    public function adminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class, 'admin_audit_log_id');
    }

    public function retentionOffers(): HasMany
    {
        return $this->hasMany(SuchakRetentionOffer::class, 'monthly_value_report_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak monthly value reports cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak monthly value reports cannot be deleted.');
    }
}
