<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakCampaignQualification extends Model
{
    use HasFactory;

    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_QUALIFIED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'suchak_campaign_qualifications';

    protected $fillable = [
        'campaign_rule_id',
        'suchak_account_id',
        'qualification_month',
        'metric_value',
        'qualification_status',
        'bonus_type',
        'bonus_amount',
        'bonus_currency',
        'qualification_note',
        'qualified_by_admin_user_id',
        'admin_audit_log_id',
        'qualified_at',
    ];

    protected $casts = [
        'metric_value' => 'decimal:2',
        'bonus_amount' => 'decimal:2',
        'qualified_at' => 'datetime',
    ];

    public function campaignRule(): BelongsTo
    {
        return $this->belongsTo(SuchakCampaignRule::class, 'campaign_rule_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function qualifiedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qualified_by_admin_user_id');
    }

    public function adminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class, 'admin_audit_log_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak campaign qualification records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak campaign qualification records cannot be deleted.');
    }
}
