<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakLoyaltyTierSnapshot extends Model
{
    use HasFactory;

    protected $table = 'suchak_loyalty_tier_snapshots';

    protected $fillable = [
        'suchak_account_id',
        'snapshot_month',
        'policy_key',
        'tier_key',
        'tier_label',
        'tier_label_mr',
        'tier_score',
        'platform_leads_count',
        'platform_value_amount',
        'verified_representation_count',
        'active_customer_count',
        'generated_by_admin_user_id',
        'admin_audit_log_id',
        'generated_at',
    ];

    protected $casts = [
        'tier_score' => 'integer',
        'platform_leads_count' => 'integer',
        'platform_value_amount' => 'decimal:2',
        'verified_representation_count' => 'integer',
        'active_customer_count' => 'integer',
        'generated_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function generatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_admin_user_id');
    }

    public function adminAuditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class, 'admin_audit_log_id');
    }

    public function monthlyValueReports(): HasMany
    {
        return $this->hasMany(SuchakMonthlyValueReport::class, 'loyalty_tier_snapshot_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak loyalty tier snapshots cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak loyalty tier snapshots cannot be deleted.');
    }
}
