<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakCampaignRule extends Model
{
    use HasFactory;

    public const GOAL_RETENTION = 'retention';
    public const GOAL_PLATFORM_VALUE = 'platform_value';
    public const GOAL_RENEWAL = 'renewal';

    public const GOALS = [
        self::GOAL_RETENTION,
        self::GOAL_PLATFORM_VALUE,
        self::GOAL_RENEWAL,
    ];

    public const METRIC_PLATFORM_VALUE = 'platform_value_amount';
    public const METRIC_PLATFORM_LEADS = 'platform_leads_count';
    public const METRIC_VERIFIED_REPRESENTATIONS = 'verified_representation_count';

    public const METRICS = [
        self::METRIC_PLATFORM_VALUE,
        self::METRIC_PLATFORM_LEADS,
        self::METRIC_VERIFIED_REPRESENTATIONS,
    ];

    public const BONUS_CASH = 'cash';
    public const BONUS_CREDIT = 'credit';
    public const BONUS_RENEWAL_DISCOUNT = 'renewal_discount';
    public const BONUS_REVENUE_SHARE_OFFER = 'revenue_share_offer';

    public const BONUS_TYPES = [
        self::BONUS_CASH,
        self::BONUS_CREDIT,
        self::BONUS_RENEWAL_DISCOUNT,
        self::BONUS_REVENUE_SHARE_OFFER,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_ARCHIVED,
    ];

    protected $table = 'suchak_campaign_rules';

    protected $fillable = [
        'campaign_key',
        'campaign_name',
        'campaign_goal',
        'qualification_metric',
        'threshold_value',
        'bonus_type',
        'bonus_amount',
        'bonus_currency',
        'campaign_status',
        'starts_at',
        'ends_at',
        'created_by_admin_user_id',
        'admin_audit_log_id',
    ];

    protected $casts = [
        'threshold_value' => 'decimal:2',
        'bonus_amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_user_id');
    }

    public function adminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class, 'admin_audit_log_id');
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(SuchakCampaignQualification::class, 'campaign_rule_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak campaign rules cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak campaign rules cannot be deleted.');
    }
}
